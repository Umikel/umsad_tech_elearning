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

$db->query('SELECT COUNT(*) AS total_courses,
                   COALESCE(SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END), 0) AS published_courses
            FROM courses WHERE instructor_id = :instructor_id');
$db->bind(':instructor_id', $instructorId);
$courseStats = $db->single() ?: ['total_courses' => 0, 'published_courses' => 0];

$db->query('SELECT COUNT(se.id) AS total_enrollments,
                   COALESCE(SUM(CASE WHEN se.is_completed = 1 THEN 1 ELSE 0 END), 0) AS completions,
                   COALESCE(AVG(se.progress_percentage), 0) AS average_progress
            FROM student_enrollments se
            JOIN courses c ON c.id = se.course_id
            WHERE c.instructor_id = :instructor_id');
$db->bind(':instructor_id', $instructorId);
$learnerStats = $db->single() ?: ['total_enrollments' => 0, 'completions' => 0, 'average_progress' => 0];

$db->query("SELECT COALESCE(SUM(p.amount), 0) AS completed_revenue
            FROM payments p
            JOIN courses c ON c.id = p.course_id
            WHERE c.instructor_id = :instructor_id AND p.status = 'completed' AND p.currency = 'NGN'");
$db->bind(':instructor_id', $instructorId);
$revenue = (float) (($db->single()['completed_revenue'] ?? 0));

$db->query('SELECT c.id, c.title, c.category, c.is_published, c.updated_at,
                   (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) AS lesson_count,
                   (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) AS learner_count,
                   (SELECT COALESCE(AVG(se2.progress_percentage), 0) FROM student_enrollments se2 WHERE se2.course_id = c.id) AS average_progress
            FROM courses c
            WHERE c.instructor_id = :instructor_id
            ORDER BY c.updated_at DESC
            LIMIT 6');
$db->bind(':instructor_id', $instructorId);
$recentCourses = $db->resultSet();

$db->query('SELECT COUNT(*) AS pending_submissions
            FROM assignment_submissions ass
            JOIN assignments a ON a.id = ass.assignment_id
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            WHERE c.instructor_id = :instructor_id AND ass.is_graded = 0');
$db->bind(':instructor_id', $instructorId);
$pendingSubmissions = (int) ($db->single()['pending_submissions'] ?? 0);

$dashboardRole = 'instructor';
$dashboardDays = isset($_GET['days']) && $_GET['days'] === '30' ? 30 : 7;
require_once dirname(__DIR__) . '/includes/dashboard-insights.php';
$insights = dashboardInsights($db, $dashboardRole, (int) $auth->getUserId(), $dashboardDays);

$pageTitle = 'Instructor Dashboard';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .instructor-wrap { margin: 1rem auto 4rem; }
    .dashboard-welcome { position: relative; overflow: hidden; padding: clamp(2rem, 5vw, 4rem); border-radius: 26px; color: #fff; background: linear-gradient(135deg, #171934, #303575 65%, #625ee2); }
    .dashboard-welcome::after { content: ''; position: absolute; width: 280px; height: 280px; right: -95px; top: -120px; border-radius: 50%; background: rgba(255,255,255,.1); }
    .dashboard-welcome > * { position: relative; z-index: 1; }
    .dashboard-welcome h1 { max-width: 720px; font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.2rem, 5vw, 4rem); letter-spacing: -.055em; }
    .metric-card { height: 100%; padding: 1.35rem; border: 1px solid #e7e7f1; border-radius: 18px; background: #fff; box-shadow: 0 10px 28px rgba(35,37,82,.055); }
    .metric-icon { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 13px; color: #565add; background: #ededff; }
    .metric-card strong { display: block; margin-top: 1.2rem; font-family: 'Space Grotesk', sans-serif; font-size: 1.8rem; color: #272942; }
    .metric-card span { color: #7b7c8e; font-size: .82rem; }
    .dashboard-panel { padding: clamp(1.25rem, 3vw, 2rem); border: 1px solid #e7e7f1; border-radius: 20px; background: #fff; box-shadow: 0 10px 30px rgba(35,37,82,.05); }
    .dashboard-panel h2 { font-family: 'Space Grotesk', sans-serif; font-size: 1.35rem; letter-spacing: -.035em; }
    .course-row { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 1rem; align-items: center; padding: 1rem 0; border-bottom: 1px solid #eeeef4; }
    .course-row:last-child { border-bottom: 0; }
    .course-row h3 { font-size: .95rem; }
    .course-row small { color: #898a9b; }
    .health-ring { --value: 0; width: 54px; height: 54px; display: grid; place-items: center; border-radius: 50%; background: conic-gradient(#5b5fe8 calc(var(--value) * 1%), #ececf3 0); }
    .health-ring::before { content: attr(data-label); width: 42px; height: 42px; display: grid; place-items: center; border-radius: 50%; background: #fff; font-size: .68rem; font-weight: 800; }
    @media (max-width: 575.98px) { .course-row { grid-template-columns: 1fr auto; } .course-row__lessons { display: none; } }
</style>

<div class="container instructor-wrap">
    <section class="dashboard-welcome mb-4">
        <span class="eyebrow text-white">Instructor workspace</span>
        <h1 class="mt-3 mb-3">Welcome back, <?php echo sanitize($currentUser['full_name']); ?>.</h1>
        <p class="text-white-50 mb-4">A clear view of your courses, learners, and the progress happening across your classroom.</p>
        <div class="d-flex flex-wrap gap-2"><a href="<?php echo APP_URL; ?>/instructor/course-edit.php" class="btn btn-light"><i class="fas fa-plus me-2"></i>Create course</a><a href="<?php echo APP_URL; ?>/instructor/submissions.php?status=pending" class="btn btn-outline-light">Review <?php echo $pendingSubmissions; ?> submission<?php echo $pendingSubmissions === 1 ? '' : 's'; ?></a></div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><article class="metric-card"><span class="metric-icon"><i class="fas fa-layer-group"></i></span><strong><?php echo (int) $courseStats['total_courses']; ?></strong><span>Total courses</span></article></div>
        <div class="col-sm-6 col-xl-3"><article class="metric-card"><span class="metric-icon"><i class="fas fa-users"></i></span><strong><?php echo (int) $learnerStats['total_enrollments']; ?></strong><span>Total enrollments</span></article></div>
        <div class="col-sm-6 col-xl-3"><article class="metric-card"><span class="metric-icon"><i class="fas fa-graduation-cap"></i></span><strong><?php echo (int) $learnerStats['completions']; ?></strong><span>Course completions</span></article></div>
        <div class="col-sm-6 col-xl-3"><article class="metric-card"><span class="metric-icon"><i class="fas fa-wallet"></i></span><strong><?php echo formatCurrency($revenue); ?></strong><span>Completed payments · NGN</span></article></div>
    </div>

    <?php require dirname(__DIR__) . '/templates/dashboard-insights.php'; ?>

    <section class="dashboard-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div><h2 class="mb-1">Recently updated courses</h2><p class="text-muted small mb-0">Progress and enrollment at a glance.</p></div>
            <a href="<?php echo APP_URL; ?>/instructor/courses.php" class="text-link">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if ($recentCourses): ?>
            <?php foreach ($recentCourses as $course): $avg = max(0, min(100, (int) round((float) $course['average_progress']))); ?>
                <article class="course-row">
                    <div><h3 class="mb-1"><?php echo sanitize($course['title']); ?></h3><small><?php echo sanitize($course['category'] ?: 'General'); ?> · Updated <?php echo formatDate($course['updated_at']); ?></small></div>
                    <div class="course-row__lessons text-end"><strong class="d-block mt-0 fs-6"><?php echo (int) $course['learner_count']; ?></strong><small>Learners · <?php echo (int) $course['lesson_count']; ?> lessons</small></div>
                    <a href="<?php echo APP_URL; ?>/instructor/course-edit.php?id=<?php echo (int) $course['id']; ?>" class="health-ring text-decoration-none text-dark" style="--value:<?php echo $avg; ?>" data-label="<?php echo $avg; ?>%" aria-label="Edit course; average learner progress <?php echo $avg; ?> percent"></a>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="py-5 text-center"><i class="fas fa-chalkboard fa-3x text-primary mb-3"></i><h3 class="h5">Create your first course</h3><p class="text-muted mb-3">Build the curriculum, add assessments, then publish it to learners.</p><a class="btn btn-primary" href="<?php echo APP_URL; ?>/instructor/course-edit.php">Create course</a></div>
        <?php endif; ?>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
