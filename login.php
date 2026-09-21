<?php
/**
 * Sign in to Umsad Tech.
 * Authentication runs before the template emits markup so redirects and
 * session cookies remain reliable on every PHP configuration.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);
$errors = [];
$email = '';
$paymentReferenceInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['payment_reference'] ?? '')
    : ($_GET['payment_reference'] ?? '');
$paymentReference = paymentReference($paymentReferenceInput);
$paymentReturnPath = $paymentReference === ''
    ? ''
    : '/payment-callback.php?reference=' . rawurlencode($paymentReference);

if ($auth->isLoggedIn() && $auth->verifySession()) {
    if ($paymentReturnPath !== '' && $auth->isStudent()) {
        redirect($paymentReturnPath);
    }

    redirect('/' . $auth->getDashboardPath());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors['general'] = 'Your session expired. Refresh the page and try again.';
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    $attemptWindow = (int) ($_SESSION['login_attempt_window'] ?? 0);
    if ($attemptWindow > 0 && time() - $attemptWindow > 900) {
        unset($_SESSION['login_attempts'], $_SESSION['login_attempt_window']);
    }
    if ((int) ($_SESSION['login_attempts'] ?? 0) >= 5) {
        $errors['general'] = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
    }

    if (empty($errors)) {
        $result = $auth->login($email, $password);

        if ($result['success']) {
            unset($_SESSION['login_attempts'], $_SESSION['login_attempt_window']);
            $_SESSION['message'] = 'Welcome back, ' . ($_SESSION['full_name'] ?? 'learner') . '.';

            if ($paymentReturnPath !== '' && $auth->isStudent()) {
                redirect($paymentReturnPath, 303);
            }

            redirect('/' . $auth->getDashboardPath());
        }

        if (($result['code'] ?? '') === 'email_unverified') {
            unset($_SESSION['login_attempts'], $_SESSION['login_attempt_window']);
            $_SESSION['verification_email'] = $email;
            $_SESSION['verification_notice'] = 'Your password is correct. Verify your email address to finish signing in.';
            redirect('/resend-verification.php', 303);
        }

        if (($result['code'] ?? '') === 'verification_unavailable') {
            $errors['general'] = 'Sign-in is temporarily unavailable while account verification is being updated.';
        } else {
            $_SESSION['login_attempts'] = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempt_window'] = $_SESSION['login_attempt_window'] ?? time();
            $errors['general'] = 'We could not sign you in with those details.';
        }
    }
}

$pageTitle = 'Sign in';
$pageNoIndex = true;
$pageReferrerPolicy = 'no-referrer';
require_once __DIR__ . '/templates/header.php';
?>

<section class="auth-section">
    <div class="container">
        <div class="auth-layout">
            <aside class="auth-story" aria-label="Why learn with Umsad Tech">
                <a class="auth-brand" href="<?php echo APP_URL; ?>" aria-label="Umsad Tech home">
                    <span class="brand-logo-frame brand-logo-frame--auth" aria-hidden="true">
                        <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024">
                    </span>
                </a>
                <span class="eyebrow text-white">Welcome back</span>
                <h1>Keep building the skills that move you forward.</h1>
                <p>Return to your courses, track your progress and continue exactly where you stopped.</p>
                <div class="auth-proof">
                    <span><i class="fas fa-circle-check" aria-hidden="true"></i> Practical lessons</span>
                    <span><i class="fas fa-circle-check" aria-hidden="true"></i> Progress tracking</span>
                    <span><i class="fas fa-circle-check" aria-hidden="true"></i> Completion certificates</span>
                </div>
            </aside>

            <div class="auth-panel">
                <div class="auth-panel-header">
                    <span class="auth-icon"><i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i></span>
                    <div>
                        <p class="auth-kicker">Your learning space</p>
                        <h2>Sign in to continue</h2>
                    </div>
                </div>

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <?php echo sanitize($errors['general']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" novalidate>
                    <?php echo csrfField(); ?>
                    <?php if ($paymentReference !== ''): ?>
                        <input type="hidden" name="payment_reference" value="<?php echo sanitize($paymentReference); ?>">
                    <?php endif; ?>

                    <div class="form-field">
                        <label for="email" class="form-label">Email address</label>
                        <div class="field-control <?php echo hasError('email', $errors) ? 'has-error' : ''; ?>">
                            <i class="far fa-envelope" aria-hidden="true"></i>
                            <input type="email" id="email" name="email" value="<?php echo sanitize($email); ?>"
                                   autocomplete="email" inputmode="email" placeholder="you@example.com"
                                   aria-describedby="email-error" required autofocus>
                        </div>
                        <?php if (hasError('email', $errors)): ?>
                            <p class="form-error" id="email-error"><?php echo sanitize(getError('email', $errors)); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <div class="form-label-row">
                            <label for="password" class="form-label">Password</label>
                            <a href="<?php echo APP_URL; ?>/forgot-password.php">Forgot password?</a>
                        </div>
                        <div class="field-control <?php echo hasError('password', $errors) ? 'has-error' : ''; ?>">
                            <i class="fas fa-lock" aria-hidden="true"></i>
                            <input type="password" id="password" name="password" autocomplete="current-password"
                                   placeholder="Enter your password" aria-describedby="password-error" required>
                            <button type="button" class="password-toggle"
                                    onclick="togglePasswordVisibility('password', 'passwordIcon')"
                                    aria-label="Show or hide password">
                                <i id="passwordIcon" class="far fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                        <?php if (hasError('password', $errors)): ?>
                            <p class="form-error" id="password-error"><?php echo sanitize(getError('password', $errors)); ?></p>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        Sign in <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="auth-switch">New to Umsad Tech? <a href="<?php echo APP_URL; ?>/register.php">Create a free account</a></p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
