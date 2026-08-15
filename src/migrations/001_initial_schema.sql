-- 001 initial schema: projects, tasks, notes, quick_links
-- Applied once by config/migrate.php. Safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS projects (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    color       VARCHAR(20) NOT NULL DEFAULT '#3b82f6',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status      ENUM('pending','in_progress','on_hold','completed') NOT NULL DEFAULT 'pending',
    priority    ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    position    INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_project (project_id),
    KEY idx_tasks_status (status),
    CONSTRAINT fk_tasks_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL DEFAULT 'Untitled',
    content     LONGTEXT NULL,
    position    INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notes_project (project_id),
    CONSTRAINT fk_notes_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quick_links (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title      VARCHAR(150) NOT NULL,
    url        VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
