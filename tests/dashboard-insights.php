<?php
require __DIR__ . '/dashboard-fixture.php';
require dirname(__DIR__) . '/includes/dashboard-insights.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$db = dashboardFixture();
$student = dashboardInsights($db, 'student', 1, 7);
check(count($student['series']) === 7 && array_sum($student['series']) === 1, 'Student activity must exclude pending enrollments and other learners');
check($student['queueCount'] === 1 && (int) $student['queue'][0]['id'] === 1, 'Student priorities must include overdue work and exclude submitted/pending content');
$instructor = dashboardInsights($db, 'instructor', 2, 30);
check(count($instructor['series']) === 30 && array_sum($instructor['series']) === 1, 'Instructor activity must be scoped to owned courses');
check($instructor['queueCount'] === 1, 'Grading queue must exclude other instructors');
$admin = dashboardInsights($db, 'admin', 4, 7);
check($admin['queueCount'] === 1 && array_sum($admin['series']) === 3, 'Admin registration counts');
$empty = dashboardInsights($db, 'student', 999, -100);
check(count($empty['series']) === 7 && array_sum($empty['series']) === 0 && $empty['queueCount'] === 0, 'Empty accounts and invalid date periods');
echo "PASS: role isolation, pending access, overdue priorities, submitted work, date periods and empty accounts.\n";
