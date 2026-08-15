-- 013 project primer: cached per-project context primer (structure, entry
-- points, detected stack, key files/symbols), regenerated only when the
-- project's file tree changes (fingerprint check) and written into the
-- claimed project's CLAUDE.md at ticket-claim time, same injection point as
-- the layered-standards flow (see mcp-server/project-primer.js).

CREATE TABLE IF NOT EXISTS project_primers (
    project_id  INT UNSIGNED NOT NULL,
    primer_md   LONGTEXT NULL,
    fingerprint VARCHAR(64) NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id),
    CONSTRAINT fk_project_primers_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
