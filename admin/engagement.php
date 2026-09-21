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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'set_review_status') {
        $reviewId = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $approved = ($_POST['approved'] ?? '') === '1' ? 1 : 0;
        $db->query('SELECT id FROM course_reviews WHERE id = :id LIMIT 1');
        $db->bind(':id', (int) $reviewId);
        if (!$reviewId || !$db->single()) {
            $errors['form'] = 'That review is unavailable.';
        } else {
            $db->query('UPDATE course_reviews SET is_approved = :approved WHERE id = :id');
            $db->bind(':approved', $approved);
            $db->bind(':id', (int) $reviewId);
            $db->execute();
            $_SESSION['message'] = $approved === 1 ? 'Review approved and published.' : 'Review hidden from the public catalog.';
            redirect('/admin/engagement.php#reviews', 303);
        }
    } elseif ($action === 'set_message_status') {
        $messageId = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $isRead = ($_POST['is_read'] ?? '') === '1' ? 1 : 0;
        $db->query('SELECT id FROM contact_messages WHERE id = :id LIMIT 1');
        $db->bind(':id', (int) $messageId);
        if (!$messageId || !$db->single()) {
            $errors['form'] = 'That message is unavailable.';
        } else {
            $db->query('UPDATE contact_messages SET is_read = :is_read WHERE id = :id');
            $db->bind(':is_read', $isRead);
            $db->bind(':id', (int) $messageId);
            $db->execute();
            $_SESSION['message'] = $isRead === 1 ? 'Message marked as handled.' : 'Message returned to the unread queue.';
            redirect('/admin/engagement.php#messages', 303);
        }
    } else {
        $errors['form'] = 'That engagement action is not supported.';
    }
}

$db->query('SELECT cr.id, cr.rating, cr.review_text, cr.is_approved, cr.created_at, cr.updated_at,
                   u.full_name, u.email, c.id AS course_id, c.title AS course_title
            FROM course_reviews cr
            JOIN users u ON u.id = cr.student_id
            JOIN courses c ON c.id = cr.course_id
            ORDER BY cr.is_approved ASC, cr.updated_at DESC
            LIMIT 100');
$reviews = $db->resultSet();

$db->query('SELECT * FROM contact_messages ORDER BY is_read ASC, created_at DESC LIMIT 100');
$messages = $db->resultSet();

$db->query('SELECT
                (SELECT COUNT(*) FROM course_reviews WHERE is_approved = 0) AS pending_reviews,
                (SELECT COUNT(*) FROM course_reviews WHERE is_approved = 1) AS approved_reviews,
                (SELECT COUNT(*) FROM contact_messages WHERE is_read = 0) AS unread_messages,
                (SELECT COUNT(*) FROM contact_messages) AS total_messages');
$metrics = $db->single() ?: [];

$pageTitle = 'Engagement';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page">
    <header class="workspace-page__header"><div><span class="workspace-eyebrow">Community operations</span><h1 class="workspace-title">Engagement</h1><p class="workspace-subtitle">Moderate learner reviews and keep support enquiries moving from unread to handled.</p></div></header>
    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <div class="workspace-metric-grid">
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-clock"></i></span></div><strong><?php echo number_format((int) ($metrics['pending_reviews'] ?? 0)); ?></strong><p>Reviews awaiting approval</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-star"></i></span></div><strong><?php echo number_format((int) ($metrics['approved_reviews'] ?? 0)); ?></strong><p>Published reviews</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-envelope"></i></span></div><strong><?php echo number_format((int) ($metrics['unread_messages'] ?? 0)); ?></strong><p>Unread messages</p></article>
        <article class="workspace-metric-card"><div class="workspace-metric-card__top"><span class="workspace-metric-card__icon"><i class="fas fa-comments"></i></span></div><strong><?php echo number_format((int) ($metrics['total_messages'] ?? 0)); ?></strong><p>Total enquiries</p></article>
    </div>

    <section class="workspace-panel mb-4" id="reviews">
        <div class="workspace-panel__header"><div><h2>Course reviews</h2><p>Only approved reviews contribute to public ratings.</p></div><span class="status-pill status-pill--warning"><?php echo (int) ($metrics['pending_reviews'] ?? 0); ?> pending</span></div>
        <div class="workspace-panel__body--flush">
            <?php if ($reviews): ?><div class="workspace-table-wrap"><table class="workspace-table"><thead><tr><th>Learner</th><th>Course</th><th>Rating</th><th>Review</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($reviews as $review): ?><tr>
                    <td><div class="workspace-person"><span class="workspace-avatar"><?php echo sanitize(mb_strtoupper(mb_substr((string) $review['full_name'], 0, 1))); ?></span><div><strong><?php echo sanitize($review['full_name']); ?></strong><small><?php echo sanitize($review['email']); ?></small></div></div></td>
                    <td><a class="text-decoration-none fw-bold" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $review['course_id']; ?>" target="_blank" rel="noopener"><?php echo sanitize($review['course_title']); ?></a></td>
                    <td><span class="text-warning text-nowrap" aria-label="<?php echo (int) $review['rating']; ?> out of 5 stars"><?php for ($star = 1; $star <= 5; $star++): ?><i class="<?php echo $star <= (int) $review['rating'] ? 'fas' : 'far'; ?> fa-star"></i><?php endfor; ?></span></td>
                    <td style="min-width:280px;max-width:500px"><span><?php echo sanitize(mb_strimwidth((string) $review['review_text'], 0, 260, '…')); ?></span><small class="d-block text-muted mt-1"><?php echo formatDate($review['updated_at'] ?: $review['created_at']); ?></small></td>
                    <td><span class="status-pill <?php echo (int) $review['is_approved'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $review['is_approved'] === 1 ? 'Published' : 'Pending'; ?></span></td>
                    <td><form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_review_status"><input type="hidden" name="review_id" value="<?php echo (int) $review['id']; ?>"><input type="hidden" name="approved" value="<?php echo (int) $review['is_approved'] === 1 ? '0' : '1'; ?>"><button class="btn btn-sm <?php echo (int) $review['is_approved'] === 1 ? 'btn-outline-danger' : 'btn-success'; ?>" type="submit"><?php echo (int) $review['is_approved'] === 1 ? 'Hide' : 'Approve'; ?></button></form></td>
                </tr><?php endforeach; ?>
            </tbody></table></div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-star"></i></span><h3>No learner reviews yet</h3><p>Submitted reviews will appear here for moderation.</p></div><?php endif; ?>
        </div>
    </section>

    <section class="workspace-panel" id="messages">
        <div class="workspace-panel__header"><div><h2>Contact messages</h2><p>Newest unread messages are shown first.</p></div><span class="status-pill status-pill--info"><?php echo (int) ($metrics['unread_messages'] ?? 0); ?> unread</span></div>
        <div class="workspace-panel__body">
            <?php if ($messages): ?><div class="builder-stack"><?php foreach ($messages as $message): ?>
                <article class="builder-section">
                    <div class="builder-section__header"><div class="workspace-person"><span class="workspace-avatar"><?php echo sanitize(mb_strtoupper(mb_substr((string) $message['name'], 0, 1))); ?></span><div><strong><?php echo sanitize($message['name']); ?></strong><small><a href="mailto:<?php echo sanitize($message['email']); ?>"><?php echo sanitize($message['email']); ?></a></small></div></div><div class="text-end"><span class="status-pill <?php echo (int) $message['is_read'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $message['is_read'] === 1 ? 'Handled' : 'Unread'; ?></span><small class="d-block text-muted mt-2"><?php echo formatDate($message['created_at'], 'd M Y, H:i'); ?></small></div></div>
                    <div class="builder-section__body"><h3 class="h6"><?php echo sanitize($message['subject'] ?: 'General enquiry'); ?></h3><p class="text-secondary mb-3" style="white-space:pre-wrap;line-height:1.7"><?php echo sanitize($message['message']); ?></p><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary btn-sm" href="mailto:<?php echo sanitize($message['email']); ?>?subject=<?php echo rawurlencode('Re: ' . ($message['subject'] ?: 'Your Umsad Tech enquiry')); ?>"><i class="fas fa-reply me-1"></i>Reply by email</a><form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_message_status"><input type="hidden" name="message_id" value="<?php echo (int) $message['id']; ?>"><input type="hidden" name="is_read" value="<?php echo (int) $message['is_read'] === 1 ? '0' : '1'; ?>"><button class="btn btn-outline-secondary btn-sm" type="submit"><?php echo (int) $message['is_read'] === 1 ? 'Mark unread' : 'Mark handled'; ?></button></form></div></div>
                </article>
            <?php endforeach; ?></div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-envelope-open"></i></span><h3>No contact messages yet</h3><p>New enquiries from the contact page will appear here.</p></div><?php endif; ?>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
