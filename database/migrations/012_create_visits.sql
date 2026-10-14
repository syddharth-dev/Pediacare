CREATE TABLE visits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    visit_date DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    findings TEXT NULL,
    actions TEXT NULL,
    referred TINYINT NOT NULL DEFAULT 0,
    referred_to VARCHAR(255) NULL,
    attended_by INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT visits_child_fk FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    CONSTRAINT visits_actor_fk FOREIGN KEY (attended_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT visits_referred CHECK (referred IN (0, 1)),
    INDEX visits_child_date (child_id, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
