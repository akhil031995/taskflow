-- 011 run tagging: tie ai_session_logs and mcp_invocations rows back to the
-- `runs` row (see 010_runs_table.sql) for the run-agent.sh session that
-- produced them, so the Logs UI can group/filter one execution at a time
-- instead of an undifferentiated stream. No FK (matches mcp_invocations'
-- and runs' existing convention) so log history survives a run row being
-- pruned later. Rows written before this migration simply have run_id NULL
-- ("unassigned" bucket in the UI) - handled gracefully, not backfilled.
ALTER TABLE ai_session_logs
    ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER task_id,
    ADD KEY idx_session_logs_run (run_id);

ALTER TABLE mcp_invocations
    ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER task_id,
    ADD KEY idx_invocations_run (run_id);

-- Outcome summary for a run, set once it finishes (NULL while still running):
-- success | error | rate_limited. Derived from exit_code by record-run.js
-- rather than recomputed ad hoc every time the Logs UI reads a row.
ALTER TABLE runs
    ADD COLUMN outcome ENUM('success', 'error', 'rate_limited') NULL AFTER is_error;
