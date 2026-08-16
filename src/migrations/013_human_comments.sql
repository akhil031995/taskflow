-- 013 human comments: two-way commenting on tickets. ai_comments is an
-- append-only text log written by the agent; this is the human side of the
-- conversation (reviewer name + free text), stored as proper rows so the
-- detail modal can show author + timestamp per entry and the MCP claim path
-- can hand unread guidance to the agent on its next run.
CREATE TABLE IF NOT EXISTS task_comments (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id      INT UNSIGNED NOT NULL,
    author       VARCHAR(100) NOT NULL DEFAULT 'Reviewer',
    comment_text TEXT NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_task_id_seq (task_id, id),
    CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id)
        REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
