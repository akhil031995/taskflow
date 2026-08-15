-- 006 live session logs: real-time AI execution events + diffs.
-- (Spec called this 004; renumbered to 006 because 004 already exists.)
-- task_id is INT UNSIGNED to match tasks.id (an INT NOT NULL here would fail
-- the foreign key with errno 150 - incompatible column types).
CREATE TABLE IF NOT EXISTS ai_session_logs (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id    INT UNSIGNED NOT NULL,
    log_type   ENUM('thought', 'terminal', 'code_diff', 'status') NOT NULL,
    content    LONGTEXT NOT NULL,
    file_path  VARCHAR(255) NULL,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    INDEX idx_task_type (task_id, log_type),
    INDEX idx_task_id_seq (task_id, id),
    CONSTRAINT fk_session_logs_task FOREIGN KEY (task_id)
        REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
