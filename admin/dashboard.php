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

$db->query("SELECT
                (SELECT COUNT(*) FROM users WHERE is_active = 1) AS active_users,
                (SELECT COUNT(*) FROM users WHERE user_type = 'student' AND is_active = 1) AS active_students,
                (SELECT COUNT(*) FROM courses) AS total_courses,
                (SELECT COUNT(*) FROM courses WHERE is_published = 1) AS published_courses,
                (SELECT COUNT(*) FROM student_enrollments) AS total_enrollments,
                (SELECT COUNT(*) FROM student_enrollments WHERE is_completed = 1) AS completions,
                (SELECT COUNT(*) FROM contact_messages WHERE is_read = 0) AS unread_messages,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND currency = 'NGN') AS completed_revenue");
$metrics = $db->single() ?: [];

$db->query('SELECT id, full_name, email, user_type, is_active, created_at FROM users ORDER BY created_at DESC LIMIT 6');
$recentUsers = $db->resultSet();

$db->query('SELECT p.id, p.amount, p.currency, p.status, p.created_at, p.paid_at,
                   u.full_name AS student_name, c.title AS course_title
            FROM payments p
            JOIN users u ON u.id = p.student_id
            JOIN courses c ON c.id = p.course_id
            ORDER BY p.created_at DESC
            LIMIT 6');
$recentPayments = $db->resultSet();

$db->query('SELECT c.id, c.title, c.is_published, c.updated_at, u.full_name AS instructor_name,
                   (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) AS learner_count
            FROM courses c
            LEFT JOIN users u ON u.id = c.instructor_id
            ORDER BY c.updated_at DESC
            LIMIT 5');
$recentCourses = $db->resultSet();

$dashboardRole = 'admin';
$dashboardDays = isset($_GET['days']) && $_GET['days'] === '30' ? 30 : 7;
require_once dirname(__DIR__) . '/includes/dashboard-insights.php';
$insights = dashboardInsights($db, $dashboardRole, (int) $auth->getUserId(), $dashboardDays);

$pageTitle = 'Admin Dashboard';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .admin-wrap { margin: 1rem auto 4rem; }
    .admin-heading { display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 1rem; }
    .admin-heading h1 { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.045em; }
    .system-chip { display: inline-flex; align-items: center; gap: .5rem; padding: .55rem .8rem; border-radius: 999px; color: #187a4d; background: #e9f8ef; font-size: .75rem; font-weight: 800; }
    .system-chip::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #21a665; box-shadow: 0 0 0 4px rgba(33,166,101,.12); }
    .admin-metric { height: 100%; padding: 1.35rem; border: 1px solid #e6e6f0; border-radius: 18px; color: #fff; background: linear-gradient(145deg, #242750, #343877); box-shadow: 0 12px 30px rgba(31,33,73,.12); }
    .admin-metric.alt { color: #292b46; background: #fff; box-shadow: 0 10px 28px rgba(35,37,82,.055); }
    .admin-metric__icon { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; color: #cacbff; background: rgba(255,255,255,.1); }
    .admin-metric.alt .admin-metric__icon { color: #565add; background: #ededff; }
    .admin-metric strong { display: block; margin-top: 1rem; font-family: 'Space Grotesk', sans-serif; font-size: 1.75rem; }
    .admin-metric span { color: rgba(255,255,255,.58); font-size: .78rem; }
    .admin-metric.alt span { color: #858697; }
    .admin-panel { height: 100%; padding: clamp(1.2rem, 3vw, 2rem); border: 1px solid #e6e6f0; border-radius: 20px; background: #fff; box-shadow: 0 10px 30px rgba(35,37,82,.05); }
    .admin-panel h2 { font-family: 'Space Grotesk', sans-serif; font-size: 1.3rem; letter-spacing: -.03em; }
    .admin-table { margin: 0; }
    .admin-table th { color: #858697; font-size: .68rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; border-top: 0; }
    .admin-table td { vertical-align: middle; font-size: .82rem; border-color: #eeeef4; }
    .person-avatar { display: grid; place-items: center; flex: 0 0 34px; width: 34px; height: 34px; border-radius: 10px; color: #5559d7; background: #eeeeff; font-size: .72rem; font-weight: 800; }
    .admin-list-item { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 0; border-bottom: 1px solid #eeeef4; }
    .admin-list-item:last-child { border-bottom: 0; }
</style>

<div class="container-fluid px-3 px-lg-4 admin-wrap">
    <header class="admin-heading mb-4">
        <div><span class="text-primary fw-bold small text-uppercase">Platform operations</span><h1 class="mt-2 mb-1">Your platform at a glance.</h1><p class="text-muted mb-0">Monitor the platform and move directly into users, courses, engagement, or payments.</p></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/admin/engagement.php"><i class="fas fa-comments me-2"></i><?php echo (int) ($metrics['unread_messages'] ?? 0); ?> unread</a><a class="btn btn-primary" href="<?php echo APP_URL; ?>/admin/users.php"><i class="fas fa-user-plus me-2"></i>Manage users</a></div>
    </header>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><article class="admin-metric"><span class="admin-metric__icon"><i class="fas fa-users"></i></span><strong><?php echo number_format((int) ($metrics['active_users'] ?? 0)); ?></strong><span>Active users · <?php echo number_format((int) ($metrics['active_students'] ?? 0)); ?> students</span></article></div>
        <div class="col-sm-6 col-xl-3"><article class="admin-metric alt"><span class="admin-metric__icon"><i class="fas fa-layer-group"></i></span><strong><?php echo number_format((int) ($metrics['total_courses'] ?? 0)); ?></strong><span>Total courses · <?php echo number_format((int) ($metrics['published_courses'] ?? 0)); ?> published</span></article></div>
        <div class="col-sm-6 col-xl-3"><article class="admin-metric alt"><span class="admin-metric__icon"><i class="fas fa-graduation-cap"></i></span><strong><?php echo number_format((int) ($metrics['total_enrollments'] ?? 0)); ?></strong><span>Enrollments · <?php echo number_format((int) ($metrics['completions'] ?? 0)); ?> completed</span></article></div>
        <div class="col-sm-6 col-xl-3"><article class="admin-metric"><span class="admin-metric__icon"><i class="fas fa-naira-sign"></i></span><strong><?php echo formatCurrency((float) ($metrics['completed_revenue'] ?? 0)); ?></strong><span>Completed revenue · NGN</span></article></div>
    </div>

    <?php require dirname(__DIR__) . '/templates/dashboard-insights.php'; ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <section class="admin-panel">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3"><div><h2 class="mb-1">Recent users</h2><p class="text-muted small mb-0">Newest accounts on the platform.</p></div><a href="<?php echo APP_URL; ?>/admin/users.php" class="text-link small">Manage users</a></div>
                <div class="table-responsive">
                    <table class="table admin-table align-middle">
                        <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
                        <tbody>
                        <?php if ($recentUsers): foreach ($recentUsers as $user):
                            $initial = function_exists('mb_substr') ? mb_substr((string) $user['full_name'], 0, 1) : substr((string) $user['full_name'], 0, 1);
                        ?>
                            <tr><td><div class="d-flex align-items-center gap-2"><span class="person-avatar"><?php echo sanitize(strtoupper($initial)); ?></span><div><strong class="d-block"><?php echo sanitize($user['full_name']); ?></strong><small class="text-muted"><?php echo sanitize($user['email']); ?></small></div></div></td><td><span class="badge text-bg-light text-capitalize"><?php echo sanitize($user['user_type']); ?></span></td><td><?php echo (int) $user['is_active'] === 1 ? '<span class="text-success">Active</span>' : '<span class="text-muted">Inactive</span>'; ?></td><td class="text-muted"><?php echo formatDate($user['created_at']); ?></td></tr>
                        <?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted py-4">No user records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-xl-5">
            <section class="admin-panel">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3"><div><h2 class="mb-1">Recently updated courses</h2><p class="text-muted small mb-0">Latest catalog activity.</p></div><a href="<?php echo APP_URL; ?>/admin/courses.php" class="text-link small">Manage courses</a></div>
                <?php if ($recentCourses): foreach ($recentCourses as $course): ?>
                    <article class="admin-list-item"><div><strong class="d-block small"><?php echo sanitize($course['title']); ?></strong><small class="text-muted"><?php echo sanitize($course['instructor_name'] ?: 'No instructor'); ?> · <?php echo (int) $course['learner_count']; ?> learners</small></div><span class="badge <?php echo (int) $course['is_published'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $course['is_published'] === 1 ? 'Published' : 'Draft'; ?></span></article>
                <?php endforeach; else: ?><p class="text-muted text-center py-4">No courses found.</p><?php endif; ?>
            </section>
        </div>
    </div>

    <section class="admin-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h2 class="mb-1">Recent payments</h2><p class="text-muted small mb-0">Latest payment attempts and completed transactions.</p></div><a class="btn btn-outline-primary btn-sm" href="<?php echo APP_URL; ?>/admin/payments.php">Open payment ledger</a></div>
        <div class="table-responsive">
            <table class="table admin-table align-middle">
                <thead><tr><th>Learner</th><th>Course</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                <?php if ($recentPayments): foreach ($recentPayments as $payment):
                    $statusClass = $payment['status'] === 'completed' ? 'success' : ($payment['status'] === 'failed' ? 'danger' : ($payment['status'] === 'refunded' ? 'secondary' : 'warning'));
                ?>
                    <tr><td><strong><?php echo sanitize($payment['student_name']); ?></strong></td><td><?php echo sanitize($payment['course_title']); ?></td><td><?php echo sanitize((string) $payment['currency']) . ' ' . number_format((float) $payment['amount'], 2); ?></td><td><span class="badge text-bg-<?php echo $statusClass; ?> text-capitalize"><?php echo sanitize($payment['status']); ?></span></td><td class="text-muted"><?php echo formatDate($payment['paid_at'] ?: $payment['created_at']); ?></td></tr>
                <?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">No payment records found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
