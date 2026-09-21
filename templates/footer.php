    </main>

    <?php if (!empty($useWorkspaceNavigation)): ?>
    <nav class="student-app-bottom-nav" aria-label="Mobile <?php echo sanitize($workspaceLabel); ?> navigation">
        <?php foreach ($workspaceNavItems as $navKey => $navItem): ?>
            <a class="student-app-bottom-nav__item<?php echo $workspaceNavActive === $navKey ? ' is-active' : ''; ?>" href="<?php echo APP_URL . $navItem['path']; ?>"<?php echo $workspaceNavActive === $navKey ? ' aria-current="page"' : ''; ?>>
                <i class="fas <?php echo sanitize($navItem['icon']); ?>" aria-hidden="true"></i>
                <span><?php echo sanitize($navItem['label']); ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($workspaceRole === 'student'): ?>
            <a class="student-app-bottom-nav__item<?php echo $workspaceNavActive === 'profile' ? ' is-active' : ''; ?>" href="<?php echo APP_URL; ?>/profile.php"<?php echo $workspaceNavActive === 'profile' ? ' aria-current="page"' : ''; ?>>
                <i class="fas fa-user" aria-hidden="true"></i>
                <span>Profile</span>
            </a>
        <?php endif; ?>
    </nav>
    <footer class="workspace-footer">
        <div class="container workspace-footer__inner">
            <p>&copy; <?php echo date('Y'); ?> Umsad Tech</p>
            <div><a href="<?php echo APP_URL; ?>/contact.php">Get support</a><a href="<?php echo APP_URL; ?>/privacy.php">Privacy</a><a href="<?php echo APP_URL; ?>/terms.php">Terms</a></div>
        </div>
    </footer>
    <?php else: ?>
    <footer class="site-footer">
        <div class="footer-glow footer-glow-one" aria-hidden="true"></div>
        <div class="footer-glow footer-glow-two" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="footer-main">
                <div class="footer-brand-column">
                    <a class="footer-brand" href="<?php echo APP_URL; ?>/" aria-label="Umsad Tech home">
                        <span class="brand-logo-frame brand-logo-frame--footer" aria-hidden="true">
                            <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024" loading="lazy">
                        </span>
                    </a>
                    <p>Practical technology education for people ready to build, grow and lead in the digital economy.</p>
                    <div class="footer-promise">
                        <span><i class="fas fa-globe-africa" aria-hidden="true"></i></span>
                        <div>
                            <strong>Learn from anywhere</strong>
                            <small>Built in Abuja, open to the world.</small>
                        </div>
                    </div>
                </div>

                <nav class="footer-links" aria-label="Course links">
                    <h2>Learn</h2>
                    <a href="<?php echo APP_URL; ?>/courses.php">Browse courses</a>
                    <a href="<?php echo APP_URL; ?>/#learning-experience">Why Umsad</a>
                    <a href="<?php echo APP_URL; ?>/#learning-path">How it works</a>
                    <a href="<?php echo APP_URL; ?>/register.php">Become a learner</a>
                </nav>

                <nav class="footer-links" aria-label="Company links">
                    <h2>Company</h2>
                    <a href="<?php echo APP_URL; ?>/about.php">About us</a>
                    <a href="<?php echo APP_URL; ?>/contact.php">Contact</a>
                    <a href="<?php echo APP_URL; ?>/contact.php?subject=Teach%20on%20Umsad">Teach on Umsad</a>
                    <a href="<?php echo APP_URL; ?>/login.php">Sign in</a>
                </nav>

                <div class="footer-contact">
                    <h2>Let’s talk</h2>
                    <p>Questions about a course or your account? Our team is ready to help.</p>
                    <a class="footer-contact-link" href="mailto:support@umsadtech.com">
                        <span><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div><small>Email us</small><strong>support@umsadtech.com</strong></div>
                    </a>
                    <div class="footer-location">
                        <i class="fas fa-location-dot" aria-hidden="true"></i>
                        <span>Abuja, Nigeria</span>
                    </div>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Umsad Tech. All rights reserved.</p>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <a class="text-reset text-decoration-none" href="<?php echo APP_URL; ?>/privacy.php">Privacy</a>
                    <a class="text-reset text-decoration-none" href="<?php echo APP_URL; ?>/terms.php">Terms</a>
                    <span>Learn with purpose. Build with confidence.</span>
                </div>
            </div>
        </div>
    </footer>
    <?php endif; ?>

    <script src="<?php echo sanitize($versionedAsset('/assets/vendor/bootstrap/bootstrap.bundle.min.js')); ?>"></script>
    <script src="<?php echo sanitize($versionedAsset('/assets/js/script.js')); ?>"></script>

    <?php if (!empty($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo APP_URL . sanitize($js); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

</body>
</html>
<?php
$db = null;
?>
