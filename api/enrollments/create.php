<?php
/**
 * Create Free Course Enrollment
 * POST /api/enrollments/create.php
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

$course_id = intval($data['course_id'] ?? 0);
$student_id = $auth->getUserId();

if ($course_id <= 0) {
    jsonResponse(false, 'Invalid course ID');
}

// Check if course exists and is free
$db->query('SELECT * FROM courses WHERE id = :id AND is_published = 1 AND price = 0');
$db->bind(':id', $course_id);
$course = $db->single();

if (!$course) {
    jsonResponse(false, 'Course not found or is not free');
}

// Check if already enrolled
$db->query('
    SELECT id FROM student_enrollments 
    WHERE student_id = :student_id AND course_id = :course_id
');
$db->bind(':student_id', $student_id);
$db->bind(':course_id', $course_id);

if ($db->single()) {
    jsonResponse(false, 'Already enrolled in this course');
}

// Create enrollment
try {
    $db->query('
        INSERT INTO student_enrollments (student_id, course_id, enrollment_date)
        VALUES (:student_id, :course_id, NOW())
    ');
    $db->bind(':student_id', $student_id);
    $db->bind(':course_id', $course_id);
    $db->execute();

    jsonResponse(true, 'Enrollment successful', [
        'enrollment_id' => $db->lastInsertId(),
        'redirect' => '/student/course-lessons.php?id=' . $course_id
    ]);
} catch (Exception $e) {
    jsonResponse(false, 'Enrollment failed: ' . $e->getMessage());
}
