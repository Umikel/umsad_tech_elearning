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
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'published', 'draft'], true)) {
    $status = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$query = 'SELECT c.id, c.title, c.description, c.category, c.price, c.discount_price,
                 c.is_published, c.created_at, c.updated_at,
                 (SELECT COUNT(*) FROM course_modules cm WHERE cm.course_id = c.id) AS module_count,
                 (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS lesson_count,
                 (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) AS learner_count,
                 (SELECT COALESCE(AVG(se2.progress_percentage), 0) FROM student_enrollments se2 WHERE se2.course_id = c.id) AS average_progress
          FROM courses c
          WHERE c.instructor_id = :instructor_id';
if ($status === 'published') {
    $query .= ' AND c.is_published = 1';
} elseif ($status === 'draft') {
    $query .= ' AND c.is_published = 0';
}
if ($search !== '') {
    $query .= ' AND (c.title LIKE :title_search OR c.category LIKE :category_search)';
}
$query .= ' ORDER BY c.updated_at DESC';

$db->query($query);
$db->bind(':instructor_id', $instructorId);
if ($search !== '') {
    $db->bind(':title_search', '%' . $search . '%');
    $db->bind(':category_search', '%' . $search . '%');
}
$courses = $db->resultSet();

$pageTitle = 'Instructor Courses';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .manage-wrap { margin: 1rem auto 4rem; }
    .manage-heading h1 { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.045em; }
    .manage-tools { padding: 1rem; border: 1px solid #e7e7f1; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(35,37,82,.055); }
    .manage-filter { display: flex; gap: .4rem; flex-wrap: wrap; }
    .manage-filter a { padding: .55rem .8rem; border-radius: 9px; color: #666779; font-size: .82rem; font-weight: 700; text-decoration: none; }
    .manage-filter a.active, .manage-filter a:hover { color: #5054d1; background: #eeeeff; }
    .manage-card { height: 100%; padding: 1.5rem; border: 1px solid #e7e7f1; border-radius: 19px; background: #fff; box-shadow: 0 10px 28px rgba(35,37,82,.05); transition: transform .22s ease, box-shadow .22s ease; }
    .manage-card:hover { transform: translateY(-4px); box-shadow: 0 17px 36px rgba(35,37,82,.1); }
    .manage-card h2 { min-height: 2.8rem; font-family: 'Space Grotesk', sans-serif; font-size: 1.15rem; line-height: 1.3; }
    .status-dot { width: 8px; height: 8px; display: inline-block; margin-right: .35rem; border-radius: 50%; background: #a1a3b0; }
    .status-dot.published { background: #27a76b; box-shadow: 0 0 0 4px #e6f6ee; }
    .course-data { display: grid; grid-template-columns: repeat(3, 1fr); gap: .65rem; padding: 1rem 0; border-top: 1px solid #eeeef4; border-bottom: 1px solid #eeeef4; }
    .course-data strong { display: block; color: #2c2e47; }
    .course-data span { color: #8a8b9b; font-size: .68rem; }
    .course-progress { height: 6px; overflow: hidden; border-radius: 999px; background: #ececf3; }
    .course-progress span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #5b5fe8, #8b64de); }
    .manage-empty { padding: 4rem 1.5rem; border: 1px dashed #d8d8e7; border-radius: 20px; text-align: center; background: #fff; }
</style>

<div class="container manage-wrap">
    <header class="manage-heading d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><span class="text-primary fw-bold small text-uppercase">Course studio</span><h1 class="mt-2 mb-1">My courses</h1><p class="text-muted mb-0">Create, publish, and improve the learning experiences you own.</p></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/instructor/dashboard.php"><i class="fas fa-chart-line me-2"></i>Dashboard</a><a class="btn btn-primary" href="<?php echo APP_URL; ?>/instructor/course-edit.php"><i class="fas fa-plus me-2"></i>Create course</a></div>
    </header>

    <section class="manage-tools mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-lg-7">
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="status" value="<?php echo sanitize($status); ?>">
                    <input type="search" name="search" maxlength="100" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Search by title or category" aria-label="Search courses">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i><span class="visually-hidden">Search</span></button>
                </form>
            </div>
            <div class="col-lg-5 d-flex justify-content-lg-end">
                <nav class="manage-filter" aria-label="Publication status">
                    <?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts'] as $key => $label): ?>
                        <a class="<?php echo $status === $key ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_filter(['status' => $key, 'search' => $search])); ?>"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
    </section>

    <?php if ($courses): ?>
        <div class="row g-4">
            <?php foreach ($courses as $course): $avg = max(0, min(100, (int) round((float) $course['average_progress']))); ?>
                <div class="col-md-6 col-xl-4">
                    <article class="manage-card">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                            <span class="small fw-bold"><span class="status-dot <?php echo (int) $course['is_published'] === 1 ? 'published' : ''; ?>"></span><?php echo (int) $course['is_published'] === 1 ? 'Published' : 'Draft'; ?></span>
                            <span class="badge rounded-pill text-bg-light text-primary"><?php echo sanitize($course['category'] ?: 'General'); ?></span>
                        </div>
                        <h2><?php echo sanitize($course['title']); ?></h2>
                        <p class="text-muted small" style="min-height:2.6rem"><?php echo sanitize(mb_strimwidth((string) $course['description'], 0, 95, '…')); ?></p>
                        <div class="course-data my-3 text-center">
                            <div><strong><?php echo (int) $course['module_count']; ?></strong><span>Modules</span></div>
                            <div><strong><?php echo (int) $course['lesson_count']; ?></strong><span>Lessons</span></div>
                            <div><strong><?php echo (int) $course['learner_count']; ?></strong><span>Learners</span></div>
                        </div>
                        <div class="d-flex justify-content-between small mb-2"><span class="text-muted">Average progress</span><strong><?php echo $avg; ?>%</strong></div>
                        <div class="course-progress mb-4"><span style="width:<?php echo $avg; ?>%"></span></div>
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <small class="text-muted">Updated <?php echo formatDate($course['updated_at']); ?></small>
                            <div class="d-flex gap-2"><a href="<?php echo APP_URL; ?>/instructor/course-edit.php?id=<?php echo (int) $course['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-pen me-1"></i>Edit</a><?php if ((int) $course['is_published'] === 1): ?><a href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>" class="btn btn-outline-primary btn-sm">Preview</a><?php endif; ?></div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <section class="manage-empty"><i class="fas fa-layer-group fa-3x text-primary mb-3"></i><h2 class="h4">No courses found</h2><p class="text-muted mb-3"><?php echo ($search !== '' || $status !== 'all') ? 'Try clearing your search or changing the status filter.' : 'Create your first course, then add modules, lessons, and assessments.'; ?></p><?php if ($search !== '' || $status !== 'all'): ?><a href="<?php echo APP_URL; ?>/instructor/courses.php" class="btn btn-outline-primary">Clear filters</a><?php else: ?><a href="<?php echo APP_URL; ?>/instructor/course-edit.php" class="btn btn-primary">Create course</a><?php endif; ?></section>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
