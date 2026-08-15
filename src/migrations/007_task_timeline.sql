-- 007 task timeline: preserve when the AI first picked a ticket up and when it
-- finished, so the task detail modal can show a full lifecycle timeline
-- (ai_locked_at is transient - cleared on release - so it can't serve this).
ALTER TABLE tasks
  ADD COLUMN ai_started_at   TIMESTAMP NULL DEFAULT NULL COMMENT 'when an agent last claimed the ticket',
  ADD COLUMN ai_completed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'when the AI marked it completed';
