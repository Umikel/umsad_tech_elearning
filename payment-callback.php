<?php
/**
 * Browser return page after Paystack Checkout.
 *
 * A browser redirect is never treated as proof of payment. This page asks the
 * authenticated verification API to confirm the transaction with Paystack.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$db = new Database();
$auth = new Auth($db);
$reference = paymentReference($_GET['reference'] ?? $_GET['trxref'] ?? '');
$validReference = $reference !== '';
$hasValidSession = $validReference && $auth->verifySession();
$canVerify = $hasValidSession && $auth->isStudent();

$pageTitle = 'Confirming your payment';
$pageNoIndex = true;
$pageReferrerPolicy = 'no-referrer';
require_once __DIR__ . '/templates/header.php';
?>

<section class="payment-return-section" aria-labelledby="payment-return-title">
    <div class="payment-return-glow" aria-hidden="true"></div>
    <div class="container position-relative">
        <div class="payment-return-card">
            <?php if (!$validReference): ?>
                <span class="payment-return-icon payment-return-icon-error" aria-hidden="true">
                    <i class="fas fa-triangle-exclamation"></i>
                </span>
                <p class="eyebrow eyebrow-dark">Payment confirmation</p>
                <h1 id="payment-return-title">We could not identify this payment.</h1>
                <p>The return link is incomplete. No course access has been granted and no payment status was assumed.</p>
                <a class="btn btn-primary" href="<?php echo APP_URL; ?>/courses.php">Return to courses</a>
            <?php elseif (!$hasValidSession): ?>
                <span class="payment-return-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                <p class="eyebrow eyebrow-dark">Payment received</p>
                <h1 id="payment-return-title">Sign in to finish confirmation.</h1>
                <p>Use the same learner account that started the payment. Paystack’s server notification can still confirm a successful charge in the background.</p>
                <a class="btn btn-primary" href="<?php echo sanitize(appUrl('/login.php?payment_reference=' . rawurlencode($reference))); ?>">Sign in securely</a>
            <?php elseif (!$auth->isStudent()): ?>
                <span class="payment-return-icon" aria-hidden="true"><i class="fas fa-user-lock"></i></span>
                <p class="eyebrow eyebrow-dark">Learner account required</p>
                <h1 id="payment-return-title">Switch to the learner account used at checkout.</h1>
                <p>You are currently signed in with a staff account. Sign out, then use the learner account that started this payment.</p>
                <a class="btn btn-primary" href="<?php echo sanitize(appUrl('/logout.php?payment_reference=' . rawurlencode($reference))); ?>">Switch accounts securely</a>
            <?php else: ?>
                <span class="payment-return-icon" id="paymentStatusIcon" aria-hidden="true">
                    <span class="spinner-border" role="presentation"></span>
                </span>
                <p class="eyebrow eyebrow-dark">Secure verification</p>
                <h1 id="payment-return-title">Confirming your payment…</h1>
                <p id="paymentStatusMessage" role="status" aria-live="polite">Please keep this page open while we confirm the transaction directly with Paystack.</p>
                <button class="btn btn-primary d-none" type="button" id="retryPaymentVerification">Try again</button>
                <a class="btn btn-primary d-none" id="switchPaymentAccount" href="<?php echo sanitize(appUrl('/logout.php?payment_reference=' . rawurlencode($reference))); ?>">Switch accounts securely</a>
                <a class="btn btn-primary d-none" id="returnToPaymentCourse" href="<?php echo APP_URL; ?>/courses.php">Return to course</a>
                <a class="payment-return-link" href="<?php echo APP_URL; ?>/student/my-courses.php">Go to my courses</a>
            <?php endif; ?>

            <div class="payment-return-trust">
                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                <span>Your card details are handled by Paystack and are never stored by Umsad Tech.</span>
            </div>
        </div>
    </div>
</section>

<?php if ($canVerify): ?>
<script>
(() => {
    const appUrl = <?php echo json_encode(APP_URL, JSON_UNESCAPED_SLASHES); ?>;
    const reference = <?php echo json_encode($reference, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const csrfToken = <?php echo json_encode(csrfToken()); ?>;
    const title = document.getElementById('payment-return-title');
    const message = document.getElementById('paymentStatusMessage');
    const icon = document.getElementById('paymentStatusIcon');
    const retryButton = document.getElementById('retryPaymentVerification');
    const switchAccountLink = document.getElementById('switchPaymentAccount');
    const returnToCourseLink = document.getElementById('returnToPaymentCourse');

    function resetActions() {
        retryButton.classList.add('d-none');
        retryButton.disabled = true;
        switchAccountLink.classList.add('d-none');
        returnToCourseLink.classList.add('d-none');
    }

    async function verifyPayment() {
        resetActions();
        title.textContent = 'Confirming your payment…';
        message.textContent = 'Please keep this page open while we confirm the transaction directly with Paystack.';
        icon.className = 'payment-return-icon';
        icon.innerHTML = '<span class="spinner-border" role="presentation"></span>';

        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 45000);
        try {
            const response = await fetch(appUrl + '/api/payments/verify.php', {
                signal: controller.signal,
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ reference })
            });
            let result;
            try {
                result = await response.json();
            } catch (parseError) {
                if (parseError.name === 'AbortError') throw parseError;
                throw new Error('The confirmation service returned an invalid response. Please try again.');
            }

            if (!response.ok || !result.success) {
                const verificationError = new Error(result.message || 'Payment confirmation is not available yet.');
                verificationError.code = result.data && typeof result.data.code === 'string'
                    ? result.data.code
                    : '';
                verificationError.providerStatus = result.data && typeof result.data.provider_status === 'string'
                    ? result.data.provider_status
                    : '';
                verificationError.courseUrl = result.data && typeof result.data.course_url === 'string'
                    ? result.data.course_url
                    : '';
                throw verificationError;
            }

            const redirectPath = result.data && typeof result.data.redirect === 'string'
                ? result.data.redirect
                : '';
            if (redirectPath !== '/student/my-courses.php') {
                throw new Error('Your payment was confirmed, but the course link was invalid. Open My courses to continue.');
            }

            icon.className = 'payment-return-icon payment-return-icon-success';
            icon.innerHTML = '<i class="fas fa-circle-check" aria-hidden="true"></i>';
            title.textContent = 'Payment confirmed.';
            message.textContent = 'Payment received. Learning access requires admin approval. Taking you to My courses…';
            window.setTimeout(() => {
                window.location.assign(appUrl + redirectPath);
            }, 900);
        } catch (error) {
            icon.className = 'payment-return-icon payment-return-icon-error';
            if (error.code === 'payment_not_found') {
                icon.innerHTML = '<i class="fas fa-user-lock" aria-hidden="true"></i>';
                title.textContent = 'Use the learner account that made this payment.';
                message.textContent = 'This reference is not connected to the learner account currently signed in. Switch accounts, then we will continue confirmation.';
                switchAccountLink.classList.remove('d-none');
                return;
            }

            if (error.code === 'payment_failed' || error.code === 'payment_refunded') {
                icon.innerHTML = '<i class="fas fa-circle-xmark" aria-hidden="true"></i>';
                title.textContent = error.code === 'payment_refunded'
                    ? 'This payment was refunded.'
                    : 'This payment was not completed.';
                message.textContent = error.code === 'payment_refunded'
                    ? 'Course access cannot be confirmed from this payment. Contact support if you believe this is incorrect.'
                    : 'Paystack did not confirm a successful charge. Return to the course when you are ready to try a new checkout.';
                if (/^\/course-detail\.php\?id=[1-9][0-9]*$/.test(error.courseUrl || '')) {
                    returnToCourseLink.href = appUrl + error.courseUrl;
                }
                returnToCourseLink.classList.remove('d-none');
                return;
            }

            icon.innerHTML = '<i class="fas fa-clock-rotate-left" aria-hidden="true"></i>';
            title.textContent = 'Confirmation is still pending.';
            message.textContent = error.name === 'AbortError'
                ? 'Confirmation is taking longer than expected. Check My courses or try verification again. You do not need to make another payment.'
                : (error.message || 'We could not confirm this payment yet. Please try again.');
            retryButton.classList.remove('d-none');
            retryButton.disabled = false;
        } finally {
            window.clearTimeout(timeout);
        }
    }

    retryButton.addEventListener('click', verifyPayment);
    verifyPayment();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
