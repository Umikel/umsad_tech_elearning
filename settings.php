<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/EmailVerification.php';

$db = new Database();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || !$auth->verifySession()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

if (!function_exists('umsadRouteCsrfToken')) {
    function umsadRouteCsrfToken(): string
    {
        if (function_exists('csrfToken')) {
            return (string) csrfToken();
        }
        if (function_exists('csrf_token')) {
            return (string) csrf_token();
        }
        if (empty($_SESSION['_umsad_route_csrf'])) {
            $_SESSION['_umsad_route_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_umsad_route_csrf'];
    }
}

if (!function_exists('umsadRouteCsrfValid')) {
    function umsadRouteCsrfValid(?string $token): bool
    {
        if (function_exists('verifyCsrfToken')) {
            return (bool) verifyCsrfToken($token);
        }
        if (function_exists('verify_csrf_token')) {
            return (bool) verify_csrf_token($token ?? '');
        }
        $expected = (string) ($_SESSION['_umsad_route_csrf'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}

$user = $auth->getUser();
if (!$user) {
    $auth->logout();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Auth deliberately omits password hashes from the general user object.
// Fetch only the current account's credential for explicit re-authentication.
$db->query('SELECT password FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
$db->bind(':id', (int) $auth->getUserId());
$credential = $db->single();
if (!$credential || !isset($credential['password'])) {
    $auth->logout();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
$currentPasswordHash = (string) $credential['password'];

$errors = [];
$activeForm = (string) ($_POST['action'] ?? 'email');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!umsadRouteCsrfValid($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($activeForm === 'email') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $currentPassword = (string) ($_POST['current_password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!password_verify($currentPassword, $currentPasswordHash)) {
            $errors['current_password'] = 'Your current password is incorrect.';
        }
        if (!$errors && $email !== strtolower((string) $user['email'])) {
            $db->query('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $db->bind(':email', $email);
            $db->bind(':id', (int) $auth->getUserId());
            if ($db->single()) {
                $errors['email'] = 'That email address is already in use.';
            }
        }
        $emailChanged = $email !== strtolower((string) $user['email']);
        if (!$errors && $emailChanged && (!defined('MAIL_CONFIGURED') || !MAIL_CONFIGURED)) {
            $errors['form'] = 'Email changes are temporarily unavailable while verification delivery is being configured.';
        }
        if (!$errors && !$emailChanged) {
            $_SESSION['message'] = 'Your sign-in email is already up to date.';
            header('Location: ' . APP_URL . '/settings.php', true, 303);
            exit;
        }
        if (!$errors && $emailChanged) {
            $userId = (int) $auth->getUserId();
            $previousEmail = strtolower((string) $user['email']);
            $previousVerifiedAt = (string) $user['email_verified_at'];

            try {
                $db->beginTransaction();
                $db->query(' 
                    UPDATE users
                    SET email = :email, email_verified_at = NULL, updated_at = NOW()
                    WHERE id = :id AND is_active = 1
                ');
                $db->bind(':email', $email);
                $db->bind(':id', $userId);
                $db->execute();

                $db->query('DELETE FROM email_verification_tokens WHERE user_id = :user_id');
                $db->bind(':user_id', $userId);
                $db->execute();
                $db->commit();
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                    $errors['email'] = 'That email address is already in use.';
                } else {
                    error_log('Email address update failed for user ' . $userId . '.');
                    $errors['form'] = 'Your email address could not be updated. Please try again.';
                }
            }

            if (!$errors) {
                $deliverySucceeded = false;
                try {
                    $verification = new EmailVerification($db);
                    $delivery = $verification->sendForUser($userId);
                    $deliverySucceeded = !empty($delivery['sent']);
                } catch (Throwable $mailException) {
                    error_log('Changed-address verification delivery failed for user ' . $userId . '.');
                }

                if (!$deliverySucceeded) {
                    // Do not strand a signed-in user behind an address that
                    // could not receive its confirmation message. Restore the
                    // previously verified address unless the new address was
                    // concurrently verified in another request.
                    $restored = false;
                    try {
                        $db->beginTransaction();
                        $db->query(' 
                            UPDATE users
                            SET email = :previous_email,
                                email_verified_at = :previous_verified_at,
                                updated_at = NOW()
                            WHERE id = :id AND email = :new_email AND email_verified_at IS NULL
                        ');
                        $db->bind(':previous_email', $previousEmail);
                        $db->bind(':previous_verified_at', $previousVerifiedAt);
                        $db->bind(':id', $userId);
                        $db->bind(':new_email', $email);
                        $db->execute();
                        $restored = $db->rowCount() === 1;

                        if ($restored) {
                            $db->query('DELETE FROM email_verification_tokens WHERE user_id = :user_id');
                            $db->bind(':user_id', $userId);
                            $db->execute();
                        }
                        $db->commit();
                    } catch (Throwable $restoreException) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        error_log('The previous verified email could not be restored for user ' . $userId . '.');
                    }

                    if ($restored) {
                        $_POST['email'] = $previousEmail;
                        $errors['form'] = 'Your email was not changed because its verification message could not be delivered. Your existing sign-in address is still active.';
                    } else {
                        $auth->logout();
                        ensureSecuritySession();
                        $_SESSION['verification_email'] = $email;
                        $_SESSION['verification_notice'] = 'Your address may have changed, but delivery could not be confirmed. Request a new link or contact support.';
                        redirect('/resend-verification.php?changed=1', 303);
                    }
                } else {
                    $auth->logout();
                    ensureSecuritySession();
                    $_SESSION['verification_email'] = $email;
                    $_SESSION['verification_notice'] = 'Your sign-in email was changed. Check your inbox to verify the new address.';
                    redirect('/resend-verification.php?changed=1', 303);
                }
            }
        }
    } elseif ($activeForm === 'password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, $currentPasswordHash)) {
            $errors['password_current'] = 'Your current password is incorrect.';
        }
        if (strlen($newPassword) < 10 || strlen($newPassword) > 72 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            $errors['new_password'] = 'Use 10–72 characters with a letter and a number.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'The new passwords do not match.';
        }
        if ($currentPassword !== '' && hash_equals($currentPassword, $newPassword)) {
            $errors['new_password'] = 'Choose a password different from your current one.';
        }
        if (!$errors) {
            $hash = password_hash($newPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);
            if ($hash === false) {
                $errors['form'] = 'Your password could not be secured. Please try again.';
            }
        }
        if (!$errors && $activeForm === 'password') {
            $db->query('UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id');
            $db->bind(':password', $hash);
            $db->bind(':id', (int) $auth->getUserId());
            $db->execute();
            if (!headers_sent() && session_regenerate_id(true)) {
                $_SESSION['_last_regenerated'] = time();
            }
            $_SESSION['message'] = 'Your password has been changed securely.';
            header('Location: ' . APP_URL . '/settings.php');
            exit;
        }
    } else {
        $errors['form'] = 'The requested settings action is not available.';
    }
}

$pageTitle = 'Account Settings';
$pageNoIndex = true;
require_once __DIR__ . '/templates/header.php';
?>

<style>
    .settings-wrap { max-width: 1050px; margin: 1rem auto 4rem; }
    .settings-heading h1 { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.045em; }
    .settings-nav, .settings-card { border: 1px solid #e8e8f2; border-radius: 20px; background: #fff; box-shadow: 0 12px 35px rgba(35,37,82,.055); }
    .settings-nav { padding: 1rem; }
    .settings-nav a { display: flex; align-items: center; gap: .75rem; padding: .8rem .9rem; border-radius: 10px; color: #55576d; font-weight: 600; text-decoration: none; }
    .settings-nav a:hover, .settings-nav a.active { color: #4e52d1; background: #eeeeff; }
    .settings-card { padding: clamp(1.5rem, 4vw, 2.5rem); scroll-margin-top: 1rem; }
    .settings-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 1.4rem; letter-spacing: -.03em; }
    .settings-icon { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; color: #565add; background: #ededff; }
    .settings-card .form-control { border-radius: 11px; border-color: #dedfeb; }
    .security-note { padding: 1rem; border-radius: 12px; color: #4e5065; background: #f6f6fb; font-size: .86rem; }
</style>

<div class="container settings-wrap">
    <header class="settings-heading mb-4">
        <span class="text-primary fw-bold small text-uppercase">Account</span>
        <h1 class="mt-2 mb-1">Settings & security</h1>
        <p class="text-muted mb-0">Manage how you sign in and protect your account.</p>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <div class="row g-4">
        <aside class="col-lg-3">
            <nav class="settings-nav position-sticky" style="top:1rem" aria-label="Settings sections">
                <a href="#email" class="active"><i class="far fa-envelope"></i> Sign-in email</a>
                <a href="#password"><i class="fas fa-lock"></i> Password</a>
                <a href="<?php echo APP_URL; ?>/profile.php"><i class="far fa-user"></i> Public profile</a>
            </nav>
        </aside>

        <div class="col-lg-9">
            <section id="email" class="settings-card mb-4">
                <div class="d-flex gap-3 align-items-start mb-4">
                    <span class="settings-icon"><i class="far fa-envelope"></i></span>
                    <div><h2 class="mb-1">Sign-in email</h2><p class="text-muted mb-0">Changing this address requires email verification and signs you out securely.</p></div>
                </div>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(umsadRouteCsrfToken()); ?>">
                    <input type="hidden" name="action" value="email">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label for="email_address" class="form-label">Email address</label>
                            <input id="email_address" name="email" type="email" maxlength="255" autocomplete="email" class="form-control <?php echo $activeForm === 'email' && isset($errors['email']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize((string) ($_POST['email'] ?? $user['email'])); ?>" required>
                            <?php if ($activeForm === 'email' && isset($errors['email'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['email']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label for="email_password" class="form-label">Current password</label>
                            <input id="email_password" name="current_password" type="password" autocomplete="current-password" class="form-control <?php echo $activeForm === 'email' && isset($errors['current_password']) ? 'is-invalid' : ''; ?>" required>
                            <?php if ($activeForm === 'email' && isset($errors['current_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['current_password']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit">Update email</button></div>
                    </div>
                </form>
            </section>

            <section id="password" class="settings-card">
                <div class="d-flex gap-3 align-items-start mb-4">
                    <span class="settings-icon"><i class="fas fa-key"></i></span>
                    <div><h2 class="mb-1">Change password</h2><p class="text-muted mb-0">Choose a strong password you do not use elsewhere.</p></div>
                </div>
                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(umsadRouteCsrfToken()); ?>">
                    <input type="hidden" name="action" value="password">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="password_current" class="form-label">Current password</label>
                            <input id="password_current" name="current_password" type="password" autocomplete="current-password" class="form-control <?php echo $activeForm === 'password' && isset($errors['password_current']) ? 'is-invalid' : ''; ?>" required>
                            <?php if ($activeForm === 'password' && isset($errors['password_current'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['password_current']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="new_password" class="form-label">New password</label>
                            <input id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password" class="form-control <?php echo $activeForm === 'password' && isset($errors['new_password']) ? 'is-invalid' : ''; ?>" required>
                            <?php if ($activeForm === 'password' && isset($errors['new_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['new_password']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm new password</label>
                            <input id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" class="form-control <?php echo $activeForm === 'password' && isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" required>
                            <?php if ($activeForm === 'password' && isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['confirm_password']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-12"><div class="security-note"><i class="fas fa-shield-halved me-2"></i>Use 10–72 characters, including a letter and a number.</div></div>
                        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit">Change password</button></div>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
