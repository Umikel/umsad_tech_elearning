-- Run once before deploying the updated PHP files. Existing enrollments also await approval.
ALTER TABLE student_enrollments
    ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN approved_at DATETIME NULL,
    ADD COLUMN approved_by INT NULL;
