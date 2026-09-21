-- Signup email-verification migration.
-- Safe to run repeatedly on MySQL/MariaDB.
--
-- The persistent phase marker makes the backfill crash-resumable:
--   pending    -> the column may exist, but legacy users still need backfill
--   backfilled -> legacy users are done; the token table may still be pending
--   completed  -> no future user may be included in the legacy backfill
--
-- An installation that already has both feature structures but no marker is an
-- older successfully applied version. It is adopted as completed without
-- changing users. Deployment order remains: backup, migrate, then publish code.

SET @schema_name = DATABASE();
SET @email_verification_migration = '20260820_email_verification';

CREATE TABLE IF NOT EXISTS umsad_schema_migrations (
    migration_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    phase VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @email_verified_column_existed = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'email_verified_at') > 0,
    1,
    0
);

SET @email_verification_tokens_existed = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = @schema_name
       AND TABLE_NAME = 'email_verification_tokens') > 0,
    1,
    0
);

-- Adopt a fully applied older migration as completed. Every other unmarked
-- state is treated as pending so a partial first run can safely recover.
INSERT INTO umsad_schema_migrations (
    migration_key,
    phase,
    started_at,
    completed_at
)
SELECT
    @email_verification_migration,
    IF(
        @email_verified_column_existed = 1
        AND @email_verification_tokens_existed = 1,
        'completed',
        'pending'
    ),
    NOW(),
    IF(
        @email_verified_column_existed = 1
        AND @email_verification_tokens_existed = 1,
        NOW(),
        NULL
    )
WHERE NOT EXISTS (
    SELECT 1
    FROM umsad_schema_migrations
    WHERE migration_key = @email_verification_migration
);

SET @migration_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @schema_name
       AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'email_verified_at') = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME NULL AFTER `email`',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @email_verification_needs_backfill = IF(
    (SELECT phase
     FROM umsad_schema_migrations
     WHERE migration_key = @email_verification_migration) = 'pending',
    1,
    0
);

-- Backfill and advance the durable phase atomically. If the connection fails
-- before COMMIT, both changes roll back and the next run retries the backfill.
START TRANSACTION;
SET @migration_sql = IF(
    @email_verification_needs_backfill = 1,
    'UPDATE `users` SET `email_verified_at` = COALESCE(`created_at`, NOW()) WHERE `email_verified_at` IS NULL',
    'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

UPDATE umsad_schema_migrations
SET phase = 'backfilled', completed_at = NULL
WHERE migration_key = @email_verification_migration
  AND phase = 'pending';
COMMIT;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    window_started_at DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY unique_token_hash (token_hash),
    INDEX idx_expires_at (expires_at),
    CONSTRAINT fk_email_verification_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE umsad_schema_migrations
SET phase = 'completed', completed_at = COALESCE(completed_at, NOW())
WHERE migration_key = @email_verification_migration
  AND phase = 'backfilled';
