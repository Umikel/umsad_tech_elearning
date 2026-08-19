<?php
$pageTitle = 'Home';
$additionalCSS = [];
$additionalJS = [];

require_once 'templates/header.php';

// Get featured courses
$db->query('
    SELECT c.*, u.full_name as instructor_name, 
           COUNT(DISTINCT se.id) as student_count
    FROM courses c
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN student_enrollments se ON c.id = se.course_id
    WHERE c.is_published = 1
    GROUP BY c.id
    LIMIT 6
');
$courses = $db->resultSet();
?>

<section class="hero-section">
    <div class="hero-orb hero-orb-one"></div>
    <div class="hero-orb hero-orb-two"></div>
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="eyebrow text-white"><i class="fas fa-sparkles"></i> Learn. Build. Lead.</span>
                <h1>Build digital skills<br>that move you <em>forward.</em></h1>
                <p class="hero-copy">Practical, career-focused courses created to help you build confidence, create real projects, and grow in technology.</p>
                <div class="d-flex flex-wrap gap-3 hero-actions">
                    <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-light btn-lg px-4">Explore courses <i class="fas fa-arrow-right ms-2"></i></a>
                    <a href="#featured-courses" class="btn btn-hero-outline btn-lg px-4"><i class="fas fa-play-circle me-2"></i>How it works</a>
                </div>
                <div class="hero-trust">
                    <div class="avatar-stack" aria-hidden="true">
                        <span>AO</span><span>TN</span><span>UE</span><span>+</span>
                    </div>
                    <p><strong>Join ambitious learners</strong><br>building skills for what’s next.</p>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="hero-visual">
                    <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=900&q=85" alt="Students collaborating on a digital project" class="hero-photo">
                    <div class="floating-card floating-progress">
                        <span class="floating-icon"><i class="fas fa-chart-line"></i></span>
                        <div><small>Learning progress</small><strong>86% complete</strong><div class="mini-progress"><span></span></div></div>
                    </div>
                    <div class="floating-card floating-course"><i class="fas fa-code"></i><div><small>Popular this week</small><strong>Web Development</strong></div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container home-content">
    <section class="stats-section reveal">
        <div class="row g-0">
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number" data-count="500">500+</div>
                <div class="stat-label">Active Students</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-number">50+</div>
                <div class="stat-label">Expert Courses</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-number">20+</div>
                <div class="stat-label">Instructors</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-heart"></i></div>
                <div class="stat-number">95%</div>
                <div class="stat-label">Satisfaction Rate</div>
            </div>
        </div>
        </div>
    </section>

    <!-- Featured Courses -->
    <section id="featured-courses" class="featured-section">
        <div class="section-heading reveal">
            <div>
                <span class="eyebrow eyebrow-dark">Handpicked for you</span>
                <h2>Start with a course<br>that inspires you.</h2>
            </div>
            <a href="<?php echo APP_URL; ?>/courses.php" class="text-link">View all courses <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="row">
        <?php if (!empty($courses)): ?>
            <?php foreach ($courses as $course): ?>
                <div class="col-lg-4 col-md-6 mb-4 reveal">
                    <div class="card course-card">
                        <div class="position-relative">
                            <img src="<?php echo sanitize($course['course_image'] ?? 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=85'); ?>" 
                                 alt="<?php echo sanitize($course['title']); ?>" class="card-img-top course-image">
                            <span class="course-category"><?php echo sanitize($course['category'] ?? 'Technology'); ?></span>
                            <?php if ($course['discount_price']): ?>
                                <span class="course-badge">DISCOUNT</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo sanitize($course['title']); ?></h5>
                            <p class="text-muted small mb-2">By <?php echo sanitize($course['instructor_name'] ?? 'Unknown'); ?></p>
                            <p class="card-text course-description"><?php echo substr(sanitize($course['description'] ?? ''), 0, 82) . '...'; ?></p>
                            
                            <div class="course-rating mb-2">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                                <span class="ms-1">(<?php echo $course['student_count']; ?> students)</span>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if ($course['discount_price']): ?>
                                        <span class="course-price"><?php echo formatCurrency($course['discount_price']); ?></span>
                                        <small class="text-muted text-decoration-line-through">
                                            <?php echo formatCurrency($course['price']); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="course-price"><?php echo formatCurrency($course['price']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 pt-0">
                            <a href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo $course['id']; ?>" class="btn btn-primary w-100">
                                View course <i class="fas fa-arrow-right ms-2"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="alert alert-info text-center">
                    <p>No courses available yet. Check back soon!</p>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <!-- Why Choose Us Section -->
    <section class="why-section reveal">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="eyebrow eyebrow-dark">A better way to learn</span>
                <h2>Everything you need to turn curiosity into capability.</h2>
                <p class="why-intro">Our learning experience is designed to keep you moving—clear lessons, useful projects and support when you need it.</p>
                <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-primary px-4">Find your course <i class="fas fa-arrow-right ms-2"></i></a>
            </div>
            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="feature-card feature-card-accent">
                            <span class="feature-icon"><i class="fas fa-video"></i></span>
                            <h5>Learn by doing</h5>
                            <p>Clear video lessons and practical exercises that help concepts stick.</p>
                        </div>
                    </div>
                    <div class="col-md-6 mt-md-5">
                        <div class="feature-card">
                            <span class="feature-icon"><i class="fas fa-certificate"></i></span>
                            <h5>Show what you know</h5>
                            <p>Earn completion certificates that celebrate your commitment.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <span class="feature-icon"><i class="fas fa-headset"></i></span>
                            <h5>Human support</h5>
                            <p>Get helpful guidance whenever you need a little momentum.</p>
                        </div>
                    </div>
                    <div class="col-md-6 mt-md-5">
                        <div class="feature-card feature-card-dark">
                            <span class="feature-icon"><i class="fas fa-rocket"></i></span>
                            <h5>Built for progress</h5>
                            <p>Follow your learning journey and take the next step with confidence.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="home-cta reveal">
        <div class="cta-pattern"></div>
        <div class="position-relative">
        <span class="eyebrow text-white">Your next chapter starts here</span>
        <h3>Ready to make your<br>learning count?</h3>
        <p>Join Umsad Tech and turn today’s ambition into tomorrow’s opportunity.</p>
        <?php if (!$auth->isLoggedIn()): ?>
            <a href="<?php echo APP_URL; ?>/register.php" class="btn btn-light btn-lg px-4">Create a free account <i class="fas fa-arrow-right ms-2"></i></a>
        <?php else: ?>
            <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-light btn-lg px-4">Browse all courses <i class="fas fa-arrow-right ms-2"></i></a>
        <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once 'templates/footer.php'; ?>
