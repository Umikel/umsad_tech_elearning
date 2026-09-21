<?php
/** Secure, rate-limited email-verification token lifecycle. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Mailer.php';

class EmailVerificationException extends RuntimeException
{
}

final class EmailVerification
{
    private Database $db;
    private ?Mailer $mailer;

    public function __construct(Database $db, ?Mailer $mailer = null)
    {
        $this->db = $db;
        $this->mailer = $mailer;
    }

    /**
     * Issue and email a new token for an active, unverified user.
     *
     * @return array{sent:bool,eligible:bool,rate_limited:bool,already_verified:bool}
     */
    public function sendForUser(int $userId): array
    {
        $issued = $this->issueToken($userId);
        $publicResult = [
            'sent' => false,
            'eligible' => (bool) $issued['eligible'],
            'rate_limited' => (bool) $issued['rate_limited'],
            'already_verified' => (bool) $issued['already_verified'],
        ];
        if (!$issued['eligible'] || $issued['rate_limited'] || $issued['already_verified']) {
            return $publicResult;
        }

        try {
            $mailer = $this->mailer ?? new Mailer();
            $verificationUrl = appUrl('/verify-email.php?token=' . rawurlencode((string) $issued['raw_token']));
            $mailer->sendVerificationEmail(
                (string) $issued['email'],
                (string) $issued['full_name'],
                $verificationUrl
            );
        } catch (Throwable $exception) {
            // A resend must not invalidate a still-valid link when the SMTP
            // provider rejects the replacement. Rate-limit timestamps remain
            // advanced, while the previous usable token is restored.
            $this->restorePreviousTokenAfterFailure($issued);
            throw $exception;
        }
        $publicResult['sent'] = true;

        return $publicResult;
    }

    /**
     * Request delivery by email without disclosing whether the account exists.
     *
     * @return array{sent:bool,eligible:bool,rate_limited:bool,already_verified:bool}
     */
    public function sendForEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return $this->emptyResult();
        }

        $this->db->query('SELECT id FROM users WHERE email = :email AND is_active = 1 LIMIT 1');
        $this->db->bind(':email', $email);
        $user = $this->db->single();
        if (!$user) {
            // Perform work comparable to a token hash before returning so the
            // unknown-account branch is not an immediate string lookup oracle.
            hash('sha256', random_bytes(32));
            return $this->emptyResult();
        }

        return $this->sendForUser((int) $user['id']);
    }

    /** @return array{valid:bool,email_masked:string} */
    public function inspectToken(string $rawToken): array
    {
        $rawToken = $this->normalizeToken($rawToken);
        if ($rawToken === null) {
            return ['valid' => false, 'email_masked' => ''];
        }

        return $this->inspectTokenDigest(hash('sha256', $rawToken));
    }

    /**
     * Inspect the server-side digest retained after the token URL is cleaned.
     * The public route never accepts this digest from request input.
     *
     * @return array{valid:bool,email_masked:string}
     */
    public function inspectTokenDigest(string $tokenHash): array
    {
        $tokenHash = $this->normalizeToken($tokenHash);
        if ($tokenHash === null) {
            return ['valid' => false, 'email_masked' => ''];
        }

        $this->db->query(' 
            SELECT t.email, t.expires_at, u.email AS current_email,
                   u.email_verified_at, u.is_active
            FROM email_verification_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.token_hash = :token_hash
            LIMIT 1
        ');
        $this->db->bind(':token_hash', $tokenHash);
        $record = $this->db->single();
        if (!$this->recordCanVerify($record)) {
            return ['valid' => false, 'email_masked' => ''];
        }

        return [
            'valid' => true,
            'email_masked' => self::maskEmail((string) $record['email']),
        ];
    }

    /** @return array{success:bool,email:string} */
    public function verifyToken(string $rawToken): array
    {
        $rawToken = $this->normalizeToken($rawToken);
        if ($rawToken === null) {
            return ['success' => false, 'email' => ''];
        }

        return $this->verifyTokenDigest(hash('sha256', $rawToken));
    }

    /**
     * Consume a server-side token digest after the raw query parameter has
     * been removed from browser history. Never call this with request input.
     *
     * @return array{success:bool,email:string}
     */
    public function verifyTokenDigest(string $tokenHash): array
    {
        $tokenHash = $this->normalizeToken($tokenHash);
        if ($tokenHash === null) {
            return ['success' => false, 'email' => ''];
        }

        $this->db->beginTransaction();
        try {
            $this->db->query(' 
                SELECT t.user_id, t.email, t.expires_at, u.email AS current_email,
                       u.email_verified_at, u.is_active
                FROM email_verification_tokens t
                JOIN users u ON u.id = t.user_id
                WHERE t.token_hash = :token_hash
                LIMIT 1
                FOR UPDATE
            ');
            $this->db->bind(':token_hash', $tokenHash);
            $record = $this->db->single();
            if (!$this->recordCanVerify($record)) {
                if ($record) {
                    $this->deleteTokenForUser((int) $record['user_id']);
                }
                $this->db->commit();
                return ['success' => false, 'email' => ''];
            }

            $this->db->query(' 
                UPDATE users
                SET email_verified_at = NOW(), updated_at = NOW()
                WHERE id = :id AND email = :email AND email_verified_at IS NULL AND is_active = 1
            ');
            $this->db->bind(':id', (int) $record['user_id']);
            $this->db->bind(':email', (string) $record['email']);
            $this->db->execute();
            if ($this->db->rowCount() !== 1) {
                throw new EmailVerificationException('The account could not be verified.');
            }

            $this->deleteTokenForUser((int) $record['user_id']);
            $this->db->commit();

            return ['success' => true, 'email' => (string) $record['email']];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof EmailVerificationException) {
                throw $exception;
            }
            throw new EmailVerificationException('The account could not be verified.', 0, $exception);
        }
    }

    public static function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $separator = strrpos($email, '@');
        if ($separator === false) {
            return 'your email address';
        }

        $local = substr($email, 0, $separator);
        $domain = substr($email, $separator + 1);
        $length = mb_strlen($local);
        if ($length <= 1) {
            $maskedLocal = '*';
        } elseif ($length === 2) {
            $maskedLocal = mb_substr($local, 0, 1) . '*';
        } else {
            $maskedLocal = mb_substr($local, 0, 1)
                . str_repeat('*', min(8, $length - 2))
                . mb_substr($local, -1);
        }

        return $maskedLocal . '@' . $domain;
    }

    /** @return array<string,mixed> */
    private function issueToken(int $userId): array
    {
        $empty = $this->emptyResult() + [
            'raw_token' => '',
            'new_token_hash' => '',
            'previous_token' => null,
            'email' => '',
            'full_name' => '',
        ];
        if ($userId <= 0) {
            return $empty;
        }

        $now = new DateTimeImmutable('now');
        $this->db->beginTransaction();
        try {
            $this->db->query(' 
                SELECT id, email, full_name, email_verified_at
                FROM users
                WHERE id = :id AND is_active = 1
                LIMIT 1
                FOR UPDATE
            ');
            $this->db->bind(':id', $userId);
            $user = $this->db->single();
            if (!$user) {
                $this->db->commit();
                return $empty;
            }
            if (!empty($user['email_verified_at'])) {
                $this->db->commit();
                $empty['eligible'] = true;
                $empty['already_verified'] = true;
                return $empty;
            }

            $this->db->query(' 
                SELECT user_id, email, token_hash, expires_at, sent_at,
                       window_started_at, request_count
                FROM email_verification_tokens
                WHERE user_id = :user_id
                FOR UPDATE
            ');
            $this->db->bind(':user_id', $userId);
            $existing = $this->db->single();

            $windowStarted = $now;
            $requestCount = 0;
            $previousToken = null;
            if ($existing) {
                $lastSent = $this->parseDatabaseDate((string) $existing['sent_at']);
                if ($lastSent !== null
                    && $lastSent->getTimestamp() > $now->getTimestamp() - EMAIL_VERIFICATION_RESEND_COOLDOWN) {
                    $this->db->commit();
                    $empty['eligible'] = true;
                    $empty['rate_limited'] = true;
                    return $empty;
                }

                $storedWindow = $this->parseDatabaseDate((string) $existing['window_started_at']);
                if ($storedWindow !== null && $storedWindow->getTimestamp() > $now->getTimestamp() - 3600) {
                    $windowStarted = $storedWindow;
                    $requestCount = max(0, (int) $existing['request_count']);
                }
                if ($requestCount >= EMAIL_VERIFICATION_MAX_PER_HOUR) {
                    $this->db->commit();
                    $empty['eligible'] = true;
                    $empty['rate_limited'] = true;
                    return $empty;
                }

                $previousExpiry = $this->parseDatabaseDate((string) $existing['expires_at']);
                if ($previousExpiry !== null
                    && $previousExpiry->getTimestamp() > $now->getTimestamp()
                    && preg_match('/^[a-f0-9]{64}$/', (string) $existing['token_hash']) === 1
                    && hash_equals(strtolower((string) $existing['email']), strtolower((string) $user['email']))) {
                    $previousToken = [
                        'email' => strtolower((string) $existing['email']),
                        'token_hash' => (string) $existing['token_hash'],
                        'expires_at' => $previousExpiry->format('Y-m-d H:i:s'),
                    ];
                }
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = $now->modify('+' . EMAIL_VERIFICATION_TTL . ' seconds');
            $databaseFormat = 'Y-m-d H:i:s';

            $statement = $existing
                ? 'UPDATE email_verification_tokens
                   SET email = :email,
                       token_hash = :token_hash,
                       expires_at = :expires_at,
                       sent_at = :sent_at,
                       window_started_at = :window_started_at,
                       request_count = :request_count
                   WHERE user_id = :user_id'
                : 'INSERT INTO email_verification_tokens (
                       user_id, email, token_hash, expires_at, sent_at,
                       window_started_at, request_count
                   ) VALUES (
                       :user_id, :email, :token_hash, :expires_at, :sent_at,
                       :window_started_at, :request_count
                   )';
            $this->db->query($statement);
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':email', strtolower((string) $user['email']));
            $this->db->bind(':token_hash', $tokenHash);
            $this->db->bind(':expires_at', $expiresAt->format($databaseFormat));
            $this->db->bind(':sent_at', $now->format($databaseFormat));
            $this->db->bind(':window_started_at', $windowStarted->format($databaseFormat));
            $this->db->bind(':request_count', $requestCount + 1);
            $this->db->execute();
            $this->db->commit();

            return [
                'sent' => false,
                'eligible' => true,
                'rate_limited' => false,
                'already_verified' => false,
                'user_id' => $userId,
                'raw_token' => $rawToken,
                'new_token_hash' => $tokenHash,
                'previous_token' => $previousToken,
                'email' => strtolower((string) $user['email']),
                'full_name' => (string) $user['full_name'],
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new EmailVerificationException('A verification link could not be prepared.', 0, $exception);
        }
    }

    /** @param array<string,mixed>|false $record */
    private function recordCanVerify($record): bool
    {
        if (!is_array($record)
            || empty($record['is_active'])
            || !empty($record['email_verified_at'])
            || !isset($record['email'], $record['current_email'], $record['expires_at'])) {
            return false;
        }

        $expiresAt = $this->parseDatabaseDate((string) $record['expires_at']);
        return $expiresAt !== null
            && $expiresAt->getTimestamp() > time()
            && hash_equals(strtolower((string) $record['email']), strtolower((string) $record['current_email']));
    }

    private function deleteTokenForUser(int $userId): void
    {
        $this->db->query('DELETE FROM email_verification_tokens WHERE user_id = :user_id');
        $this->db->bind(':user_id', $userId);
        $this->db->execute();
    }

    /** @param array<string,mixed> $issued */
    private function restorePreviousTokenAfterFailure(array $issued): void
    {
        $previous = $issued['previous_token'] ?? null;
        $userId = (int) ($issued['user_id'] ?? 0);
        $newTokenHash = (string) ($issued['new_token_hash'] ?? '');
        if ($userId <= 0
            || !is_array($previous)
            || preg_match('/^[a-f0-9]{64}$/', $newTokenHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($previous['token_hash'] ?? '')) !== 1
            || empty($previous['email'])
            || $this->parseDatabaseDate((string) ($previous['expires_at'] ?? '')) === null) {
            return;
        }

        try {
            $this->db->query(' 
                UPDATE email_verification_tokens
                SET email = :email, token_hash = :token_hash, expires_at = :expires_at
                WHERE user_id = :user_id AND token_hash = :new_token_hash
            ');
            $this->db->bind(':email', (string) $previous['email']);
            $this->db->bind(':token_hash', (string) $previous['token_hash']);
            $this->db->bind(':expires_at', (string) $previous['expires_at']);
            $this->db->bind(':user_id', $userId);
            $this->db->bind(':new_token_hash', $newTokenHash);
            $this->db->execute();
        } catch (Throwable $exception) {
            error_log('A previous email-verification token could not be restored after a delivery failure.');
        }
    }

    private function normalizeToken(string $rawToken): ?string
    {
        $rawToken = strtolower(trim($rawToken));
        return preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1 ? $rawToken : null;
    }

    private function parseDatabaseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }

    /** @return array{sent:bool,eligible:bool,rate_limited:bool,already_verified:bool} */
    private function emptyResult(): array
    {
        return [
            'sent' => false,
            'eligible' => false,
            'rate_limited' => false,
            'already_verified' => false,
        ];
    }
}
