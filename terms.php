<?php
$pageTitle = 'Terms of Service';
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
        <span class="eyebrow text-white">Platform agreement</span>
        <h1 class="mt-3 mb-2">Terms of service</h1>
        <p class="text-white-50 mb-0">The basic rules that help keep learning fair, safe, and useful.</p>
    </header>
    <article class="legal-card">
        <p class="small text-muted">Last updated: 20 August 2026</p>
        <h2>Using Umsad Tech</h2>
        <p>You must provide accurate account information, keep your login credentials private, and use the platform only for lawful learning purposes. You are responsible for activity performed through your account.</p>
        <h2>Courses and content</h2>
        <p>Course materials are provided for personal learning unless stated otherwise. You may not copy, resell, publish, scrape, or redistribute content without permission. Course availability and lesson content can change as instructors improve the catalog.</p>
        <h2>Payments and access</h2>
        <p>Paid enrollment is activated only after successful payment verification. Prices are shown before checkout. Any refund request is considered according to the circumstances of the purchase and applicable law; contact support with the payment reference.</p>
        <h2>Acceptable conduct</h2>
        <p>Do not attempt to bypass access controls, interfere with the service, upload harmful material, misuse another person’s account, or disrupt other learners. Access may be restricted when necessary to protect users or the platform.</p>
        <h2>Availability and responsibility</h2>
        <p>We work to keep the service reliable, but uninterrupted access is not guaranteed. Educational content supports skill development and does not guarantee employment, income, third-party certification, or a particular outcome.</p>
        <h2>Questions</h2>
        <p>These terms work alongside our <a href="<?php echo APP_URL; ?>/privacy.php">privacy policy</a>. For questions about enrollment, payments, or this agreement, use our <a href="<?php echo APP_URL; ?>/contact.php">contact page</a>.</p>
    </article>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
