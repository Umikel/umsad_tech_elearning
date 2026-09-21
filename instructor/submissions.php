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
$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$assignmentId = $assignmentId ? (int) $assignmentId : 0;
$status = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'pending', 'graded'], true)) {
    $status = 'all';
}
$errors = [];

function instructorSubmission(Database $db, int $submissionId, int $instructorId): ?array
{
    $db->query('SELECT ass.*, a.max_score, a.title AS assignment_title, cl.course_id
                FROM assignment_submissions ass
                JOIN assignments a ON a.id = ass.assignment_id
                JOIN course_lessons cl ON cl.id = a.lesson_id
                JOIN courses c ON c.id = cl.course_id
                WHERE ass.id = :submission_id AND c.instructor_id = :instructor_id
                LIMIT 1');
    $db->bind(':submission_id', $submissionId);
    $db->bind(':instructor_id', $instructorId);
    $row = $db->single();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') !== 'grade_submission') {
        $errors['form'] = 'That grading action is not supported.';
    } else {
        $submissionId = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $submission = $submissionId ? instructorSubmission($db, (int) $submissionId, $instructorId) : null;
        $score = filter_var($_POST['score'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $feedback = trim((string) ($_POST['feedback'] ?? ''));
        if (!$submission) {
            $errors['form'] = 'That submission is unavailable.';
        } elseif ($score === false || (int) $score > (int) $submission['max_score']) {
            $errors['form'] = 'Use a score between 0 and ' . (int) $submission['max_score'] . '.';
        } elseif (mb_strlen($feedback) > 10000) {
            $errors['form'] = 'Keep feedback under 10,000 characters.';
        } else {
            $db->query('UPDATE assignment_submissions
                        SET is_graded = 1, score = :score, feedback = :feedback, graded_at = NOW()
                        WHERE id = :submission_id');
            $db->bind(':score', (int) $score);
            $db->bind(':feedback', $feedback === '' ? null : $feedback);
            $db->bind(':submission_id', (int) $submissionId);
            $db->execute();
            $_SESSION['message'] = 'Submission graded and feedback saved.';
            $return = array_filter(['course_id' => $courseId ?: null, 'assignment_id' => $assignmentId ?: null, 'status' => $status !== 'all' ? $status : null]);
            redirect('/instructor/submissions.php' . ($return ? '?' . http_build_query($return) : ''), 303);
        }
    }
}

$db->query('SELECT id, title FROM courses WHERE instructor_id = :instructor_id ORDER BY title');
$db->bind(':instructor_id', $instructorId);
$courses = $db->resultSet();
$courseIds = array_map(static fn(array $course): int => (int) $course['id'], $courses);
if ($courseId > 0 && !in_array($courseId, $courseIds, true)) {
    $courseId = 0;
}

$db->query('SELECT a.id, a.title, c.id AS course_id, c.title AS course_title
            FROM assignments a
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            WHERE c.instructor_id = :instructor_id' . ($courseId > 0 ? ' AND c.id = :course_id' : '') . '
            ORDER BY c.title, a.title');
$db->bind(':instructor_id', $instructorId);
if ($courseId > 0) {
    $db->bind(':course_id', $courseId);
}
$assignments = $db->resultSet();
$assignmentIds = array_map(static fn(array $assignment): int => (int) $assignment['id'], $assignments);
if ($assignmentId > 0 && !in_array($assignmentId, $assignmentIds, true)) {
    $assignmentId = 0;
}

$where = ['c.instructor_id = :instructor_id'];
if ($courseId > 0) {
    $where[] = 'c.id = :course_id';
}
if ($assignmentId > 0) {
    $where[] = 'a.id = :assignment_id';
}
if ($status === 'pending') {
    $where[] = 'ass.is_graded = 0';
} elseif ($status === 'graded') {
    $where[] = 'ass.is_graded = 1';
}

$db->query('SELECT ass.*, a.title AS assignment_title, a.description AS assignment_description,
                   a.max_score, a.due_date, cl.title AS lesson_title,
                   c.id AS course_id, c.title AS course_title,
                   u.full_name, u.email, u.profile_image
            FROM assignment_submissions ass
            JOIN assignments a ON a.id = ass.assignment_id
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            JOIN users u ON u.id = ass.student_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ass.is_graded ASC, ass.submitted_at DESC');
$db->bind(':instructor_id', $instructorId);
if ($courseId > 0) {
    $db->bind(':course_id', $courseId);
}
if ($assignmentId > 0) {
    $db->bind(':assignment_id', $assignmentId);
}
$submissions = $db->resultSet();

$db->query('SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN ass.is_graded = 0 THEN 1 ELSE 0 END), 0) AS pending,
                   COALESCE(SUM(CASE WHEN ass.is_graded = 1 THEN 1 ELSE 0 END), 0) AS graded,
                   COALESCE(AVG(CASE WHEN ass.is_graded = 1 AND a.max_score > 0 THEN (ass.score / a.max_score) * 100 END), 0) AS average_score
            FROM assignment_submissions ass
            JOIN assignments a ON a.id = ass.assignment_id
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            WHERE c.instructor_id = :instructor_id');
$db->bind(':instructor_id', $instructorId);
$metrics = $db->single() ?: [];

$pageTitle = 'Submissions';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page">
    <header class="workspace-page__header">
        <div><span class="workspace-eyebrow">Feedback queue</span><h1 class="workspace-title">Submissions</h1><p class="workspace-subtitle">Review practical work, score it consistently, and give learners feedback they can act on.</p></div>
        <div class="workspace-actions"><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/instructor/assessments.php<?php echo $courseId ? '?course_id=' . $courseId : ''; ?>"><i class="fas fa-plus me-2"></i>Create assessment</a></div>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-inbox"></i></span></div><strong><?php echo number_format((int) ($metrics['total'] ?? 0)); ?></strong><p>Total submissions</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-hourglass-half"></i></span></div><strong><?php echo number_format((int) ($metrics['pending'] ?? 0)); ?></strong><p>Awaiting feedback</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-circle-check"></i></span></div><strong><?php echo number_format((int) ($metrics['graded'] ?? 0)); ?></strong><p>Graded</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-chart-simple"></i></span></div><strong><?php echo (int) round((float) ($metrics['average_score'] ?? 0)); ?>%</strong><p>Average graded score</p></article>
    </div>

    <section class="workspace-toolbar">
        <form method="get">
            <input type="hidden" name="status" value="<?php echo sanitize($status); ?>">
            <select class="form-select" name="course_id" aria-label="Filter by course" onchange="this.form.submit()"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?php echo (int) $course['id']; ?>" <?php echo $courseId === (int) $course['id'] ? 'selected' : ''; ?>><?php echo sanitize($course['title']); ?></option><?php endforeach; ?></select>
        </form>
        <form method="get">
            <input type="hidden" name="status" value="<?php echo sanitize($status); ?>"><?php if ($courseId): ?><input type="hidden" name="course_id" value="<?php echo $courseId; ?>"><?php endif; ?>
            <select class="form-select" name="assignment_id" aria-label="Filter by assignment" onchange="this.form.submit()"><option value="">All assignments</option><?php foreach ($assignments as $assignment): ?><option value="<?php echo (int) $assignment['id']; ?>" <?php echo $assignmentId === (int) $assignment['id'] ? 'selected' : ''; ?>><?php echo sanitize($assignment['title']); ?></option><?php endforeach; ?></select>
        </form>
        <nav class="workspace-filter-tabs" aria-label="Grading status"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'graded' => 'Graded'] as $key => $label): $params = array_filter(['status' => $key, 'course_id' => $courseId ?: null, 'assignment_id' => $assignmentId ?: null]); ?><a class="<?php echo $status === $key ? 'is-active' : ''; ?>" href="?<?php echo sanitize(http_build_query($params)); ?>"><?php echo $label; ?></a><?php endforeach; ?></nav>
    </section>

    <?php if ($submissions): ?>
        <div class="builder-stack">
            <?php foreach ($submissions as $submission): ?>
                <article class="workspace-panel">
                    <div class="workspace-panel__header">
                        <div class="workspace-person"><span class="workspace-avatar"><?php echo sanitize(mb_strtoupper(mb_substr((string) $submission['full_name'], 0, 1))); ?></span><div><strong><?php echo sanitize($submission['full_name']); ?></strong><small><?php echo sanitize($submission['email']); ?></small></div></div>
                        <div class="text-end"><span class="status-pill <?php echo (int) $submission['is_graded'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $submission['is_graded'] === 1 ? 'Graded' : 'Needs feedback'; ?></span><small class="d-block mt-2 text-muted">Submitted <?php echo formatDate($submission['submitted_at'], 'd M Y, H:i'); ?></small></div>
                    </div>
                    <div class="workspace-panel__body">
                        <div class="workspace-grid">
                            <div>
                                <span class="workspace-eyebrow"><?php echo sanitize($submission['course_title']); ?> · <?php echo sanitize($submission['lesson_title']); ?></span>
                                <h2 class="h5 mb-3"><?php echo sanitize($submission['assignment_title']); ?></h2>
                                <?php if (trim((string) $submission['submission_text']) !== ''): ?><div class="p-3 rounded-3 bg-light text-secondary" style="white-space:pre-wrap;line-height:1.7"><?php echo sanitize($submission['submission_text']); ?></div><?php else: ?><p class="text-muted">No written response was included.</p><?php endif; ?>
                                <?php if ($submission['submission_file']): ?><a class="btn btn-outline-secondary btn-sm mt-3" href="<?php echo APP_URL . '/' . sanitize(ltrim((string) $submission['submission_file'], '/')); ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip me-2"></i>Open attachment</a><?php endif; ?>
                            </div>
                            <form method="post">
                                <?php echo csrfField(); ?><input type="hidden" name="action" value="grade_submission"><input type="hidden" name="submission_id" value="<?php echo (int) $submission['id']; ?>">
                                <div class="mb-3"><label class="form-label" for="score-<?php echo (int) $submission['id']; ?>">Score (out of <?php echo (int) $submission['max_score']; ?>)</label><input id="score-<?php echo (int) $submission['id']; ?>" class="form-control" name="score" type="number" min="0" max="<?php echo (int) $submission['max_score']; ?>" value="<?php echo $submission['score'] !== null ? (int) $submission['score'] : ''; ?>" required></div>
                                <div class="mb-3"><label class="form-label" for="feedback-<?php echo (int) $submission['id']; ?>">Feedback</label><textarea id="feedback-<?php echo (int) $submission['id']; ?>" class="form-control" name="feedback" rows="5" maxlength="10000" placeholder="Highlight what worked and the clearest next improvement."><?php echo sanitize($submission['feedback']); ?></textarea></div>
                                <button class="btn btn-primary w-100" type="submit"><i class="fas fa-check me-2"></i><?php echo (int) $submission['is_graded'] === 1 ? 'Update grade' : 'Save grade'; ?></button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <section class="workspace-panel"><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-inbox"></i></span><h2>No submissions match these filters</h2><p>New learner work will appear here as soon as it is submitted.</p><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/instructor/submissions.php">Clear filters</a></div></section>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
