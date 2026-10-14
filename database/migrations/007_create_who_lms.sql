CREATE TABLE who_lms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference ENUM('WHO2006', 'WHO2007') NOT NULL,
    indicator ENUM('WFA', 'HFA', 'WFL', 'WFH', 'BFA') NOT NULL,
    sex ENUM('M', 'F') NOT NULL,
    age_or_size DECIMAL(7,2) UNSIGNED NOT NULL,
    l DECIMAL(10,6) NOT NULL,
    m DECIMAL(10,6) NOT NULL,
    s DECIMAL(10,6) NOT NULL,
    UNIQUE KEY who_lms_lookup (reference, indicator, sex, age_or_size),
    CONSTRAINT who_lms_positive_parameters CHECK (m > 0 AND s > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
