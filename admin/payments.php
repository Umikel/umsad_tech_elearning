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

$status = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'pending', 'completed', 'failed', 'refunded'], true)) {
    $status = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 120) {
    $search = mb_substr($search, 0, 120);
}
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$perPage = 30;

$where = ['1 = 1'];
if ($status !== 'all') {
    $where[] = 'p.status = :status';
}
if ($search !== '') {
    $where[] = '(p.payment_reference LIKE :search_reference OR p.transaction_id LIKE :search_transaction OR u.full_name LIKE :search_name OR u.email LIKE :search_email OR c.title LIKE :search_course)';
}
$whereSql = implode(' AND ', $where);

$db->query('SELECT COUNT(*) AS total
            FROM payments p JOIN users u ON u.id = p.student_id JOIN courses c ON c.id = p.course_id
            WHERE ' . $whereSql);
if ($status !== 'all') {
    $db->bind(':status', $status);
}
if ($search !== '') {
    $term = '%' . $search . '%';
    $db->bind(':search_reference', $term);
    $db->bind(':search_transaction', $term);
    $db->bind(':search_name', $term);
    $db->bind(':search_email', $term);
    $db->bind(':search_course', $term);
}
$totalPayments = (int) ($db->single()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalPayments / $perPage));
if ((int) $page > $totalPages) {
    $page = $totalPages;
}
$offset = ((int) $page - 1) * $perPage;

$db->query('SELECT p.*, u.full_name, u.email, c.title AS course_title
            FROM payments p
            JOIN users u ON u.id = p.student_id
            JOIN courses c ON c.id = p.course_id
            WHERE ' . $whereSql . '
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset');
if ($status !== 'all') {
    $db->bind(':status', $status);
}
if ($search !== '') {
    $term = '%' . $search . '%';
    $db->bind(':search_reference', $term);
    $db->bind(':search_transaction', $term);
    $db->bind(':search_name', $term);
    $db->bind(':search_email', $term);
    $db->bind(':search_course', $term);
}
$db->bind(':limit', $perPage, PDO::PARAM_INT);
$db->bind(':offset', $offset, PDO::PARAM_INT);
$payments = $db->resultSet();

$db->query("SELECT
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed
            FROM payments");
$metrics = $db->single() ?: [];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="umsad-payments-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    if ($output !== false) {
        fputcsv($output, ['Reference', 'Transaction', 'Learner', 'Email', 'Course', 'Amount', 'Currency', 'Status', 'Provider status', 'Created', 'Paid'], ',', '"', '');
        foreach ($payments as $payment) {
            fputcsv($output, [
                spreadsheetSafeCell($payment['payment_reference']),
                spreadsheetSafeCell($payment['transaction_id']),
                spreadsheetSafeCell($payment['full_name']),
                spreadsheetSafeCell($payment['email']),
                spreadsheetSafeCell($payment['course_title']),
                $payment['amount'],
                spreadsheetSafeCell($payment['currency']),
                spreadsheetSafeCell($payment['status']),
                spreadsheetSafeCell($payment['provider_status']),
                spreadsheetSafeCell($payment['created_at']),
                spreadsheetSafeCell($payment['paid_at']),
            ], ',', '"', '');
        }
        fclose($output);
    }
    exit;
}

$pageTitle = 'Payments';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
$exportParams = $_GET;
$exportParams['export'] = 'csv';
?>

<div class="workspace-page">
    <header class="workspace-page__header"><div><span class="workspace-eyebrow">Financial operations</span><h1 class="workspace-title">Payments</h1><p class="workspace-subtitle">A searchable audit trail of checkout attempts, confirmed revenue, provider references, and payment failures.</p></div><div class="workspace-actions"><a class="btn btn-outline-primary" href="?<?php echo sanitize(http_build_query($exportParams)); ?>"><i class="fas fa-download me-2"></i>Export current page</a></div></header>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-naira-sign"></i></span></div><strong><?php echo formatCurrency((float) ($metrics['revenue'] ?? 0)); ?></strong><p>Completed revenue</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-circle-check"></i></span></div><strong><?php echo number_format((int) ($metrics['completed'] ?? 0)); ?></strong><p>Completed payments</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-clock"></i></span></div><strong><?php echo number_format((int) ($metrics['pending'] ?? 0)); ?></strong><p>Pending attempts</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-triangle-exclamation"></i></span></div><strong><?php echo number_format((int) ($metrics['failed'] ?? 0)); ?></strong><p>Failed payments</p></article>
    </div>

    <section class="workspace-toolbar">
        <form method="get"><input type="hidden" name="status" value="<?php echo sanitize($status); ?>"><input class="form-control" name="search" type="search" maxlength="120" value="<?php echo sanitize($search); ?>" placeholder="Reference, learner, email, course"><button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button></form>
        <nav class="workspace-filter-tabs" aria-label="Payment status"><?php foreach (['all' => 'All', 'completed' => 'Completed', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $key => $label): ?><a class="<?php echo $status === $key ? 'is-active' : ''; ?>" href="?<?php echo sanitize(http_build_query(array_filter(['status' => $key, 'search' => $search ?: null]))); ?>"><?php echo $label; ?></a><?php endforeach; ?></nav>
    </section>

    <section class="workspace-panel">
        <div class="workspace-panel__header"><div><h2>Transaction ledger</h2><p><?php echo number_format($totalPayments); ?> matching payment<?php echo $totalPayments === 1 ? '' : 's'; ?>.</p></div></div>
        <div class="workspace-panel__body--flush">
            <?php if ($payments): ?><div class="workspace-table-wrap"><table class="workspace-table"><thead><tr><th>Learner</th><th>Course</th><th>Reference</th><th>Amount</th><th>Status</th><th>Provider</th><th>Date</th></tr></thead><tbody>
                <?php foreach ($payments as $payment): $statusClass = $payment['status'] === 'completed' ? 'status-pill--success' : ($payment['status'] === 'failed' ? 'status-pill--danger' : ($payment['status'] === 'pending' ? 'status-pill--warning' : '')); ?><tr>
                    <td><div class="workspace-person"><span class="workspace-avatar"><?php echo sanitize(mb_strtoupper(mb_substr((string) $payment['full_name'], 0, 1))); ?></span><div><strong><?php echo sanitize($payment['full_name']); ?></strong><small><?php echo sanitize($payment['email']); ?></small></div></div></td>
                    <td><strong class="text-dark"><?php echo sanitize($payment['course_title']); ?></strong></td>
                    <td><code class="small"><?php echo sanitize($payment['payment_reference']); ?></code><?php if ($payment['transaction_id']): ?><small class="d-block text-muted">Txn <?php echo sanitize($payment['transaction_id']); ?></small><?php endif; ?></td>
                    <td><strong class="text-dark"><?php echo formatCurrency((float) $payment['amount']); ?></strong><small class="d-block text-muted"><?php echo sanitize($payment['currency']); ?></small></td>
                    <td><span class="status-pill <?php echo $statusClass; ?>"><?php echo sanitize(ucfirst((string) $payment['status'])); ?></span></td>
                    <td><?php echo sanitize($payment['provider_status'] ?: '—'); ?><?php if ($payment['failure_reason']): ?><small class="d-block text-danger" title="<?php echo sanitize($payment['failure_reason']); ?>"><?php echo sanitize(mb_strimwidth((string) $payment['failure_reason'], 0, 60, '…')); ?></small><?php endif; ?></td>
                    <td><?php echo formatDate($payment['paid_at'] ?: $payment['created_at'], 'd M Y'); ?><small class="d-block text-muted"><?php echo formatDate($payment['paid_at'] ?: $payment['created_at'], 'H:i'); ?></small></td>
                </tr><?php endforeach; ?>
            </tbody></table></div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-credit-card"></i></span><h3>No payments match these filters</h3><p>Try a different status, reference, learner, or course.</p><a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/admin/payments.php">Clear filters</a></div><?php endif; ?>
        </div>
    </section>
    <?php if ($totalPages > 1): ?><nav class="d-flex justify-content-center gap-2 mt-4" aria-label="Payment pages"><?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): $params = array_filter(['page' => $pageNumber, 'status' => $status !== 'all' ? $status : null, 'search' => $search ?: null]); ?><a class="btn btn-sm <?php echo (int) $page === $pageNumber ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?<?php echo sanitize(http_build_query($params)); ?>"><?php echo $pageNumber; ?></a><?php endfor; ?></nav><?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
