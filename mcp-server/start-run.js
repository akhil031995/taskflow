#!/usr/bin/env node
/**
 * Pre-launch run step for run-agent.sh, mirroring claim-ticket.js: inserts
 * the `runs` row (see 010_runs_table.sql / 011_run_tagging.sql) for the
 * session that's about to start, BEFORE Claude launches, so its id can be
 * exported as TASKFLOW_RUN_ID and inherited by the MCP server subprocess -
 * that's what lets logInvocation/sessionLog (db.js) tag every
 * mcp_invocations/ai_session_logs row this session writes with the run that
 * produced it. record-run.js UPDATEs this same row once the session ends.
 *
 * Prints just the new run id (an integer) on stdout.
 * Usage: node start-run.js <task_id|-> <project_id|->
 */
import { pool } from './db.js';

async function main() {
  const [, , taskIdArg, projectIdArg] = process.argv;
  const taskId = taskIdArg && taskIdArg !== '-' ? Number(taskIdArg) : null;
  const projectId = projectIdArg && projectIdArg !== '-' ? Number(projectIdArg) : null;

  const [res] = await pool.query(
    'INSERT INTO runs (task_id, project_id, started_at) VALUES (?, ?, NOW())',
    [taskId, projectId]
  );
  process.stdout.write(String(res.insertId));
  await pool.end();
}

main().catch((err) => {
  console.error('[start-run] error:', err.message);
  process.exit(1);
});
