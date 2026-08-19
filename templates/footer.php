    </main>

    <!-- Footer -->
    <footer class="bg-dark text-white mt-5 py-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h5 class="mb-3"><i class="fas fa-book-open"></i> Umsad Tech E-Learning</h5>
                    <p class="text-muted">Empowering businesses to thrive in the digital landscape through innovative online learning solutions.</p>
                    <div class="social-links">
                        <a href="#" class="text-white me-2"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white me-2"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white me-2"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>

                <div class="col-md-3 mb-4">
                    <h5 class="mb-3">Quick Links</h5>
                    <ul class="list-unstyled text-muted">
                        <li class="mb-2"><a href="<?php echo APP_URL; ?>" class="text-white-50 text-decoration-none">Home</a></li>
                        <li class="mb-2"><a href="<?php echo APP_URL; ?>/courses.php" class="text-white-50 text-decoration-none">Courses</a></li>
                        <li class="mb-2"><a href="<?php echo APP_URL; ?>/about.php" class="text-white-50 text-decoration-none">About Us</a></li>
                        <li class="mb-2"><a href="<?php echo APP_URL; ?>/contact.php" class="text-white-50 text-decoration-none">Contact</a></li>
                    </ul>
                </div>

                <div class="col-md-3 mb-4">
                    <h5 class="mb-3">Support</h5>
                    <ul class="list-unstyled text-muted">
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">FAQ</a></li>
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">Privacy Policy</a></li>
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">Terms of Service</a></li>
                        <li class="mb-2"><a href="#" class="text-white-50 text-decoration-none">Help Center</a></li>
                    </ul>
                </div>

                <div class="col-md-2 mb-4">
                    <h5 class="mb-3">Contact Info</h5>
                    <p class="text-muted small">
                        <i class="fas fa-phone"></i> +234 XXX XXX XXXX<br>
                        <i class="fas fa-envelope"></i> support@umsadtech.com<br>
                        <i class="fas fa-map-marker-alt"></i> Abuja, Nigeria
                    </p>
                </div>
            </div>

            <hr class="bg-secondary">
            <div class="text-center text-muted small">
                <p>&copy; <?php echo date('Y'); ?> Umsad Tech E-Learning Platform. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Custom JS -->
    <script src="<?php echo APP_URL; ?>/assets/js/script.js"></script>

    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo APP_URL . $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Paystack SDK (if needed) -->
    <script src="https://js.paystack.co/v1/inline.js"></script>
</body>
</html>
<?php
// Clean up
$db = null;
?>
