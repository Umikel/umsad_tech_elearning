<?php
/**
 * Paystack Payment Verification
 * POST /api/payments/verify
 */

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/Database.php';
require_once dirname(__DIR__, 2) . '/includes/Auth.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/Paystack.php';
require_once dirname(__DIR__, 2) . '/includes/NotificationService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(false, 'Method not allowed.', [], 405);
}

try {
    $db = new Database();
    $auth = new Auth($db);

    if (!$auth->verifySession()) {
        jsonResponse(false, 'Authentication required.', [], 401);
    }

    if (!$auth->isStudent()) {
        jsonResponse(false, 'Only active student accounts can verify course payments.', [], 403);
    }

    $data = requestJson();
    requireCsrfToken(isset($data['csrf_token']) && is_string($data['csrf_token']) ? $data['csrf_token'] : null);

    $reference = paymentReference($data['reference'] ?? '');
    if ($reference === '') {
        jsonResponse(false, 'A valid payment reference is required.', [], 422);
    }

    $studentId = $auth->getUserId();
    $db->query(' 
        SELECT id, student_id, course_id, amount, currency, payment_reference,
               payment_method, status, provider_status
        FROM payments
        WHERE payment_reference = :reference AND student_id = :student_id
        LIMIT 1
    ');
    $db->bind(':reference', $reference);
    $db->bind(':student_id', $studentId);
    $payment = $db->single();

    if (!$payment) {
        // Use the same response whether the reference is unknown or belongs to
        // another learner. The callback can offer account switching without
        // disclosing payment ownership.
        jsonResponse(false, 'Payment not found for this learner account.', [
            'code' => 'payment_not_found',
        ], 404);
    }

    if ($payment['payment_method'] !== 'paystack') {
        jsonResponse(false, 'This payment cannot be verified through Paystack.', [], 422);
    }

    if ($payment['status'] === 'refunded') {
        jsonResponse(false, 'This payment has been refunded.', [
            'code' => 'payment_refunded',
            'course_url' => '/course-detail.php?id=' . (int) $payment['course_id'],
        ], 409);
    }

    if ($payment['status'] === 'failed') {
        jsonResponse(false, 'The payment was not completed successfully.', [
            'code' => 'payment_failed',
            'provider_status' => (string) ($payment['provider_status'] ?: 'failed'),
            'course_url' => '/course-detail.php?id=' . (int) $payment['course_id'],
        ], 409);
    }

    if ($payment['status'] === 'completed') {
        $db->query(' 
            SELECT id FROM student_enrollments
            WHERE student_id = :student_id AND course_id = :course_id
        ');
        $db->bind(':student_id', $studentId);
        $db->bind(':course_id', (int) $payment['course_id']);
        $existingEnrollment = $db->single();
        if ($existingEnrollment) {
            jsonResponse(true, 'Payment was already verified.', [
                'payment_id' => (int) $payment['id'],
                'enrollment_id' => (int) $existingEnrollment['id'],
                'already_verified' => true,
                'redirect' => '/student/my-courses.php',
            ], 200);
        }
    }

    if (!PAYSTACK_CONFIGURED) {
        jsonResponse(false, 'Payment verification is not configured.', [], 503);
    }

    $paystack = new Paystack($db);
    $paystackData = $paystack->verifyReference($reference);
    $providerStatus = strtolower((string) ($paystackData['status'] ?? ''));
    if ($providerStatus !== 'success') {
        if (in_array($providerStatus, ['failed', 'abandoned', 'reversed'], true)) {
            $db->query(' 
                UPDATE payments
                SET status = :status,
                    provider_status = :provider_status,
                    failure_reason = :failure_reason
                WHERE id = :id AND student_id = :student_id AND status = :pending
            ');
            $db->bind(':status', 'failed');
            $db->bind(':provider_status', $providerStatus);
            $db->bind(':failure_reason', 'Paystack reported that the transaction did not complete.');
            $db->bind(':id', (int) $payment['id']);
            $db->bind(':student_id', $studentId);
            $db->bind(':pending', 'pending');
            $db->execute();
        } else {
            // Do not let a stale browser verification overwrite a terminal
            // status written concurrently by the signed webhook.
            $db->query(' 
                UPDATE payments
                SET provider_status = :provider_status
                WHERE id = :id AND student_id = :student_id AND status = :pending
            ');
            $db->bind(':provider_status', $providerStatus ?: 'unknown');
            $db->bind(':id', (int) $payment['id']);
            $db->bind(':student_id', $studentId);
            $db->bind(':pending', 'pending');
            $db->execute();
        }

        jsonResponse(false, 'The payment has not completed successfully.', [
            'code' => in_array($providerStatus, ['failed', 'abandoned', 'reversed'], true)
                ? 'payment_failed'
                : 'payment_pending',
            'provider_status' => $providerStatus ?: 'unknown',
            'course_url' => '/course-detail.php?id=' . (int) $payment['course_id'],
        ], 409);
    }

    $fulfillment = $paystack->fulfillSuccessfulPayment($paystackData, $studentId);
    $alreadyVerified = (bool) $fulfillment['already_completed'];

    try {
        $notifications = new NotificationService($db);
        $notifications->dispatchBestEffort(
            is_array($fulfillment['notification_event_keys'] ?? null)
                ? $fulfillment['notification_event_keys']
                : []
        );
    } catch (Throwable $notificationException) {
        error_log('Payment notifications remain queued for retry.');
    }

    jsonResponse(true, $alreadyVerified ? 'Payment was already verified.' : 'Payment verified successfully.', [
        'payment_id' => (int) $fulfillment['payment_id'],
        'enrollment_id' => (int) $fulfillment['enrollment_id'],
        'already_verified' => $alreadyVerified,
        'requires_review' => (bool) $fulfillment['requires_review'],
        'redirect' => '/student/my-courses.php',
    ], 200);
} catch (PaystackException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Paystack verification rejected: ' . $e->getMessage());
    $status = $e->getHttpStatus();
    $message = $status === 503
        ? 'Payment verification is temporarily unavailable.'
        : 'We could not confirm this payment with Paystack. Please try again.';
    jsonResponse(false, $message, [], $status);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Payment verification failed: ' . $e->getMessage());
    jsonResponse(false, 'We could not verify the payment. Please try again.', [], 500);
}
