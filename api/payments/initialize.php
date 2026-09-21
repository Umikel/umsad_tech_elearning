<?php
/**
 * Paystack Payment Initialization
 * POST /api/payments/initialize
 */

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/Database.php';
require_once dirname(__DIR__, 2) . '/includes/Auth.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/course-registration-guide.php';
require_once dirname(__DIR__, 2) . '/includes/Paystack.php';

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
        jsonResponse(false, 'Only active student accounts can purchase courses.', [], 403);
    }

    $data = requestJson();
    requireCsrfToken(isset($data['csrf_token']) && is_string($data['csrf_token']) ? $data['csrf_token'] : null);

    $courseId = filter_var($data['course_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($courseId === false) {
        jsonResponse(false, 'Please select a valid course.', [], 422);
    }

    if (!PAYSTACK_CONFIGURED) {
        jsonResponse(false, 'Payments are temporarily unavailable.', [], 503);
    }

    if (PAYSTACK_IS_LIVE && (
        APP_ENV !== 'production'
        || parse_url(APP_URL, PHP_URL_SCHEME) !== 'https'
        || !SESSION_COOKIE_SECURE
    )) {
        jsonResponse(false, 'Live checkout requires a production HTTPS application URL.', [], 503);
    }

    $studentId = $auth->getUserId();
    $db->beginTransaction();

    // Lock this student to make payment initialization idempotent under concurrency.
    $db->query(' 
        SELECT id, email FROM users
        WHERE id = :id AND user_type = :type AND is_active = 1
        FOR UPDATE
    ');
    $db->bind(':id', $studentId);
    $db->bind(':type', 'student');
    $student = $db->single();

    if (!$student) {
        $db->rollBack();
        $auth->logout();
        jsonResponse(false, 'Your student account is no longer active.', [], 401);
    }

    $db->query(' 
        SELECT id, slug, title, price, discount_price
        FROM courses
        WHERE id = :id AND is_published = 1
        FOR UPDATE
    ');
    $db->bind(':id', $courseId);
    $course = $db->single();

    if (!$course) {
        $db->rollBack();
        jsonResponse(false, 'The course is unavailable.', [], 404);
    }

    $registrationGuide = courseRegistrationGuide($course);
    if (!validCourseGuideAcknowledgment($registrationGuide, $data)) {
        $db->rollBack();
        jsonResponse(false, 'Please open and acknowledge the current course guide before registering. Refresh the course page if the guide has changed.', ['code' => 'course_guide_required'], 422);
    }

    $pricing = coursePriceDetails($course);
    $learningPlan = $data['learning_plan'] ?? 'online';
    if (!is_string($learningPlan) || !in_array($learningPlan, ['online', 'sunday_physical'], true)
        || ($learningPlan === 'sunday_physical' && !hasSundayPhysicalClass($course))) {
        $db->rollBack();
        jsonResponse(false, 'Please select a valid class option.', [], 422);
    }
    $amountMinor = coursePlanAmountMinor($course, $learningPlan, $pricing['effective_minor']);
    if (!$pricing['valid'] || $amountMinor <= 0) {
        $db->rollBack();
        jsonResponse(false, 'This course does not require payment. Use free enrollment instead.', [], 422);
    }
    $amount = minorUnitsToMoney($amountMinor);

    $db->query(' 
        SELECT id FROM student_enrollments
        WHERE student_id = :student_id AND course_id = :course_id
    ');
    $db->bind(':student_id', $studentId);
    $db->bind(':course_id', $courseId);
    if ($db->single()) {
        $db->rollBack();
        jsonResponse(false, 'You are already enrolled in this course.', [], 409);
    }

    if ($registrationGuide !== null) {
        recordCourseGuideAcknowledgment($db, (int) $studentId, (int) $courseId, $registrationGuide);
    }

    // Reuse a recent, matching pending payment instead of creating duplicates.
    $db->query(' 
        SELECT id, amount, currency, payment_reference, learning_plan
        FROM payments
        WHERE student_id = :student_id
          AND course_id = :course_id
          AND status = :status
          AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ORDER BY id DESC
        LIMIT 1
    ');
    $db->bind(':student_id', $studentId);
    $db->bind(':course_id', $courseId);
    $db->bind(':status', 'pending');
    $pendingPayment = $db->single();

    $reused = $pendingPayment
        && $pendingPayment['learning_plan'] === $learningPlan
        && strtoupper((string) $pendingPayment['currency']) === PAYSTACK_CURRENCY
        && moneyToMinorUnits($pendingPayment['amount']) === $amountMinor;

    if ($reused) {
        $reference = $pendingPayment['payment_reference'];
        $paymentId = (int) $pendingPayment['id'];
    } else {
        $reference = 'UMSAD-' . strtoupper(bin2hex(random_bytes(20)));
        $db->query(' 
            INSERT INTO payments (
                student_id, course_id, amount, currency, payment_reference, payment_method, status, learning_plan
            ) VALUES (
                :student_id, :course_id, :amount, :currency, :reference, :method, :status, :learning_plan
            )
        ');
        $db->bind(':student_id', $studentId);
        $db->bind(':course_id', $courseId);
        $db->bind(':amount', $amount);
        $db->bind(':currency', PAYSTACK_CURRENCY);
        $db->bind(':reference', $reference);
        $db->bind(':method', 'paystack');
        $db->bind(':learning_plan', $learningPlan);
        $db->bind(':status', 'pending');
        $db->execute();
        $paymentId = (int) $db->lastInsertId();
    }

    $db->commit();

    $paystack = new Paystack($db);
    $checkout = $paystack->initializeTransaction(
        $paymentId,
        appUrl('/payment-callback.php'),
        [
            'payment_id' => $paymentId,
            'student_id' => $studentId,
            'course_id' => (int) $courseId,
            'course_title' => (string) $course['title'],
        ]
    );

    jsonResponse(true, !empty($checkout['reused']) ? 'Payment is ready to continue.' : 'Secure checkout initialized.', [
        'payment_id' => (int) $checkout['payment_id'],
        'reference' => (string) $checkout['reference'],
        'access_code' => (string) $checkout['access_code'],
        'authorization_url' => (string) $checkout['authorization_url'],
        'amount' => $amount,
        'amount_minor' => $amountMinor,
        'currency' => PAYSTACK_CURRENCY,
        'course_title' => $course['title'],
        'reused' => (bool) $checkout['reused'],
    ], !empty($checkout['reused']) ? 200 : 201);
} catch (PaystackException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Paystack initialization rejected: ' . $e->getMessage());
    jsonResponse(false, 'Secure checkout could not be started. Please try again.', [], $e->getHttpStatus());
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Payment initialization failed: ' . $e->getMessage());
    jsonResponse(false, 'We could not initialize the payment. Please try again.', [], 500);
}
