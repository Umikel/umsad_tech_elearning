<?php
/** Durable transactional outbox for learner-facing email notifications. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Mailer.php';

class NotificationServiceException extends RuntimeException
{
}

final class NotificationService
{
    private const TYPE_SIGNUP_WELCOME = 'signup_welcome';
    private const TYPE_COURSE_ENROLLMENT = 'course_enrollment';
    private const TYPE_PAYMENT_COMPLETED = 'payment_completed';
    private const STALE_MINUTES = 10;

    private Database $db;
    private ?Mailer $mailer;

    public function __construct(Database $db, ?Mailer $mailer = null)
    {
        $this->db = $db;
        $this->mailer = $mailer;
    }

    public function queueSignupWelcome(int $userId, string $email, string $name): string
    {
        return $this->queue(
            'signup.welcome:' . $userId,
            self::TYPE_SIGNUP_WELCOME,
            $userId,
            $email,
            $name,
            []
        );
    }

    public function queueCourseEnrollment(
        int $enrollmentId,
        int $userId,
        string $email,
        string $name,
        int $courseId,
        string $courseTitle
    ): string {
        if ($enrollmentId <= 0 || $courseId <= 0) {
            throw new NotificationServiceException('The enrollment notification is invalid.');
        }

        return $this->queue(
            'enrollment.confirmed:' . $enrollmentId,
            self::TYPE_COURSE_ENROLLMENT,
            $userId,
            $email,
            $name,
            [
                'course_id' => $courseId,
                'course_title' => $this->normalizeCourseTitle($courseTitle),
            ]
        );
    }

    public function queuePaymentCompleted(
        int $paymentId,
        int $userId,
        string $email,
        string $name,
        int $courseId,
        string $courseTitle,
        $amount,
        string $currency,
        string $reference,
        bool $requiresReview
    ): string {
        $amountMinor = moneyToMinorUnits($amount);
        $currency = strtoupper(trim($currency));
        $reference = trim($reference);
        if ($paymentId <= 0
            || $courseId <= 0
            || $amountMinor === null
            || $amountMinor <= 0
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || $reference === ''
            || strlen($reference) > 100
            || preg_match('/^[A-Za-z0-9._=-]+$/', $reference) !== 1) {
            throw new NotificationServiceException('The payment notification is invalid.');
        }

        return $this->queue(
            'payment.completed:' . $paymentId,
            self::TYPE_PAYMENT_COMPLETED,
            $userId,
            $email,
            $name,
            [
                'course_id' => $courseId,
                'course_title' => $this->normalizeCourseTitle($courseTitle),
                'amount' => minorUnitsToMoney($amountMinor),
                'currency' => $currency,
                'reference' => $reference,
                'requires_review' => $requiresReview,
            ]
        );
    }

    /**
     * Best-effort inline delivery after the owning business transaction commits.
     * Every error is retained for worker retry and deliberately kept away from
     * the successful HTTP response.
     *
     * @param array<int,string> $eventKeys
     */
    public function dispatchBestEffort(array $eventKeys): void
    {
        foreach (array_values(array_unique($eventKeys)) as $eventKey) {
            if (!is_string($eventKey) || !$this->eventKeyIsValid($eventKey)) {
                continue;
            }

            try {
                $this->dispatchByEventKey($eventKey);
            } catch (Throwable $exception) {
                error_log('A queued notification could not be dispatched immediately.');
            }
        }
    }

    public function dispatchByEventKey(string $eventKey): bool
    {
        if (!$this->eventKeyIsValid($eventKey)) {
            return false;
        }

        $workerId = 'web-' . bin2hex(random_bytes(12));
        $event = $this->claimEvent($workerId, $eventKey);
        return $event !== null && $this->deliverClaimedEvent($event, $workerId);
    }

    /**
     * Claim and process one ready event for a CLI worker.
     *
     * @return bool|null true when sent, false when retained for retry, null when idle
     */
    public function dispatchNext(string $workerId): ?bool
    {
        $workerId = $this->normalizeWorkerId($workerId);
        $event = $this->claimEvent($workerId);
        if ($event === null) {
            return null;
        }

        return $this->deliverClaimedEvent($event, $workerId);
    }

    /** @param array<string,mixed> $payload */
    private function queue(
        string $eventKey,
        string $eventType,
        int $userId,
        string $email,
        string $name,
        array $payload
    ): string {
        [$email, $name] = $this->normalizeRecipient($email, $name);
        if ($userId <= 0
            || !$this->eventKeyIsValid($eventKey)
            || !in_array($eventType, [
                self::TYPE_SIGNUP_WELCOME,
                self::TYPE_COURSE_ENROLLMENT,
                self::TYPE_PAYMENT_COMPLETED,
            ], true)) {
            throw new NotificationServiceException('The queued notification is invalid.');
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new NotificationServiceException('The notification payload is invalid.', 0, $exception);
        }
        if (strlen($payloadJson) > 16384) {
            throw new NotificationServiceException('The notification payload is too large.');
        }

        $this->db->query(' 
            INSERT INTO notification_outbox (
                event_key, event_type, user_id, recipient_email, recipient_name,
                payload, status, attempts, available_at
            ) VALUES (
                :event_key, :event_type, :user_id, :recipient_email, :recipient_name,
                :payload, :status, 0, NOW()
            )
            ON DUPLICATE KEY UPDATE event_key = VALUES(event_key)
        ');
        $this->db->bind(':event_key', $eventKey);
        $this->db->bind(':event_type', $eventType);
        $this->db->bind(':user_id', $userId);
        $this->db->bind(':recipient_email', $email);
        $this->db->bind(':recipient_name', $name);
        $this->db->bind(':payload', $payloadJson);
        $this->db->bind(':status', 'pending');
        $this->db->execute();

        return $eventKey;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function claimEvent(string $workerId, ?string $eventKey = null): ?array
    {
        $workerId = $this->normalizeWorkerId($workerId);
        if ($eventKey !== null && !$this->eventKeyIsValid($eventKey)) {
            return null;
        }
        if ($this->db->inTransaction()) {
            throw new NotificationServiceException('Notifications can only be dispatched after commit.');
        }

        $this->db->beginTransaction();
        try {
            $eventFilter = $eventKey === null ? '' : ' AND event_key = :event_key';
            $this->db->query(' 
                SELECT id, event_key, event_type, user_id, recipient_email,
                       recipient_name, payload, attempts
                FROM notification_outbox
                WHERE (
                    (status = :pending AND available_at <= NOW())
                    OR (
                        status = :processing
                        AND (locked_at IS NULL OR locked_at <= DATE_SUB(NOW(), INTERVAL '
                            . self::STALE_MINUTES . ' MINUTE))
                    )
                )'
                . $eventFilter . '
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ');
            $this->db->bind(':pending', 'pending');
            $this->db->bind(':processing', 'processing');
            if ($eventKey !== null) {
                $this->db->bind(':event_key', $eventKey);
            }
            $event = $this->db->single();
            if (!$event) {
                $this->db->commit();
                return null;
            }

            $this->db->query(' 
                UPDATE notification_outbox
                SET status = :processing,
                    attempts = CASE WHEN attempts < 65535 THEN attempts + 1 ELSE attempts END,
                    locked_at = NOW(),
                    locked_by = :locked_by,
                    last_error = NULL
                WHERE id = :id
            ');
            $this->db->bind(':processing', 'processing');
            $this->db->bind(':locked_by', $workerId);
            $this->db->bind(':id', (int) $event['id']);
            $this->db->execute();
            $this->db->commit();

            $event['attempts'] = min(65535, max(0, (int) $event['attempts']) + 1);
            return $event;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $event */
    private function deliverClaimedEvent(array $event, string $workerId): bool
    {
        $eventId = (int) ($event['id'] ?? 0);
        try {
            $payload = json_decode((string) ($event['payload'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
                throw new NotificationServiceException('The queued notification payload is invalid.');
            }

            $mailer = $this->mailer ?? new Mailer();
            $eventType = (string) ($event['event_type'] ?? '');
            $email = (string) ($event['recipient_email'] ?? '');
            $name = (string) ($event['recipient_name'] ?? '');
            $eventKey = (string) ($event['event_key'] ?? '');

            if ($eventType === self::TYPE_SIGNUP_WELCOME) {
                $mailer->sendSignupWelcome($email, $name, $eventKey);
            } elseif ($eventType === self::TYPE_COURSE_ENROLLMENT) {
                $mailer->sendCourseEnrollmentConfirmation(
                    $email,
                    $name,
                    (string) ($payload['course_title'] ?? ''),
                    (int) ($payload['course_id'] ?? 0),
                    $eventKey
                );
            } elseif ($eventType === self::TYPE_PAYMENT_COMPLETED) {
                $mailer->sendPaymentConfirmation(
                    $email,
                    $name,
                    (string) ($payload['course_title'] ?? ''),
                    (int) ($payload['course_id'] ?? 0),
                    (string) ($payload['amount'] ?? ''),
                    (string) ($payload['currency'] ?? ''),
                    (string) ($payload['reference'] ?? ''),
                    (bool) ($payload['requires_review'] ?? false),
                    $eventKey
                );
            } else {
                throw new NotificationServiceException('The queued notification type is invalid.');
            }

            $this->markSent($eventId, $workerId);
            return true;
        } catch (JsonException | NotificationServiceException $exception) {
            $this->markPermanentlyFailed($eventId, $workerId);
            error_log('A malformed queued notification was marked failed.');
            return false;
        } catch (Throwable $exception) {
            $this->releaseForRetry($eventId, $workerId, (int) ($event['attempts'] ?? 1));
            error_log('A queued notification delivery attempt failed and will be retried.');
            return false;
        }
    }

    private function markSent(int $eventId, string $workerId): void
    {
        $this->db->query(' 
            UPDATE notification_outbox
            SET status = :sent,
                sent_at = COALESCE(sent_at, NOW()),
                locked_at = NULL,
                locked_by = NULL,
                last_error = NULL
            WHERE id = :id AND status = :processing AND locked_by = :locked_by
        ');
        $this->db->bind(':sent', 'sent');
        $this->db->bind(':id', $eventId);
        $this->db->bind(':processing', 'processing');
        $this->db->bind(':locked_by', $workerId);
        $this->db->execute();
        if ($this->db->rowCount() !== 1) {
            throw new NotificationServiceException('The sent notification could not be finalized.');
        }
    }

    private function releaseForRetry(int $eventId, string $workerId, int $attempt): void
    {
        if ($eventId <= 0) {
            return;
        }
        $exponent = max(0, min(7, $attempt - 1));
        $delaySeconds = min(3600, 30 * (2 ** $exponent));
        $availableAt = (new DateTimeImmutable('now'))
            ->modify('+' . $delaySeconds . ' seconds')
            ->format('Y-m-d H:i:s');

        try {
            $this->db->query(' 
                UPDATE notification_outbox
                SET status = :pending,
                    available_at = :available_at,
                    locked_at = NULL,
                    locked_by = NULL,
                    last_error = :last_error
                WHERE id = :id AND status = :processing AND locked_by = :locked_by
            ');
            $this->db->bind(':pending', 'pending');
            $this->db->bind(':available_at', $availableAt);
            $this->db->bind(':last_error', 'Delivery attempt failed.');
            $this->db->bind(':id', $eventId);
            $this->db->bind(':processing', 'processing');
            $this->db->bind(':locked_by', $workerId);
            $this->db->execute();
        } catch (Throwable $exception) {
            error_log('A failed notification could not be released for retry; its stale lock will be recovered.');
        }
    }

    private function markPermanentlyFailed(int $eventId, string $workerId): void
    {
        if ($eventId <= 0) {
            return;
        }

        try {
            $this->db->query(' 
                UPDATE notification_outbox
                SET status = :failed,
                    locked_at = NULL,
                    locked_by = NULL,
                    last_error = :last_error
                WHERE id = :id AND status = :processing AND locked_by = :locked_by
            ');
            $this->db->bind(':failed', 'failed');
            $this->db->bind(':last_error', 'Notification data is invalid.');
            $this->db->bind(':id', $eventId);
            $this->db->bind(':processing', 'processing');
            $this->db->bind(':locked_by', $workerId);
            $this->db->execute();
        } catch (Throwable $exception) {
            error_log('An invalid notification could not be marked failed; its stale lock will be recovered.');
        }
    }

    /** @return array{0:string,1:string} */
    private function normalizeRecipient(string $email, string $name): array
    {
        $email = strtolower(trim($email));
        $name = trim((string) preg_replace('/[\r\n\x00]+/', ' ', $name));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 255
            || preg_match('/[\r\n\x00]/', $email)
            || $name === ''
            || mb_strlen($name) > 255) {
            throw new NotificationServiceException('The notification recipient is invalid.');
        }

        return [$email, $name];
    }

    private function normalizeCourseTitle(string $courseTitle): string
    {
        $courseTitle = trim((string) preg_replace('/[\r\n\x00]+/', ' ', $courseTitle));
        if ($courseTitle === '' || mb_strlen($courseTitle) > 255) {
            throw new NotificationServiceException('The notification course is invalid.');
        }
        return $courseTitle;
    }

    private function eventKeyIsValid(string $eventKey): bool
    {
        return strlen($eventKey) <= 191
            && preg_match('/^(?:signup\.welcome|enrollment\.confirmed|payment\.completed):[1-9][0-9]*$/', $eventKey) === 1;
    }

    private function normalizeWorkerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if ($workerId === '' || strlen($workerId) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $workerId) !== 1) {
            throw new NotificationServiceException('The notification worker identifier is invalid.');
        }
        return $workerId;
    }
}
