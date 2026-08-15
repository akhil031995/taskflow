#!/usr/bin/env node
/**
 * Pre-launch claim step for run-agent.sh.
 *
 * Runs the exact same atomic claim as the get_highest_priority_ticket MCP
 * tool (see claimHighestPriorityTicket in db.js), but from the host shell
 * BEFORE Claude starts. That lets run-agent.sh read the claimed ticket's
 * project_folder and cd there before launching Claude - CLAUDE.md is only
 * auto-loaded at process start, so this is the only point where "the right
 * project's standards get loaded" is actually decidable.
 *
 * The claimed ticket is handed to Claude inline in the run prompt (see
 * AGENT_PROMPT.md). Claude must NOT call get_highest_priority_ticket itself
 * for that run - it would claim a second, unrelated ticket.
 *
 * Prints the claimed ticket (or {}) as one line of JSON on stdout.
 * Usage: node claim-ticket.js
 */
import { pool, claimHighestPriorityTicket } from './db.js';

async function main() {
  const ticket = await claimHighestPriorityTicket();
  process.stdout.write(JSON.stringify(ticket));
  await pool.end();
}

main().catch((err) => {
  console.error('[claim-ticket] error:', err.message);
  process.exit(1);
});
