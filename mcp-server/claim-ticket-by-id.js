#!/usr/bin/env node
/**
 * Claim a SPECIFIC ticket by id, bypassing priority order - used by
 * run-agent.sh's -t flag so an operator can force a particular ticket to run
 * next regardless of what's highest priority.
 *
 * On success, prints the claimed ticket as one line of JSON on stdout (same
 * shape as claim-ticket.js). On failure, prints nothing to stdout, a clear
 * message to stderr, and exits non-zero:
 *   1  - bad usage (missing/non-numeric id)
 *   2  - no ticket with that id
 *   3  - ticket exists but isn't in a claimable state right now
 *
 * Usage: node claim-ticket-by-id.js <task_id>
 */
import { pool, claimTicketById, CLAIM_NOT_FOUND, CLAIM_NOT_CLAIMABLE } from './db.js';

const id = Number(process.argv[2]);
const key = (n) => `TF-${String(n).padStart(3, '0')}`;

async function main() {
  if (!Number.isInteger(id) || id <= 0) {
    console.error('[claim-ticket-by-id] usage: node claim-ticket-by-id.js <task_id>');
    process.exitCode = 1;
    await pool.end();
    return;
  }

  const result = await claimTicketById(id);
  if (!result.ok) {
    if (result.reason === CLAIM_NOT_FOUND) {
      console.error(`[claim-ticket-by-id] No ticket with id ${id} (${key(id)}).`);
      process.exitCode = 2;
    } else {
      const cur = result.current || {};
      console.error(
        `[claim-ticket-by-id] ${key(id)} is not claimable right now ` +
          `(status=${cur.status}, ai_execution_status=${cur.ai_execution_status}). ` +
          'Only tickets in Pending (and not already in-progress) or Rate-Limited-Paused can be claimed.'
      );
      process.exitCode = 3;
    }
    await pool.end();
    return;
  }

  process.stdout.write(JSON.stringify(result.ticket));
  await pool.end();
}

main().catch((err) => {
  console.error('[claim-ticket-by-id] error:', err.message);
  process.exit(1);
});
