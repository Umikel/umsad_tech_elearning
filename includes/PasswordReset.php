<?php
/** Secure, non-enumerating password-reset token lifecycle. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Mailer.php';

final class PasswordReset
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Request a reset without revealing whether the email is registered.
     */
    public function requestForEmail(string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return;
        }

        $this->db->query('SELECT id, email, full_name
                          FROM users
                          WHERE email = :email AND is_active = 1 AND email_verified_at IS NOT NULL
                          LIMIT 1');
        $this->db->bind(':email', $email);
        $user = $this->db->single();
        if (!$user) {
            return;
        }

        $userId = (int) $user['id'];
        $this->db->query('SELECT * FROM password_reset_tokens WHERE user_id = :user_id LIMIT 1');
        $this->db->bind(':user_id', $userId);
        $previous = $this->db->single() ?: null;
        $now = time();
        $requestCount = 1;
        $windowStartedAt = date('Y-m-d H:i:s', $now);

        if ($previous) {
            $lastSent = strtotime((string) $previous['sent_at']) ?: 0;
            if ($lastSent > 0 && $now - $lastSent < PASSWORD_RESET_COOLDOWN) {
                return;
            }
            $windowStart = strtotime((string) $previous['window_started_at']) ?: 0;
            if ($windowStart > 0 && $now - $windowStart < 3600) {
                $requestCount = (int) $previous['request_count'] + 1;
                $windowStartedAt = (string) $previous['window_started_at'];
                if ($requestCount > PASSWORD_RESET_MAX_PER_HOUR) {
                    return;
                }
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', $now + PASSWORD_RESET_TTL);
        $sentAt = date('Y-m-d H:i:s', $now);

        $this->db->query('INSERT INTO password_reset_tokens
                          (user_id, token_hash, expires_at, sent_at, window_started_at, request_count)
                          VALUES (:user_id, :token_hash, :expires_at, :sent_at, :window_started_at, :request_count)
                          ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at),
                                                  sent_at = VALUES(sent_at), window_started_at = VALUES(window_started_at),
                                                  request_count = VALUES(request_count), updated_at = NOW()');
        $this->db->bind(':user_id', $userId);
        $this->db->bind(':token_hash', $tokenHash);
        $this->db->bind(':expires_at', $expiresAt);
        $this->db->bind(':sent_at', $sentAt);
        $this->db->bind(':window_started_at', $windowStartedAt);
        $this->db->bind(':request_count', $requestCount);
        $this->db->execute();

        try {
            $resetUrl = appUrl('/reset-password.php?token=' . rawurlencode($rawToken));
            (new Mailer())->sendPasswordResetEmail((string) $user['email'], (string) $user['full_name'], $resetUrl);
        } catch (Throwable $exception) {
            error_log('Password reset email could not be delivered: ' . $exception->getMessage());
            $this->restorePreviousToken($userId, $previous);
        }
    }

    public function tokenIsValid(string $rawToken): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
            return false;
        }
        $this->db->query('SELECT user_id
                          FROM password_reset_tokens
                          WHERE token_hash = :token_hash AND expires_at > NOW()
                          LIMIT 1');
        $this->db->bind(':token_hash', hash('sha256', $rawToken));
        return (bool) $this->db->single();
    }

    public function consume(string $rawToken, string $newPassword): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $rawToken) !== 1) {
            return false;
        }
        $passwordHash = password_hash($newPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);
        if ($passwordHash === false) {
            throw new RuntimeException('Unable to secure the new password.');
        }

        try {
            $this->db->beginTransaction();
            $this->db->query('SELECT t.user_id
                              FROM password_reset_tokens t
                              JOIN users u ON u.id = t.user_id
                              WHERE t.token_hash = :token_hash AND t.expires_at > NOW() AND u.is_active = 1
                              FOR UPDATE');
            $this->db->bind(':token_hash', hash('sha256', $rawToken));
            $token = $this->db->single();
            if (!$token) {
                $this->db->rollBack();
                return false;
            }
            $userId = (int) $token['user_id'];
            $this->db->query('UPDATE users SET password = :password WHERE id = :user_id AND is_active = 1');
            $this->db->bind(':password', $passwordHash);
            $this->db->bind(':user_id', $userId);
            $this->db->execute();
            $this->db->query('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
            $this->db->bind(':user_id', $userId);
            $this->db->execute();
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function restorePreviousToken(int $userId, ?array $previous): void
    {
        try {
            if (!$previous) {
                $this->db->query('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
                $this->db->bind(':user_id', $userId);
                $this->db->execute();
                return;
            }
            $this->db->query('UPDATE password_reset_tokens
                              SET token_hash = :token_hash, expires_at = :expires_at, sent_at = :sent_at,
                                  window_started_at = :window_started_at, request_count = :request_count
                              WHERE user_id = :user_id');
            $this->db->bind(':token_hash', (string) $previous['token_hash']);
            $this->db->bind(':expires_at', (string) $previous['expires_at']);
            $this->db->bind(':sent_at', (string) $previous['sent_at']);
            $this->db->bind(':window_started_at', (string) $previous['window_started_at']);
            $this->db->bind(':request_count', (int) $previous['request_count']);
            $this->db->bind(':user_id', $userId);
            $this->db->execute();
        } catch (Throwable $restoreException) {
            error_log('A previous password reset token could not be restored: ' . $restoreException->getMessage());
        }
    }
}
