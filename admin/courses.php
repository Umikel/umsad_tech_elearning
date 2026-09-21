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
if (!$auth->isAdmin()) {
    redirect('/' . $auth->getDashboardPath());
}
$errors = [];

function adminCourseRecord(Database $db, int $courseId): ?array
{
    $db->query('SELECT c.*,
                       (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS lesson_count
                FROM courses c WHERE c.id = :id LIMIT 1');
    $db->bind(':id', $courseId);
    $row = $db->single();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'toggle_publish') {
        $courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $course = $courseId ? adminCourseRecord($db, (int) $courseId) : null;
        if (!$course) {
            $errors['form'] = 'That course is unavailable.';
        } elseif ((int) $course['is_published'] === 0 && (int) $course['lesson_count'] < 1) {
            $errors['form'] = 'A course needs at least one lesson before it can be published.';
        } else {
            $next = (int) $course['is_published'] === 1 ? 0 : 1;
            $db->query('UPDATE courses SET is_published = :is_published WHERE id = :id');
            $db->bind(':is_published', $next);
            $db->bind(':id', (int) $courseId);
            $db->execute();
            $_SESSION['message'] = $next === 1 ? 'Course published.' : 'Course moved to draft.';
            redirect('/admin/courses.php', 303);
        }
    } elseif ($action === 'reassign_instructor') {
        $courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $instructorId = filter_input(INPUT_POST, 'instructor_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $course = $courseId ? adminCourseRecord($db, (int) $courseId) : null;
        $db->query("SELECT id FROM users WHERE id = :id AND user_type = 'instructor' AND is_active = 1 LIMIT 1");
        $db->bind(':id', (int) $instructorId);
        $instructor = $db->single();
        if (!$course || !$instructor) {
            $errors['form'] = 'Choose a valid course and active instructor.';
        } else {
            $db->query('UPDATE courses SET instructor_id = :instructor_id WHERE id = :id');
            $db->bind(':instructor_id', (int) $instructorId);
            $db->bind(':id', (int) $courseId);
            $db->execute();
            $_SESSION['message'] = 'Course ownership reassigned.';
            redirect('/admin/courses.php', 303);
        }
    } else {
        $errors['form'] = 'That course-management action is not supported.';
    }
}

$status = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'published', 'draft'], true)) {
    $status = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$db->query("SELECT id, full_name FROM users WHERE user_type = 'instructor' AND is_active = 1 ORDER BY full_name");
$instructors = $db->resultSet();

$where = ['1 = 1'];
if ($status === 'published') {
    $where[] = 'c.is_published = 1';
} elseif ($status === 'draft') {
    $where[] = 'c.is_published = 0';
}
if ($search !== '') {
    $where[] = '(c.title LIKE :search_title OR c.category LIKE :search_category OR u.full_name LIKE :search_instructor)';
}
$db->query('SELECT c.*, u.full_name AS instructor_name,
                   (SELECT COUNT(*) FROM course_modules cm WHERE cm.course_id = c.id) AS module_count,
                   (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS lesson_count,
                   (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) AS learner_count,
                   (SELECT COUNT(*) FROM course_reviews cr WHERE cr.course_id = c.id AND cr.is_approved = 1) AS review_count,
                   (SELECT COALESCE(AVG(cr2.rating), 0) FROM course_reviews cr2 WHERE cr2.course_id = c.id AND cr2.is_approved = 1) AS rating,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.course_id = c.id AND p.status = \'completed\') AS revenue
            FROM courses c
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.updated_at DESC');
if ($search !== '') {
    $term = '%' . $search . '%';
    $db->bind(':search_title', $term);
    $db->bind(':search_category', $term);
    $db->bind(':search_instructor', $term);
}
$courses = $db->resultSet();

$db->query('SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END), 0) AS published,
                   (SELECT COUNT(*) FROM student_enrollments) AS enrollments,
                   (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'completed\') AS revenue
            FROM courses');
$metrics = $db->single() ?: [];

$pageTitle = 'Course Management';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page">
    <header class="workspace-page__header"><div><span class="workspace-eyebrow">Catalog operations</span><h1 class="workspace-title">Courses</h1><p class="workspace-subtitle">Moderate catalog visibility, assign course ownership, and understand the health of every learning product.</p></div><div class="workspace-actions"><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/courses.php" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square me-2"></i>Public catalog</a></div></header>
    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-layer-group"></i></span></div><strong><?php echo number_format((int) ($metrics['total'] ?? 0)); ?></strong><p>Total courses</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-circle-check"></i></span></div><strong><?php echo number_format((int) ($metrics['published'] ?? 0)); ?></strong><p>Published</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-user-graduate"></i></span></div><strong><?php echo number_format((int) ($metrics['enrollments'] ?? 0)); ?></strong><p>Enrollments</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-naira-sign"></i></span></div><strong><?php echo formatCurrency((float) ($metrics['revenue'] ?? 0)); ?></strong><p>Completed revenue</p></article>
    </div>

    <section class="workspace-toolbar">
        <form method="get"><input type="hidden" name="status" value="<?php echo sanitize($status); ?>"><input class="form-control" name="search" type="search" maxlength="100" value="<?php echo sanitize($search); ?>" placeholder="Search course, category, instructor"><button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button></form>
        <nav class="workspace-filter-tabs" aria-label="Publication status"><?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts'] as $key => $label): ?><a class="<?php echo $status === $key ? 'is-active' : ''; ?>" href="?<?php echo sanitize(http_build_query(array_filter(['status' => $key, 'search' => $search ?: null]))); ?>"><?php echo $label; ?></a><?php endforeach; ?></nav>
    </section>

    <?php if ($courses): ?><div class="workspace-card-grid">
        <?php foreach ($courses as $course): ?>
            <article class="workspace-card">
                <div class="d-flex align-items-center justify-content-between gap-2"><span class="status-pill <?php echo (int) $course['is_published'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $course['is_published'] === 1 ? 'Published' : 'Draft'; ?></span><span class="small text-muted"><?php echo sanitize($course['category'] ?: 'General'); ?></span></div>
                <h2><?php echo sanitize($course['title']); ?></h2><p><?php echo sanitize(mb_strimwidth((string) $course['description'], 0, 125, '…')); ?></p>
                <div class="row g-2 my-3 text-center"><div class="col-4"><div class="bg-light rounded-3 p-2"><strong class="d-block h6 mb-0"><?php echo (int) $course['lesson_count']; ?></strong><small class="text-muted">Lessons</small></div></div><div class="col-4"><div class="bg-light rounded-3 p-2"><strong class="d-block h6 mb-0"><?php echo (int) $course['learner_count']; ?></strong><small class="text-muted">Learners</small></div></div><div class="col-4"><div class="bg-light rounded-3 p-2"><strong class="d-block h6 mb-0"><?php echo number_format((float) $course['rating'], 1); ?></strong><small class="text-muted">Rating</small></div></div></div>
                <div class="small text-muted mb-3">Owner</div>
                <form method="post" class="d-flex gap-2 mb-3"><?php echo csrfField(); ?><input type="hidden" name="action" value="reassign_instructor"><input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>"><select class="form-select form-select-sm" name="instructor_id" required><?php foreach ($instructors as $instructor): ?><option value="<?php echo (int) $instructor['id']; ?>" <?php echo (int) $course['instructor_id'] === (int) $instructor['id'] ? 'selected' : ''; ?>><?php echo sanitize($instructor['full_name']); ?></option><?php endforeach; ?></select><button class="btn btn-outline-secondary btn-sm" type="submit" title="Save owner"><i class="fas fa-check"></i></button></form>
                <div class="workspace-card__meta"><span><?php echo formatCurrency((float) $course['revenue']); ?> revenue</span><div class="ms-auto d-flex gap-2"><?php if ((int) $course['is_published'] === 1): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>" target="_blank" rel="noopener">View</a><?php endif; ?><form method="post" data-confirm="<?php echo (int) $course['is_published'] === 1 ? 'Move this course back to draft?' : 'Publish this course?'; ?>"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_publish"><input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>"><button class="btn btn-sm <?php echo (int) $course['is_published'] === 1 ? 'btn-outline-danger' : 'btn-primary'; ?>" type="submit"><?php echo (int) $course['is_published'] === 1 ? 'Unpublish' : 'Publish'; ?></button></form></div></div>
            </article>
        <?php endforeach; ?>
    </div><?php else: ?><section class="workspace-panel"><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-layer-group"></i></span><h2>No courses match these filters</h2><p>Try another status or search term.</p><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/admin/courses.php">Clear filters</a></div></section><?php endif; ?>
</div>
<script>document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => { if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault(); }));</script>
<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
