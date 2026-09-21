-- Production Paystack persistence migration.
-- Safe to run repeatedly on MySQL/MariaDB; each DDL statement is conditional.

SET @schema_name = DATABASE();

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'access_code') = 0,
    'ALTER TABLE `payments` ADD COLUMN `access_code` VARCHAR(255) NULL AFTER `transaction_id`',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'authorization_url') = 0,
    'ALTER TABLE `payments` ADD COLUMN `authorization_url` VARCHAR(500) NULL AFTER `access_code`',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'provider_status') = 0,
    'ALTER TABLE `payments` ADD COLUMN `provider_status` VARCHAR(50) NULL AFTER `authorization_url`',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'verified_at') = 0,
    'ALTER TABLE `payments` ADD COLUMN `verified_at` DATETIME NULL AFTER `paid_at`',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'failure_reason') = 0,
    'ALTER TABLE `payments` ADD COLUMN `failure_reason` VARCHAR(500) NULL AFTER `verified_at`',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Provider transaction IDs must not fulfill two local payments.
SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND INDEX_NAME = 'unique_transaction_id') = 0,
    'ALTER TABLE `payments` ADD UNIQUE INDEX `unique_transaction_id` (`transaction_id`)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_student_course_status') = 0,
    'ALTER TABLE `payments` ADD INDEX `idx_student_course_status` (`student_id`, `course_id`, `status`)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_provider_status') = 0,
    'ALTER TABLE `payments` ADD INDEX `idx_provider_status` (`provider_status`)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Tighten new/future references when legacy data contains no NULL reference.
SET @migration_sql = IF(
    (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'payment_reference') = 'YES'
    AND (SELECT COUNT(*) FROM payments WHERE payment_reference IS NULL) = 0,
    'ALTER TABLE `payments` MODIFY COLUMN `payment_reference` VARCHAR(255) NOT NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
