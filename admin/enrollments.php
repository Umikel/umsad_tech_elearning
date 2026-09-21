<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
$db = new Database();
$auth = new Auth($db);
if (!$auth->verifySession()) redirect('/login.php');
if (!$auth->isAdmin()) redirect('/' . $auth->getDashboardPath());
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'enrollment_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $error = 'Your session expired. Refresh and try again.';
    } elseif (!$id || ($_POST['action'] ?? '') !== 'approve') {
        $error = 'Choose a valid registration to approve.';
    } else {
        $db->query('UPDATE student_enrollments SET is_approved = 1, approved_at = NOW(), approved_by = :admin_id WHERE id = :id AND is_approved = 0');
        $db->bind(':admin_id', (int) $auth->getUserId());
        $db->bind(':id', (int) $id);
        $db->execute();
        $_SESSION['success'] = $db->rowCount() ? 'Enrollment approved. The student can now start learning.' : 'No pending registration was changed.';
        redirect('/admin/enrollments.php');
    }
}
$db->query('SELECT se.*, u.full_name, u.email, c.title AS course_title
            FROM student_enrollments se JOIN users u ON u.id = se.student_id
            JOIN courses c ON c.id = se.course_id
            ORDER BY se.is_approved ASC, se.enrollment_date DESC');
$enrollments = $db->resultSet();
$pageTitle = 'Enrollment approvals';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>
<div class="workspace-page">
    <header class="workspace-page__header"><div><span class="workspace-eyebrow">Learning access</span><h1 class="workspace-title">Enrollment approvals</h1><p class="workspace-subtitle">Approve each course registration when the student is ready to begin. Payment alone does not unlock lessons.</p></div></header>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($error); ?></div><?php endif; ?>
    <section class="workspace-panel"><div class="workspace-table-wrap"><table class="workspace-table">
        <thead><tr><th>Student</th><th>Course</th><th>Registered</th><th>Status</th><th>Action</th></tr></thead>
        <tbody><?php foreach ($enrollments as $enrollment): ?>
            <tr><td><strong><?php echo sanitize($enrollment['full_name']); ?></strong><small class="d-block"><?php echo sanitize($enrollment['email']); ?></small></td>
                <td><?php echo sanitize($enrollment['course_title']); ?><?php if (($enrollment['learning_plan'] ?? 'online') === 'sunday_physical'): ?><small class="d-block">Online + Sunday physical class · 10am–11am · Venue to be announced</small><?php endif; ?></td>
                <td><?php echo sanitize(formatDate($enrollment['enrollment_date'])); ?></td>
                <td><span class="status-pill <?php echo $enrollment['is_approved'] ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo $enrollment['is_approved'] ? 'Approved' : 'Awaiting approval'; ?></span></td>
                <td><?php if (!$enrollment['is_approved']): ?><form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="approve"><input type="hidden" name="enrollment_id" value="<?php echo (int) $enrollment['id']; ?>"><button class="btn btn-primary btn-sm" type="submit">Approve learning</button></form><?php else: ?>Approved <?php echo sanitize(formatDate($enrollment['approved_at'])); ?><?php endif; ?></td></tr>
        <?php endforeach; ?><?php if (!$enrollments): ?><tr><td colspan="5">No course registrations yet.</td></tr><?php endif; ?></tbody>
    </table></div></section>
</div>
<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
