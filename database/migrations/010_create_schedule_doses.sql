CREATE TABLE schedule_doses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vaccine_id INT UNSIGNED NOT NULL,
    dose_number INT UNSIGNED NOT NULL,
    recommended_age_days INT UNSIGNED NULL,
    recommended_age_months INT UNSIGNED NULL,
    grace_days INT UNSIGNED NOT NULL,
    min_interval_days INT UNSIGNED NULL,
    min_interval_months INT UNSIGNED NULL,
    counts_toward_fic TINYINT NOT NULL,
    sort_order INT UNSIGNED NOT NULL,
    CONSTRAINT schedule_vaccine_fk FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE RESTRICT,
    CONSTRAINT schedule_dose_positive CHECK (dose_number > 0),
    CONSTRAINT schedule_age_unit CHECK ((recommended_age_days IS NULL) <> (recommended_age_months IS NULL)),
    CONSTRAINT schedule_interval_unit CHECK ((min_interval_days IS NULL) <> (min_interval_months IS NULL)),
    CONSTRAINT schedule_fic CHECK (counts_toward_fic IN (0, 1)),
    UNIQUE KEY schedule_vaccine_dose (vaccine_id, dose_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
