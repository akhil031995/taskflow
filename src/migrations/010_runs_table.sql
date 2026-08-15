-- 010 runs table: per-run token/cost capture from `claude --output-format
-- stream-json` agent sessions, for per-ticket and per-project cost rollups.
-- No FK constraints (matches mcp_invocations' convention) so cost history
-- survives a task/project being deleted later.
CREATE TABLE IF NOT EXISTS runs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id               INT UNSIGNED NULL,
    project_id            INT UNSIGNED NULL,
    started_at            TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at           TIMESTAMP(3) NULL,
    exit_code             INT NULL,
    is_error              TINYINT(1) NOT NULL DEFAULT 0,
    num_turns             INT UNSIGNED NULL,
    duration_ms           INT UNSIGNED NULL,
    duration_api_ms       INT UNSIGNED NULL,
    model                 VARCHAR(100) NULL,
    input_tokens          INT UNSIGNED NULL,
    output_tokens         INT UNSIGNED NULL,
    cache_creation_tokens INT UNSIGNED NULL,
    cache_read_tokens     INT UNSIGNED NULL,
    total_cost_usd        DECIMAL(10,4) NULL,
    created_at            TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_runs_task (task_id),
    KEY idx_runs_project (project_id),
    KEY idx_runs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
