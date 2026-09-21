-- Run once before uploading the Sunday class update.
ALTER TABLE payments ADD COLUMN learning_plan ENUM('online', 'sunday_physical') NOT NULL DEFAULT 'online';
ALTER TABLE student_enrollments ADD COLUMN learning_plan ENUM('online', 'sunday_physical') NOT NULL DEFAULT 'online';
