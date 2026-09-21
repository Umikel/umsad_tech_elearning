<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);
if (!$auth->verifySession()) {
    redirect('/login.php');
}
if (!$auth->isStudent()) {
    redirect('/' . $auth->getDashboardPath());
}

$studentId = (int) $auth->getUserId();
$currentUser = $auth->getUser();

$db->query('SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed,
                   COALESCE(AVG(progress_percentage), 0) AS average_progress,
                   COALESCE(SUM(CASE WHEN is_completed = 0 THEN 1 ELSE 0 END), 0) AS in_progress
            FROM student_enrollments WHERE is_approved = 1 AND student_id = :student_id');
$db->bind(':student_id', $studentId);
$summary = $db->single() ?: [];

$db->query('SELECT COUNT(DISTINCT DATE(completed_at)) AS learning_days
            FROM student_progress
            WHERE student_id = :student_id AND is_completed = 1 AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
$db->bind(':student_id', $studentId);
$learningDays = (int) ($db->single()['learning_days'] ?? 0);

$db->query('SELECT COUNT(*) AS certificate_count FROM certificates WHERE student_id = :student_id');
$db->bind(':student_id', $studentId);
$certificateCount = (int) ($db->single()['certificate_count'] ?? 0);

$db->query('SELECT c.id, c.title, c.category, c.course_image, u.full_name AS instructor_name,
                   se.progress_percentage, se.is_completed, se.enrollment_date,
                   (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS total_lessons,
                   (SELECT COUNT(*) FROM student_progress sp
                    JOIN course_lessons pcl ON pcl.id = sp.lesson_id
                    WHERE sp.student_id = :progress_student_id AND pcl.course_id = c.id AND sp.is_completed = 1) AS completed_lessons,
                   (SELECT cl2.id FROM course_lessons cl2
                    JOIN course_modules cm2 ON cm2.id = cl2.module_id
                    LEFT JOIN student_progress sp2 ON sp2.lesson_id = cl2.id AND sp2.student_id = :next_student_id
                    WHERE cl2.course_id = c.id AND COALESCE(sp2.is_completed, 0) = 0
                    ORDER BY cm2.sequence, cl2.sequence, cl2.id LIMIT 1) AS next_lesson_id
            FROM student_enrollments se
            JOIN courses c ON c.id = se.course_id
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE se.is_approved = 1 AND se.student_id = :student_id
            ORDER BY se.is_completed ASC, se.progress_percentage DESC, se.enrollment_date DESC
            LIMIT 6');
$db->bind(':progress_student_id', $studentId);
$db->bind(':next_student_id', $studentId);
$db->bind(':student_id', $studentId);
$courses = $db->resultSet();
$continueCourse = null;
foreach ($courses as $course) {
    if ((int) $course['is_completed'] === 0) {
        $continueCourse = $course;
        break;
    }
}

$db->query('SELECT a.id, a.title, a.due_date, a.max_score, c.id AS course_id, c.title AS course_title,
                   (SELECT ass.is_graded FROM assignment_submissions ass
                    WHERE ass.assignment_id = a.id AND ass.student_id = :submission_student_id
                    ORDER BY ass.submitted_at DESC, ass.id DESC LIMIT 1) AS is_graded,
                   (SELECT ass2.id FROM assignment_submissions ass2
                    WHERE ass2.assignment_id = a.id AND ass2.student_id = :id_student_id
                    ORDER BY ass2.submitted_at DESC, ass2.id DESC LIMIT 1) AS submission_id
            FROM assignments a
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            JOIN student_enrollments se ON se.course_id = c.id AND se.is_approved = 1 AND se.student_id = :student_id
            WHERE a.due_date IS NULL OR a.due_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY a.due_date IS NULL, a.due_date ASC, a.created_at DESC
            LIMIT 5');
$db->bind(':submission_student_id', $studentId);
$db->bind(':id_student_id', $studentId);
$db->bind(':student_id', $studentId);
$assignments = $db->resultSet();

$db->query('SELECT qa.score, qa.passed, qa.completed_at, q.title, c.title AS course_title
            FROM quiz_attempts qa
            JOIN quizzes q ON q.id = qa.quiz_id
            JOIN course_lessons cl ON cl.id = q.lesson_id
            JOIN courses c ON c.id = cl.course_id
            WHERE qa.student_id = :student_id
            ORDER BY qa.completed_at DESC, qa.id DESC LIMIT 5');
$db->bind(':student_id', $studentId);
$quizActivity = $db->resultSet();

$db->query('SELECT COUNT(*) AS pending FROM student_enrollments WHERE student_id = :student_id AND is_approved = 0');
$db->bind(':student_id', $studentId);
$pendingApprovals = (int) ($db->single()['pending'] ?? 0);

$dashboardRole = 'student';
$dashboardDays = isset($_GET['days']) && $_GET['days'] === '30' ? 30 : 7;
require_once dirname(__DIR__) . '/includes/dashboard-insights.php';
$insights = dashboardInsights($db, $dashboardRole, (int) $auth->getUserId(), $dashboardDays);

$pageTitle = 'Learner Dashboard';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
$firstName = trim((string) ($currentUser['full_name'] ?? 'Learner'));
$firstName = explode(' ', $firstName)[0] ?: 'Learner';
?>
<div class="workspace-page">
<?php if ($pendingApprovals > 0): ?>
<div class="alert alert-info" role="status">You have <?php echo $pendingApprovals; ?> course <?php echo $pendingApprovals === 1 ? "registration" : "registrations"; ?> awaiting admin approval. Lessons will unlock after approval. <a href="<?php echo APP_URL; ?>/student/my-courses.php">View my courses</a></div>
<?php endif; ?>


    <section class="workspace-hero">
        <div class="workspace-hero__content">
        <span class="workspace-eyebrow">Your learning space</span><h1>Welcome back, <?php echo sanitize($firstName); ?>.</h1><p><?php echo $continueCourse ? 'Your next lesson is ready. Keep the momentum going with a focused learning session today.' : ((int) ($summary['total'] ?? 0) > 0 ? 'You have completed every active course—excellent work.' : ($pendingApprovals > 0 ? 'Your registration is received. Your courses will unlock once an admin approves them.' : 'Choose a practical course and begin building your next skill.')); ?></p><div class="d-flex flex-wrap gap-2 mt-4"><?php if ($continueCourse): ?><a class="btn btn-light" href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo (int) $continueCourse['id']; ?><?php echo $continueCourse['next_lesson_id'] ? '&amp;lesson_id=' . (int) $continueCourse['next_lesson_id'] : ''; ?>"><i class="fas fa-play me-2"></i>Continue learning</a><?php endif; ?><a class="btn btn-primary" href="<?php echo APP_URL; ?>/courses.php"><i class="fas fa-compass me-2"></i>Explore courses</a></div></div>
        <div class="workspace-hero__aside"><div class="workspace-hero__badge"><small>Overall progress</small><strong><?php echo (int) round((float) ($summary['average_progress'] ?? 0)); ?>%</strong><small><?php echo (int) ($summary['completed'] ?? 0); ?> completed</small></div></div>
    </section>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-book-open"></i></span></div><strong><?php echo number_format((int) ($summary['in_progress'] ?? 0)); ?></strong><p>Courses in progress</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-circle-check"></i></span></div><strong><?php echo number_format((int) ($summary['completed'] ?? 0)); ?></strong><p>Courses completed</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-calendar-check"></i></span></div><strong><?php echo number_format($learningDays); ?></strong><p>Learning days · last 30 days</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-award"></i></span></div><strong><?php echo number_format($certificateCount); ?></strong><p>Certificates earned</p></article>
    </div>

    <?php require dirname(__DIR__) . '/templates/dashboard-insights.php'; ?>

    <div class="workspace-grid">
        <div>
            <section class="workspace-panel">
                <div class="workspace-panel__header"><div><h2>Continue learning</h2><p>Your active courses, ordered by momentum.</p></div><a class="text-link small" href="<?php echo APP_URL; ?>/student/my-courses.php">View library</a></div>
                <div class="workspace-panel__body">
                    <?php if ($courses): ?><div class="workspace-card-grid">
                        <?php foreach (array_slice($courses, 0, 3) as $course): $progress = max(0, min(100, (int) $course['progress_percentage'])); ?>
                            <article class="workspace-card"><div class="d-flex align-items-center justify-content-between gap-2"><span class="status-pill <?php echo (int) $course['is_completed'] === 1 ? 'status-pill--success' : 'status-pill--info'; ?>"><?php echo (int) $course['is_completed'] === 1 ? 'Completed' : 'In progress'; ?></span><small class="text-muted"><?php echo sanitize($course['category'] ?: 'Course'); ?></small></div><h3><?php echo sanitize($course['title']); ?></h3><p><i class="fas fa-chalkboard-user me-1"></i><?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech instructor'); ?></p><div class="d-flex justify-content-between small mb-2"><span class="text-muted"><?php echo (int) $course['completed_lessons']; ?>/<?php echo (int) $course['total_lessons']; ?> lessons</span><strong><?php echo $progress; ?>%</strong></div><div class="workspace-progress"><span style="width:<?php echo $progress; ?>%"></span></div><div class="workspace-card__meta"><a class="btn btn-primary btn-sm w-100" href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo (int) $course['id']; ?><?php echo $course['next_lesson_id'] ? '&amp;lesson_id=' . (int) $course['next_lesson_id'] : ''; ?>"><?php echo (int) $course['is_completed'] === 1 ? 'Review course' : ($progress > 0 ? 'Continue' : 'Start course'); ?> <i class="fas fa-arrow-right ms-1"></i></a></div></article>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-compass"></i></span><h3><?php echo $pendingApprovals ? "Your learning journey is almost ready" : "Find your first course"; ?></h3><p><?php echo $pendingApprovals ? "Your registrations are waiting for admin approval. Check My courses for their status." : "Explore practical courses and enroll to start tracking progress here."; ?></p><a class="btn btn-primary" href="<?php echo APP_URL; ?>/courses.php">Explore courses</a></div><?php endif; ?>
                </div>
            </section>

            <section class="workspace-panel mt-4">
                <div class="workspace-panel__header"><div><h2>Recent quiz activity</h2><p>Your latest knowledge checks.</p></div></div>
                <div class="workspace-panel__body--flush">
                    <?php if ($quizActivity): ?><div class="workspace-table-wrap"><table class="workspace-table"><thead><tr><th>Quiz</th><th>Course</th><th>Score</th><th>Result</th><th>Date</th></tr></thead><tbody><?php foreach ($quizActivity as $activity): ?><tr><td><strong class="text-dark"><?php echo sanitize($activity['title']); ?></strong></td><td><?php echo sanitize($activity['course_title']); ?></td><td><strong><?php echo (int) $activity['score']; ?>%</strong></td><td><span class="status-pill <?php echo (int) $activity['passed'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $activity['passed'] === 1 ? 'Passed' : 'Try again'; ?></span></td><td><?php echo formatDate($activity['completed_at']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="workspace-empty p-4"><span class="workspace-empty__icon"><i class="fas fa-circle-question"></i></span><h3>No quiz attempts yet</h3><p>Quizzes linked to your lessons will appear as you progress.</p></div><?php endif; ?>
                </div>
            </section>
        </div>

        <aside>
            <section class="workspace-panel">
                <div class="workspace-panel__header"><div><h2>Assignments</h2><p>Upcoming work and feedback status.</p></div></div>
                <div class="workspace-panel__body">
                    <?php if ($assignments): ?><div class="builder-stack"><?php foreach ($assignments as $assignment): ?><a class="builder-section text-decoration-none" href="<?php echo APP_URL; ?>/student/assignment.php?assignment_id=<?php echo (int) $assignment['id']; ?>"><div class="builder-section__header"><div><h3><?php echo sanitize($assignment['title']); ?></h3><small class="text-muted"><?php echo sanitize($assignment['course_title']); ?></small></div><span class="status-pill <?php echo $assignment['submission_id'] ? ((int) $assignment['is_graded'] === 1 ? 'status-pill--success' : 'status-pill--warning') : 'status-pill--info'; ?>"><?php echo $assignment['submission_id'] ? ((int) $assignment['is_graded'] === 1 ? 'Graded' : 'Submitted') : 'To do'; ?></span></div><div class="builder-section__body d-flex justify-content-between align-items-center gap-2"><small class="text-muted"><?php echo $assignment['due_date'] ? 'Due ' . formatDate($assignment['due_date'], 'd M Y') : 'No deadline'; ?></small><i class="fas fa-arrow-right text-primary"></i></div></a><?php endforeach; ?></div><?php else: ?><div class="workspace-empty p-3"><span class="workspace-empty__icon"><i class="fas fa-file-pen"></i></span><h3>Nothing due right now</h3><p>Assignments from your lessons will appear here.</p></div><?php endif; ?>
                </div>
            </section>

            <section class="workspace-panel mt-4">
                <div class="workspace-panel__header"><div><h2>Account snapshot</h2><p>Your learning profile at a glance.</p></div></div>
                <div class="workspace-panel__body"><div class="builder-item"><div><h4>Member since</h4><p><?php echo formatDate($currentUser['created_at'], 'F Y'); ?></p></div><i class="far fa-calendar text-primary"></i></div><div class="builder-item"><div><h4>Last sign in</h4><p><?php echo $currentUser['last_login'] ? formatDate($currentUser['last_login'], 'd M Y, H:i') : 'First session'; ?></p></div><i class="fas fa-shield-halved text-primary"></i></div><a class="btn btn-outline-primary w-100 mt-3" href="<?php echo APP_URL; ?>/profile.php"><i class="fas fa-user-pen me-2"></i>Update profile</a></div>
            </section>
        </aside>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
