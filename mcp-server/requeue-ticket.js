#!/usr/bin/env node
/**
 * Give back a ticket claimed this iteration, without marking it blocked or
 * completed - used by run-agent.sh when its per-project lock (see
 * run-agent.sh) for the claimed ticket's project is already held by a
 * concurrently-running instance. The ticket goes back to the queue (as
 * `rate-limited-paused` if this claim was itself a resume, otherwise fresh
 * `pending`) so another run-agent instance - or this same one, shortly - can
 * claim it, or claim a different project's ticket instead.
 *
 * Usage: node requeue-ticket.js <task_id> <resumed:0|1> "<reason>"
 */
import { pool, requeueTicket } from './db.js';

const [, , taskIdArg, resumedArg, reason] = process.argv;
const taskId = Number(taskIdArg);
const resumed = resumedArg === '1' || resumedArg === 'true';

async function main() {
  if (!Number.isInteger(taskId) || taskId <= 0 || !reason) {
    console.error('[requeue-ticket] usage: node requeue-ticket.js <task_id> <resumed:0|1> "<reason>"');
    process.exitCode = 1;
    await pool.end();
    return;
  }
  await requeueTicket(taskId, resumed, reason);
  console.error(`[requeue-ticket] TF-${String(taskId).padStart(3, '0')} requeued (resumed=${resumed}).`);
  await pool.end();
}

main().catch((err) => {
  console.error('[requeue-ticket] error:', err.message);
  process.exit(1);
});
