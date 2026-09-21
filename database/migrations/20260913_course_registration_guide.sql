CREATE TABLE IF NOT EXISTS course_registration_acknowledgments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    guide_version CHAR(64) NOT NULL,
    accepted_at DATETIME NOT NULL,
    UNIQUE KEY unique_course_guide_ack (student_id, course_id, guide_version),
    KEY course_guide_lookup (course_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
