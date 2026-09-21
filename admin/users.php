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

$adminId = (int) $auth->getUserId();
$errors = [];

function adminUserRecord(Database $db, int $userId): ?array
{
    $db->query('SELECT id, email, full_name, user_type, is_active FROM users WHERE id = :id LIMIT 1');
    $db->bind(':id', $userId);
    $row = $db->single();
    return $row ?: null;
}

function adminActiveAdminCount(Database $db): int
{
    $db->query("SELECT COUNT(*) AS total FROM users WHERE user_type = 'admin' AND is_active = 1");
    return (int) ($db->single()['total'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'create_user') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = trim((string) ($_POST['user_type'] ?? 'student'));
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 255) {
            $errors['create'] = 'Use a full name between 2 and 255 characters.';
        } elseif (!isValidEmail($email) || strlen($email) > 255) {
            $errors['create'] = 'Enter a valid email address.';
        } elseif (!in_array($role, ['student', 'instructor', 'admin'], true)) {
            $errors['create'] = 'Choose a valid account role.';
        } elseif (strlen($password) < 10 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['create'] = 'Use a 10–72 character temporary password containing a letter and number.';
        } else {
            $db->query('SELECT id FROM users WHERE email = :email LIMIT 1');
            $db->bind(':email', $email);
            if ($db->single()) {
                $errors['create'] = 'An account already uses that email address.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);
                if ($passwordHash === false) {
                    $errors['create'] = 'The account password could not be secured.';
                } else {
                    $db->query('INSERT INTO users (email, email_verified_at, password, full_name, user_type, is_active)
                                VALUES (:email, NOW(), :password, :full_name, :user_type, 1)');
                    $db->bind(':email', $email);
                    $db->bind(':password', $passwordHash);
                    $db->bind(':full_name', $fullName);
                    $db->bind(':user_type', $role);
                    $db->execute();
                    $_SESSION['message'] = ucfirst($role) . ' account created. Share the temporary password through a secure channel.';
                    redirect('/admin/users.php', 303);
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $user = $userId ? adminUserRecord($db, (int) $userId) : null;
        if (!$user) {
            $errors['form'] = 'That account is unavailable.';
        } elseif ((int) $user['id'] === $adminId) {
            $errors['form'] = 'You cannot deactivate your own signed-in account.';
        } elseif ($user['user_type'] === 'admin' && (int) $user['is_active'] === 1 && adminActiveAdminCount($db) <= 1) {
            $errors['form'] = 'At least one active administrator must remain.';
        } else {
            $nextStatus = (int) $user['is_active'] === 1 ? 0 : 1;
            $db->query('UPDATE users SET is_active = :is_active WHERE id = :id');
            $db->bind(':is_active', $nextStatus);
            $db->bind(':id', (int) $user['id']);
            $db->execute();
            $_SESSION['message'] = $nextStatus === 1 ? 'Account reactivated.' : 'Account deactivated.';
            redirect('/admin/users.php', 303);
        }
    } elseif ($action === 'change_role') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $role = trim((string) ($_POST['user_type'] ?? ''));
        $user = $userId ? adminUserRecord($db, (int) $userId) : null;
        if (!$user || !in_array($role, ['student', 'instructor', 'admin'], true)) {
            $errors['form'] = 'Choose a valid account and role.';
        } elseif ((int) $user['id'] === $adminId) {
            $errors['form'] = 'Use another administrator account to change your own role.';
        } elseif ($user['user_type'] === 'admin' && $role !== 'admin' && (int) $user['is_active'] === 1 && adminActiveAdminCount($db) <= 1) {
            $errors['form'] = 'At least one active administrator must remain.';
        } else {
            $db->query('UPDATE users SET user_type = :user_type WHERE id = :id');
            $db->bind(':user_type', $role);
            $db->bind(':id', (int) $user['id']);
            $db->execute();
            $_SESSION['message'] = 'Account role updated to ' . $role . '.';
            redirect('/admin/users.php', 303);
        }
    } else {
        $errors['form'] = 'That user-management action is not supported.';
    }
}

$role = trim((string) ($_GET['role'] ?? 'all'));
if (!in_array($role, ['all', 'student', 'instructor', 'admin'], true)) {
    $role = 'all';
}
$status = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    $status = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$perPage = 25;
$offset = ((int) $page - 1) * $perPage;

$where = ['1 = 1'];
if ($role !== 'all') {
    $where[] = 'u.user_type = :role';
}
if ($status === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'u.is_active = 0';
}
if ($search !== '') {
    $where[] = '(u.full_name LIKE :search_name OR u.email LIKE :search_email)';
}
$whereSql = implode(' AND ', $where);

$db->query('SELECT COUNT(*) AS total FROM users u WHERE ' . $whereSql);
if ($role !== 'all') {
    $db->bind(':role', $role);
}
if ($search !== '') {
    $term = '%' . $search . '%';
    $db->bind(':search_name', $term);
    $db->bind(':search_email', $term);
}
$totalUsers = (int) ($db->single()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ((int) $page > $totalPages) {
    $page = $totalPages;
    $offset = ((int) $page - 1) * $perPage;
}

$db->query('SELECT u.id, u.full_name, u.email, u.user_type, u.is_active, u.email_verified_at,
                   u.created_at, u.last_login,
                   (SELECT COUNT(*) FROM student_enrollments se WHERE se.student_id = u.id) AS enrollment_count,
                   (SELECT COUNT(*) FROM courses c WHERE c.instructor_id = u.id) AS course_count
            FROM users u
            WHERE ' . $whereSql . '
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset');
if ($role !== 'all') {
    $db->bind(':role', $role);
}
if ($search !== '') {
    $term = '%' . $search . '%';
    $db->bind(':search_name', $term);
    $db->bind(':search_email', $term);
}
$db->bind(':limit', $perPage, PDO::PARAM_INT);
$db->bind(':offset', $offset, PDO::PARAM_INT);
$users = $db->resultSet();

$db->query("SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active,
                   COALESCE(SUM(CASE WHEN user_type = 'student' THEN 1 ELSE 0 END), 0) AS students,
                   COALESCE(SUM(CASE WHEN user_type = 'instructor' THEN 1 ELSE 0 END), 0) AS instructors
            FROM users");
$metrics = $db->single() ?: [];

$pageTitle = 'User Management';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page">
    <header class="workspace-page__header"><div><span class="workspace-eyebrow">Identity & access</span><h1 class="workspace-title">Users</h1><p class="workspace-subtitle">Provision staff accounts, manage roles, and keep platform access current without touching the database directly.</p></div></header>
    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-users"></i></span></div><strong><?php echo number_format((int) ($metrics['total'] ?? 0)); ?></strong><p>Total accounts</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-user-check"></i></span></div><strong><?php echo number_format((int) ($metrics['active'] ?? 0)); ?></strong><p>Active accounts</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-graduation-cap"></i></span></div><strong><?php echo number_format((int) ($metrics['students'] ?? 0)); ?></strong><p>Learners</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-chalkboard-user"></i></span></div><strong><?php echo number_format((int) ($metrics['instructors'] ?? 0)); ?></strong><p>Instructors</p></article>
    </div>

    <div class="workspace-grid mb-4">
        <section class="workspace-panel">
            <div class="workspace-panel__header"><div><h2>Directory</h2><p><?php echo number_format($totalUsers); ?> matching account<?php echo $totalUsers === 1 ? '' : 's'; ?>.</p></div></div>
            <div class="workspace-panel__body">
                <section class="workspace-toolbar mb-0">
                    <form method="get"><input type="hidden" name="role" value="<?php echo sanitize($role); ?>"><input type="hidden" name="status" value="<?php echo sanitize($status); ?>"><input class="form-control" name="search" type="search" maxlength="100" value="<?php echo sanitize($search); ?>" placeholder="Search name or email"><button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button></form>
                    <nav class="workspace-filter-tabs" aria-label="User role"><?php foreach (['all' => 'All', 'student' => 'Learners', 'instructor' => 'Instructors', 'admin' => 'Admins'] as $key => $label): ?><a class="<?php echo $role === $key ? 'is-active' : ''; ?>" href="?<?php echo sanitize(http_build_query(array_filter(['role' => $key, 'status' => $status !== 'all' ? $status : null, 'search' => $search ?: null]))); ?>"><?php echo $label; ?></a><?php endforeach; ?></nav>
                </section>
            </div>
        </section>

        <section class="workspace-panel">
            <div class="workspace-panel__header"><div><h2>Create staff or learner</h2><p>The email is marked verified; share the temporary password securely.</p></div></div>
            <div class="workspace-panel__body">
                <?php if (isset($errors['create'])): ?><div class="alert alert-danger py-2"><?php echo sanitize($errors['create']); ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="create_user">
                    <div class="workspace-form-grid"><div><label class="form-label">Full name</label><input class="form-control" name="full_name" maxlength="255" required></div><div><label class="form-label">Role</label><select class="form-select" name="user_type"><option value="student">Learner</option><option value="instructor">Instructor</option><option value="admin">Administrator</option></select></div><div class="form-span-2"><label class="form-label">Email address</label><input class="form-control" name="email" type="email" maxlength="255" autocomplete="off" required></div><div class="form-span-2"><label class="form-label">Temporary password</label><input class="form-control" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required><div class="form-text">10–72 characters with a letter and number.</div></div></div>
                    <div class="workspace-form-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-user-plus me-2"></i>Create account</button></div>
                </form>
            </div>
        </section>
    </div>

    <section class="workspace-panel">
        <div class="workspace-panel__body--flush">
            <?php if ($users): ?><div class="workspace-table-wrap"><table class="workspace-table"><thead><tr><th>User</th><th>Role</th><th>Activity</th><th>Verification</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><div class="workspace-person"><span class="workspace-avatar"><?php echo sanitize(mb_strtoupper(mb_substr((string) $user['full_name'], 0, 1))); ?></span><div><strong><?php echo sanitize($user['full_name']); ?></strong><small><?php echo sanitize($user['email']); ?></small></div></div></td>
                        <td><form method="post" class="workspace-inline-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="change_role"><input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>"><select class="form-select form-select-sm" name="user_type" <?php echo (int) $user['id'] === $adminId ? 'disabled' : ''; ?> onchange="this.form.submit()"><?php foreach (['student' => 'Learner', 'instructor' => 'Instructor', 'admin' => 'Admin'] as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo $user['user_type'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></form></td>
                        <td><?php if ($user['user_type'] === 'student'): ?><?php echo (int) $user['enrollment_count']; ?> enrollments<?php elseif ($user['user_type'] === 'instructor'): ?><?php echo (int) $user['course_count']; ?> courses<?php else: ?>Platform operations<?php endif; ?><small class="d-block text-muted">Last login <?php echo $user['last_login'] ? formatDate($user['last_login']) : 'never'; ?></small></td>
                        <td><span class="status-pill <?php echo $user['email_verified_at'] ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo $user['email_verified_at'] ? 'Verified' : 'Pending'; ?></span></td>
                        <td><span class="status-pill <?php echo (int) $user['is_active'] === 1 ? 'status-pill--success' : 'status-pill--danger'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                        <td><?php if ((int) $user['id'] !== $adminId): ?><form method="post" class="workspace-inline-form" data-confirm="<?php echo (int) $user['is_active'] === 1 ? 'Deactivate this account?' : 'Reactivate this account?'; ?>"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>"><button class="btn btn-sm <?php echo (int) $user['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success'; ?>" type="submit"><?php echo (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate'; ?></button></form><?php else: ?><small class="text-muted">Current account</small><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-users"></i></span><h3>No users match these filters</h3><p>Try a different role, status, or search term.</p></div><?php endif; ?>
        </div>
    </section>

    <?php if ($totalPages > 1): ?><nav class="d-flex justify-content-center gap-2 mt-4" aria-label="User pages"><?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): $params = array_filter(['page' => $pageNumber, 'role' => $role !== 'all' ? $role : null, 'status' => $status !== 'all' ? $status : null, 'search' => $search ?: null]); ?><a class="btn btn-sm <?php echo (int) $page === $pageNumber ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?<?php echo sanitize(http_build_query($params)); ?>"><?php echo $pageNumber; ?></a><?php endfor; ?></nav><?php endif; ?>
</div>

<script>document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => { if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault(); }));</script>
<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
