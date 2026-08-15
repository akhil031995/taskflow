-- 003 AI agent fields: state management, atomic locking, prioritization,
-- typing, acceptance criteria, comments, and authorship on the tasks table.
--
-- NOTE ON `priority`: migration 001 already created `priority` as an
-- ENUM('low','medium','high','urgent'). The AI control plane needs a NUMERIC
-- priority (1-High, 2-Medium, 3-Low) that sorts ASC. We therefore CONVERT the
-- existing column in place (backfilling old enum values) rather than adding a
-- duplicate column, which would fail with "Duplicate column name 'priority'".

-- 1. Add a numeric priority alongside the old enum, then backfill from it.
ALTER TABLE tasks
  ADD COLUMN priority_num TINYINT UNSIGNED NOT NULL DEFAULT 2
  COMMENT '1-High, 2-Medium, 3-Low';

UPDATE tasks SET priority_num = CASE priority
    WHEN 'urgent' THEN 1
    WHEN 'high'   THEN 1
    WHEN 'medium' THEN 2
    WHEN 'low'    THEN 3
    ELSE 2
END;

-- 2. Drop the old enum column and rename the numeric one into its place.
ALTER TABLE tasks DROP COLUMN priority;

ALTER TABLE tasks
  CHANGE COLUMN priority_num priority TINYINT UNSIGNED NOT NULL DEFAULT 2
  COMMENT '1-High, 2-Medium, 3-Low';

-- 3. Add the remaining AI-agent fields.
ALTER TABLE tasks
  ADD COLUMN task_type ENUM('feature', 'bug', 'tech-debt', 'sub-task') NOT NULL DEFAULT 'feature',
  ADD COLUMN acceptance_criteria TEXT NULL,
  ADD COLUMN ai_execution_status ENUM('pending', 'in-progress', 'completed', 'blocked', 'rate-limited-paused') DEFAULT 'pending',
  ADD COLUMN ai_locked_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN ai_comments LONGTEXT NULL,
  ADD COLUMN created_by ENUM('human', 'ai') NOT NULL DEFAULT 'human';

-- 4. Index the columns the MCP dispatcher orders / filters by.
ALTER TABLE tasks
  ADD INDEX idx_tasks_ai_dispatch (ai_execution_status, priority, id);
