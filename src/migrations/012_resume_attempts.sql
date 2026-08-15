-- 012 Resume-attempt limit: track consecutive orphaned resumes per ticket so
-- reconcile.js can auto-block a perpetually-failing ticket (e.g. one that
-- keeps crashing before it can report status) instead of resuming it forever
-- and starving the queue. Reset to 0 whenever the ticket is freshly claimed
-- (not resumed), so the count only spans an unbroken streak of orphaned
-- sessions.
ALTER TABLE tasks
  ADD COLUMN resume_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0
  COMMENT 'Consecutive orphaned in-progress->rate-limited-paused reconciliations since the last fresh claim, auto-blocks at RESUME_ATTEMPT_LIMIT';
