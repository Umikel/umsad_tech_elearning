-- Durable transactional email outbox.
-- Safe to run repeatedly on MySQL or MariaDB.

CREATE TABLE IF NOT EXISTS notification_outbox (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    event_key VARCHAR(191) NOT NULL,
    event_type ENUM('signup_welcome', 'course_enrollment', 'payment_completed') NOT NULL,
    user_id INT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(64) NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_notification_event (event_key),
    INDEX idx_notification_ready (status, available_at, id),
    INDEX idx_notification_stale (status, locked_at),
    INDEX idx_notification_user (user_id),
    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
