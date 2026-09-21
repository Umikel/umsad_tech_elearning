<?php
$pageTitle = 'Privacy Policy';
require_once __DIR__ . '/templates/header.php';
?>

<style>
    .legal-wrap { max-width: 900px; margin: 1rem auto 5rem; }
    .legal-hero { padding: clamp(2rem, 6vw, 4rem); border-radius: 24px; color: #fff; background: linear-gradient(135deg, #171934, #34397f); }
    .legal-hero h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.4rem, 5vw, 4rem); letter-spacing: -.05em; }
    .legal-card { margin-top: -1.2rem; padding: clamp(1.5rem, 5vw, 3.5rem); border: 1px solid #e7e7f1; border-radius: 20px; background: #fff; box-shadow: 0 16px 40px rgba(35,37,82,.08); }
    .legal-card h2 { margin-top: 2rem; font-family: 'Space Grotesk', sans-serif; font-size: 1.25rem; }
    .legal-card p { color: #66687b; line-height: 1.75; }
</style>

<div class="container legal-wrap">
    <header class="legal-hero">
        <span class="eyebrow text-white">Your information</span>
        <h1 class="mt-3 mb-2">Privacy policy</h1>
        <p class="text-white-50 mb-0">A plain-language overview of how Umsad Tech handles platform data.</p>
    </header>
    <article class="legal-card">
        <p class="small text-muted">Last updated: 20 August 2026</p>
        <h2>Information we collect</h2>
        <p>We store information you provide when creating or updating an account, contacting support, enrolling in courses, completing lessons, or making a payment. This can include your name, email address, phone number, learning progress, and payment reference. Card details are handled by the payment provider and are not stored by this platform.</p>
        <h2>How information is used</h2>
        <p>We use account data to provide access, maintain learning records, process enrollment, respond to support requests, improve course delivery, and protect the platform from misuse.</p>
        <h2>Sharing and retention</h2>
        <p>We do not sell personal information. Data may be shared with service providers only when needed to operate the platform or process a requested service. Records are retained for as long as reasonably necessary for those purposes and applicable obligations.</p>
        <h2>Your choices</h2>
        <p>You can update core account information from your profile and settings pages. To ask about access, correction, or deletion of other personal data, contact support. Some learning or transaction records may need to be retained.</p>
        <h2>Security and contact</h2>
        <p>We use reasonable technical and organizational safeguards, but no online service can promise absolute security. Never share your password. Questions about this policy can be sent through our <a href="<?php echo APP_URL; ?>/contact.php">contact page</a>.</p>
    </article>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
