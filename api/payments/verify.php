<?php
/**
 * Paystack Payment Verification
 * POST /api/payments/verify
 */

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    jsonResponse(false, 'Unauthorized', ['code' => 401]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', ['code' => 405]);
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);
$reference = sanitize($data['reference'] ?? '');

if (empty($reference)) {
    jsonResponse(false, 'Payment reference is required');
}

// Get payment record
$db->query('SELECT * FROM payments WHERE payment_reference = :reference');
$db->bind(':reference', $reference);
$payment = $db->single();

if (!$payment) {
    jsonResponse(false, 'Payment not found');
}

// Verify with Paystack API
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . urlencode($reference),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
    ],
]);

$response = curl_exec($curl);
$curl_error = curl_error($curl);
curl_close($curl);

if ($curl_error) {
    jsonResponse(false, 'Payment verification failed: ' . $curl_error);
}

$result = json_decode($response, true);

if (!isset($result['status']) || !$result['status']) {
    // Update payment status
    $db->query('UPDATE payments SET status = :status WHERE id = :id');
    $db->bind(':status', 'failed');
    $db->bind(':id', $payment['id']);
    $db->execute();
    
    jsonResponse(false, 'Payment verification failed');
}

$paystack_data = $result['data'];

// Verify amount
if ($paystack_data['amount'] !== ($payment['amount'] * 100)) {
    jsonResponse(false, 'Amount mismatch');
}

// Update payment record
$db->beginTransaction();

try {
    $db->query('
        UPDATE payments 
        SET status = :status, transaction_id = :transaction_id, payer_email = :payer_email, paid_at = NOW()
        WHERE id = :id
    ');
    $db->bind(':status', 'completed');
    $db->bind(':transaction_id', $paystack_data['reference']);
    $db->bind(':payer_email', $paystack_data['customer']['email']);
    $db->bind(':id', $payment['id']);
    $db->execute();

    // Create enrollment record
    $db->query('
        INSERT INTO student_enrollments (student_id, course_id, enrollment_date)
        VALUES (:student_id, :course_id, NOW())
    ');
    $db->bind(':student_id', $payment['student_id']);
    $db->bind(':course_id', $payment['course_id']);
    $db->execute();

    $db->commit();

    jsonResponse(true, 'Payment verified successfully', [
        'enrollment_id' => $db->lastInsertId(),
        'redirect' => '/student/course-lessons.php?id=' . $payment['course_id']
    ]);
} catch (Exception $e) {
    $db->rollBack();
    jsonResponse(false, 'Payment verification error: ' . $e->getMessage());
}
