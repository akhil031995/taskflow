-- 009 Definition-of-Done gates: per-project lint/test/build commands plus an
-- optional per-project override of ACCEPTANCE_CRITERIA_TEMPLATE. Resolved at
-- ticket-completion time (mcp-server/dod-gates.js mirrors this on the Node
-- side, same split as project_standards / standards.js).

CREATE TABLE IF NOT EXISTS project_dod_gates (
    project_id  INT UNSIGNED NOT NULL,
    lint_cmd    VARCHAR(500) NULL,
    test_cmd    VARCHAR(500) NULL,
    build_cmd   VARCHAR(500) NULL,
    criteria_md LONGTEXT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_project_dod_gates_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
