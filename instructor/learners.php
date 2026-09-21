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
if (!$auth->isInstructor()) {
    redirect('/' . $auth->getDashboardPath());
}

$instructorId = (int) $auth->getUserId();
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$courseId = $courseId ? (int) $courseId : 0;
$status = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'not_started', 'in_progress', 'completed'], true)) {
    $status = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$db->query('SELECT id, title FROM courses WHERE instructor_id = :instructor_id ORDER BY title');
$db->bind(':instructor_id', $instructorId);
$ownedCourses = $db->resultSet();
$ownedCourseIds = array_map(static fn(array $course): int => (int) $course['id'], $ownedCourses);
if ($courseId > 0 && !in_array($courseId, $ownedCourseIds, true)) {
    $courseId = 0;
}

$where = ['c.instructor_id = :instructor_id'];
if ($courseId > 0) {
    $where[] = 'c.id = :course_id';
}
if ($status === 'not_started') {
    $where[] = 'se.progress_percentage = 0 AND se.is_completed = 0';
} elseif ($status === 'in_progress') {
    $where[] = 'se.progress_percentage > 0 AND se.is_completed = 0';
} elseif ($status === 'completed') {
    $where[] = 'se.is_completed = 1';
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email OR c.title LIKE :search_course)';
}

$query = 'SELECT se.id AS enrollment_id, se.enrollment_date, se.completion_date,
                 se.progress_percentage, se.is_completed,
                 u.id AS student_id, u.full_name, u.email, u.profile_image, u.last_login,
                 c.id AS course_id, c.title AS course_title,
                 (SELECT COUNT(*) FROM student_progress sp WHERE sp.enrollment_id = se.id AND sp.is_completed = 1) AS completed_lessons,
                 (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS total_lessons,
                 (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.enrollment_id = se.id AND qa.passed = 1) AS passed_quizzes,
                 (SELECT COUNT(*) FROM assignment_submissions ass
                  JOIN assignments a ON a.id = ass.assignment_id
                  JOIN course_lessons acl ON acl.id = a.lesson_id
                  WHERE ass.student_id = u.id AND acl.course_id = c.id) AS submissions
          FROM student_enrollments se
          JOIN users u ON u.id = se.student_id
          JOIN courses c ON c.id = se.course_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY se.enrollment_date DESC';
$db->query($query);
$db->bind(':instructor_id', $instructorId);
if ($courseId > 0) {
    $db->bind(':course_id', $courseId);
}
if ($search !== '') {
    $term = '%' . $search . '%';
    $db->bind(':search_name', $term);
    $db->bind(':search_email', $term);
    $db->bind(':search_course', $term);
}
$learners = $db->resultSet();

$db->query('SELECT COUNT(DISTINCT se.student_id) AS learner_count,
                   COUNT(se.id) AS enrollment_count,
                   COALESCE(AVG(se.progress_percentage), 0) AS average_progress,
                   COALESCE(SUM(CASE WHEN se.is_completed = 1 THEN 1 ELSE 0 END), 0) AS completions
            FROM student_enrollments se
            JOIN courses c ON c.id = se.course_id
            WHERE c.instructor_id = :instructor_id');
$db->bind(':instructor_id', $instructorId);
$metrics = $db->single() ?: [];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="umsad-learners-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    if ($output !== false) {
        fputcsv($output, ['Learner', 'Email', 'Course', 'Progress', 'Completed lessons', 'Total lessons', 'Status', 'Enrolled']);
        foreach ($learners as $learner) {
            fputcsv($output, [
                $learner['full_name'], $learner['email'], $learner['course_title'],
                (int) $learner['progress_percentage'] . '%', (int) $learner['completed_lessons'],
                (int) $learner['total_lessons'], (int) $learner['is_completed'] === 1 ? 'Completed' : 'Active',
                $learner['enrollment_date'],
            ]);
        }
        fclose($output);
    }
    exit;
}

$pageTitle = 'Learners';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
?>

<div class="workspace-page">
    <header class="workspace-page__header">
        <div><span class="workspace-eyebrow">Learner success</span><h1 class="workspace-title">Learners</h1><p class="workspace-subtitle">Track enrollment, lesson completion, assessment activity, and the learners who may need a timely nudge.</p></div>
        <div class="workspace-actions"><a class="btn btn-outline-primary" href="?<?php echo sanitize(http_build_query($exportQuery)); ?>"><i class="fas fa-download me-2"></i>Export CSV</a></div>
    </header>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-user-group"></i></span></div><strong><?php echo number_format((int) ($metrics['learner_count'] ?? 0)); ?></strong><p>Unique learners</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-book-open"></i></span></div><strong><?php echo number_format((int) ($metrics['enrollment_count'] ?? 0)); ?></strong><p>Total enrollments</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-chart-line"></i></span></div><strong><?php echo (int) round((float) ($metrics['average_progress'] ?? 0)); ?>%</strong><p>Average progress</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-award"></i></span></div><strong><?php echo number_format((int) ($metrics['completions'] ?? 0)); ?></strong><p>Course completions</p></article>
    </div>

    <section class="workspace-toolbar">
        <form method="get">
            <input type="hidden" name="status" value="<?php echo sanitize($status); ?>">
            <?php if ($courseId > 0): ?><input type="hidden" name="course_id" value="<?php echo $courseId; ?>"><?php endif; ?>
            <label class="visually-hidden" for="learner-search">Search learners</label>
            <input id="learner-search" name="search" type="search" maxlength="100" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Search learner, email, or course">
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i><span class="visually-hidden">Search</span></button>
        </form>
        <form method="get">
            <input type="hidden" name="status" value="<?php echo sanitize($status); ?>">
            <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?php echo sanitize($search); ?>"><?php endif; ?>
            <label class="visually-hidden" for="learner-course">Filter by course</label>
            <select id="learner-course" name="course_id" class="form-select" onchange="this.form.submit()">
                <option value="">All courses</option>
                <?php foreach ($ownedCourses as $ownedCourse): ?><option value="<?php echo (int) $ownedCourse['id']; ?>" <?php echo $courseId === (int) $ownedCourse['id'] ? 'selected' : ''; ?>><?php echo sanitize($ownedCourse['title']); ?></option><?php endforeach; ?>
            </select>
        </form>
        <nav class="workspace-filter-tabs" aria-label="Progress status">
            <?php foreach (['all' => 'All', 'not_started' => 'Not started', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $key => $label):
                $params = array_filter(['status' => $key, 'course_id' => $courseId ?: null, 'search' => $search ?: null]); ?>
                <a class="<?php echo $status === $key ? 'is-active' : ''; ?>" href="?<?php echo sanitize(http_build_query($params)); ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
    </section>

    <section class="workspace-panel">
        <div class="workspace-panel__header"><div><h2>Enrollment activity</h2><p><?php echo number_format(count($learners)); ?> matching enrollment<?php echo count($learners) === 1 ? '' : 's'; ?>.</p></div></div>
        <div class="workspace-panel__body--flush">
            <?php if ($learners): ?>
                <div class="workspace-table-wrap"><table class="workspace-table">
                    <thead><tr><th>Learner</th><th>Course</th><th>Progress</th><th>Learning activity</th><th>Status</th><th>Enrolled</th></tr></thead>
                    <tbody><?php foreach ($learners as $learner): $progress = max(0, min(100, (int) $learner['progress_percentage'])); ?>
                        <tr>
                            <td><div class="workspace-person"><span class="workspace-avatar"><?php echo sanitize(mb_strtoupper(mb_substr((string) $learner['full_name'], 0, 1))); ?></span><div><strong><?php echo sanitize($learner['full_name']); ?></strong><small><?php echo sanitize($learner['email']); ?></small></div></div></td>
                            <td><strong class="d-block text-dark"><?php echo sanitize($learner['course_title']); ?></strong><small class="text-muted">Last active <?php echo $learner['last_login'] ? formatDate($learner['last_login'], 'd M Y') : 'not yet'; ?></small></td>
                            <td style="min-width:150px"><div class="d-flex justify-content-between small mb-2"><span><?php echo $progress; ?>%</span><span><?php echo (int) $learner['completed_lessons']; ?>/<?php echo (int) $learner['total_lessons']; ?></span></div><div class="workspace-progress"><span style="width:<?php echo $progress; ?>%"></span></div></td>
                            <td><span class="d-block"><?php echo (int) $learner['passed_quizzes']; ?> passed quizzes</span><small class="text-muted"><?php echo (int) $learner['submissions']; ?> assignment submissions</small></td>
                            <td><span class="status-pill <?php echo (int) $learner['is_completed'] === 1 ? 'status-pill--success' : ($progress > 0 ? 'status-pill--info' : 'status-pill--warning'); ?>"><?php echo (int) $learner['is_completed'] === 1 ? 'Completed' : ($progress > 0 ? 'In progress' : 'Not started'); ?></span></td>
                            <td><?php echo formatDate($learner['enrollment_date']); ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-user-group"></i></span><h3>No learners match these filters</h3><p>Try another course, progress state, or search term.</p><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/instructor/learners.php">Clear filters</a></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
