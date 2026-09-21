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
$passwordReset = new PasswordReset($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    $token = strtolower(trim((string) $_GET['token']));
    if ($passwordReset->tokenIsValid($token)) {
        $_SESSION['_password_reset_token'] = $token;
        redirect('/reset-password.php', 303);
    }
    unset($_SESSION['_password_reset_token']);
    redirect('/reset-password.php?invalid=1', 303);
}

$token = isset($_SESSION['_password_reset_token']) && is_string($_SESSION['_password_reset_token'])
    ? $_SESSION['_password_reset_token']
    : '';
$tokenValid = $token !== '' && $passwordReset->tokenIsValid($token);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirm'] ?? '');
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif (!$tokenValid) {
        $errors['form'] = 'This password-reset link is invalid or has expired.';
    } elseif (strlen($password) < 10 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors['password'] = 'Use 10–72 characters, including a letter and a number.';
    } elseif (!hash_equals($password, $confirmation)) {
        $errors['password_confirm'] = 'The passwords do not match.';
    } else {
        try {
            if ($passwordReset->consume($token, $password)) {
                unset($_SESSION['_password_reset_token']);
                $_SESSION['message'] = 'Your password has been reset. Sign in with the new password.';
                redirect('/login.php', 303);
            }
            $tokenValid = false;
            $errors['form'] = 'This password-reset link is invalid or has expired.';
        } catch (Throwable $exception) {
            error_log('Unable to consume password reset token: ' . $exception->getMessage());
            $errors['form'] = 'Your password could not be reset. Please try again.';
        }
    }
}

$pageTitle = 'Choose New Password';
$pageNoIndex = true;
$pageReferrerPolicy = 'no-referrer';
require_once __DIR__ . '/templates/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div class="auth-layout">
            <aside class="auth-story" aria-label="Choose a secure password">
                <a class="auth-brand" href="<?php echo APP_URL; ?>" aria-label="Umsad Tech home"><span class="brand-logo-frame brand-logo-frame--auth" aria-hidden="true"><img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024"></span></a>
                <span class="eyebrow text-white">Protect your progress</span><h1>Choose a password that is yours alone.</h1><p>A strong, unique password protects your courses, certificates, and personal details.</p>
            </aside>
            <div class="auth-panel">
                <div class="auth-panel-header"><span class="auth-icon"><i class="fas fa-shield-halved"></i></span><div><p class="auth-kicker">Account recovery</p><h2>Choose a new password</h2></div></div>
                <?php if (isset($errors['form'])): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i><span><?php echo sanitize($errors['form']); ?></span></div><?php endif; ?>
                <?php if (!$tokenValid): ?>
                    <div class="workspace-empty p-2"><span class="workspace-empty__icon"><i class="fas fa-link-slash"></i></span><h3>Reset link unavailable</h3><p>The link is invalid, expired, or has already been used.</p><a class="btn btn-primary" href="<?php echo APP_URL; ?>/forgot-password.php">Request a new link</a></div>
                <?php else: ?>
                    <form method="post" class="auth-form" novalidate>
                        <?php echo csrfField(); ?>
                        <div class="form-field"><label class="form-label" for="password">New password</label><div class="field-control <?php echo isset($errors['password']) ? 'has-error' : ''; ?>"><i class="fas fa-lock"></i><input id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required><button class="password-toggle" type="button" onclick="togglePasswordVisibility('password', 'newPasswordIcon')" aria-label="Show or hide password"><i id="newPasswordIcon" class="far fa-eye"></i></button></div><?php if (isset($errors['password'])): ?><p class="form-error"><?php echo sanitize($errors['password']); ?></p><?php endif; ?></div>
                        <div class="form-field"><label class="form-label" for="password_confirm">Confirm new password</label><div class="field-control <?php echo isset($errors['password_confirm']) ? 'has-error' : ''; ?>"><i class="fas fa-shield-halved"></i><input id="password_confirm" name="password_confirm" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></div><?php if (isset($errors['password_confirm'])): ?><p class="form-error"><?php echo sanitize($errors['password_confirm']); ?></p><?php endif; ?></div>
                        <div class="workspace-callout"><i class="fas fa-circle-info"></i><div>Use 10–72 characters with at least one letter and one number. Avoid passwords used on other sites.</div></div>
                        <button class="btn btn-primary btn-lg w-100" type="submit">Reset password <i class="fas fa-arrow-right"></i></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
