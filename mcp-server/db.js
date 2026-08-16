// Shared MySQL connection pool for the TaskFlow MCP server.
// Reads credentials from environment variables (see .env.example), mirroring
// the PHP app so both talk to the same `dev_taskflow` database.
import mysql from 'mysql2/promise';
import dotenv from 'dotenv';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { resolveEffectiveStandards, writeResolvedStandards } from './standards.js';
import { getOrRefreshPrimer, writePrimerBlock } from './project-primer.js';
import { notifyTicketEvent } from './notify.js';

// Load THIS directory's .env, not the caller's cwd. run-agent.sh cd's into the
// CLAIMED TICKET's project_folder (which varies per run - any registered
// project, not just taskflow) before starting Claude, so a bare dotenv.config()
// would read the wrong .env (or none) depending on which project's ticket is
// being worked. Anchoring to __dirname makes DB config independent of cwd.
const __dirname = dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: join(__dirname, '.env') });

export const pool = mysql.createPool({
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || 'dev_taskflow',
  waitForConnections: true,
  connectionLimit: 5,
  // Keep numeric priority as a Number, not a string.
  supportBigNumbers: true,
  // Interpret DATE/DATETIME <-> JS Date in IST.
  timezone: '+05:30',
});

// Pin every pooled connection's session to IST so NOW()/CURRENT_TIMESTAMP
// writes match how the PHP app reads them.
pool.on('connection', (conn) => {
  conn.query("SET time_zone = '+05:30'");
});

// run-agent.sh's start-run.js inserts a `runs` row before Claude launches and
// exports its id as TASKFLOW_RUN_ID; the MCP server child process inherits
// that env var for its whole lifetime, so every invocation/session-log row
// this process writes can be tagged back to the run that produced it. Unset
// (e.g. Claude started outside run-agent.sh) simply yields a NULL run_id.
const RUN_ID = process.env.TASKFLOW_RUN_ID ? Number(process.env.TASKFLOW_RUN_ID) : null;

/**
 * Append a row to the MCP invocation log (rendered on the app's Logs screen).
 * Best-effort: logging failures must never break a tool call.
 */
export async function logInvocation({ tool, taskId = null, params, status, result, durationMs }) {
  try {
    await pool.query(
      `INSERT INTO mcp_invocations (tool, task_id, run_id, params, status, result, duration_ms)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [
        tool,
        taskId,
        RUN_ID,
        params ? JSON.stringify(params).slice(0, 4000) : null,
        status,
        result ? JSON.stringify(result).slice(0, 4000) : null,
        durationMs,
      ]
    );
  } catch (err) {
    console.error('[taskflow-mcp] failed to write invocation log:', err.message);
  }
}

/**
 * Push a live session log entry (rendered in the app's Live Session drawer).
 * Best-effort; never breaks a tool call. log_type: thought|terminal|code_diff|status.
 */
export async function sessionLog(taskId, logType, content, filePath = null) {
  if (!taskId) return;
  try {
    await pool.query(
      `INSERT INTO ai_session_logs (task_id, run_id, log_type, content, file_path) VALUES (?, ?, ?, ?, ?)`,
      [taskId, RUN_ID, logType, String(content).slice(0, 60000), filePath]
    );
  } catch (err) {
    console.error('[taskflow-mcp] session log failed:', err.message);
  }
}

// Default acceptance-criteria template applied to AI-created tickets that omit
// one, matching the PHP side (functions.php ACCEPTANCE_CRITERIA_TEMPLATE).
export const ACCEPTANCE_CRITERIA_TEMPLATE = [
  '- [ ] Requirement implemented as described',
  '- [ ] Edge cases handled',
  '- [ ] No regressions in related features',
  '- [ ] Verified locally',
].join('\n');

// Convention-based per-project standards file: CLAUDE.md (preferred, auto-loaded
// by Claude Code as project context) or STANDARDS.md, at the project_folder root.
export function findStandardsFile(projectFolder) {
  if (!projectFolder) return null;
  for (const name of ['CLAUDE.md', 'STANDARDS.md']) {
    const candidate = join(projectFolder, name);
    if (fs.existsSync(candidate)) return candidate;
  }
  return null;
}

/**
 * Human comments left on a ticket (reviewer name + free text, oldest first) -
 * the other half of the two-way conversation from add_ticket_comment, which
 * only ever appends to the agent's own ai_comments log. Handed to the agent
 * inline in the claimed-ticket payload so guidance left between runs is
 * actually read, not just visible in the UI.
 */
export async function getHumanComments(taskId) {
  const [rows] = await pool.query(
    `SELECT author, comment_text, created_at FROM task_comments WHERE task_id = ? ORDER BY id ASC`,
    [taskId]
  );
  return rows.map((r) => ({ author: r.author, text: r.comment_text, created_at: r.created_at }));
}

/**
 * Finish claiming a ticket row already SELECTed ... FOR UPDATE inside `conn`'s
 * open transaction: lock it, move the card to In Progress, resolve/write
 * standards, log the session start, and commit. Shared by
 * claimHighestPriorityTicket and claimTicketById so there is exactly one
 * "what happens when a ticket is claimed" implementation.
 */
async function finalizeClaim(conn, ticket, resuming) {
  // A fresh (non-resumed) claim starts a new attempt streak - reset the
  // orphaned-resume counter so a ticket a human requeues from On Hold /
  // pending gets the full RESUME_ATTEMPT_LIMIT again (see reconcile.js).
  // Resuming a rate-limited-paused ticket must NOT reset it, since that's
  // the exact streak reconcile.js is counting.
  await conn.query(
    `UPDATE tasks
        SET ai_execution_status = 'in-progress', status = 'in_progress',
            ai_locked_at = NOW(), ai_started_at = NOW()
            ${resuming ? '' : ', resume_attempts = 0'}
      WHERE id = ?`,
    [ticket.id]
  );
  await conn.commit();
  ticket.standards_file = findStandardsFile(ticket.project_folder);
  ticket.human_comments = await getHumanComments(ticket.id);

  // Resolve the layered standards (org baseline + this project's override)
  // and write them into the project's CLAUDE.md as a managed block, so the
  // headless session about to start auto-loads them at process start (the
  // one point where "the right project's standards get loaded" is
  // decidable - see claim-ticket.js's header comment).
  if (ticket.project_folder) {
    try {
      const effective = await resolveEffectiveStandards(pool, ticket.project_id);
      if (effective) {
        ticket.standards_file = writeResolvedStandards(
          ticket.project_folder,
          ticket.standards_file,
          effective
        );
      }
    } catch (err) {
      console.error('[taskflow-mcp] standards resolution failed:', err.message);
    }

    // Cached per-project primer (structure/entry points/key files), only
    // regenerated when the file tree actually changed since the last claim
    // (see computeFingerprint in project-primer.js) - then written into the
    // same CLAUDE.md as a second managed block, so it's auto-loaded context
    // right alongside the standards block above.
    try {
      const primerMd = await getOrRefreshPrimer(pool, {
        projectId: ticket.project_id,
        projectFolder: ticket.project_folder,
        projectName: ticket.project_name,
      });
      if (primerMd) {
        ticket.standards_file = writePrimerBlock(ticket.project_folder, ticket.standards_file, primerMd);
      }
    } catch (err) {
      console.error('[taskflow-mcp] project primer generation failed:', err.message);
    }
  }

  // Announce the session start in the live drawer.
  await sessionLog(
    ticket.id,
    'status',
    resuming ? 'Resuming paused session; locked.' : 'Session initialized and locked.'
  );
  await sessionLog(
    ticket.id,
    'thought',
    `${resuming ? 'Resumed' : 'Claimed'} ${ticket.title}. Working directory: ` +
      `${ticket.project_folder || '(not set)'}.` +
      (ticket.standards_file ? ` Standards file: ${ticket.standards_file}.` : ' No standards file found.')
  );
  await notifyTicketEvent(ticket.id, 'in-progress', resuming ? 'Resumed after rate-limited pause.' : null);
  // Lets a caller that later has to give this claim back (e.g. run-agent.sh's
  // per-project lock is already held by a concurrently-running instance for
  // this ticket's project) know which prior state to restore - see
  // requeueTicket below.
  ticket.resumed = resuming;
  return ticket;
}

/**
 * Atomically claim the highest-priority pending ticket (resuming any
 * rate-limited-paused ticket first). This is the normal path - both the
 * `get_highest_priority_ticket` MCP tool and claim-ticket.js (the
 * run-agent.sh pre-launch step) call through here.
 *
 * Adds `standards_file`: the absolute path to the claimed project's
 * CLAUDE.md/STANDARDS.md, or null if it has none yet.
 */
export async function claimHighestPriorityTicket() {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    // Eligibility is driven by the Kanban column (the human's control):
    //   - tickets a human left in the "pending" column (not actively locked)
    //   - plus rate-limited-paused tickets to RESUME (they sit in In Progress)
    // Anything a human parked in On Hold / Completed is excluded.
    // Paused tickets are resumed first, then highest priority, then oldest.
    const [rows] = await conn.query(
      `SELECT t.id, t.title, t.description, t.acceptance_criteria, t.task_type,
              t.project_id, t.priority, t.ai_execution_status,
              p.name AS project_name, p.folder_path AS project_folder,
              p.access_url AS project_url
         FROM tasks t
         JOIN projects p ON p.id = t.project_id
        WHERE (
                (t.status = 'pending'
                 AND (t.ai_execution_status IS NULL OR t.ai_execution_status <> 'in-progress'))
                OR t.ai_execution_status = 'rate-limited-paused'
              )
        ORDER BY (t.ai_execution_status = 'rate-limited-paused') DESC,
                 t.priority ASC, t.id ASC
        LIMIT 1
        FOR UPDATE`
    );
    if (rows.length === 0) {
      await conn.commit();
      return {};
    }
    const ticket = rows[0];
    const resuming = ticket.ai_execution_status === 'rate-limited-paused';
    return await finalizeClaim(conn, ticket, resuming);
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

/** Reasons claimTicketById can fail, for a precise operator-facing message. */
export const CLAIM_NOT_FOUND = 'not_found';
export const CLAIM_NOT_CLAIMABLE = 'not_claimable';

/**
 * Atomically claim a SPECIFIC ticket by id, bypassing priority order. Used by
 * run-agent.sh's -t flag so an operator can force a particular ticket to run
 * next regardless of what's highest priority. Still enforces the same
 * eligibility rule as the priority claim (pending-and-unlocked, or resuming a
 * rate-limited-paused ticket), so it can never double-claim a ticket another
 * session already has in-progress, or silently re-run one a human parked On
 * Hold / Completed.
 *
 * Returns { ok:true, ticket } on success, or
 * { ok:false, reason: CLAIM_NOT_FOUND | CLAIM_NOT_CLAIMABLE, current? } on
 * failure (`current` is the ticket's present status, when it exists).
 */
export async function claimTicketById(id) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [rows] = await conn.query(
      `SELECT t.id, t.title, t.description, t.acceptance_criteria, t.task_type,
              t.project_id, t.priority, t.status, t.ai_execution_status,
              p.name AS project_name, p.folder_path AS project_folder,
              p.access_url AS project_url
         FROM tasks t
         JOIN projects p ON p.id = t.project_id
        WHERE t.id = ?
        FOR UPDATE`,
      [id]
    );
    if (rows.length === 0) {
      await conn.commit();
      return { ok: false, reason: CLAIM_NOT_FOUND };
    }
    const ticket = rows[0];
    const claimable =
      (ticket.status === 'pending' && ticket.ai_execution_status !== 'in-progress') ||
      ticket.ai_execution_status === 'rate-limited-paused';
    if (!claimable) {
      await conn.commit();
      return {
        ok: false,
        reason: CLAIM_NOT_CLAIMABLE,
        current: { status: ticket.status, ai_execution_status: ticket.ai_execution_status },
      };
    }
    const resuming = ticket.ai_execution_status === 'rate-limited-paused';
    const claimed = await finalizeClaim(conn, ticket, resuming);
    return { ok: true, ticket: claimed };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

/**
 * Mark a ticket blocked from the host shell (not from Claude), for failures
 * run-agent.sh itself detects - before Claude starts (e.g. a claimed ticket
 * whose project_folder doesn't exist on this host), between runs (reconcile.js
 * giving up on a repeatedly-orphaned session), or after Claude ends (a
 * conflicting auto-merge in git_checkpoint_finish) - so it reaches the
 * human-visible On Hold column instead of sitting locked in-progress forever.
 * `stage` labels WHEN in the harness's lifecycle this happened, since that's
 * not derivable from the reason text alone.
 */
export async function markTicketBlocked(taskId, reason, stage = 'pre-flight') {
  const note = `\n[${new Date().toISOString()}] ${reason}`;
  const [res] = await pool.query(
    `UPDATE tasks
        SET ai_execution_status = 'blocked', status = 'on_hold', ai_locked_at = NULL,
            ai_comments = CONCAT(COALESCE(ai_comments, ''), ?)
      WHERE id = ?`,
    [note, taskId]
  );
  if (res.affectedRows === 0) throw new Error(`No ticket with id ${taskId}`);
  await sessionLog(taskId, 'status', `Blocked by harness (${stage}): ${reason}`);
  await notifyTicketEvent(taskId, 'blocked', reason);
}

/**
 * Give back a ticket claimed by claimHighestPriorityTicket/claimTicketById
 * without treating it as blocked, completed, or orphaned - used by
 * run-agent.sh when a ticket was claimed but its project's per-project lock
 * (see run-agent.sh's "Per-project locking" section) is already held by a
 * concurrently-running instance working that same project. Restores exactly
 * the state the ticket was claimable from, using the `resumed` flag
 * finalizeClaim recorded at claim time: back to `rate-limited-paused` if this
 * claim was itself a resume (status stays `in_progress`, matching how paused
 * tickets already sit in that column), otherwise back to a fresh `pending`
 * row. Does NOT touch `resume_attempts` - this is a host-side scheduling
 * deferral, not a crashed/orphaned session (see reconcile.js).
 */
export async function requeueTicket(taskId, resumed, reason) {
  const note = `\n[${new Date().toISOString()}] ${reason}`;
  if (resumed) {
    await pool.query(
      `UPDATE tasks
          SET ai_execution_status = 'rate-limited-paused', ai_locked_at = NULL,
              ai_comments = CONCAT(COALESCE(ai_comments, ''), ?)
        WHERE id = ?`,
      [note, taskId]
    );
  } else {
    await pool.query(
      `UPDATE tasks
          SET ai_execution_status = NULL, status = 'pending', ai_locked_at = NULL,
              ai_comments = CONCAT(COALESCE(ai_comments, ''), ?)
        WHERE id = ?`,
      [note, taskId]
    );
  }
  await sessionLog(taskId, 'status', `Requeued: ${reason}`);
}

/**
 * Read a ticket's CURRENT lifecycle state. run-agent.sh calls this right
 * after a Claude session ends, to decide whether the just-finished session's
 * checkpoint branch is eligible for auto-merge - the process exit code alone
 * isn't trustworthy for that decision (a session can exit 0 after already
 * calling update_ticket_status('blocked') mid-session, and a crashed session
 * can exit non-zero while the ticket is still sitting 'in-progress',
 * unreconciled). Only the DB's ai_execution_status is authoritative.
 */
export async function getTicketState(taskId) {
  const [rows] = await pool.query(
    `SELECT ai_execution_status, status FROM tasks WHERE id = ?`,
    [taskId]
  );
  return rows[0] || null;
}

/**
 * Record that a completed ticket's checkpoint branch was auto-merged into the
 * base branch by run-agent.sh - both as a durable ai_comments entry and a
 * Live Session drawer / Agent Logs entry, so the merge is visible next to the
 * rest of the ticket's history.
 */
export async function recordMerge(taskId, scratchBranch, baseBranch) {
  const note = `\n[${new Date().toISOString()}] Auto-merged ${scratchBranch} into ` +
    `${baseBranch} (clean merge, no conflicts).`;
  await pool.query(
    `UPDATE tasks SET ai_comments = CONCAT(COALESCE(ai_comments, ''), ?) WHERE id = ?`,
    [note, taskId]
  );
  await sessionLog(taskId, 'status', `Auto-merged ${scratchBranch} into ${baseBranch}.`);
}
