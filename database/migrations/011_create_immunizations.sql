CREATE TABLE immunizations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    schedule_dose_id INT UNSIGNED NOT NULL,
    date_given DATE NOT NULL,
    lot_number VARCHAR(100) NULL,
    site VARCHAR(150) NULL,
    given_elsewhere TINYINT NOT NULL DEFAULT 0,
    place_given VARCHAR(255) NOT NULL,
    given_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT immunizations_child_fk FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE RESTRICT,
    CONSTRAINT immunizations_dose_fk FOREIGN KEY (schedule_dose_id) REFERENCES schedule_doses(id) ON DELETE RESTRICT,
    CONSTRAINT immunizations_actor_fk FOREIGN KEY (given_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT immunizations_elsewhere CHECK (given_elsewhere IN (0, 1)),
    UNIQUE KEY immunizations_child_dose (child_id, schedule_dose_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
