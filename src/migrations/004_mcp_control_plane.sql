-- 004 MCP control plane: editable settings/system-prompts + MCP invocation log.

-- Key/value store for MCP settings and the editable system prompts.
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL,
    value       LONGTEXT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only log of every MCP tool invocation (rendered on the Logs screen).
CREATE TABLE IF NOT EXISTS mcp_invocations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tool        VARCHAR(100) NOT NULL,
    task_id     INT UNSIGNED NULL,
    params      LONGTEXT NULL,           -- JSON of the call arguments
    status      ENUM('ok', 'error') NOT NULL DEFAULT 'ok',
    result      LONGTEXT NULL,           -- JSON / summary of the result
    duration_ms INT UNSIGNED NULL,
    created_at  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_mcp_created (created_at),
    KEY idx_mcp_tool (tool)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings (INSERT IGNORE so re-runs / manual edits are preserved).
INSERT IGNORE INTO settings (setting_key, value) VALUES
    ('mcp_poll_interval_minutes', '30'),
    ('mcp_server_command', 'node /var/www/mcp-server/index.js'),
    ('mcp_enabled', '1'),
    ('agent_system_prompt', 'Call get_highest_priority_ticket. If none, exit. Implement one ticket in the local working directory using repo-map and semantic search to find files. Never run git commit/push. On rate limit: update_ticket_status rate-limited-paused, add_ticket_comment with state, exit non-zero. On success: run tests, add_ticket_comment summary, update_ticket_status completed.'),
    ('agent_operating_rules', 'Surgical navigation only (no full-directory scans). Local modifications only - no git commit/push/tag. Decompose oversized work and log tech debt via create_ticket.');
