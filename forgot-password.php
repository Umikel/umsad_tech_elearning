<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/PasswordReset.php';

$db = new Database();
$auth = new Auth($db);
if ($auth->isLoggedIn() && $auth->verifySession()) {
    redirect('/' . $auth->getDashboardPath());
}

$submitted = false;
$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!isValidEmail($email) || strlen($email) > 255) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            (new PasswordReset($db))->requestForEmail($email);
        } catch (Throwable $exception) {
            error_log('Password reset request failed: ' . $exception->getMessage());
        }
        $submitted = true;
    }
}

$pageTitle = 'Reset Password';
$pageNoIndex = true;
$pageReferrerPolicy = 'no-referrer';
require_once __DIR__ . '/templates/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div class="auth-layout">
            <aside class="auth-story" aria-label="Secure account recovery">
                <a class="auth-brand" href="<?php echo APP_URL; ?>" aria-label="Umsad Tech home"><span class="brand-logo-frame brand-logo-frame--auth" aria-hidden="true"><img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024"></span></a>
                <span class="eyebrow text-white">Secure recovery</span>
                <h1>Get back to learning safely.</h1>
                <p>Reset links are time-limited, single-use, and sent only to verified accounts.</p>
                <div class="auth-proof"><span><i class="fas fa-circle-check"></i> One-time secure link</span><span><i class="fas fa-circle-check"></i> Expires automatically</span><span><i class="fas fa-circle-check"></i> Private by design</span></div>
            </aside>
            <div class="auth-panel">
                <div class="auth-panel-header"><span class="auth-icon"><i class="fas fa-key"></i></span><div><p class="auth-kicker">Account recovery</p><h2><?php echo $submitted ? 'Check your email' : 'Reset your password'; ?></h2></div></div>
                <?php if ($submitted): ?>
                    <div class="alert alert-success" role="status"><i class="fas fa-envelope-circle-check"></i><span>If a verified account matches that email, a password-reset link has been sent. Check your inbox and spam folder.</span></div>
                    <div class="workspace-callout mb-4"><i class="fas fa-clock"></i><div>The link expires in <?php echo (int) ceil(PASSWORD_RESET_TTL / 60); ?> minutes. Request a new one only if this message does not arrive.</div></div>
                    <div class="d-grid gap-2"><a class="btn btn-primary btn-lg" href="<?php echo APP_URL; ?>/login.php">Return to sign in</a><a class="btn btn-outline-secondary" href="<?php echo APP_URL; ?>/forgot-password.php">Try another email</a></div>
                <?php else: ?>
                    <?php if ($error): ?><div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation"></i><span><?php echo sanitize($error); ?></span></div><?php endif; ?>
                    <p class="text-muted mb-4">Enter the email used for your account. We will send recovery instructions if it is eligible.</p>
                    <form method="post" class="auth-form" novalidate>
                        <?php echo csrfField(); ?>
                        <div class="form-field"><label for="email" class="form-label">Email address</label><div class="field-control <?php echo $error ? 'has-error' : ''; ?>"><i class="far fa-envelope"></i><input id="email" name="email" type="email" maxlength="255" autocomplete="email" value="<?php echo sanitize($email); ?>" placeholder="you@example.com" required></div></div>
                        <button class="btn btn-primary btn-lg w-100" type="submit">Send reset link <i class="fas fa-arrow-right"></i></button>
                    </form>
                    <p class="auth-switch"><a href="<?php echo APP_URL; ?>/login.php"><i class="fas fa-arrow-left me-1"></i>Back to sign in</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
