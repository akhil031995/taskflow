#!/usr/bin/env node
/**
 * TaskFlow MCP server.
 *
 * Exposes the TaskFlow ticket database to headless Claude Code agents over the
 * Model Context Protocol (stdio transport). Four tools:
 *
 *   - get_highest_priority_ticket : atomically claim the top pending ticket
 *   - update_ticket_status        : set final AI execution state
 *   - add_ticket_comment          : append a timestamped implementation note
 *   - create_ticket               : file a new (AI-authored) ticket
 *
 * Every invocation is timed and written to the `mcp_invocations` table so the
 * app's "AI Agent Logs" screen can render it.
 */
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { pool, logInvocation, sessionLog, claimHighestPriorityTicket } from './db.js';
import { getEffectiveAcceptanceCriteria, getProjectDodGates, runDodGates } from './dod-gates.js';
import { notifyTicketEvent } from './notify.js';

const server = new McpServer({ name: 'taskflow-mcp-server', version: '1.0.0' });

/**
 * Register a tool whose core returns a plain payload object (or throws).
 * The wrapper times the call, formats the MCP result, and logs the invocation.
 *
 * @param {string} name
 * @param {string} description
 * @param {object} schema         zod raw shape (or {})
 * @param {(args) => Promise<object>} core  returns the payload to return/log
 */
function registerTool(name, description, schema, core) {
  server.tool(name, description, schema, async (args = {}) => {
    const startedAt = Date.now();
    try {
      const payload = await core(args);
      const taskId = args.task_id ?? payload?.id ?? null;
      const ms = Date.now() - startedAt;
      await logInvocation({
        tool: name,
        taskId: taskId ? Number(taskId) : null,
        params: args,
        status: 'ok',
        result: payload,
        durationMs: ms,
      });
      // Mirror the call into the live session terminal feed.
      await sessionLog(taskId ? Number(taskId) : null, 'terminal', `mcp-call ${name} -> Success (${ms} ms)`);
      return { content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }] };
    } catch (err) {
      const taskId = args.task_id ? Number(args.task_id) : null;
      await logInvocation({
        tool: name,
        taskId,
        params: args,
        status: 'error',
        result: { error: err.message },
        durationMs: Date.now() - startedAt,
      });
      await sessionLog(taskId, 'terminal', `mcp-call ${name} -> ERROR: ${err.message}`);
      return {
        content: [{ type: 'text', text: JSON.stringify({ error: err.message }) }],
        isError: true,
      };
    }
  });
}

// ===================================================================
// 1. get_highest_priority_ticket
//    Claims the top pending ticket in one atomic transaction so two concurrent
//    agent runs can never grab the same ticket.
// ===================================================================
registerTool(
  'get_highest_priority_ticket',
  'Return the highest-priority pending ticket and atomically lock it ' +
    '(ai_execution_status = in-progress, ai_locked_at = NOW()). Ordered by ' +
    'priority ASC (1 High before 3 Low), then id ASC. Includes standards_file ' +
    '(the claimed project\'s CLAUDE.md/STANDARDS.md path, or null). Returns {} when none. ' +
    'NOTE: run-agent.sh claims the ticket itself (via claim-ticket.js) before starting ' +
    'this session so it can cd into project_folder first; do not call this tool if the ' +
    'run prompt already handed you a claimed ticket.',
  {},
  async () => claimHighestPriorityTicket()
);

// ===================================================================
// 2. update_ticket_status
// ===================================================================
registerTool(
  'update_ticket_status',
  "Set a ticket's final AI execution status and clear its lock. Requesting " +
    "'completed' first runs the project's configured Definition-of-Done gates " +
    "(lint/test/build commands, see the project's DoD Gates tab) in its " +
    'project_folder; if any configured gate fails, the ticket is blocked ' +
    'instead and the captured command output is attached as a comment.',
  {
    task_id: z.number().int().positive(),
    status: z.enum(['completed', 'blocked', 'rate-limited-paused']),
  },
  async ({ task_id, status }) => {
    if (status === 'completed') {
      const gateResult = await enforceDodGates(task_id);
      if (gateResult) return gateResult; // gates failed; ticket was blocked instead
    }

    // Map the AI lifecycle state onto the Kanban column:
    //   completed            -> Completed
    //   blocked (failure)    -> On Hold (human takes over; error is in comments)
    //   rate-limited-paused  -> stays In Progress (waiting to resume)
    const kanban = status === 'completed' ? 'completed'
                 : status === 'blocked'   ? 'on_hold'
                 : 'in_progress';
    // Stamp completion time when finishing.
    const completedSet = status === 'completed' ? ', ai_completed_at = NOW()' : '';
    const [res] = await pool.query(
      `UPDATE tasks SET ai_execution_status = ?, status = ?, ai_locked_at = NULL${completedSet} WHERE id = ?`,
      [status, kanban, task_id]
    );
    if (res.affectedRows === 0) throw new Error(`No ticket with id ${task_id}`);
    // Under run-agent.sh (TASKFLOW_RUN_ID set, inherited from the launching
    // shell - see db.js's finalizeClaim comment), record-run.js sends the
    // 'completed' notification itself once the session ends, enriched with
    // this run's actual token usage/cost (unknowable here, mid-session) and
    // after git_checkpoint_finish has confirmed the auto-merge didn't
    // conflict. Sending it here too would just duplicate it early and
    // token-less. Manual/standalone sessions (no run-agent.sh, so no
    // record-run.js call coming) still get this best-effort notification.
    if (!(status === 'completed' && process.env.TASKFLOW_RUN_ID)) {
      await notifyTicketEvent(task_id, status);
    }
    return { id: task_id, ai_execution_status: status, status: kanban };
  }
);

/**
 * Run the claimed project's DoD gates for a ticket about to be marked
 * completed. Returns null when there is nothing configured or every gate
 * passed (caller proceeds with the normal completed update). Returns the
 * tool payload directly when a gate failed - the ticket has already been
 * written to 'blocked' with the captured output, so the caller returns early.
 */
async function enforceDodGates(task_id) {
  const [rows] = await pool.query(
    `SELECT t.id, p.id AS project_id, p.folder_path AS project_folder
       FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ?`,
    [task_id]
  );
  const ticket = rows[0];
  if (!ticket) throw new Error(`No ticket with id ${task_id}`);
  if (!ticket.project_folder) return null; // nothing to run gates in

  const gates = await getProjectDodGates(pool, ticket.project_id);
  const { passed, report } = runDodGates(ticket.project_folder, gates);
  await sessionLog(task_id, 'terminal', `DoD gates ${passed ? 'passed' : 'FAILED'}:\n${report}`);
  if (passed) return null;

  const note = `\n[${new Date().toISOString()}] Definition-of-Done gates failed; ` +
    `blocked automatically instead of completed.\n${report}`;
  const [res] = await pool.query(
    `UPDATE tasks
        SET ai_execution_status = 'blocked', status = 'on_hold', ai_locked_at = NULL,
            ai_comments = CONCAT(COALESCE(ai_comments, ''), ?)
      WHERE id = ?`,
    [note, task_id]
  );
  if (res.affectedRows === 0) throw new Error(`No ticket with id ${task_id}`);
  await sessionLog(task_id, 'status', 'DoD gates failed -> blocked (see comments for captured output).');
  await notifyTicketEvent(task_id, 'blocked', 'Definition-of-Done gates failed.');
  return { id: task_id, ai_execution_status: 'blocked', status: 'on_hold', gates_passed: false, gate_report: report };
}

// ===================================================================
// 3. add_ticket_comment
// ===================================================================
registerTool(
  'add_ticket_comment',
  "Append a timestamped comment to a ticket's ai_comments implementation log.",
  {
    task_id: z.number().int().positive(),
    comment_text: z.string().min(1),
  },
  async ({ task_id, comment_text }) => {
    const entry = `\n[${new Date().toISOString()}] ${comment_text}`;
    const [res] = await pool.query(
      `UPDATE tasks SET ai_comments = CONCAT(COALESCE(ai_comments, ''), ?) WHERE id = ?`,
      [entry, task_id]
    );
    if (res.affectedRows === 0) throw new Error(`No ticket with id ${task_id}`);
    return { id: task_id, appended: entry.trim() };
  }
);

// ===================================================================
// 4. create_ticket
// ===================================================================
registerTool(
  'create_ticket',
  'Create a new AI-authored ticket (created_by = ai, ai_execution_status = ' +
    'pending). Use this to decompose oversized work or log discovered tech debt.',
  {
    title: z.string().min(1),
    description: z.string().default(''),
    priority: z.number().int().min(1).max(3).default(2),
    task_type: z.enum(['feature', 'bug', 'tech-debt', 'sub-task']),
    project_id: z.number().int().positive(),
  },
  async ({ title, description, priority, task_type, project_id }) => {
    const [proj] = await pool.query('SELECT id FROM projects WHERE id = ?', [project_id]);
    if (proj.length === 0) throw new Error(`No project with id ${project_id}`);
    const criteria = await getEffectiveAcceptanceCriteria(pool, project_id);
    const [res] = await pool.query(
      `INSERT INTO tasks
          (project_id, title, description, status, priority, position,
           task_type, acceptance_criteria, created_by, ai_execution_status)
       VALUES (?, ?, ?, 'pending', ?, 0, ?, ?, 'ai', 'pending')`,
      [project_id, title, description, priority, task_type, criteria]
    );
    await notifyTicketEvent(res.insertId, 'created');
    return { id: res.insertId, created_by: 'ai', ai_execution_status: 'pending' };
  }
);

// ===================================================================
// Boot over stdio.
// ===================================================================
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('[taskflow-mcp] ready on stdio');
}

main().catch((err) => {
  console.error('[taskflow-mcp] fatal:', err);
  process.exit(1);
});
