#!/usr/bin/env node
/**
 * Pre-launch model selection for run-agent.sh (see model-router.js for the
 * routing rules). Called after claim-ticket.js, using the priority/task_type
 * already present on the claimed ticket, so no extra DB round-trip to `tasks`
 * is needed.
 *
 * Prints `{"model": "..."|null, "reason": "..."}` as one line of JSON on
 * stdout. run-agent.sh logs this line verbatim (see AC "Verified routing in
 * run logs") and, when `model` is non-null, passes it to `claude --model`.
 *
 * Usage: node resolve-model.js <priority> <task_type>
 */
import { pool } from './db.js';
import { resolveModelForTicket } from './model-router.js';

const [, , priorityArg, taskType] = process.argv;

async function main() {
  const priority = Number(priorityArg);
  if (!priority || !taskType) {
    console.error('[resolve-model] usage: node resolve-model.js <priority> <task_type>');
    process.exit(1);
  }
  const result = await resolveModelForTicket({ priority, task_type: taskType });
  process.stdout.write(JSON.stringify(result));
  await pool.end();
}

main().catch((err) => {
  console.error('[resolve-model] error:', err.message);
  process.exit(1);
});
