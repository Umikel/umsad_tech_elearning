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
$status = (string) ($_GET['status'] ?? 'all');
$allowedStatuses = ['all', 'in-progress', 'completed'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$db->query('SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed,
                   COALESCE(AVG(progress_percentage), 0) AS average_progress
            FROM student_enrollments
            WHERE student_id = :student_id');
$db->bind(':student_id', $studentId);
$summary = $db->single() ?: ['total' => 0, 'completed' => 0, 'average_progress' => 0];

$query = 'SELECT c.id, c.title, c.slug, c.description, c.category, c.course_image,
                 u.full_name AS instructor_name,
                 se.learning_plan, se.is_approved, se.enrollment_date, se.completion_date, se.progress_percentage, se.is_completed,
                 (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS total_lessons,
                 (SELECT COUNT(*)
                    FROM student_progress sp
                    JOIN course_lessons spl ON spl.id = sp.lesson_id
                   WHERE sp.student_id = :progress_student_id
                     AND spl.course_id = c.id
                     AND sp.is_completed = 1) AS completed_lessons
          FROM student_enrollments se
          JOIN courses c ON c.id = se.course_id
          LEFT JOIN users u ON u.id = c.instructor_id
          WHERE se.student_id = :student_id';

if ($status === 'completed') {
    $query .= ' AND se.is_completed = 1';
} elseif ($status === 'in-progress') {
    $query .= ' AND se.is_approved = 1 AND se.is_completed = 0';
}
if ($search !== '') {
    $query .= ' AND (c.title LIKE :title_search OR c.category LIKE :category_search)';
}
$query .= ' ORDER BY se.is_completed ASC, se.enrollment_date DESC';

$db->query($query);
$db->bind(':progress_student_id', $studentId);
$db->bind(':student_id', $studentId);
if ($search !== '') {
    $db->bind(':title_search', '%' . $search . '%');
    $db->bind(':category_search', '%' . $search . '%');
}
$courses = $db->resultSet();

function umsadCourseImage(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        return $url;
    }
    if (preg_match('#^(?:uploads/|/umsadtech/uploads/)[A-Za-z0-9_./-]+$#', $url) && strpos($url, '..') === false) {
        return $url[0] === '/' ? $url : APP_URL . '/' . $url;
    }
    return null;
}

$pageTitle = 'My Courses';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .library-wrap { margin: 1rem auto 4rem; }
    .library-hero { position: relative; overflow: hidden; padding: clamp(2rem, 5vw, 4rem); border-radius: 26px; color: #fff; background: linear-gradient(135deg, #171934, #323779 65%, #655fe5); }
    .library-hero::after { content: ''; position: absolute; width: 260px; height: 260px; right: -70px; top: -110px; border-radius: 50%; background: rgba(255,255,255,.09); }
    .library-hero > * { position: relative; z-index: 1; }
    .library-hero h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.35rem, 5vw, 4rem); letter-spacing: -.05em; }
    .library-stat { min-width: 125px; padding: 1rem 1.15rem; border: 1px solid rgba(255,255,255,.14); border-radius: 14px; background: rgba(255,255,255,.08); backdrop-filter: blur(8px); }
    .library-stat strong { display: block; font-family: 'Space Grotesk', sans-serif; font-size: 1.5rem; }
    .library-stat span { color: rgba(255,255,255,.65); font-size: .76rem; }
    .library-tools { margin: -1.2rem 1.5rem 2.5rem; padding: 1rem; position: relative; z-index: 2; border: 1px solid #e8e8f2; border-radius: 16px; background: #fff; box-shadow: 0 12px 32px rgba(32,34,73,.09); }
    .library-tools .form-control { border-radius: 10px; border-color: #e0e0eb; }
    .filter-pills { display: flex; gap: .4rem; flex-wrap: wrap; }
    .filter-pills a { padding: .55rem .8rem; border-radius: 9px; color: #646579; font-size: .82rem; font-weight: 700; text-decoration: none; }
    .filter-pills a:hover, .filter-pills a.active { color: #4f53d2; background: #eeeeff; }
    .learning-card { height: 100%; overflow: hidden; border: 1px solid #e8e8f2; border-radius: 20px; background: #fff; box-shadow: 0 10px 30px rgba(35,37,82,.055); transition: transform .25s ease, box-shadow .25s ease; }
    .learning-card:hover { transform: translateY(-5px); box-shadow: 0 18px 38px rgba(35,37,82,.11); }
    .learning-card__media { position: relative; height: 175px; display: grid; place-items: center; overflow: hidden; color: #fff; background: linear-gradient(145deg, #5559dc, #292d68); }
    .learning-card__media img { width: 100%; height: 100%; object-fit: cover; }
    .learning-card__media > i { font-size: 2.5rem; opacity: .65; }
    .learning-card__status { position: absolute; top: .8rem; right: .8rem; padding: .38rem .65rem; border-radius: 999px; color: #fff; background: rgba(18,20,47,.78); font-size: .68rem; font-weight: 800; backdrop-filter: blur(7px); }
    .learning-card__body { padding: 1.35rem; }
    .learning-card h2 { min-height: 2.7rem; font-family: 'Space Grotesk', sans-serif; font-size: 1.12rem; line-height: 1.25; }
    .progress-slim { height: 7px; overflow: hidden; border-radius: 999px; background: #ececf3; }
    .progress-slim span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #5b5fe8, #8c63dd); }
    .library-empty { padding: 4rem 1.5rem; border: 1px dashed #d8d8e7; border-radius: 22px; text-align: center; background: #fff; }
    .library-empty__icon { display: grid; place-items: center; width: 70px; height: 70px; margin: 0 auto 1.2rem; border-radius: 20px; color: #5a5edb; background: #eeeeff; font-size: 1.5rem; }
</style>

<div class="container library-wrap">
    <section class="library-hero">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-4">
            <div>
                <span class="eyebrow text-white">Learning library</span>
                <h1 class="mt-2 mb-2">My courses</h1>
                <p class="mb-0 text-white-50">Pick up where you stopped and keep your momentum moving.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <div class="library-stat"><strong><?php echo (int) $summary['total']; ?></strong><span>Total courses</span></div>
                <div class="library-stat"><strong><?php echo (int) $summary['completed']; ?></strong><span>Completed</span></div>
                <div class="library-stat"><strong><?php echo (int) round((float) $summary['average_progress']); ?>%</strong><span>Average progress</span></div>
            </div>
        </div>
    </section>

    <section class="library-tools">
        <div class="row g-3 align-items-center">
            <div class="col-lg-7">
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="status" value="<?php echo sanitize($status); ?>">
                    <label class="visually-hidden" for="course-search">Search your courses</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input id="course-search" type="search" name="search" maxlength="100" class="form-control border-start-0" value="<?php echo sanitize($search); ?>" placeholder="Search your courses">
                    </div>
                    <button class="btn btn-primary" type="submit">Search</button>
                </form>
            </div>
            <div class="col-lg-5 d-flex justify-content-lg-end">
                <nav class="filter-pills" aria-label="Course status">
                    <?php foreach (['all' => 'All', 'in-progress' => 'In progress', 'completed' => 'Completed'] as $key => $label): ?>
                        <a class="<?php echo $status === $key ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_filter(['status' => $key, 'search' => $search])); ?>"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
    </section>

    <?php if ($courses): ?>
        <div class="row g-4">
            <?php foreach ($courses as $course):
                $progress = max(0, min(100, (int) $course['progress_percentage']));
                $image = umsadCourseImage($course['course_image'] ?? null);
            ?>
                <div class="col-md-6 col-xl-4">
                    <article class="learning-card">
                        <div class="learning-card__media">
                            <?php if ($image): ?><img src="<?php echo sanitize($image); ?>" alt=""><?php else: ?><i class="fas fa-code" aria-hidden="true"></i><?php endif; ?>
                            <span class="learning-card__status"><?php echo !(int) $course['is_approved'] ? 'Awaiting approval' : ((int) $course['is_completed'] === 1 ? 'Completed' : 'In progress'); ?></span>
                        </div>
                        <div class="learning-card__body">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <span class="badge rounded-pill text-bg-light text-primary"><?php echo sanitize($course['category'] ?: 'General'); ?></span>
                                <small class="text-muted"><?php echo (int) $course['completed_lessons']; ?>/<?php echo (int) $course['total_lessons']; ?> lessons</small>
                            </div>
                            <h2><?php echo sanitize($course['title']); ?></h2>
                            <?php if ($course['learning_plan'] === 'sunday_physical'): ?><p class="small text-primary">Online + Sunday physical class<br>Sundays, 10am–11am · Venue to be announced</p><?php endif; ?>
                            <p class="text-muted small mb-3"><i class="fas fa-chalkboard-user me-1"></i><?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech instructor'); ?></p>
                            <div class="d-flex justify-content-between small mb-2"><span class="text-muted">Progress</span><strong><?php echo $progress; ?>%</strong></div>
                            <div class="progress-slim mb-4" role="progressbar" aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo $progress; ?>%"></span></div>
                            <div class="d-flex gap-2">
                                <?php if ((int) $course['is_approved'] === 1): ?>
                                <a class="btn btn-primary flex-grow-1" href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo (int) $course['id']; ?>"><?php echo $progress > 0 ? 'Continue learning' : 'Start course'; ?> <i class="fas fa-arrow-right ms-1"></i></a>
                                <?php else: ?><span class="status-pill status-pill--warning" role="status">Awaiting admin approval</span><?php endif; ?>
                                <?php if ((int) $course['is_approved'] === 1 && (int) $course['is_completed'] === 1): ?>
                                    <a class="btn btn-outline-success" href="<?php echo APP_URL; ?>/student/certificate.php?course_id=<?php echo (int) $course['id']; ?>" aria-label="View completion record"><i class="fas fa-award"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <section class="library-empty">
            <span class="library-empty__icon"><i class="fas fa-book-open"></i></span>
            <h2 class="h4"><?php echo ($search !== '' || $status !== 'all') ? 'No matching courses' : 'Your learning library is ready'; ?></h2>
            <p class="text-muted"><?php echo ($search !== '' || $status !== 'all') ? 'Try another search or status filter.' : 'Enroll in a course and it will appear here.'; ?></p>
            <?php if ($search !== '' || $status !== 'all'): ?>
                <a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/student/my-courses.php">Clear filters</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?php echo APP_URL; ?>/courses.php">Explore courses <i class="fas fa-arrow-right ms-2"></i></a>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
