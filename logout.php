<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

try {
    $db = new Database();
    $auth = new Auth($db);
} catch (Throwable $e) {
    error_log('Logout bootstrap failed: ' . $e->getMessage());
    http_response_code(503);
    exit('The service is temporarily unavailable.');
}

$paymentReferenceInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['payment_reference'] ?? '')
    : ($_GET['payment_reference'] ?? '');
$paymentReference = paymentReference($paymentReferenceInput);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null);

    $auth->logout();

    $expectsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    if ($expectsJson) {
        jsonResponse(true, 'Logged out successfully.', ['redirect' => APP_URL], 200);
    }

    if ($paymentReference !== '') {
        redirect('/login.php?payment_reference=' . rawurlencode($paymentReference), 303);
    }

    redirect('/?logged_out=1', 303);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!$auth->isLoggedIn()) {
    redirect('/');
}

$pageTitle = 'Sign out';
$pageNoIndex = true;
$pageReferrerPolicy = 'no-referrer';
require __DIR__ . '/templates/header.php';
?>
<section class="container py-5" aria-labelledby="signout-title">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4 p-md-5 text-center">
                    <span class="brand-mark mx-auto mb-3" aria-hidden="true"><i class="fas fa-arrow-right-from-bracket"></i></span>
                    <h1 class="h3 mb-3" id="signout-title">Ready to sign out?</h1>
                    <p class="text-muted mb-4">You can sign back in at any time to continue learning.</p>
                    <form method="post" action="<?php echo sanitize(APP_URL . '/logout.php'); ?>">
                        <?php echo csrfField(); ?>
                        <?php if ($paymentReference !== ''): ?>
                            <input type="hidden" name="payment_reference" value="<?php echo sanitize($paymentReference); ?>">
                        <?php endif; ?>
                        <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                            <button type="submit" class="btn btn-danger px-4">Yes, sign out</button>
                            <a class="btn btn-outline-secondary px-4" href="<?php echo sanitize(APP_URL); ?>">Stay signed in</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/templates/footer.php'; ?>
