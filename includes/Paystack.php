<?php
/**
 * Paystack gateway service.
 *
 * Keeps provider I/O and payment fulfillment in one reusable, server-side
 * implementation so browser verification and signed webhooks share the same
 * validation and idempotency rules.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/NotificationService.php';

class PaystackException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = max(400, min(599, $httpStatus));
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}

final class Paystack
{
    private const API_BASE_URL = 'https://api.paystack.co';
    private const MAX_REFERENCE_LENGTH = 100;

    private Database $db;
    private string $mode;
    private string $secretKey;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->mode = defined('PAYSTACK_MODE') ? strtolower((string) PAYSTACK_MODE) : '';
        $this->secretKey = defined('PAYSTACK_SECRET_KEY') ? trim((string) PAYSTACK_SECRET_KEY) : '';

        if (!in_array($this->mode, ['test', 'live'], true)) {
            throw new PaystackException('Paystack mode is not configured.', 503);
        }

        if (!preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $this->secretKey, $matches)
            || $matches[1] !== $this->mode) {
            throw new PaystackException('Paystack credentials are not configured for the selected mode.', 503);
        }

        if (!function_exists('curl_init')) {
            throw new PaystackException('The Paystack HTTP client is unavailable.', 503);
        }
    }

    /**
     * Initialize a locally-created payment with Paystack from the server.
     *
     * @return array{payment_id:int,reference:string,access_code:string,authorization_url:string,reused:bool}
     */
    public function initializeTransaction(
        int $paymentId,
        ?string $callbackUrl = null,
        array $metadata = []
    ): array {
        if ($paymentId <= 0) {
            throw new PaystackException('A valid payment is required.', 422);
        }

        $this->db->query(' 
            SELECT id, student_id
            FROM payments
            WHERE id = :id AND payment_method = :method
            LIMIT 1
        ');
        $this->db->bind(':id', $paymentId);
        $this->db->bind(':method', 'paystack');
        $snapshot = $this->db->single();
        if (!$snapshot) {
            throw new PaystackException('Payment not found.', 404);
        }

        $studentId = (int) $snapshot['student_id'];
        $this->db->beginTransaction();

        try {
            $this->db->query(' 
                SELECT id, email
                FROM users
                WHERE id = :id AND user_type = :type AND is_active = 1
                FOR UPDATE
            ');
            $this->db->bind(':id', $studentId);
            $this->db->bind(':type', 'student');
            $student = $this->db->single();
            if (!$student) {
                throw new PaystackException('The student account is not active.', 409);
            }

            $this->db->query(' 
                SELECT id, student_id, course_id, amount, currency, payment_reference,
                       payment_method, status, access_code, authorization_url,
                       provider_status, updated_at
                FROM payments
                WHERE id = :id AND student_id = :student_id
                FOR UPDATE
            ');
            $this->db->bind(':id', $paymentId);
            $this->db->bind(':student_id', $studentId);
            $payment = $this->db->single();
            if (!$payment || $payment['payment_method'] !== 'paystack') {
                throw new PaystackException('Payment not found.', 404);
            }

            if ($payment['status'] === 'completed') {
                throw new PaystackException('This payment has already completed.', 409);
            }
            if ($payment['status'] === 'refunded') {
                throw new PaystackException('This payment has been refunded.', 409);
            }

            $reference = trim((string) $payment['payment_reference']);
            $this->assertReference($reference, false);

            $currency = strtoupper((string) $payment['currency']);
            if ($currency !== PAYSTACK_CURRENCY) {
                throw new PaystackException('The payment currency is invalid.', 422);
            }

            $amountMinor = moneyToMinorUnits($payment['amount']);
            if ($amountMinor === null || $amountMinor <= 0) {
                throw new PaystackException('The payment amount is invalid.', 422);
            }

            $email = strtolower(trim((string) $student['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new PaystackException('The student email address is invalid.', 422);
            }

            $accessCode = trim((string) ($payment['access_code'] ?? ''));
            $authorizationUrl = trim((string) ($payment['authorization_url'] ?? ''));
            if ($accessCode !== '' && $this->isSecureUrl($authorizationUrl)) {
                $this->db->commit();
                return [
                    'payment_id' => $paymentId,
                    'reference' => $reference,
                    'access_code' => $accessCode,
                    'authorization_url' => $authorizationUrl,
                    'reused' => true,
                ];
            }

            if (($payment['provider_status'] ?? '') === 'initializing') {
                $lastUpdate = strtotime((string) ($payment['updated_at'] ?? '')) ?: 0;
                if ($lastUpdate > time() - 120) {
                    throw new PaystackException('Payment initialization is already in progress.', 409);
                }
            }

            $this->db->query(' 
                UPDATE payments
                SET provider_status = :provider_status, failure_reason = NULL
                WHERE id = :id AND student_id = :student_id
            ');
            $this->db->bind(':provider_status', 'initializing');
            $this->db->bind(':id', $paymentId);
            $this->db->bind(':student_id', $studentId);
            $this->db->execute();
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof PaystackException) {
                throw $e;
            }
            throw new PaystackException('Unable to prepare the payment for initialization.', 500, $e);
        }

        try {
            $providerMetadata = array_merge($metadata, [
                'payment_id' => $paymentId,
                'student_id' => $studentId,
                'course_id' => (int) $payment['course_id'],
            ]);
            $payload = [
                'email' => $email,
                'amount' => (string) $amountMinor,
                'currency' => $currency,
                'reference' => $reference,
                'metadata' => json_encode($providerMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ];

            if ($callbackUrl !== null && trim($callbackUrl) !== '') {
                $payload['callback_url'] = $this->validateCallbackUrl($callbackUrl);
            }

            $response = $this->request('POST', '/transaction/initialize', $payload);
            $providerData = $response['data'];
            $providerReference = isset($providerData['reference']) && is_string($providerData['reference'])
                ? $providerData['reference']
                : '';
            $accessCode = isset($providerData['access_code']) && is_string($providerData['access_code'])
                ? trim($providerData['access_code'])
                : '';
            $authorizationUrl = isset($providerData['authorization_url']) && is_string($providerData['authorization_url'])
                ? trim($providerData['authorization_url'])
                : '';

            if ($providerReference === ''
                || !hash_equals($reference, $providerReference)
                || $accessCode === ''
                || strlen($accessCode) > 255
                || !$this->isSecureUrl($authorizationUrl)
                || strlen($authorizationUrl) > 500) {
                throw new PaystackException('Paystack returned invalid initialization data.', 502);
            }

            $this->db->beginTransaction();
            $this->db->query('SELECT id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
            $this->db->bind(':id', $studentId);
            if (!$this->db->single()) {
                throw new PaystackException('The student account is not active.', 409);
            }

            $this->db->query(' 
                SELECT id, student_id, payment_reference, access_code
                FROM payments
                WHERE id = :id AND student_id = :student_id
                FOR UPDATE
            ');
            $this->db->bind(':id', $paymentId);
            $this->db->bind(':student_id', $studentId);
            $lockedPayment = $this->db->single();
            if (!$lockedPayment
                || !hash_equals((string) $lockedPayment['payment_reference'], $providerReference)) {
                throw new PaystackException('The payment changed during initialization.', 409);
            }

            $reused = trim((string) ($lockedPayment['access_code'] ?? '')) !== '';
            if (!$reused) {
                $this->db->query(' 
                    UPDATE payments
                    SET access_code = :access_code,
                        authorization_url = :authorization_url,
                        provider_status = :provider_status,
                        failure_reason = NULL
                    WHERE id = :id AND student_id = :student_id
                ');
                $this->db->bind(':access_code', $accessCode);
                $this->db->bind(':authorization_url', $authorizationUrl);
                $this->db->bind(':provider_status', 'initialized');
                $this->db->bind(':id', $paymentId);
                $this->db->bind(':student_id', $studentId);
                $this->db->execute();
            } else {
                $accessCode = (string) $lockedPayment['access_code'];
            }
            $this->db->commit();

            return [
                'payment_id' => $paymentId,
                'reference' => $reference,
                'access_code' => $accessCode,
                'authorization_url' => $authorizationUrl,
                'reused' => $reused,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->recordInitializationFailure($paymentId, $e->getMessage());
            if ($e instanceof PaystackException) {
                throw $e;
            }
            throw new PaystackException('Unable to initialize the Paystack transaction.', 502, $e);
        }
    }

    /**
     * Retrieve a transaction from Paystack. The returned data still needs to be
     * passed to fulfillSuccessfulPayment() before delivering course access.
     */
    public function verifyReference(string $reference): array
    {
        $reference = trim($reference);
        $this->assertReference($reference, true);

        $response = $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
        $providerData = $response['data'];
        $providerReference = isset($providerData['reference']) && is_string($providerData['reference'])
            ? $providerData['reference']
            : '';
        if ($providerReference === '' || !hash_equals($reference, $providerReference)) {
            throw new PaystackException('Paystack returned a mismatched payment reference.', 502);
        }

        return $providerData;
    }

    /**
     * Validate a successful Paystack transaction and atomically fulfill it.
     *
     * @return array{payment_id:int,student_id:int,course_id:int,enrollment_id:int,already_completed:bool,enrollment_created:bool,duplicate_payment:bool,requires_review:bool,notification_event_keys:array<int,string>}
     */
    public function fulfillSuccessfulPayment(array $providerData, ?int $expectedStudentId = null): array
    {
        $providerStatus = strtolower(trim((string) ($providerData['status'] ?? '')));
        if ($providerStatus !== 'success') {
            throw new PaystackException('The Paystack transaction is not successful.', 409);
        }

        $reference = isset($providerData['reference']) && is_string($providerData['reference'])
            ? trim($providerData['reference'])
            : '';
        $this->assertReference($reference, true);

        $providerDomain = strtolower(trim((string) ($providerData['domain'] ?? '')));
        if ($providerDomain !== $this->mode) {
            throw new PaystackException('The Paystack transaction mode does not match this application.', 422);
        }

        $providerAmount = filter_var($providerData['amount'] ?? null, FILTER_VALIDATE_INT);
        if ($providerAmount === false || $providerAmount <= 0) {
            throw new PaystackException('The Paystack transaction amount is invalid.', 422);
        }

        $providerCurrency = strtoupper(trim((string) ($providerData['currency'] ?? '')));
        if ($providerCurrency !== PAYSTACK_CURRENCY) {
            throw new PaystackException('The Paystack transaction currency is invalid.', 422);
        }

        $transactionId = trim((string) ($providerData['id'] ?? ''));
        if ($transactionId === '' || strlen($transactionId) > 255 || !ctype_digit($transactionId)) {
            throw new PaystackException('The Paystack transaction identifier is invalid.', 422);
        }

        $this->db->query(' 
            SELECT id, student_id
            FROM payments
            WHERE payment_reference = :reference AND payment_method = :method
            LIMIT 1
        ');
        $this->db->bind(':reference', $reference);
        $this->db->bind(':method', 'paystack');
        $snapshot = $this->db->single();
        if (!$snapshot) {
            throw new PaystackException('No local payment matches this Paystack transaction.', 404);
        }

        $studentId = (int) $snapshot['student_id'];
        if ($expectedStudentId !== null && ($expectedStudentId <= 0 || $studentId !== $expectedStudentId)) {
            throw new PaystackException('This payment does not belong to the current student.', 403);
        }

        $this->db->beginTransaction();

        try {
            $this->db->query(' 
                SELECT id, email, full_name
                FROM users
                WHERE id = :id AND user_type = :type AND is_active = 1
                FOR UPDATE
            ');
            $this->db->bind(':id', $studentId);
            $this->db->bind(':type', 'student');
            $student = $this->db->single();
            if (!$student) {
                throw new PaystackException('The student account is not active.', 409);
            }

            $this->db->query(' 
                SELECT p.id, p.student_id, p.course_id, p.amount, p.currency,
                       p.payment_reference, p.payment_method, p.status,
                       p.transaction_id, p.learning_plan, c.title AS course_title
                FROM payments p
                JOIN courses c ON c.id = p.course_id
                WHERE p.id = :id AND p.student_id = :student_id
                FOR UPDATE
            ');
            $this->db->bind(':id', (int) $snapshot['id']);
            $this->db->bind(':student_id', $studentId);
            $payment = $this->db->single();
            if (!$payment || $payment['payment_method'] !== 'paystack') {
                throw new PaystackException('Payment not found.', 404);
            }
            if ($payment['status'] === 'refunded') {
                throw new PaystackException('This payment has been refunded.', 409);
            }
            if (!hash_equals((string) $payment['payment_reference'], $reference)) {
                throw new PaystackException('The local payment reference changed during verification.', 409);
            }

            $localAmount = moneyToMinorUnits($payment['amount']);
            $localCurrency = strtoupper((string) $payment['currency']);
            if ($localAmount === null
                || $localAmount !== $providerAmount
                || $localCurrency !== $providerCurrency
                || $localCurrency !== PAYSTACK_CURRENCY) {
                throw new PaystackException('The Paystack transaction does not match the local payment.', 422);
            }

            $existingTransactionId = trim((string) ($payment['transaction_id'] ?? ''));
            if ($payment['status'] === 'completed'
                && $existingTransactionId !== ''
                && !hash_equals($existingTransactionId, $transactionId)) {
                throw new PaystackException('The payment was completed by a different provider transaction.', 409);
            }

            $this->db->query(' 
                SELECT id
                FROM payments
                WHERE student_id = :student_id
                  AND course_id = :course_id
                  AND status = :status
                  AND id <> :payment_id
                LIMIT 1
                FOR UPDATE
            ');
            $this->db->bind(':student_id', $studentId);
            $this->db->bind(':course_id', (int) $payment['course_id']);
            $this->db->bind(':status', 'completed');
            $this->db->bind(':payment_id', (int) $payment['id']);
            $otherCompletedPayment = $this->db->single();

            $this->db->query(' 
                SELECT id
                FROM student_enrollments
                WHERE student_id = :student_id AND course_id = :course_id
                FOR UPDATE
            ');
            $this->db->bind(':student_id', $studentId);
            $this->db->bind(':course_id', (int) $payment['course_id']);
            $enrollment = $this->db->single();
            $enrollmentCreated = false;

            if (!$enrollment) {
                $this->db->query(' 
                    INSERT INTO student_enrollments (student_id, course_id, enrollment_date, learning_plan)
                    VALUES (:student_id, :course_id, NOW(), :learning_plan)
                ');
                $this->db->bind(':learning_plan', $payment['learning_plan']);
                $this->db->bind(':student_id', $studentId);
                $this->db->bind(':course_id', (int) $payment['course_id']);
                $this->db->execute();
                $enrollmentId = (int) $this->db->lastInsertId();
                $enrollmentCreated = true;
            } else {
                $enrollmentId = (int) $enrollment['id'];
            }

            $alreadyCompleted = $payment['status'] === 'completed';
            $duplicatePayment = (bool) $otherCompletedPayment;
            $requiresReview = !$alreadyCompleted && ($duplicatePayment || (!$enrollmentCreated && $enrollment !== false));
            $reviewReason = $requiresReview
                ? 'Successful payment requires review because course access already existed.'
                : null;

            $payerEmail = strtolower((string) $student['email']);
            if (isset($providerData['customer']['email'])
                && is_string($providerData['customer']['email'])
                && filter_var($providerData['customer']['email'], FILTER_VALIDATE_EMAIL)) {
                $payerEmail = strtolower($providerData['customer']['email']);
            }
            $paidAt = $this->normalizeProviderDate($providerData['paid_at'] ?? $providerData['paidAt'] ?? null);

            $this->db->query(' 
                UPDATE payments
                SET status = :status,
                    transaction_id = :transaction_id,
                    payer_email = :payer_email,
                    paid_at = COALESCE(paid_at, :paid_at),
                    provider_status = :provider_status,
                    verified_at = COALESCE(verified_at, NOW()),
                    failure_reason = :failure_reason
                WHERE id = :id AND student_id = :student_id
            ');
            $this->db->bind(':status', 'completed');
            $this->db->bind(':transaction_id', $transactionId);
            $this->db->bind(':payer_email', substr($payerEmail, 0, 255));
            $this->db->bind(':paid_at', $paidAt);
            $this->db->bind(':provider_status', 'success');
            $this->db->bind(':failure_reason', $reviewReason);
            $this->db->bind(':id', (int) $payment['id']);
            $this->db->bind(':student_id', $studentId);
            $this->db->execute();

            $notifications = new NotificationService($this->db);
            $notificationEventKeys = [];
            if ($enrollmentCreated) {
                $notificationEventKeys[] = $notifications->queueCourseEnrollment(
                    $enrollmentId,
                    $studentId,
                    (string) $student['email'],
                    (string) $student['full_name'],
                    (int) $payment['course_id'],
                    (string) $payment['course_title']
                );
            }
            if (!$alreadyCompleted) {
                $notificationEventKeys[] = $notifications->queuePaymentCompleted(
                    (int) $payment['id'],
                    $studentId,
                    (string) $student['email'],
                    (string) $student['full_name'],
                    (int) $payment['course_id'],
                    (string) $payment['course_title'],
                    $payment['amount'],
                    (string) $payment['currency'],
                    (string) $payment['payment_reference'],
                    $requiresReview
                );
            }
            $this->db->commit();

            return [
                'payment_id' => (int) $payment['id'],
                'student_id' => $studentId,
                'course_id' => (int) $payment['course_id'],
                'enrollment_id' => $enrollmentId,
                'already_completed' => $alreadyCompleted,
                'enrollment_created' => $enrollmentCreated,
                'duplicate_payment' => $duplicatePayment,
                'requires_review' => $requiresReview,
                'notification_event_keys' => $notificationEventKeys,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof PaystackException) {
                throw $e;
            }
            throw new PaystackException('Unable to fulfill the verified payment.', 500, $e);
        }
    }

    /**
     * Execute an authenticated request against the fixed Paystack HTTPS API.
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)
            || !str_starts_with($path, '/')
            || str_contains($path, '..')
            || preg_match('/[\r\n]/', $path)) {
            throw new PaystackException('Invalid Paystack API request.', 500);
        }

        $url = self::API_BASE_URL . $path;
        $curl = curl_init($url);
        if ($curl === false) {
            throw new PaystackException('Unable to initialize the Paystack HTTP client.', 503);
        }

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'UmsadTech-Payments/1.0',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($payload !== null) {
            try {
                $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                curl_close($curl);
                throw new PaystackException('Unable to encode the Paystack request.', 500, $e);
            }
            $options[CURLOPT_POSTFIELDS] = $encodedPayload;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, $options);
        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== '') {
            error_log('Paystack transport error: ' . $curlError);
            throw new PaystackException('Unable to contact Paystack. Please try again.', 502);
        }

        try {
            $response = json_decode(
                $rawResponse,
                true,
                32,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
        } catch (JsonException $e) {
            error_log('Paystack returned invalid JSON (HTTP ' . $httpStatus . ').');
            throw new PaystackException('Paystack returned an invalid response.', 502, $e);
        }

        if ($httpStatus < 200 || $httpStatus >= 300
            || !is_array($response)
            || ($response['status'] ?? false) !== true
            || !isset($response['data'])
            || !is_array($response['data'])) {
            $providerMessage = is_array($response) && isset($response['message'])
                ? preg_replace('/[^\pL\pN\s.,:_-]/u', '', (string) $response['message'])
                : '';
            error_log('Paystack API request failed (HTTP ' . $httpStatus . '): ' . substr($providerMessage, 0, 200));
            throw new PaystackException('Paystack could not process the transaction.', 502);
        }

        return $response;
    }

    private function assertReference(string $reference, bool $allowLegacyUnderscore): void
    {
        $characters = $allowLegacyUnderscore ? 'A-Za-z0-9._=-' : 'A-Za-z0-9.=-';
        if ($reference === ''
            || strlen($reference) > self::MAX_REFERENCE_LENGTH
            || !preg_match('/^[' . $characters . ']+$/', $reference)) {
            throw new PaystackException('The payment reference is invalid.', 422);
        }
    }

    private function validateCallbackUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || ($this->mode === 'live' && strtolower($parts['scheme']) !== 'https')) {
            throw new PaystackException('The payment callback URL is invalid.', 422);
        }

        return $url;
    }

    private function isSecureUrl(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'checkout.paystack.com'
            && (!isset($parts['port']) || (int) $parts['port'] === 443)
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function normalizeProviderDate($value): string
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                $date = new DateTimeImmutable($value);
                return $date
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                // Fall back to the verified time below.
            }
        }

        return date('Y-m-d H:i:s');
    }

    private function recordInitializationFailure(int $paymentId, string $reason): void
    {
        try {
            $cleanReason = preg_replace('/[^\pL\pN\s.,:_-]/u', '', $reason) ?? 'Initialization failed.';
            $this->db->query(' 
                UPDATE payments
                SET provider_status = :provider_status, failure_reason = :failure_reason
                WHERE id = :id AND status = :status
            ');
            $this->db->bind(':provider_status', 'initialization_failed');
            $this->db->bind(':failure_reason', substr($cleanReason, 0, 500));
            $this->db->bind(':id', $paymentId);
            $this->db->bind(':status', 'pending');
            $this->db->execute();
        } catch (Throwable $e) {
            error_log('Unable to record Paystack initialization failure for payment ' . $paymentId . '.');
        }
    }
}
