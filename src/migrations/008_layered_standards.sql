-- 008 layered standards: org-wide baseline (settings key/value) + per-project
-- overrides. Effective standards = baseline merged with the project's
-- override, resolved at ticket-claim time and written into the project's
-- CLAUDE.md (see mcp-server/standards.js).

-- One override document per project (LONGTEXT, like notes.content).
CREATE TABLE IF NOT EXISTS project_standards (
    project_id  INT UNSIGNED NOT NULL,
    override_md LONGTEXT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_project_standards_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Org-wide baseline, reusing the existing settings key/value store.
-- NOTE: migrate.php's statement splitter is semicolon-based (see split_sql())
-- so this value must not contain a literal `;`.
INSERT IGNORE INTO settings (setting_key, value) VALUES
    ('org_standards_baseline',
     '- No secrets or credentials committed to the repo.\n- Keep changes scoped to the ticket''s acceptance criteria, filing tech-debt tickets for unrelated issues instead of expanding scope.\n- Prefer editing existing files over creating new ones.');
