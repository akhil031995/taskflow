#!/usr/bin/env node
/**
 * Look up a ticket's CURRENT ai_execution_status/status from the DB, for
 * run-agent.sh's git_checkpoint_finish to decide whether the checkpoint
 * branch it just committed is eligible for auto-merge. Must be called AFTER
 * Claude's session has ended, not before - see getTicketState in db.js for
 * why the exit code alone can't drive this decision.
 *
 * Prints `{"ai_execution_status":"...","status":"..."}` (or `{}` if the
 * ticket doesn't exist) as one line of JSON on stdout.
 * Usage: node get-ticket-status.js <task_id>
 */
import { pool, getTicketState } from './db.js';

const [, , taskIdArg] = process.argv;
const taskId = Number(taskIdArg);

async function main() {
  if (!taskId) {
    console.error('[get-ticket-status] usage: node get-ticket-status.js <task_id>');
    process.exit(1);
  }
  const state = await getTicketState(taskId);
  process.stdout.write(JSON.stringify(state || {}));
  await pool.end();
}

main().catch((err) => {
  console.error('[get-ticket-status] error:', err.message);
  process.exit(1);
});
