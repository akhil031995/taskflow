#!/usr/bin/env node
/**
 * Reconcile orphaned AI sessions.
 *
 * run-agent.sh is single-instance (flock) and runs sessions sequentially, so
 * any ticket still `in-progress` when this runs BETWEEN sessions died without
 * reporting its status - almost always because the CLI hit the token/rate
 * limit and was killed before it could call update_ticket_status.
 *
 * Such tickets are moved to `rate-limited-paused` (they stay in the In Progress
 * column) so the next run RESUMES them (get_highest_priority_ticket resumes
 * paused tickets first, and the agent continues from the prior `git diff`).
 *
 * Each reconciliation increments the ticket's `resume_attempts` counter
 * (reset to 0 on the next fresh, non-resumed claim - see finalizeClaim in
 * db.js). Once a ticket has racked up RESUME_ATTEMPT_LIMIT consecutive
 * orphaned resumes without ever completing a session, it's auto-blocked
 * instead of resumed again, so a perpetually-failing ticket can't loop
 * forever and starve the queue - a human has to clear it from On Hold.
 * Configure the threshold with the RESUME_ATTEMPT_LIMIT env var (default 3).
 *
 * Run standalone:  node mcp-server/reconcile.js
 * run-agent.sh calls this at the start of every iteration.
 */
import { pool, sessionLog, markTicketBlocked } from './db.js';

const RESUME_ATTEMPT_LIMIT = Number(process.env.RESUME_ATTEMPT_LIMIT) || 3;

async function main() {
  const [rows] = await pool.query(
    "SELECT id, title, resume_attempts FROM tasks WHERE ai_execution_status = 'in-progress'"
  );

  for (const t of rows) {
    const attempts = t.resume_attempts + 1;
    await pool.query('UPDATE tasks SET resume_attempts = ? WHERE id = ?', [attempts, t.id]);

    if (attempts >= RESUME_ATTEMPT_LIMIT) {
      await markTicketBlocked(
        t.id,
        `Auto-blocked after ${attempts} consecutive orphaned resumes (limit ${RESUME_ATTEMPT_LIMIT}). ` +
          `The session keeps ending without reporting status - needs human investigation before retrying.`
      );
      console.error(`[reconcile] TF-${String(t.id).padStart(3, '0')} "${t.title}": in-progress → blocked (resume limit reached, ${attempts}/${RESUME_ATTEMPT_LIMIT})`);
      continue;
    }

    const note = `\n[${new Date().toISOString()}] Session ended without reporting status ` +
      `(likely token/rate limit or crash); auto-marked rate-limited-paused for resume ` +
      `(attempt ${attempts}/${RESUME_ATTEMPT_LIMIT}).`;
    await pool.query(
      `UPDATE tasks
          SET ai_execution_status = 'rate-limited-paused',
              ai_locked_at = NULL,
              ai_comments = CONCAT(COALESCE(ai_comments, ''), ?)
        WHERE id = ?`,
      [note, t.id]
    );
    await sessionLog(t.id, 'status', `Orphaned session reconciled → rate-limited-paused (attempt ${attempts}/${RESUME_ATTEMPT_LIMIT}; will resume next run).`);
    console.error(`[reconcile] TF-${String(t.id).padStart(3, '0')} "${t.title}": in-progress → rate-limited-paused (${attempts}/${RESUME_ATTEMPT_LIMIT})`);
  }

  console.error(rows.length === 0
    ? '[reconcile] no orphaned in-progress sessions.'
    : `[reconcile] reconciled ${rows.length} orphaned session(s).`);

  await pool.end();
}

main().catch((e) => {
  console.error('[reconcile] error:', e.message);
  process.exit(1);
});
