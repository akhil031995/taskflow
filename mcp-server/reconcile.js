#!/usr/bin/env node
/**
 * Reconcile orphaned AI sessions.
 *
 * run-agent.sh instances now hold only a per-project lock (see run-agent.sh's
 * "Per-project locking" section), not a single global one, so several
 * instances can legitimately have tickets `in-progress` at once - one per
 * locked project. A ticket found `in-progress` here is only truly orphaned if
 * NO run-agent instance currently holds its project's lock; if the lock is
 * held, a live session is still working it and must not be touched. Such
 * orphans die without reporting status - almost always because the CLI hit
 * the token/rate limit and was killed before it could call
 * update_ticket_status.
 *
 * Orphaned tickets are moved to `rate-limited-paused` (they stay in the In
 * Progress column) so the next run RESUMES them (get_highest_priority_ticket
 * resumes paused tickets first, and the agent continues from the prior
 * `git diff`).
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
 * run-agent.sh calls this at the start of every iteration, before it holds
 * any lock itself - so at that point, any project lock found held belongs to
 * a genuinely concurrent sibling instance, never to this run.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { pool, sessionLog, markTicketBlocked } from './db.js';
import { notifyTicketEvent } from './notify.js';

const RESUME_ATTEMPT_LIMIT = Number(process.env.RESUME_ATTEMPT_LIMIT) || 3;
const LOCK_DIR = process.env.TASKFLOW_LOCK_DIR || '/tmp/taskflow-agent-locks';

/** True if another live process currently holds project_id's per-project lock. */
function isProjectLocked(projectId) {
  const lockFile = path.join(LOCK_DIR, `project-${projectId}.lock`);
  if (!fs.existsSync(lockFile)) return false; // never locked yet on this host
  try {
    // Acquire-and-immediately-release: succeeds (exit 0) iff no one else
    // holds it right now. `-n` fails fast instead of blocking.
    execFileSync('flock', ['-n', lockFile, '-c', 'true'], { stdio: 'ignore' });
    return false;
  } catch {
    return true;
  }
}

async function main() {
  const [rows] = await pool.query(
    "SELECT id, title, resume_attempts, project_id FROM tasks WHERE ai_execution_status = 'in-progress'"
  );

  for (const t of rows) {
    if (isProjectLocked(t.project_id)) {
      console.error(`[reconcile] TF-${String(t.id).padStart(3, '0')} "${t.title}": project ${t.project_id} is locked by a live run-agent instance; skipping (not orphaned).`);
      continue;
    }

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

    const reason = `Session ended without reporting status (likely token/rate limit or crash); ` +
      `auto-marked rate-limited-paused for resume (attempt ${attempts}/${RESUME_ATTEMPT_LIMIT}).`;
    const note = `\n[${new Date().toISOString()}] ${reason}`;
    await pool.query(
      `UPDATE tasks
          SET ai_execution_status = 'rate-limited-paused',
              ai_locked_at = NULL,
              ai_comments = CONCAT(COALESCE(ai_comments, ''), ?)
        WHERE id = ?`,
      [note, t.id]
    );
    await sessionLog(t.id, 'status', `Orphaned session reconciled → rate-limited-paused (attempt ${attempts}/${RESUME_ATTEMPT_LIMIT}; will resume next run).`);
    // This is an unreported crash, not the agent's own voluntary
    // update_ticket_status('rate-limited-paused') call (which already
    // notifies from index.js) - flag it distinctly as an error so a human
    // can tell "hit the rate limit normally" apart from "session died".
    await notifyTicketEvent(t.id, 'error', reason);
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
