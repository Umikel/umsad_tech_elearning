<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

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

$errors = [];
$form = [
    'full_name' => trim((string) ($_POST['full_name'] ?? $user['full_name'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? $user['phone'] ?? '')),
    'bio' => trim((string) ($_POST['bio'] ?? $user['bio'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!umsadRouteCsrfValid($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    }
    if (mb_strlen($form['full_name']) < 2 || mb_strlen($form['full_name']) > 120) {
        $errors['full_name'] = 'Your name must be between 2 and 120 characters.';
    }
    if ($form['phone'] !== '' && (!preg_match('/^[0-9+() .\-]{7,20}$/', $form['phone']))) {
        $errors['phone'] = 'Enter a valid phone number using 7–20 digits and common symbols.';
    }
    if (mb_strlen($form['bio']) > 1000) {
        $errors['bio'] = 'Keep your bio under 1,000 characters.';
    }

    if (!$errors) {
        $db->query('UPDATE users SET full_name = :full_name, phone = :phone, bio = :bio, updated_at = NOW() WHERE id = :id');
        $db->bind(':full_name', $form['full_name']);
        $db->bind(':phone', $form['phone'] !== '' ? $form['phone'] : null);
        $db->bind(':bio', $form['bio'] !== '' ? $form['bio'] : null);
        $db->bind(':id', (int) $auth->getUserId());
        $db->execute();
        $_SESSION['full_name'] = $form['full_name'];
        $_SESSION['message'] = 'Your profile has been updated.';
        header('Location: ' . APP_URL . '/profile.php');
        exit;
    }
}

$initials = '';
foreach (preg_split('/\s+/', trim((string) $user['full_name'])) ?: [] as $part) {
    if ($part !== '') {
        $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
    }
    if (strlen($initials) >= 2) {
        break;
    }
}
$initials = strtoupper($initials ?: 'U');
$profileFields = [$user['full_name'] ?? '', $user['email'] ?? '', $user['phone'] ?? '', $user['bio'] ?? ''];
$completedFields = count(array_filter($profileFields, static fn($value) => trim((string) $value) !== ''));
$profileCompletion = (int) round(($completedFields / count($profileFields)) * 100);

$pageTitle = 'My Profile';
$pageNoIndex = true;
require_once __DIR__ . '/templates/header.php';
?>

<style>
    .account-wrap { margin: 1rem auto 4rem; }
    .account-heading h1 { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.045em; }
    .profile-summary, .account-card { border: 1px solid #e8e8f2; border-radius: 22px; background: #fff; box-shadow: 0 12px 35px rgba(35,37,82,.06); }
    .profile-summary { overflow: hidden; }
    .profile-summary__cover { height: 110px; background: linear-gradient(135deg, #252957, #6565e7 65%, #8a65dc); }
    .profile-summary__body { padding: 0 1.75rem 1.75rem; }
    .profile-avatar { display: grid; place-items: center; width: 92px; height: 92px; margin-top: -46px; border: 6px solid #fff; border-radius: 24px; background: #ececff; color: #5054d4; font-family: 'Space Grotesk', sans-serif; font-size: 1.7rem; font-weight: 700; box-shadow: 0 8px 22px rgba(28,30,69,.13); }
    .role-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .7rem; border-radius: 999px; color: #5155cf; background: #eeeefe; font-size: .72rem; font-weight: 800; text-transform: capitalize; }
    .account-card { padding: clamp(1.5rem, 4vw, 2.5rem); }
    .account-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 1.45rem; letter-spacing: -.035em; }
    .account-card .form-control { border-radius: 11px; border-color: #dedfeb; }
    .account-card textarea { min-height: 135px; resize: vertical; }
    .completion-track { height: 7px; overflow: hidden; border-radius: 999px; background: #ececf3; }
    .completion-track span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #5b5fe8, #8d63de); }
</style>

<div class="container account-wrap">
    <header class="account-heading d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <span class="text-primary fw-bold small text-uppercase">Account</span>
            <h1 class="mt-2 mb-1">Your profile</h1>
            <p class="text-muted mb-0">Keep your personal details accurate and up to date.</p>
        </div>
        <a href="<?php echo APP_URL; ?>/settings.php" class="btn btn-outline-primary"><i class="fas fa-gear me-2"></i>Account settings</a>
    </header>

    <div class="row g-4">
        <aside class="col-lg-4">
            <section class="profile-summary">
                <div class="profile-summary__cover"></div>
                <div class="profile-summary__body">
                    <div class="profile-avatar" aria-hidden="true"><?php echo sanitize($initials); ?></div>
                    <h2 class="h4 mt-3 mb-1"><?php echo sanitize($user['full_name']); ?></h2>
                    <p class="text-muted small mb-3"><?php echo sanitize($user['email']); ?></p>
                    <span class="role-pill"><i class="fas fa-circle-check"></i><?php echo sanitize($user['user_type']); ?></span>
                    <hr class="my-4">
                    <div class="d-flex justify-content-between small mb-2"><span class="text-muted">Profile completion</span><strong><?php echo $profileCompletion; ?>%</strong></div>
                    <div class="completion-track" role="progressbar" aria-label="Profile completion" aria-valuenow="<?php echo $profileCompletion; ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo $profileCompletion; ?>%"></span></div>
                    <p class="text-muted small mt-3 mb-0"><i class="far fa-calendar me-2"></i>Member since <?php echo formatDate($user['created_at'], 'M Y'); ?></p>
                </div>
            </section>
        </aside>

        <div class="col-lg-8">
            <section class="account-card">
                <div class="mb-4">
                    <h2 class="mb-1">Personal information</h2>
                    <p class="text-muted mb-0">This information helps personalize your learning experience.</p>
                </div>

                <?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(umsadRouteCsrfToken()); ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="full_name" class="form-label">Full name</label>
                            <input id="full_name" name="full_name" maxlength="120" autocomplete="name" class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($form['full_name']); ?>" required>
                            <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['full_name']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone number <span class="text-muted fw-normal">(optional)</span></label>
                            <input id="phone" name="phone" maxlength="20" autocomplete="tel" class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($form['phone']); ?>" placeholder="+234 800 000 0000">
                            <?php if (isset($errors['phone'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['phone']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label for="bio" class="form-label">Short bio <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea id="bio" name="bio" maxlength="1000" class="form-control <?php echo isset($errors['bio']) ? 'is-invalid' : ''; ?>" placeholder="Share a little about your goals or experience."><?php echo sanitize($form['bio']); ?></textarea>
                            <?php if (isset($errors['bio'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['bio']); ?></div><?php endif; ?>
                            <div class="form-text">Up to 1,000 characters.</div>
                        </div>
                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4">Save changes <i class="fas fa-check ms-2"></i></button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
