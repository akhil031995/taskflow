#!/usr/bin/env node
/**
 * Mark a ticket blocked from the host shell, for failures run-agent.sh
 * detects before Claude even starts (currently: a claimed ticket whose
 * project_folder is unset or doesn't exist on this host). Without this the
 * ticket would stay locked in-progress with no path to a human-visible
 * column - reconcile.js would keep flipping it to rate-limited-paused and
 * run-agent.sh would keep re-claiming and re-failing it forever.
 *
 * Usage: node block-ticket.js <task_id> "<reason>" [stage]
 * `stage` labels WHEN this happened (default "pre-flight"); git_checkpoint_finish
 * passes "post-run merge" when a completed ticket's auto-merge conflicted.
 */
import { pool, markTicketBlocked } from './db.js';

const [, , taskIdArg, reason, stage] = process.argv;
const taskId = Number(taskIdArg);

async function main() {
  if (!taskId || !reason) {
    console.error('[block-ticket] usage: node block-ticket.js <task_id> "<reason>" [stage]');
    process.exit(1);
  }
  await markTicketBlocked(taskId, reason, stage || undefined);
  console.error(`[block-ticket] TF-${taskId} marked blocked.`);
  await pool.end();
}

main().catch((e) => {
  console.error('[block-ticket] error:', e.message);
  process.exit(1);
});
