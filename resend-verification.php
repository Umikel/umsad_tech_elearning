<?php
/** Request a new email-verification link without revealing account status. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/EmailVerification.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$db = new Database();
$auth = new Auth($db);
if ($auth->isLoggedIn() && $auth->verifySession()) {
    redirect('/' . $auth->getDashboardPath());
}

$email = strtolower(trim((string) ($_POST['email'] ?? $_SESSION['verification_email'] ?? '')));
$notice = (string) ($_SESSION['verification_notice'] ?? '');
unset($_SESSION['verification_notice']);
$submitted = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $errors['email'] = 'Enter a valid email address.';
    }

    $windowStarted = (int) ($_SESSION['verification_resend_window'] ?? 0);
    if ($windowStarted <= 0 || time() - $windowStarted > 900) {
        $_SESSION['verification_resend_window'] = time();
        $_SESSION['verification_resend_attempts'] = 0;
    }
    if ((int) ($_SESSION['verification_resend_attempts'] ?? 0) >= 5) {
        $errors['form'] = 'Too many requests. Please wait 15 minutes before trying again.';
    }
    if (!$errors && (!defined('MAIL_CONFIGURED') || !MAIL_CONFIGURED)) {
        $errors['form'] = 'Email delivery is temporarily unavailable. Please try again later.';
    }

    if (!$errors) {
        $_SESSION['verification_resend_attempts'] = (int) ($_SESSION['verification_resend_attempts'] ?? 0) + 1;
        $_SESSION['verification_email'] = $email;

        try {
            $verification = new EmailVerification($db);
            $verification->sendForEmail($email);
            $submitted = true;
        } catch (Throwable $exception) {
            error_log('Verification resend delivery is unavailable.');
            // Keep the public response neutral so an SMTP outage cannot turn
            // this form into an account-enumeration oracle.
            $submitted = true;
        }
    }
}

$registered = isset($_GET['registered']) || isset($_GET['changed']);
$pageTitle = $submitted || $registered ? 'Check your email' : 'Resend verification email';
$pageReferrerPolicy = 'no-referrer';
$pageNoIndex = true;
require_once __DIR__ . '/templates/header.php';
?>

<section class="email-verification-section" aria-labelledby="verification-title">
    <div class="verification-orb verification-orb-one" aria-hidden="true"></div>
    <div class="verification-orb verification-orb-two" aria-hidden="true"></div>
    <div class="container position-relative">
        <div class="verification-card">
            <?php if ($submitted): ?>
                <span class="verification-icon verification-icon-success" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                <p class="auth-kicker">Check your inbox</p>
                <h1 id="verification-title">A verification link is on its way.</h1>
                <p>If an unverified account exists for that address, we sent a new link. Allow a few minutes and check your spam folder too.</p>
                <a class="btn btn-primary btn-lg w-100" href="<?php echo APP_URL; ?>/login.php">Return to sign in</a>
                <a class="verification-text-button" href="<?php echo APP_URL; ?>/resend-verification.php">Try another address</a>
            <?php else: ?>
                <span class="verification-icon" aria-hidden="true"><i class="fas fa-envelope-open-text"></i></span>
                <p class="auth-kicker"><?php echo $registered ? 'Account created' : 'Email verification'; ?></p>
                <h1 id="verification-title"><?php echo $registered ? 'Check your email to continue.' : 'Send a new verification link.'; ?></h1>
                <p><?php echo $registered ? 'Your account stays protected until you verify the email address used during registration.' : 'Enter your account email and we’ll send a fresh, secure link if verification is still required.'; ?></p>

                <?php if ($notice !== ''): ?>
                    <div class="alert alert-info" role="status">
                        <i class="fas fa-circle-info" aria-hidden="true"></i>
                        <?php echo sanitize($notice); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($errors['form'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <?php echo sanitize($errors['form']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="verification-action-form" novalidate>
                    <?php echo csrfField(); ?>
                    <div class="form-field text-start">
                        <label for="verification_email" class="form-label">Email address</label>
                        <div class="field-control <?php echo isset($errors['email']) ? 'has-error' : ''; ?>">
                            <i class="far fa-envelope" aria-hidden="true"></i>
                            <input type="email" id="verification_email" name="email" value="<?php echo sanitize($email); ?>"
                                   autocomplete="email" inputmode="email" placeholder="you@example.com" required autofocus>
                        </div>
                        <?php if (isset($errors['email'])): ?>
                            <p class="form-error"><?php echo sanitize($errors['email']); ?></p>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        Send verification link <i class="fas fa-paper-plane" aria-hidden="true"></i>
                    </button>
                </form>
                <a class="verification-secondary-link" href="<?php echo APP_URL; ?>/login.php">Back to sign in</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
