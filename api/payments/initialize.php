<?php
/**
 * Paystack Payment Initialization
 * POST /api/payments/initialize
 */

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);

// Check if user is logged in and is a student
if (!$auth->isLoggedIn() || !$auth->isStudent()) {
    jsonResponse(false, 'Unauthorized', ['code' => 401]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', ['code' => 405]);
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

$course_id = intval($data['course_id'] ?? 0);
$student_id = $auth->getUserId();

if ($course_id <= 0) {
    jsonResponse(false, 'Invalid course ID');
}

// Check if course exists
$db->query('SELECT * FROM courses WHERE id = :id AND is_published = 1');
$db->bind(':id', $course_id);
$course = $db->single();

if (!$course) {
    jsonResponse(false, 'Course not found');
}

// Check if student already enrolled
$db->query('SELECT * FROM student_enrollments WHERE student_id = :student_id AND course_id = :course_id');
$db->bind(':student_id', $student_id);
$db->bind(':course_id', $course_id);

if ($db->single()) {
    jsonResponse(false, 'Already enrolled in this course');
}

// Get student email
$db->query('SELECT email FROM users WHERE id = :id');
$db->bind(':id', $student_id);
$student = $db->single();

// Prepare payment data
$amount = $course['discount_price'] ?? $course['price'];
$reference = 'UMSAD_' . time() . '_' . $student_id . '_' . $course_id;

// Create payment record
$db->query('
    INSERT INTO payments (student_id, course_id, amount, payment_reference, status)
    VALUES (:student_id, :course_id, :amount, :reference, "pending")
');
$db->bind(':student_id', $student_id);
$db->bind(':course_id', $course_id);
$db->bind(':amount', $amount);
$db->bind(':reference', $reference);
$db->execute();

// Return payment initialization data
jsonResponse(true, 'Payment initialized successfully', [
    'reference' => $reference,
    'amount' => $amount,
    'currency' => 'NGN',
    'email' => $student['email'],
    'public_key' => PAYSTACK_PUBLIC_KEY,
    'course_title' => $course['title']
]);
