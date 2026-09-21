<?php
/** Create a learner account. Public registration never accepts a role. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/EmailVerification.php';
require_once __DIR__ . '/includes/NotificationService.php';

$db = new Database();
$auth = new Auth($db);
$errors = [];
$values = ['full_name' => '', 'email' => ''];

if ($auth->isLoggedIn() && $auth->verifySession()) {
    redirect('/' . $auth->getDashboardPath());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    }

    $values['full_name'] = trim($_POST['full_name'] ?? '');
    $values['email'] = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $termsAccepted = isset($_POST['terms']);

    if (mb_strlen($values['full_name']) < 2 || mb_strlen($values['full_name']) > 120) {
        $errors['full_name'] = 'Enter your full name (2–120 characters).';
    }
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (strlen($password) < 10 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors['password'] = 'Use 10–72 characters with a letter and a number.';
    }
    if ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'The passwords do not match.';
    }
    if (!$termsAccepted) {
        $errors['terms'] = 'Please accept the learner agreement to continue.';
    }

    if (empty($errors) && (!defined('MAIL_CONFIGURED') || !MAIL_CONFIGURED)) {
        $errors['form'] = 'Account registration is temporarily unavailable while email delivery is being configured.';
    }

    if (empty($errors)) {
        try {
            $result = $auth->register($values['email'], $password, $values['full_name'], 'student');
            if ($result['success']) {
                $userId = (int) ($result['user_id'] ?? 0);
                $_SESSION['verification_email'] = $values['email'];

                try {
                    $verification = new EmailVerification($db);
                    $delivery = $verification->sendForUser($userId);
                    $_SESSION['verification_notice'] = !empty($delivery['sent'])
                        ? 'We sent a secure verification link to your email address.'
                        : 'Your account was created, but the verification email could not be sent yet. Please request another link.';
                } catch (Throwable $mailException) {
                    error_log('Initial verification email delivery failed for user ' . $userId . '.');
                    $_SESSION['verification_notice'] = 'Your account was created, but the verification email could not be sent yet. Please request another link.';
                }

                try {
                    $notifications = new NotificationService($db);
                    $notifications->dispatchBestEffort(
                        is_array($result['notification_event_keys'] ?? null)
                            ? $result['notification_event_keys']
                            : []
                    );
                } catch (Throwable $notificationException) {
                    error_log('The signup welcome notification remains queued for retry.');
                }

                redirect('/resend-verification.php?registered=1', 303);
            }
            $errors['form'] = $result['message'] ?? 'We could not create your account.';
        } catch (Throwable $exception) {
            error_log('Registration failed: ' . $exception->getMessage());
            $errors['form'] = 'We could not create your account right now. Please try again.';
        }
    }
}

$pageTitle = 'Create account';
$pageNoIndex = true;
require_once __DIR__ . '/templates/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div class="auth-layout auth-layout-register">
            <aside class="auth-story" aria-label="Start learning with Umsad Tech">
                <a class="auth-brand" href="<?php echo APP_URL; ?>" aria-label="Umsad Tech home">
                    <span class="brand-logo-frame brand-logo-frame--auth" aria-hidden="true">
                        <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024">
                    </span>
                </a>
                <span class="eyebrow text-white">Start for free</span>
                <h1>Turn curiosity into real-world capability.</h1>
                <p>Create your learner profile and get a clear path from first lesson to finished project.</p>
                <div class="auth-metric-grid" aria-label="Platform highlights">
                    <div><strong>Self-paced</strong><span>Learn on your schedule</span></div>
                    <div><strong>Practical</strong><span>Build as you learn</span></div>
                </div>
            </aside>

            <div class="auth-panel">
                <div class="auth-panel-header">
                    <span class="auth-icon"><i class="fas fa-user-plus" aria-hidden="true"></i></span>
                    <div>
                        <p class="auth-kicker">Join the community</p>
                        <h2>Create your account</h2>
                    </div>
                </div>

                <?php if (!empty($errors['form'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <?php echo sanitize($errors['form']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" novalidate>
                    <?php echo csrfField(); ?>

                    <div class="form-field">
                        <label for="full_name" class="form-label">Full name</label>
                        <div class="field-control <?php echo hasError('full_name', $errors) ? 'has-error' : ''; ?>">
                            <i class="far fa-user" aria-hidden="true"></i>
                            <input type="text" id="full_name" name="full_name" value="<?php echo sanitize($values['full_name']); ?>"
                                   autocomplete="name" placeholder="Your full name" required autofocus>
                        </div>
                        <?php if (hasError('full_name', $errors)): ?>
                            <p class="form-error"><?php echo sanitize(getError('full_name', $errors)); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="email" class="form-label">Email address</label>
                        <div class="field-control <?php echo hasError('email', $errors) ? 'has-error' : ''; ?>">
                            <i class="far fa-envelope" aria-hidden="true"></i>
                            <input type="email" id="email" name="email" value="<?php echo sanitize($values['email']); ?>"
                                   autocomplete="email" inputmode="email" placeholder="you@example.com" required>
                        </div>
                        <?php if (hasError('email', $errors)): ?>
                            <p class="form-error"><?php echo sanitize(getError('email', $errors)); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label for="password" class="form-label">Password</label>
                            <div class="field-control <?php echo hasError('password', $errors) ? 'has-error' : ''; ?>">
                                <i class="fas fa-lock" aria-hidden="true"></i>
                                <input type="password" id="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" placeholder="10+ characters" required>
                                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', 'passwordIcon')" aria-label="Show or hide password">
                                    <i id="passwordIcon" class="far fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <?php if (hasError('password', $errors)): ?>
                                <p class="form-error"><?php echo sanitize(getError('password', $errors)); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-field">
                            <label for="password_confirm" class="form-label">Confirm password</label>
                            <div class="field-control <?php echo hasError('password_confirm', $errors) ? 'has-error' : ''; ?>">
                                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                <input type="password" id="password_confirm" name="password_confirm" minlength="10" maxlength="72" autocomplete="new-password" placeholder="Repeat password" required>
                            </div>
                            <?php if (hasError('password_confirm', $errors)): ?>
                                <p class="form-error"><?php echo sanitize(getError('password_confirm', $errors)); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <label class="check-row">
                        <input type="checkbox" name="terms" value="1" <?php echo isset($_POST['terms']) ? 'checked' : ''; ?> required>
                        <span>I agree to the <a href="<?php echo APP_URL; ?>/terms.php" target="_blank" rel="noopener">Terms of Use</a> and <a href="<?php echo APP_URL; ?>/privacy.php" target="_blank" rel="noopener">Privacy Policy</a>.</span>
                    </label>
                    <?php if (hasError('terms', $errors)): ?>
                        <p class="form-error"><?php echo sanitize(getError('terms', $errors)); ?></p>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        Create free account <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>
                    <p class="verification-footnote"><i class="fas fa-envelope-circle-check" aria-hidden="true"></i> We’ll email you a secure link to verify your account.</p>
                </form>

                <p class="auth-switch">Already learning with us? <a href="<?php echo APP_URL; ?>/login.php">Sign in</a></p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
