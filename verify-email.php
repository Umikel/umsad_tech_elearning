<?php
/**
 * Confirm an email-verification token.
 *
 * GET only inspects the token so automated email-link scanners cannot verify
 * an account. The user must submit the CSRF-protected confirmation form.
 */
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
$verification = new EmailVerification($db);

// Replace the emailed token URL immediately with a non-secret, per-tab flow
// URL. Only a SHA-256 digest is retained server-side; the raw bearer token is
// never rendered into HTML or kept in session storage.
$verificationFlows = $_SESSION['_email_verification_flows'] ?? [];
if (!is_array($verificationFlows)) {
    $verificationFlows = [];
}
$flowCutoff = time() - min(SESSION_LIFETIME, EMAIL_VERIFICATION_TTL);
foreach ($verificationFlows as $storedFlowId => $storedFlow) {
    if (!is_array($storedFlow)
        || (int) ($storedFlow['created_at'] ?? 0) < $flowCutoff
        || preg_match('/^[a-f0-9]{32}$/', (string) $storedFlowId) !== 1
        || preg_match('/^[a-f0-9]{64}$/', (string) ($storedFlow['digest'] ?? '')) !== 1) {
        unset($verificationFlows[$storedFlowId]);
    }
}
if ($verificationFlows === []) {
    unset($_SESSION['_email_verification_flows']);
} else {
    $_SESSION['_email_verification_flows'] = $verificationFlows;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && array_key_exists('token', $_GET)) {
    $rawToken = strtolower(trim((string) $_GET['token']));
    if (preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1) {
        $flowId = bin2hex(random_bytes(16));
        $verificationFlows[$flowId] = [
            'digest' => hash('sha256', $rawToken),
            'created_at' => time(),
        ];
        while (count($verificationFlows) > 5) {
            unset($verificationFlows[array_key_first($verificationFlows)]);
        }
        $_SESSION['_email_verification_flows'] = $verificationFlows;
        redirect('/verify-email.php?flow=' . rawurlencode($flowId), 303);
    }
    redirect('/verify-email.php', 303);
}

$flowId = strtolower(trim((string) ($_POST['flow_id'] ?? $_GET['flow'] ?? '')));
$flowIsWellFormed = preg_match('/^[a-f0-9]{32}$/', $flowId) === 1;
$flow = $flowIsWellFormed && isset($verificationFlows[$flowId]) && is_array($verificationFlows[$flowId])
    ? $verificationFlows[$flowId]
    : null;
$tokenDigest = is_array($flow) ? (string) ($flow['digest'] ?? '') : '';
$tokenIsWellFormed = $flowIsWellFormed && preg_match('/^[a-f0-9]{64}$/', $tokenDigest) === 1;
$forgetFlow = static function (string $id) use (&$verificationFlows): void {
    unset($verificationFlows[$id]);
    if ($verificationFlows === []) {
        unset($_SESSION['_email_verification_flows']);
    } else {
        $_SESSION['_email_verification_flows'] = $verificationFlows;
    }
};
$state = 'invalid';
$maskedEmail = '';
$formError = '';

if ($tokenIsWellFormed) {
    $inspection = $verification->inspectTokenDigest($tokenDigest);
    if (!empty($inspection['valid'])) {
        $state = 'confirm';
        $maskedEmail = (string) ($inspection['email_masked'] ?? 'your email address');
    } else {
        $forgetFlow($flowId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Refresh this page and try again.';
    } elseif (!$tokenIsWellFormed) {
        $state = 'invalid';
    } else {
        try {
            $result = $verification->verifyTokenDigest($tokenDigest);
            if (!empty($result['success'])) {
                $_SESSION['message'] = 'Your email address is verified. Sign in to continue.';
                $forgetFlow($flowId);
                unset(
                    $_SESSION['verification_email'],
                    $_SESSION['verification_notice']
                );
                redirect('/login.php', 303);
            }
            $forgetFlow($flowId);
            $state = 'invalid';
        } catch (Throwable $exception) {
            error_log('Email verification could not be completed.');
            $formError = 'We could not verify this address right now. Please try again.';
        }
    }
}

$pageTitle = $state === 'confirm' ? 'Verify your email' : 'Verification link unavailable';
$pageReferrerPolicy = 'no-referrer';
$pageNoIndex = true;
require_once __DIR__ . '/templates/header.php';
?>

<section class="email-verification-section" aria-labelledby="verification-title">
    <div class="verification-orb verification-orb-one" aria-hidden="true"></div>
    <div class="verification-orb verification-orb-two" aria-hidden="true"></div>
    <div class="container position-relative">
        <div class="verification-card">
            <?php if ($state === 'confirm'): ?>
                <span class="verification-icon" aria-hidden="true"><i class="fas fa-envelope-circle-check"></i></span>
                <p class="auth-kicker">One final step</p>
                <h1 id="verification-title">Confirm your email address</h1>
                <p>Verify <strong><?php echo sanitize($maskedEmail); ?></strong> to protect your account and unlock your learning space.</p>

                <?php if ($formError !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <?php echo sanitize($formError); ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="verification-action-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="flow_id" value="<?php echo sanitize($flowId); ?>">
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        Verify email <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>
                <p class="verification-footnote"><i class="fas fa-shield-halved" aria-hidden="true"></i> The link is single-use and expires automatically.</p>
            <?php else: ?>
                <span class="verification-icon verification-icon-warning" aria-hidden="true"><i class="fas fa-link-slash"></i></span>
                <p class="auth-kicker">Email verification</p>
                <h1 id="verification-title">This link is invalid or has expired.</h1>
                <p>Request a fresh verification email and use only the newest link we send you.</p>

                <?php if ($formError !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?php echo sanitize($formError); ?></div>
                <?php endif; ?>

                <a class="btn btn-primary btn-lg w-100" href="<?php echo APP_URL; ?>/resend-verification.php">
                    Send a new link <i class="fas fa-paper-plane" aria-hidden="true"></i>
                </a>
                <a class="verification-secondary-link" href="<?php echo APP_URL; ?>/login.php">Back to sign in</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
