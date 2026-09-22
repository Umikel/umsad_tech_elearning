<?php
$pageTitle = 'Practical Tech Skills for Your Next Move';
$additionalCSS = [];
$additionalJS = [];

require_once __DIR__ . '/includes/course-content.php';
require_once 'templates/header.php';

// Get the latest published courses for the homepage.
$db->query('
    SELECT c.*, u.full_name as instructor_name,
           (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) as student_count
    FROM courses c
    LEFT JOIN users u ON c.instructor_id = u.id
    WHERE c.is_published = 1
    ORDER BY c.created_at DESC
    LIMIT 6
');
$courses = $db->resultSet();

// Resolve the announcement independently from the six newest cards so the
// first-visit modal always links to the intended course by its stable slug.
$courseAnnouncementContent = umsadCourseContentForSlug('website-development-for-beginners');
$db->query('
    SELECT id, title, slug, description, price, discount_price, course_image
    FROM courses
    WHERE slug = :slug AND is_published = 1
    LIMIT 1
');
$db->bind(':slug', 'website-development-for-beginners');
$courseAnnouncement = $db->single();
$courseAnnouncementPricing = $courseAnnouncement ? coursePriceDetails($courseAnnouncement) : null;
?>

<section class="home-hero" aria-labelledby="hero-title">
    <div class="hero-grid-pattern" aria-hidden="true"></div>
    <div class="hero-orb hero-orb-one" aria-hidden="true"></div>
    <div class="hero-orb hero-orb-two" aria-hidden="true"></div>

    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <div class="hero-copy-block">
                    <span class="eyebrow eyebrow-light">
                        <span class="eyebrow-dot" aria-hidden="true"></span>
                        Practical TEST new app learning for real-world growth
                    </span>
                    <h1 id="hero-title">Learn the skills.<br><span>Build what matters.</span></h1>
                    <p class="hero-lead">Career-focused technology courses that turn complex ideas into practical skills—one clear lesson, useful project and confident step at a time.</p>

                    <div class="hero-actions">
                        <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-primary btn-lg">
                            Explore courses <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                        <a href="#learning-path" class="hero-secondary-link">
                            <span><i class="fas fa-play" aria-hidden="true"></i></span> See how it works
                        </a>
                    </div>

                    <ul class="hero-assurances" aria-label="Learning benefits">
                        <li><i class="fas fa-circle-check" aria-hidden="true"></i> Learn at your pace</li>
                        <li><i class="fas fa-circle-check" aria-hidden="true"></i> Build practical projects</li>
                        <li><i class="fas fa-circle-check" aria-hidden="true"></i> Earn certificates</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="hero-visual" aria-label="A learner working through an online technology course">
                    <div class="hero-image-shell">
                        <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&amp;fit=crop&amp;w=1000&amp;q=88" alt="Learners collaborating around a laptop" class="hero-photo" width="1000" height="1120" fetchpriority="high">
                        <div class="hero-image-shade" aria-hidden="true"></div>
                        <div class="hero-photo-caption">
                            <span><i class="fas fa-code" aria-hidden="true"></i></span>
                            <div><small>Learn by building</small><strong>Skills you can put to work</strong></div>
                        </div>
                    </div>

                    <div class="floating-card floating-progress" aria-hidden="true">
                        <div class="progress-ring"><span>86%</span></div>
                        <div><small>Your weekly goal</small><strong>Excellent progress</strong></div>
                    </div>

                    <div class="floating-card floating-live" aria-hidden="true">
                        <span class="live-icon"><i class="fas fa-circle-play"></i></span>
                        <div><small>Now learning</small><strong>Responsive web design</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="learning-pillars" aria-label="What makes Umsad Tech different">
    <div class="container">
        <div class="pillar-grid">
            <div class="pillar-intro">
                <span class="pillar-kicker">Learning that fits real life</span>
                <strong>Move from “I’m curious” to “I can do this.”</strong>
            </div>
            <div class="pillar-item">
                <span><i class="fas fa-laptop-code" aria-hidden="true"></i></span>
                <div><strong>Project-led</strong><small>Practice as you learn</small></div>
            </div>
            <div class="pillar-item">
                <span><i class="fas fa-clock" aria-hidden="true"></i></span>
                <div><strong>Flexible</strong><small>Study on your schedule</small></div>
            </div>
            <div class="pillar-item">
                <span><i class="fas fa-user-group" aria-hidden="true"></i></span>
                <div><strong>Supported</strong><small>Guidance when it matters</small></div>
            </div>
        </div>
    </div>
</section>

<section id="featured-courses" class="section-shell featured-courses" aria-labelledby="featured-title">
    <div class="container">
        <div class="section-heading reveal">
            <div class="section-heading-copy">
                <span class="eyebrow eyebrow-dark">Featured courses</span>
                <h2 id="featured-title">Find the right starting point.</h2>
                <p>Choose a practical course designed to help you make visible progress.</p>
            </div>
            <a href="<?php echo APP_URL; ?>/courses.php" class="arrow-link">
                Browse all courses <span><i class="fas fa-arrow-right" aria-hidden="true"></i></span>
            </a>
        </div>

        <?php if (!empty($courses)): ?>
            <div class="row g-4 course-grid">
                <?php foreach ($courses as $course): ?>
                    <?php
                    $courseImage = !empty($course['course_image'])
                        ? sanitize($course['course_image'])
                        : 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=85';
                    $courseCategory = !empty($course['category']) ? sanitize($course['category']) : 'Technology';
                    $courseDescription = trim((string) ($course['description'] ?? ''));
                    $courseDescription = $courseDescription !== ''
                        ? sanitize(mb_strimwidth($courseDescription, 0, 112, '…'))
                        : 'A practical course designed to help you build useful, career-ready skills.';
                    $pricing = coursePriceDetails($course);
                    $coursePrice = (float) $pricing['base_amount'];
                    $hasDiscount = $pricing['has_discount'];
                    $displayPrice = (float) $pricing['effective_amount'];
                    ?>
                    <div class="col-lg-4 col-md-6 reveal">
                        <article class="course-card">
                            <a class="course-media" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>" aria-label="View <?php echo sanitize($course['title']); ?>">
                                <img src="<?php echo $courseImage; ?>" alt="" class="course-image" width="900" height="570" loading="lazy">
                                <span class="course-category"><?php echo $courseCategory; ?></span>
                                <?php if ($hasDiscount): ?>
                                    <span class="course-badge">Special price</span>
                                <?php endif; ?>
                                <span class="course-media-action" aria-hidden="true"><i class="fas fa-arrow-up-right-from-square"></i></span>
                            </a>

                            <div class="course-card-body">
                                <div class="course-meta">
                                    <span><i class="fas fa-user" aria-hidden="true"></i> <?php echo sanitize($course['instructor_name'] ?? 'Umsad Tech'); ?></span>
                                    <span><i class="fas fa-user-group" aria-hidden="true"></i> <?php echo (int) ($course['student_count'] ?? 0); ?> learners</span>
                                </div>

                                <h3><a href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>"><?php echo sanitize($course['title']); ?></a></h3>
                                <p><?php echo $courseDescription; ?></p>

                                <div class="course-card-footer">
                                    <div class="course-pricing">
                                        <small>Course fee</small>
                                        <?php if ($displayPrice <= 0): ?>
                                            <strong>Free</strong>
                                        <?php else: ?>
                                            <strong><?php echo formatCurrency($displayPrice); ?></strong>
                                            <?php if ($hasDiscount): ?>
                                                <del><?php echo formatCurrency($coursePrice); ?></del>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <a class="course-arrow" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>" aria-label="Open <?php echo sanitize($course['title']); ?>">
                                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-courses reveal" role="status">
                <span><i class="fas fa-book-open" aria-hidden="true"></i></span>
                <h3>Fresh courses are on the way.</h3>
                <p>We’re preparing the next set of practical learning experiences. Check back soon.</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<section id="learning-experience" class="experience-section" aria-labelledby="experience-title">
    <div class="experience-shape" aria-hidden="true"></div>
    <div class="container position-relative">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="experience-copy reveal">
                    <span class="eyebrow eyebrow-light">Made for momentum</span>
                    <h2 id="experience-title">A learning experience that keeps you moving.</h2>
                    <p>Focused lessons, meaningful practice and visible progress make it easier to stay consistent—even when life gets busy.</p>

                    <div class="experience-list">
                        <div class="experience-item">
                            <span><i class="fas fa-layer-group" aria-hidden="true"></i></span>
                            <div><h3>Clear, structured lessons</h3><p>Follow a thoughtful path from the fundamentals to confident application.</p></div>
                        </div>
                        <div class="experience-item">
                            <span><i class="fas fa-pen-ruler" aria-hidden="true"></i></span>
                            <div><h3>Practice with purpose</h3><p>Turn each new concept into something tangible you can understand and share.</p></div>
                        </div>
                        <div class="experience-item">
                            <span><i class="fas fa-award" aria-hidden="true"></i></span>
                            <div><h3>Recognise your progress</h3><p>Track your momentum and earn certificates when you complete a course.</p></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="learning-preview reveal" aria-label="Preview of the Umsad Tech learning dashboard">
                    <div class="preview-toolbar">
                        <div class="preview-brand">
                            <span class="brand-logo-frame brand-logo-frame--preview" aria-hidden="true">
                                <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024" loading="lazy">
                            </span>
                            Learning space
                        </div>
                        <span class="preview-status"><i class="fas fa-circle" aria-hidden="true"></i> In progress</span>
                    </div>
                    <div class="preview-body">
                        <div class="preview-sidebar" aria-hidden="true">
                            <span class="active"></span><span></span><span></span><span></span>
                        </div>
                        <div class="preview-content">
                            <span class="preview-label">COURSE 04 · LESSON 08</span>
                            <h3>Build a responsive landing page</h3>
                            <div class="preview-video">
                                <span><i class="fas fa-play" aria-hidden="true"></i></span>
                            </div>
                            <div class="preview-progress-copy"><span>Course progress</span><strong>68%</strong></div>
                            <div class="preview-progress"><span></span></div>
                            <div class="preview-next">
                                <span><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                                <div><small>Up next</small><strong>Make the layout adapt</strong></div>
                                <i class="fas fa-chevron-right" aria-hidden="true"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="learning-path" class="section-shell learning-path" aria-labelledby="path-title">
    <div class="container">
        <div class="path-heading reveal">
            <span class="eyebrow eyebrow-dark">Simple by design</span>
            <h2 id="path-title">Your next skill is three steps away.</h2>
            <p>No complicated onboarding. Choose where you want to grow and start making progress.</p>
        </div>

        <ol class="path-grid">
            <li class="path-step reveal">
                <div class="path-number">01</div>
                <span class="path-icon"><i class="fas fa-compass" aria-hidden="true"></i></span>
                <h3>Choose your direction</h3>
                <p>Explore focused courses and find the skill that supports your next goal.</p>
            </li>
            <li class="path-step reveal">
                <div class="path-number">02</div>
                <span class="path-icon"><i class="fas fa-laptop" aria-hidden="true"></i></span>
                <h3>Learn and practise</h3>
                <p>Work through clear lessons and apply what you learn at your own pace.</p>
            </li>
            <li class="path-step reveal">
                <div class="path-number">03</div>
                <span class="path-icon"><i class="fas fa-rocket" aria-hidden="true"></i></span>
                <h3>Put your skill to work</h3>
                <p>Finish with confidence, share your certificate and keep building forward.</p>
            </li>
        </ol>
    </div>
</section>

<section class="container home-cta-wrap" aria-labelledby="cta-title">
    <div class="home-cta reveal">
        <div class="cta-orb" aria-hidden="true"></div>
        <div class="cta-copy">
            <span class="eyebrow eyebrow-light">Make today count</span>
            <h2 id="cta-title">Your next chapter can start right here.</h2>
            <p>Choose a course, learn something useful and build the confidence to take your next step.</p>
        </div>
        <div class="cta-action">
            <?php if (!$auth->isLoggedIn()): ?>
                <a href="<?php echo APP_URL; ?>/register.php" class="btn btn-light btn-lg">Create your account <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <small>No complicated setup. Start exploring in minutes.</small>
            <?php else: ?>
                <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-light btn-lg">Find your next course <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                <small>Your learning space is ready when you are.</small>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($courseAnnouncement && is_array($courseAnnouncementContent)): ?>
    <?php $announcement = $courseAnnouncementContent['announcement'] ?? []; ?>
    <div class="modal fade course-announcement-modal" id="courseAnnouncementModal" tabindex="-1"
         aria-labelledby="courseAnnouncementTitle" aria-describedby="courseAnnouncementDescription"
         data-announcement-key="umsad.courseAnnouncement.website-development-for-beginners.v1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <button type="button" class="btn-close course-announcement-close" data-bs-dismiss="modal" aria-label="Close course announcement"></button>

                <div class="modal-body p-0">
                    <div class="course-announcement-layout">
                    <div class="course-announcement-visual" aria-hidden="true">
                        <div class="announcement-code-card">
                            <span>&lt;html&gt;</span>
                            <span>&nbsp;&nbsp;&lt;build&gt;</span>
                            <span>&nbsp;&nbsp;&nbsp;&nbsp;your future</span>
                            <span>&nbsp;&nbsp;&lt;/build&gt;</span>
                            <span>&lt;/html&gt;</span>
                        </div>
                        <span class="announcement-live-pill"><i class="fas fa-circle" aria-hidden="true"></i> Live online</span>
                        <span class="announcement-duration-pill"><strong>4</strong> weeks</span>
                    </div>

                    <div class="course-announcement-copy">
                        <span class="brand-logo-frame brand-logo-frame--announcement" aria-hidden="true">
                            <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024">
                        </span>
                        <p class="announcement-eyebrow"><span aria-hidden="true"></span><?php echo sanitize($announcement['eyebrow'] ?? 'Registration is open'); ?></p>
                        <h2 id="courseAnnouncementTitle"><?php echo sanitize($announcement['title'] ?? $courseAnnouncement['title']); ?></h2>
                        <p id="courseAnnouncementDescription"><?php echo sanitize($announcement['description'] ?? $courseAnnouncement['description']); ?></p>

                        <?php if (!empty($announcement['highlights'])): ?>
                            <ul class="course-announcement-highlights">
                                <?php foreach ($announcement['highlights'] as $highlight): ?>
                                    <li><i class="fas fa-circle-check" aria-hidden="true"></i><?php echo sanitize($highlight); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="course-announcement-actions">
                            <div>
                                <small>Course fee</small>
                                <strong><?php echo formatCurrency($courseAnnouncementPricing['effective_amount']); ?></strong>
                            </div>
                            <a class="btn btn-primary btn-lg" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $courseAnnouncement['id']; ?>">
                                <?php echo sanitize($courseAnnouncementContent['cta_label'] ?? 'Apply Now'); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                        <button type="button" class="announcement-later" data-bs-dismiss="modal">Maybe later</button>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'templates/footer.php'; ?>
