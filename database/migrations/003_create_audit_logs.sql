CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    action ENUM('CREATE', 'UPDATE', 'ARCHIVE', 'RESTORE') NOT NULL,
    entity VARCHAR(64) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    changes JSON NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT audit_logs_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    INDEX audit_logs_entity_id (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
