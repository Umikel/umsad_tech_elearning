<?php
/**
 * Create Free Course Enrollment
 * POST /api/enrollments/create.php
 */

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/Database.php';
require_once dirname(__DIR__, 2) . '/includes/Auth.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/course-registration-guide.php';
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
        jsonResponse(false, 'Only active student accounts can enroll in courses.', [], 403);
    }

    $data = requestJson();
    requireCsrfToken(isset($data['csrf_token']) && is_string($data['csrf_token']) ? $data['csrf_token'] : null);

    $courseId = filter_var($data['course_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($courseId === false) {
        jsonResponse(false, 'Please select a valid course.', [], 422);
    }

    $studentId = $auth->getUserId();
    $db->beginTransaction();

    // Lock the student row so concurrent enrollment/payment requests serialize.
    $db->query(' 
        SELECT id, email, full_name
        FROM users
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

    if (($data['learning_plan'] ?? 'online') !== 'online') {
        $db->rollBack();
        jsonResponse(false, 'The Sunday physical class requires paid registration.', [], 422);
    }

    $pricing = coursePriceDetails($course);
    if (!$pricing['valid'] || $pricing['effective_minor'] !== 0) {
        $db->rollBack();
        jsonResponse(false, 'This course requires payment before enrollment.', [], 422);
    }

    $db->query(' 
        SELECT id FROM student_enrollments
        WHERE student_id = :student_id AND course_id = :course_id
    ');
    $db->bind(':student_id', $studentId);
    $db->bind(':course_id', $courseId);
    $existingEnrollment = $db->single();

    if ($existingEnrollment) {
        $db->commit();
        jsonResponse(true, 'You are already enrolled in this course.', [
            'enrollment_id' => (int) $existingEnrollment['id'],
            'already_enrolled' => true,
            'redirect' => '/student/my-courses.php',
        ], 200);
    }

    if ($registrationGuide !== null) {
        recordCourseGuideAcknowledgment($db, (int) $studentId, (int) $courseId, $registrationGuide);
    }

    $db->query(' 
        INSERT INTO student_enrollments (student_id, course_id, enrollment_date)
        VALUES (:student_id, :course_id, NOW())
    ');
    $db->bind(':student_id', $studentId);
    $db->bind(':course_id', $courseId);
    $db->execute();
    $enrollmentId = (int) $db->lastInsertId();

    $notifications = new NotificationService($db);
    $enrollmentEventKey = $notifications->queueCourseEnrollment(
        $enrollmentId,
        (int) $studentId,
        (string) $student['email'],
        (string) $student['full_name'],
        (int) $courseId,
        (string) $course['title']
    );
    $db->commit();

    try {
        $notifications->dispatchBestEffort([$enrollmentEventKey]);
    } catch (Throwable $notificationException) {
        error_log('The enrollment notification remains queued for retry.');
    }

    jsonResponse(true, 'Registration received. Please wait for admin approval before starting the course.', [
        'enrollment_id' => $enrollmentId,
        'already_enrolled' => false,
        'redirect' => '/student/my-courses.php',
    ], 201);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    // A concurrent request may have created the unique enrollment first.
    if ((string) $e->getCode() === '23000' && isset($db, $studentId, $courseId)) {
        try {
            $db->query(' 
                SELECT id FROM student_enrollments
                WHERE student_id = :student_id AND course_id = :course_id
            ');
            $db->bind(':student_id', $studentId);
            $db->bind(':course_id', $courseId);
            $existingEnrollment = $db->single();
            if ($existingEnrollment) {
                jsonResponse(true, 'You are already enrolled in this course.', [
                    'enrollment_id' => (int) $existingEnrollment['id'],
                    'already_enrolled' => true,
                    'redirect' => '/student/my-courses.php',
                ], 200);
            }
        } catch (Throwable $lookupError) {
            error_log('Enrollment recovery failed: ' . $lookupError->getMessage());
        }
    }

    error_log('Enrollment failed: ' . $e->getMessage());
    jsonResponse(false, 'We could not complete the enrollment. Please try again.', [], 500);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Enrollment failed: ' . $e->getMessage());
    jsonResponse(false, 'We could not complete the enrollment. Please try again.', [], 500);
}
