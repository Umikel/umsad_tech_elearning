<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);

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

$errors = [];
$form = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'subject' => trim((string) ($_POST['subject'] ?? $_GET['subject'] ?? '')),
    'message' => trim((string) ($_POST['message'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!umsadRouteCsrfValid($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your session expired. Please refresh the page and try again.';
    }
    if ($form['name'] === '' || mb_strlen($form['name']) < 2 || mb_strlen($form['name']) > 120) {
        $errors['name'] = 'Enter a name between 2 and 120 characters.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 255) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (mb_strlen($form['subject']) > 180) {
        $errors['subject'] = 'Keep the subject under 180 characters.';
    }
    if (mb_strlen($form['message']) < 10 || mb_strlen($form['message']) > 5000) {
        $errors['message'] = 'Your message must be between 10 and 5,000 characters.';
    }

    if (!$errors) {
        $db->query('INSERT INTO contact_messages (name, email, subject, message, created_at) VALUES (:name, :email, :subject, :message, NOW())');
        $db->bind(':name', $form['name']);
        $db->bind(':email', strtolower($form['email']));
        $db->bind(':subject', $form['subject'] !== '' ? $form['subject'] : null);
        $db->bind(':message', $form['message']);
        $db->execute();
        $_SESSION['message'] = 'Thanks for reaching out. Your message has been received.';
        header('Location: ' . APP_URL . '/contact.php');
        exit;
    }
}

$pageTitle = 'Contact Us';
require_once __DIR__ . '/templates/header.php';
?>

<style>
    .contact-shell { margin: 1rem auto 4rem; }
    .contact-intro { padding: clamp(2rem, 5vw, 4.5rem); border-radius: 28px 0 0 28px; color: #fff; background: linear-gradient(145deg, #171934, #32377e); }
    .contact-intro h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.5rem, 5vw, 4.3rem); line-height: 1.04; letter-spacing: -.055em; }
    .contact-intro > p { color: rgba(255,255,255,.72); line-height: 1.75; }
    .contact-point { display: flex; gap: 1rem; align-items: flex-start; margin-top: 1.5rem; }
    .contact-point__icon { flex: 0 0 42px; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; color: #c8caff; background: rgba(255,255,255,.1); }
    .contact-point small { display: block; color: rgba(255,255,255,.55); }
    .contact-card { height: 100%; padding: clamp(2rem, 5vw, 4.5rem); border: 1px solid #e8e8f2; border-left: 0; border-radius: 0 28px 28px 0; background: #fff; box-shadow: 0 20px 55px rgba(32,34,73,.09); }
    .contact-card h2 { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.035em; }
    .contact-card .form-control { border-radius: 11px; border-color: #dedfeb; padding: .85rem 1rem; }
    .contact-card textarea { min-height: 150px; resize: vertical; }
    @media (max-width: 991.98px) { .contact-intro { border-radius: 24px 24px 0 0; } .contact-card { border-left: 1px solid #e8e8f2; border-top: 0; border-radius: 0 0 24px 24px; } }
</style>

<div class="container contact-shell">
    <div class="row g-0 align-items-stretch">
        <div class="col-lg-5">
            <section class="contact-intro h-100">
                <span class="eyebrow text-white">Let’s connect</span>
                <h1 class="mt-3 mb-4">How can we help?</h1>
                <p>Questions about courses, enrollment, or your learning journey? Send us a message and our team will take a look.</p>

                <div class="contact-point">
                    <span class="contact-point__icon"><i class="fas fa-envelope"></i></span>
                    <div><strong>Email support</strong><small>support@umsadtech.com</small></div>
                </div>
                <div class="contact-point">
                    <span class="contact-point__icon"><i class="fas fa-location-dot"></i></span>
                    <div><strong>Based in Abuja</strong><small>Serving learners everywhere</small></div>
                </div>
                <div class="contact-point">
                    <span class="contact-point__icon"><i class="fas fa-clock"></i></span>
                    <div><strong>Response time</strong><small>Usually within two business days</small></div>
                </div>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="contact-card">
                <span class="about-kicker text-primary fw-bold small text-uppercase">Send a message</span>
                <h2 class="mt-2 mb-4">Tell us what you need.</h2>

                <?php if (isset($errors['form'])): ?>
                    <div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(umsadRouteCsrfToken()); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Your name</label>
                            <input id="name" name="name" type="text" maxlength="120" autocomplete="name" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($form['name']); ?>" required>
                            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email address</label>
                            <input id="email" name="email" type="email" maxlength="255" autocomplete="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($form['email']); ?>" required>
                            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['email']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label for="subject" class="form-label">Subject <span class="text-muted fw-normal">(optional)</span></label>
                            <input id="subject" name="subject" type="text" maxlength="180" class="form-control <?php echo isset($errors['subject']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($form['subject']); ?>" placeholder="How can we help?">
                            <?php if (isset($errors['subject'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['subject']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label for="message" class="form-label">Message</label>
                            <textarea id="message" name="message" maxlength="5000" class="form-control <?php echo isset($errors['message']) ? 'is-invalid' : ''; ?>" required><?php echo sanitize($form['message']); ?></textarea>
                            <?php if (isset($errors['message'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['message']); ?></div><?php endif; ?>
                        </div>
                        <div class="col-12 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mt-4">
                            <small class="text-muted"><i class="fas fa-shield-halved me-1"></i> Your details are used only to respond.</small>
                            <button type="submit" class="btn btn-primary px-4">Send message <i class="fas fa-paper-plane ms-2"></i></button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
