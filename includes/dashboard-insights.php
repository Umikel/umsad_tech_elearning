<?php
/** Dashboard read models. Call only after the route has authenticated its role. */
function dashboardInsights(Database $db, string $role, int $userId, int $days): array
{
    $days = $days === 30 ? 30 : 7;
    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $end = date('Y-m-d', strtotime('+1 day'));
    if ($role === 'admin') {
        $sql = 'SELECT DATE(enrollment_date) AS day, COUNT(*) AS total FROM student_enrollments WHERE enrollment_date >= :start AND enrollment_date < :end GROUP BY DATE(enrollment_date)';
        $label = 'Course registrations';
        $queueSql = 'SELECT se.id, u.full_name AS title, c.title AS detail, se.enrollment_date AS date FROM student_enrollments se JOIN users u ON u.id = se.student_id JOIN courses c ON c.id = se.course_id WHERE se.is_approved = 0 ORDER BY se.enrollment_date, se.id LIMIT 5';
        $countSql = 'SELECT COUNT(*) AS total FROM student_enrollments WHERE is_approved = 0';
        $queueTitle = 'Waiting for your approval';
        $queueUrl = '/admin/enrollments.php';
        $empty = 'All registrations have been reviewed.';
    } elseif ($role === 'instructor') {
        $sql = 'SELECT DATE(s.submitted_at) AS day, COUNT(*) AS total FROM assignment_submissions s JOIN assignments a ON a.id = s.assignment_id JOIN course_lessons cl ON cl.id = a.lesson_id JOIN courses c ON c.id = cl.course_id WHERE c.instructor_id = :user_id AND s.submitted_at >= :start AND s.submitted_at < :end GROUP BY DATE(s.submitted_at)';
        $label = 'Assignment submissions';
        $queueSql = 'SELECT a.id, a.title, u.full_name AS detail, s.submitted_at AS date FROM assignment_submissions s JOIN assignments a ON a.id = s.assignment_id JOIN course_lessons cl ON cl.id = a.lesson_id JOIN courses c ON c.id = cl.course_id JOIN users u ON u.id = s.student_id WHERE c.instructor_id = :user_id AND s.is_graded = 0 ORDER BY s.submitted_at, s.id LIMIT 5';
        $countSql = 'SELECT COUNT(*) AS total FROM assignment_submissions s JOIN assignments a ON a.id = s.assignment_id JOIN course_lessons cl ON cl.id = a.lesson_id JOIN courses c ON c.id = cl.course_id WHERE c.instructor_id = :user_id AND s.is_graded = 0';
        $queueTitle = 'Ready for your feedback';
        $queueUrl = '/instructor/submissions.php?status=pending';
        $empty = 'You are up to date with grading.';
    } else {
        $sql = 'SELECT DATE(sp.completed_at) AS day, COUNT(*) AS total FROM student_progress sp JOIN student_enrollments se ON se.id = sp.enrollment_id AND se.student_id = sp.student_id WHERE sp.student_id = :user_id AND se.is_approved = 1 AND sp.is_completed = 1 AND sp.completed_at >= :start AND sp.completed_at < :end GROUP BY DATE(sp.completed_at)';
        $label = 'Lessons completed';
        $queueSql = 'SELECT a.id, a.title, c.title AS detail, a.due_date AS date FROM assignments a JOIN course_lessons cl ON cl.id = a.lesson_id JOIN courses c ON c.id = cl.course_id JOIN student_enrollments se ON se.course_id = c.id WHERE se.student_id = :user_id AND se.is_approved = 1 AND NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id = a.id AND s.student_id = se.student_id) ORDER BY a.due_date IS NULL, a.due_date, a.id LIMIT 5';
        $countSql = 'SELECT COUNT(*) AS total FROM assignments a JOIN course_lessons cl ON cl.id = a.lesson_id JOIN student_enrollments se ON se.course_id = cl.course_id WHERE se.student_id = :user_id AND se.is_approved = 1 AND NOT EXISTS (SELECT 1 FROM assignment_submissions s WHERE s.assignment_id = a.id AND s.student_id = se.student_id)';
        $queueTitle = 'Your next priorities';
        $queueUrl = '/student/my-courses.php';
        $empty = 'No outstanding assignments. Keep learning at your pace.';
    }
    $db->query($sql);
    $db->bind(':start', $start);
    $db->bind(':end', $end);
    if ($role !== 'admin') $db->bind(':user_id', $userId);
    $counts = array_column($db->resultSet(), 'total', 'day');
    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $day = date('Y-m-d', strtotime($start . ' +' . $i . ' days'));
        $series[$day] = (int) ($counts[$day] ?? 0);
    }
    $db->query($queueSql);
    if ($role !== 'admin') $db->bind(':user_id', $userId);
    $queue = $db->resultSet();
    $db->query($countSql);
    if ($role !== 'admin') $db->bind(':user_id', $userId);
    $queueCount = (int) ($db->single()['total'] ?? 0);
    return compact('days', 'series', 'label', 'queue', 'queueCount', 'queueTitle', 'queueUrl', 'empty');
}
