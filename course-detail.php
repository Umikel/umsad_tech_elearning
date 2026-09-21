<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/course-content.php';
require_once __DIR__ . '/includes/course-registration-guide.php';

$db = new Database();
$auth = new Auth($db);

$course_id = intval($_GET['id'] ?? 0);

if ($course_id <= 0) {
    redirect('/courses.php');
}

// Get course details.
$db->query('
    SELECT c.*, u.full_name as instructor_name, u.bio as instructor_bio, u.profile_image,
           (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) as student_count,
           (SELECT COUNT(*) FROM course_modules cm WHERE cm.course_id = c.id) as module_count,
           (SELECT COUNT(*) FROM course_lessons cl WHERE cl.course_id = c.id) as lesson_count,
           (SELECT AVG(cr.rating) FROM course_reviews cr WHERE cr.course_id = c.id AND cr.is_approved = 1) as avg_rating,
           (SELECT COUNT(*) FROM course_reviews cr WHERE cr.course_id = c.id AND cr.is_approved = 1) as review_count
    FROM courses c
    LEFT JOIN users u ON c.instructor_id = u.id
    WHERE c.id = :id AND c.is_published = 1
');
$db->bind(':id', $course_id);
$course = $db->single();

if (!$course) {
    redirect('/courses.php');
}

$registrationGuide = courseRegistrationGuide($course);

$structuredCourse = umsadCourseContentForSlug((string) $course['slug']);
$isStructuredCourse = is_array($structuredCourse);
$courseSummary = $isStructuredCourse
    ? (string) ($structuredCourse['summary'] ?? $course['description'] ?? '')
    : (string) ($course['description'] ?? '');
$coursePrerequisite = $isStructuredCourse
    ? (string) ($structuredCourse['prerequisite'] ?? '')
    : '';
$courseApplyLabel = $isStructuredCourse
    ? (string) ($structuredCourse['cta_label'] ?? 'Apply Now')
    : 'Enroll securely';
$courseFacts = [];
if ($isStructuredCourse) {
    foreach (($structuredCourse['facts'] ?? []) as $fact) {
        if (!is_array($fact) || empty($fact['key'])) {
            continue;
        }
        $courseFacts[(string) $fact['key']] = (string) ($fact['value'] ?? '');
    }
}
$courseSectionIcons = [
    'about_the_course' => 'fa-circle-info',
    'who_can_apply' => 'fa-user-group',
    'what_you_will_learn' => 'fa-lightbulb',
    'four_week_course_schedule' => 'fa-calendar-week',
    'how_every_live_class_will_work' => 'fa-chalkboard-user',
    'course_assessment' => 'fa-list-check',
    'applications_and_software_required' => 'fa-laptop-code',
    'online_class_instructions' => 'fa-video',
    'student_rules_and_regulations' => 'fa-scale-balanced',
    'certificate_requirements' => 'fa-certificate',
    'support_and_communication' => 'fa-headset',
    'how_to_apply' => 'fa-arrow-pointer',
];

// Check whether the current learner already owns this course.
$is_enrolled = false;
if ($auth->isLoggedIn() && $auth->isStudent()) {
    $db->query('
        SELECT id FROM student_enrollments
        WHERE student_id = :student_id AND course_id = :course_id
    ');
    $db->bind(':student_id', $auth->getUserId());
    $db->bind(':course_id', $course_id);
    $is_enrolled = $db->single() ? true : false;
}

$learnerReview = null;
$reviewFormError = null;
if ($is_enrolled) {
    $db->query('SELECT * FROM course_reviews WHERE course_id = :course_id AND student_id = :student_id LIMIT 1');
    $db->bind(':course_id', $course_id);
    $db->bind(':student_id', (int) $auth->getUserId());
    $learnerReview = $db->single() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_review') {
    if (!$auth->verifySession() || !$auth->isStudent() || !$is_enrolled) {
        $reviewFormError = 'Only enrolled learners can review this course.';
    } elseif (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $reviewFormError = 'Your session expired. Refresh the page and try again.';
    } else {
        $reviewRating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $reviewText = trim((string) ($_POST['review_text'] ?? ''));
        if ($reviewRating === false) {
            $reviewFormError = 'Choose a rating from one to five stars.';
        } elseif (mb_strlen($reviewText) < 10 || mb_strlen($reviewText) > 2000) {
            $reviewFormError = 'Write a helpful review between 10 and 2,000 characters.';
        } else {
            $db->query('INSERT INTO course_reviews (course_id, student_id, rating, review_text, is_approved)
                        VALUES (:course_id, :student_id, :rating, :review_text, 0)
                        ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text),
                                                is_approved = 0, updated_at = NOW()');
            $db->bind(':course_id', $course_id);
            $db->bind(':student_id', (int) $auth->getUserId());
            $db->bind(':rating', (int) $reviewRating);
            $db->bind(':review_text', $reviewText);
            $db->execute();
            $_SESSION['message'] = 'Thanks for sharing your feedback. Your review is awaiting moderation.';
            redirect('/course-detail.php?id=' . $course_id . '#reviews', 303);
        }
        $learnerReview = [
            'rating' => $reviewRating === false ? 0 : (int) $reviewRating,
            'review_text' => $reviewText,
            'is_approved' => 0,
        ];
    }
}

// Get course modules.
$db->query('
    SELECT cm.*, COUNT(DISTINCT cl.id) as lesson_count
    FROM course_modules cm
    LEFT JOIN course_lessons cl ON cm.id = cl.module_id
    WHERE cm.course_id = :course_id
    GROUP BY cm.id
    ORDER BY cm.sequence
');
$db->bind(':course_id', $course_id);
$modules = $db->resultSet();

// Load lesson summaries once so the public schedule and curriculum can share
// the same database-backed class plan without an N+1 query loop.
$db->query('
    SELECT * FROM course_lessons
    WHERE course_id = :course_id
    ORDER BY module_id, sequence
');
$db->bind(':course_id', $course_id);
$courseLessons = $db->resultSet();
$lessonsByModule = [];
foreach ($courseLessons as $lesson) {
    $lessonsByModule[(int) $lesson['module_id']][] = $lesson;
}
foreach ($modules as &$module) {
    $module['lessons'] = $lessonsByModule[(int) $module['id']] ?? [];
}
unset($module);

// Get approved learner reviews.
$db->query('
    SELECT cr.*, u.full_name, u.profile_image
    FROM course_reviews cr
    JOIN users u ON cr.student_id = u.id
    WHERE cr.course_id = :course_id AND cr.is_approved = 1
    ORDER BY cr.created_at DESC
    LIMIT 5
');
$db->bind(':course_id', $course_id);
$reviews = $db->resultSet();

$pricing = coursePriceDetails($course);
$effectivePrice = (float) $pricing['effective_amount'];
$hasDiscount = $pricing['has_discount'];
$courseImage = $course['course_image'] ?: 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=85';
$rating = min(5, max(0, (float) ($course['avg_rating'] ?? 0)));
$reviewCount = (int) ($course['review_count'] ?? 0);
$discountPercentage = 0;
if ($hasDiscount && $pricing['base_minor'] > 0) {
    $discountPercentage = (int) round((($pricing['base_minor'] - $pricing['effective_minor']) / $pricing['base_minor']) * 100);
}

$paystackCheckoutReady = PAYSTACK_CONFIGURED
    && (!PAYSTACK_IS_LIVE || (
        APP_ENV === 'production'
        && parse_url(APP_URL, PHP_URL_SCHEME) === 'https'
        && SESSION_COOKIE_SECURE
    ));

// Paid enrollment is available only in a safe gateway environment.
$canPurchaseCourse = $paystackCheckoutReady
    && $auth->isLoggedIn()
    && $auth->isStudent()
    && !$is_enrolled
    && $effectivePrice > 0;

$pageTitle = $course['title'];
require_once __DIR__ . '/templates/header.php';
?>

<section class="course-detail-hero" aria-labelledby="course-title">
    <div class="course-detail-grid-pattern" aria-hidden="true"></div>
    <div class="course-detail-orb" aria-hidden="true"></div>
    <div class="container position-relative">
        <nav class="course-breadcrumb" aria-label="Breadcrumb">
            <a href="<?php echo APP_URL; ?>/">Home</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="<?php echo APP_URL; ?>/courses.php">Courses</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <span aria-current="page"><?php echo sanitize($course['title']); ?></span>
        </nav>

        <div class="course-hero-copy">
            <span class="course-detail-category"><?php echo sanitize($course['category'] ?: 'Digital skills'); ?></span>
            <h1 id="course-title"><?php echo sanitize($course['title']); ?></h1>
            <p><?php echo sanitize(mb_strimwidth($courseSummary, 0, 230, '…')); ?></p>
            <?php if ($coursePrerequisite !== ''): ?>
                <p class="course-prerequisite"><i class="fas fa-circle-check" aria-hidden="true"></i> <?php echo sanitize($coursePrerequisite); ?></p>
            <?php endif; ?>

            <div class="course-hero-meta">
                <div class="course-detail-rating" aria-label="<?php echo number_format($rating, 1); ?> out of 5 stars from <?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?>">
                    <strong><?php echo $reviewCount > 0 ? number_format($rating, 1) : 'New'; ?></strong>
                    <?php if ($reviewCount > 0): ?>
                        <span class="course-stars" aria-hidden="true">
                            <?php for ($star = 1; $star <= 5; $star++): ?>
                                <?php if ($rating >= $star): ?>
                                    <i class="fas fa-star"></i>
                                <?php elseif ($rating >= $star - 0.5): ?>
                                    <i class="fas fa-star-half-alt"></i>
                                <?php else: ?>
                                    <i class="far fa-star"></i>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </span>
                        <a href="#reviews" class="review-jump-link"><?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?></a>
                    <?php else: ?>
                        <span class="new-course-label">Be among the first learners</span>
                    <?php endif; ?>
                </div>
                <span class="course-meta-divider" aria-hidden="true"></span>
                <span><i class="far fa-user" aria-hidden="true"></i> Taught by <strong><?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech'); ?></strong></span>
                <span><i class="fas fa-user-group" aria-hidden="true"></i> <?php echo (int) $course['student_count']; ?> learner<?php echo (int) $course['student_count'] === 1 ? '' : 's'; ?></span>
            </div>
        </div>
    </div>
</section>

<section class="course-detail-section">
    <div class="container">
        <div class="course-detail-layout">
            <div class="course-cover-panel">
                <img src="<?php echo sanitize($courseImage); ?>"
                     alt="Course cover for <?php echo sanitize($course['title']); ?>"
                     class="course-cover-image" width="1200" height="720">
                <div class="course-cover-overlay" aria-hidden="true"></div>
                <div class="course-cover-caption">
                    <span><i class="fas fa-laptop-code" aria-hidden="true"></i></span>
                    <?php if ($isStructuredCourse): ?>
                        <div><small><?php echo sanitize($courseFacts['mode'] ?? 'Live online training'); ?></small><strong><?php echo sanitize($courseFacts['classes'] ?? 'Practical instructor-led classes'); ?></strong></div>
                    <?php else: ?>
                        <div><small>Practical, self-paced learning</small><strong>Learn it. Build it. Use it.</strong></div>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="course-purchase-column" aria-label="Course enrollment">
                <div class="course-purchase-card" id="course-apply-card">
                    <?php if ($is_enrolled): ?>
                        <span class="purchase-status purchase-status-success"><i class="fas fa-circle-check" aria-hidden="true"></i> You’re enrolled</span>
                    <?php elseif ($isStructuredCourse): ?>
                        <span class="purchase-status purchase-status-free"><i class="fas fa-door-open" aria-hidden="true"></i> Registration <?php echo sanitize(strtolower($courseFacts['registration_status'] ?? 'open')); ?></span>
                    <?php elseif ($effectivePrice === 0.0): ?>
                        <span class="purchase-status purchase-status-free"><i class="fas fa-gift" aria-hidden="true"></i> Free course</span>
                    <?php elseif ($discountPercentage > 0): ?>
                        <span class="purchase-status purchase-status-offer"><i class="fas fa-bolt" aria-hidden="true"></i> Save <?php echo $discountPercentage; ?>%</span>
                    <?php else: ?>
                        <span class="purchase-status"><i class="fas fa-graduation-cap" aria-hidden="true"></i> Full course access</span>
                    <?php endif; ?>

                    <div class="course-purchase-price">
                        <small><?php echo $is_enrolled ? 'Your course' : 'Course fee'; ?></small>
                        <?php if ($is_enrolled): ?>
                            <strong>Ready to continue</strong>
                        <?php elseif ($effectivePrice === 0.0): ?>
                            <strong>Free</strong>
                        <?php else: ?>
                            <strong><?php echo formatCurrency($effectivePrice); ?></strong>
                            <?php if ($hasDiscount): ?>
                                <del><?php echo formatCurrency($pricing['base_amount']); ?></del>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <ul class="course-includes" aria-label="This course includes">
                        <?php if ($isStructuredCourse): ?>
                            <li><span><i class="fas fa-calendar-days" aria-hidden="true"></i></span><strong><?php echo sanitize($courseFacts['duration'] ?? 'Four Weeks'); ?></strong></li>
                            <li><span><i class="fas fa-video" aria-hidden="true"></i></span><strong>3 live classes</strong> every week</li>
                            <li><span><i class="fas fa-language" aria-hidden="true"></i></span><strong>English or Hausa</strong> your choice</li>
                            <li><span><i class="fas fa-award" aria-hidden="true"></i></span><strong>Certificate</strong> of completion</li>
                        <?php else: ?>
                            <li><span><i class="fas fa-layer-group" aria-hidden="true"></i></span><strong><?php echo (int) $course['module_count']; ?></strong> modules</li>
                            <li><span><i class="fas fa-circle-play" aria-hidden="true"></i></span><strong><?php echo (int) $course['lesson_count']; ?></strong> lessons</li>
                            <li><span><i class="fas fa-users" aria-hidden="true"></i></span><strong><?php echo (int) $course['student_count']; ?></strong> learners</li>
                            <li><span><i class="fas fa-award" aria-hidden="true"></i></span><strong>Certificate</strong> of completion</li>
                        <?php endif; ?>
                    </ul>

                    <?php if (hasSundayPhysicalClass($course)): ?>
                    <div class="alert alert-light border">
                        <strong>Online + Sunday Physical Class</strong>
                        <p class="small my-2">₦50,000 total registration fee, including the online course.<br>Meet in person every Sunday, 10am–11am (Nigeria time).<br>Venue to be announced.</p>
                        <?php if (!$is_enrolled): ?>
                            <?php if ($auth->isLoggedIn() && $auth->isStudent() && $paystackCheckoutReady): ?>
                                <button type="button" class="btn btn-primary w-100" data-enrollment-action="paid" data-learning-plan="sunday_physical" data-enrollment-course="<?php echo $course_id; ?>">Apply for Sunday class — ₦50,000</button>
                            <?php elseif (!$auth->isLoggedIn()): ?>
                                <a class="btn btn-outline-primary w-100" href="<?php echo APP_URL; ?>/login.php">Log in to apply for Sunday class</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="course-purchase-actions" aria-live="polite">
                        <?php if ($auth->isLoggedIn() && $is_enrolled): ?>
                            <a href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo $course_id; ?>" class="btn btn-primary btn-lg w-100">
                                Continue learning <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        <?php elseif ($auth->isLoggedIn() && $auth->isStudent() && !$is_enrolled): ?>
                            <?php if ($effectivePrice > 0): ?>
                                <?php if ($paystackCheckoutReady): ?>
                                    <button type="button" class="btn btn-primary btn-lg w-100 enrollment-button"
                                            data-enrollment-action="paid" data-enrollment-course="<?php echo $course_id; ?>">
                                        <?php echo sanitize($courseApplyLabel); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-primary btn-lg w-100" disabled>
                                        Payment setup required <i class="fas fa-lock" aria-hidden="true"></i>
                                    </button>
                                    <p class="purchase-config-notice">Live checkout requires a production HTTPS URL. Use Paystack test keys for localhost testing.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-lg w-100 enrollment-button"
                                        data-enrollment-action="free" data-enrollment-course="<?php echo $course_id; ?>">
                                    Enroll for free <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                </button>
                            <?php endif; ?>
                        <?php elseif (!$auth->isLoggedIn()): ?>
                            <a href="<?php echo APP_URL; ?>/login.php" class="btn btn-primary btn-lg w-100">
                                <?php echo sanitize($isStructuredCourse ? $courseApplyLabel : 'Log in to enroll'); ?> <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                            <p class="purchase-account-note">New to Umsad Tech? <a href="<?php echo APP_URL; ?>/register.php">Create an account</a></p>
                        <?php else: ?>
                            <div class="purchase-role-note"><i class="fas fa-circle-info" aria-hidden="true"></i><span>Switch to a learner account to enroll in this course.</span></div>
                        <?php endif; ?>

                        <?php if ($registrationGuide !== null): ?>
                            <button type="button" class="course-share-button" id="previewCourseGuide">Read the course guide</button>
                        <?php endif; ?>
                        <button type="button" class="course-share-button" id="shareCourseButton">
                            <i class="fas fa-share-nodes" aria-hidden="true"></i> Share this course
                        </button>
                    </div>

                    <?php if ($canPurchaseCourse): ?>
                        <p class="purchase-trust"><i class="fas fa-lock" aria-hidden="true"></i> Secure checkout powered by Paystack</p>
                        <?php if (PAYSTACK_IS_LIVE): ?>
                            <p class="purchase-live-notice"><i class="fas fa-circle-exclamation" aria-hidden="true"></i> Live payment: your bank account or card will be charged real money.</p>
                        <?php endif; ?>
                    <?php elseif ($effectivePrice === 0.0 && !$is_enrolled): ?>
                        <p class="purchase-trust"><i class="fas fa-circle-check" aria-hidden="true"></i> No payment details required</p>
                    <?php endif; ?>
                </div>
            </aside>

            <div class="course-content-column">
                <nav class="course-tab-nav" aria-label="Course information">
                    <ul class="nav course-tabs" id="courseContentTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                                <i class="fas fa-circle-info" aria-hidden="true"></i> Overview
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="curriculum-tab" data-bs-toggle="tab" data-bs-target="#curriculum" type="button" role="tab" aria-controls="curriculum" aria-selected="false">
                                <i class="fas fa-list-check" aria-hidden="true"></i> <?php echo $isStructuredCourse ? 'Class schedule' : 'Curriculum'; ?> <span><?php echo (int) $course['lesson_count']; ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="instructor-tab" data-bs-toggle="tab" data-bs-target="#instructor" type="button" role="tab" aria-controls="instructor" aria-selected="false">
                                <i class="fas fa-user" aria-hidden="true"></i> Instructor
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="reviews-tab" data-bs-toggle="tab" data-bs-target="#reviews" type="button" role="tab" aria-controls="reviews" aria-selected="false">
                                <i class="fas fa-star" aria-hidden="true"></i> Reviews <span><?php echo $reviewCount; ?></span>
                            </button>
                        </li>
                    </ul>
                </nav>

                <div class="tab-content course-tab-content" id="courseContent">
                    <section class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab" tabindex="0">
                        <div class="course-content-card">
                            <?php if ($isStructuredCourse): ?>
                                <span class="content-kicker">Live beginner programme</span>
                                <h2><?php echo sanitize($coursePrerequisite); ?></h2>
                                <p class="course-program-summary"><?php echo sanitize($courseSummary); ?></p>

                                <dl class="course-fact-grid" aria-label="Course dates and requirements">
                                    <?php foreach (($structuredCourse['facts'] ?? []) as $fact): ?>
                                        <?php if (!is_array($fact)) continue; ?>
                                        <?php
                                        $factValue = ($fact['key'] ?? '') === 'course_fee'
                                            ? formatCurrency($effectivePrice)
                                            : (string) ($fact['value'] ?? '');
                                        ?>
                                        <div class="course-fact<?php echo ($fact['key'] ?? '') === 'registration_status' ? ' course-fact--status' : ''; ?>">
                                            <dt><?php echo sanitize($fact['label'] ?? 'Course detail'); ?></dt>
                                            <dd><?php echo sanitize($factValue); ?></dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>

                                <div class="course-guide-sections">
                                    <?php foreach (($structuredCourse['sections'] ?? []) as $sectionKey => $section): ?>
                                        <?php
                                        if (!is_array($section)) continue;
                                        $sectionId = 'course-section-' . str_replace('_', '-', (string) $sectionKey);
                                        $isScheduleSection = $sectionKey === 'four_week_course_schedule';
                                        $listStyle = ($section['list_style'] ?? 'bullets') === 'steps' ? 'steps' : 'bullets';
                                        ?>
                                        <section class="course-guide-section<?php echo $isScheduleSection ? ' course-guide-section--schedule' : ''; ?>" id="<?php echo sanitize($sectionId); ?>" aria-labelledby="<?php echo sanitize($sectionId); ?>-title">
                                            <div class="course-guide-heading">
                                                <span aria-hidden="true"><i class="fas <?php echo sanitize($courseSectionIcons[$sectionKey] ?? 'fa-circle-check'); ?>"></i></span>
                                                <h3 id="<?php echo sanitize($sectionId); ?>-title"><?php echo sanitize($section['title'] ?? 'Course information'); ?></h3>
                                            </div>

                                            <?php foreach (($section['paragraphs'] ?? []) as $paragraph): ?>
                                                <p><?php echo sanitize($paragraph); ?></p>
                                            <?php endforeach; ?>

                                            <?php if ($isScheduleSection && !empty($modules)): ?>
                                                <div class="course-week-grid">
                                                    <?php foreach ($modules as $weekIndex => $module): ?>
                                                        <article class="course-week-card">
                                                            <span>Week <?php echo $weekIndex + 1; ?></span>
                                                            <h4><?php echo sanitize($module['title']); ?></h4>
                                                            <?php if (!empty($module['description'])): ?>
                                                                <p><?php echo sanitize($module['description']); ?></p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($module['lessons'])): ?>
                                                                <ol>
                                                                    <?php foreach ($module['lessons'] as $classIndex => $lesson): ?>
                                                                        <li><small>Class <?php echo $classIndex + 1; ?></small><?php echo sanitize($lesson['title']); ?></li>
                                                                    <?php endforeach; ?>
                                                                </ol>
                                                            <?php endif; ?>
                                                        </article>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif (!empty($section['items'])): ?>
                                                <?php if ($listStyle === 'steps'): ?>
                                                    <ol class="course-guide-list course-guide-list--steps">
                                                        <?php foreach ($section['items'] as $item): ?><li><?php echo sanitize($item); ?></li><?php endforeach; ?>
                                                    </ol>
                                                <?php else: ?>
                                                    <ul class="course-guide-list">
                                                        <?php foreach ($section['items'] as $item): ?><li><?php echo sanitize($item); ?></li><?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($sectionKey === 'how_to_apply'): ?>
                                                <a href="#course-apply-card" class="btn btn-primary course-guide-apply">
                                                    <?php echo sanitize($courseApplyLabel); ?> <i class="fas fa-arrow-up" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="content-kicker">About this course</span>
                                <h2>Build understanding you can put into practice.</h2>
                                <div class="course-description-copy"><?php echo nl2br(sanitize($course['description'])); ?></div>

                                <div class="course-value-grid">
                                    <div class="course-value-item">
                                        <span><i class="fas fa-route" aria-hidden="true"></i></span>
                                        <div><h3>Structured pathway</h3><p>Move through <?php echo (int) $course['module_count']; ?> focused modules in a clear order.</p></div>
                                    </div>
                                    <div class="course-value-item">
                                        <span><i class="fas fa-laptop-code" aria-hidden="true"></i></span>
                                        <div><h3>Practical learning</h3><p>Use concise lessons to turn new concepts into usable skills.</p></div>
                                    </div>
                                    <div class="course-value-item">
                                        <span><i class="fas fa-clock" aria-hidden="true"></i></span>
                                        <div><h3>Learn flexibly</h3><p>Return to your lessons and continue at a pace that works for you.</p></div>
                                    </div>
                                    <div class="course-value-item">
                                        <span><i class="fas fa-certificate" aria-hidden="true"></i></span>
                                        <div><h3>Mark your progress</h3><p>Earn a certificate when you successfully complete the course.</p></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="tab-pane fade" id="curriculum" role="tabpanel" aria-labelledby="curriculum-tab" tabindex="0">
                        <div class="course-content-card">
                            <div class="course-content-heading">
                                <div>
                                    <span class="content-kicker"><?php echo $isStructuredCourse ? 'Three live classes every week' : 'Course curriculum'; ?></span>
                                    <h2><?php echo $isStructuredCourse ? 'Four-Week Course Schedule' : 'Your learning roadmap.'; ?></h2>
                                </div>
                                <p><?php echo (int) $course['module_count']; ?> <?php echo $isStructuredCourse ? 'weeks' : 'modules'; ?> · <?php echo (int) $course['lesson_count']; ?> <?php echo $isStructuredCourse ? 'live classes' : 'lessons'; ?></p>
                            </div>

                            <?php if (!empty($modules)): ?>
                                <div class="accordion curriculum-accordion" id="curriculumAccordion">
                                    <?php foreach ($modules as $index => $module): ?>
                                        <?php $moduleId = (int) $module['id']; ?>
                                        <div class="accordion-item">
                                            <h3 class="accordion-header" id="heading<?php echo $moduleId; ?>">
                                                <button class="accordion-button <?php echo $index > 0 ? 'collapsed' : ''; ?>"
                                                        type="button"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#collapse<?php echo $moduleId; ?>"
                                                        aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                                                        aria-controls="collapse<?php echo $moduleId; ?>">
                                                    <span class="module-number"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                                    <span class="module-title"><?php echo sanitize($module['title']); ?></span>
                                                    <span class="module-count"><?php echo (int) $module['lesson_count']; ?> <?php echo $isStructuredCourse
                                                        ? ((int) $module['lesson_count'] === 1 ? 'class' : 'classes')
                                                        : ((int) $module['lesson_count'] === 1 ? 'lesson' : 'lessons'); ?></span>
                                                </button>
                                            </h3>
                                            <div id="collapse<?php echo $moduleId; ?>"
                                                 class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>"
                                                 aria-labelledby="heading<?php echo $moduleId; ?>"
                                                 data-bs-parent="#curriculumAccordion">
                                                <div class="accordion-body">
                                                    <?php $lessons = $module['lessons'] ?? []; ?>

                                                    <?php if (!empty($lessons)): ?>
                                                        <ol class="lesson-list">
                                                            <?php foreach ($lessons as $lesson): ?>
                                                                <li>
                                                                    <span class="lesson-play"><i class="fas fa-play" aria-hidden="true"></i></span>
                                                                    <span class="lesson-title"><?php echo sanitize($lesson['title']); ?></span>
                                                                    <?php if (!empty($lesson['duration'])): ?>
                                                                        <span class="lesson-duration"><i class="far fa-clock" aria-hidden="true"></i> <?php echo (int) $lesson['duration']; ?> min</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($lesson['is_free']): ?>
                                                                        <span class="lesson-preview-badge">Preview</span>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ol>
                                                    <?php else: ?>
                                                        <p class="curriculum-empty">Lessons for this module are being prepared.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="course-tab-empty"><i class="fas fa-layer-group" aria-hidden="true"></i><p>The curriculum is being prepared. Please check back soon.</p></div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="tab-pane fade" id="instructor" role="tabpanel" aria-labelledby="instructor-tab" tabindex="0">
                        <div class="course-content-card">
                            <span class="content-kicker">Meet your instructor</span>
                            <div class="instructor-profile">
                                <img src="<?php echo sanitize($course['profile_image'] ?: 'https://ui-avatars.com/api/?name=' . rawurlencode($course['instructor_name'] ?: 'Umsad Tech') . '&background=ECEBFF&color=29265F'); ?>"
                                     alt="<?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech'); ?>" class="instructor-avatar" width="112" height="112" loading="lazy">
                                <div>
                                    <span>Course instructor</span>
                                    <h2><?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech'); ?></h2>
                                    <p><?php echo sanitize($course['instructor_bio'] ?: 'An Umsad Tech instructor focused on clear teaching, practical skills and meaningful learner progress.'); ?></p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="tab-pane fade" id="reviews" role="tabpanel" aria-labelledby="reviews-tab" tabindex="0">
                        <div class="course-content-card">
                            <div class="review-summary">
                                <div>
                                    <span class="content-kicker">Learner feedback</span>
                                    <h2>What learners are saying.</h2>
                                </div>
                                <?php if ($reviewCount > 0): ?>
                                    <div class="review-score" aria-label="Average rating <?php echo number_format($rating, 1); ?> out of 5">
                                        <strong><?php echo number_format($rating, 1); ?></strong>
                                        <span class="course-stars" aria-hidden="true">
                                            <?php for ($star = 1; $star <= 5; $star++): ?>
                                                <?php if ($rating >= $star): ?>
                                                    <i class="fas fa-star"></i>
                                                <?php elseif ($rating >= $star - 0.5): ?>
                                                    <i class="fas fa-star-half-alt"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </span>
                                        <small><?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_enrolled): ?>
                                <div class="border rounded-4 p-4 mb-4 bg-light">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                        <div><h3 class="h5 mb-1"><?php echo $learnerReview ? 'Update your review' : 'Share your experience'; ?></h3><p class="text-muted small mb-0">Thoughtful feedback helps future learners choose with confidence.</p></div>
                                        <?php if ($learnerReview): ?><span class="status-pill <?php echo (int) $learnerReview['is_approved'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $learnerReview['is_approved'] === 1 ? 'Published' : 'Awaiting moderation'; ?></span><?php endif; ?>
                                    </div>
                                    <?php if ($reviewFormError): ?><div class="alert alert-danger py-2" role="alert"><?php echo sanitize($reviewFormError); ?></div><?php endif; ?>
                                    <form method="post">
                                        <?php echo csrfField(); ?><input type="hidden" name="action" value="submit_review">
                                        <div class="row g-3">
                                            <div class="col-md-4"><label class="form-label" for="review-rating">Your rating</label><select id="review-rating" class="form-select" name="rating" required><option value="">Choose rating</option><?php for ($reviewOption = 5; $reviewOption >= 1; $reviewOption--): ?><option value="<?php echo $reviewOption; ?>" <?php echo (int) ($learnerReview['rating'] ?? 0) === $reviewOption ? 'selected' : ''; ?>><?php echo $reviewOption; ?> star<?php echo $reviewOption === 1 ? '' : 's'; ?></option><?php endfor; ?></select></div>
                                            <div class="col-md-8"><label class="form-label" for="review-text">Review</label><textarea id="review-text" class="form-control" name="review_text" rows="4" minlength="10" maxlength="2000" placeholder="What did you learn, and who would you recommend this course to?" required><?php echo sanitize($learnerReview['review_text'] ?? ''); ?></textarea></div>
                                        </div>
                                        <div class="text-end mt-3"><button class="btn btn-primary" type="submit"><i class="fas fa-star me-2"></i><?php echo $learnerReview ? 'Update review' : 'Submit review'; ?></button></div>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($reviews)): ?>
                                <div class="review-list">
                                    <?php foreach ($reviews as $review): ?>
                                        <?php $reviewRating = min(5, max(0, (int) $review['rating'])); ?>
                                        <article class="review-card">
                                            <img src="<?php echo sanitize($review['profile_image'] ?: 'https://ui-avatars.com/api/?name=' . rawurlencode($review['full_name']) . '&background=F1F1F7&color=29265F'); ?>"
                                                 alt="" class="review-avatar" width="48" height="48" loading="lazy">
                                            <div class="review-body">
                                                <div class="review-author-line">
                                                    <div><h3><?php echo sanitize($review['full_name']); ?></h3><time datetime="<?php echo sanitize(date('Y-m-d', strtotime($review['created_at']))); ?>"><?php echo formatDate($review['created_at']); ?></time></div>
                                                    <span class="course-stars" aria-label="<?php echo $reviewRating; ?> out of 5 stars">
                                                        <?php for ($star = 1; $star <= 5; $star++): ?>
                                                            <i class="<?php echo $star <= $reviewRating ? 'fas' : 'far'; ?> fa-star" aria-hidden="true"></i>
                                                        <?php endfor; ?>
                                                    </span>
                                                </div>
                                                <p><?php echo sanitize($review['review_text']); ?></p>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="course-tab-empty"><i class="far fa-comment-dots" aria-hidden="true"></i><p>No reviews yet. Enrolled learners can be the first to share their experience.</p></div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($registrationGuide !== null): require __DIR__ . '/templates/course-registration-guide.php'; endif; ?>
<script>
const courseAppUrl = <?php echo json_encode(APP_URL, JSON_UNESCAPED_SLASHES); ?>;
const courseCsrfToken = <?php echo json_encode(csrfToken()); ?>;

async function courseApiRequest(path, payload) {
    const response = await fetch(courseAppUrl + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': courseCsrfToken
        },
        body: JSON.stringify(payload)
    });

    let result;
    try {
        result = await response.json();
    } catch (error) {
        throw new Error('The server returned an unexpected response.');
    }

    if (!response.ok || !result.success) {
        throw new Error(result.message || 'Something went wrong. Please try again.');
    }
    return result;
}

function setEnrollmentButtonState(courseId, isLoading) {
    const buttons = document.querySelectorAll('[data-enrollment-course="' + courseId + '"]');
    buttons.forEach(button => {
    if (!button) return;

    button.disabled = isLoading;
    button.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    if (isLoading) {
        button.dataset.label = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Please wait';
    } else if (button.dataset.label) {
        button.innerHTML = button.dataset.label;
        button.removeAttribute('aria-busy');
    }
    });
}

async function enrollCourse(courseId) {
    setEnrollmentButtonState(courseId, true);
    let leavingForCheckout = false;
    try {
        const result = await courseApiRequest('/api/payments/initialize.php', Object.assign({ course_id: courseId }, courseGuideAcknowledgment));
        const payment = result.data;
        let checkoutUrl;
        try {
            checkoutUrl = new URL(payment.authorization_url);
        } catch (error) {
            throw new Error('The payment provider returned an invalid checkout address.');
        }

        if (checkoutUrl.protocol !== 'https:' || checkoutUrl.hostname !== 'checkout.paystack.com') {
            throw new Error('The payment provider returned an untrusted checkout address.');
        }

        leavingForCheckout = true;
        window.location.assign(checkoutUrl.href);
    } catch (error) {
        UmsadTechHelper.showError(error.message);
    } finally {
        if (!leavingForCheckout) setEnrollmentButtonState(courseId, false);
    }
}

async function freeEnroll(courseId) {
    setEnrollmentButtonState(courseId, true);
    try {
        const result = await courseApiRequest('/api/enrollments/create.php', Object.assign({ course_id: courseId }, courseGuideAcknowledgment));
        UmsadTechHelper.showSuccess(result.message);
        window.setTimeout(() => {
            window.location.href = courseAppUrl + result.data.redirect;
        }, 700);
    } catch (error) {
        UmsadTechHelper.showError(error.message);
        setEnrollmentButtonState(courseId, false);
    }
}

let courseGuideAcknowledgment = {};
const courseGuideDialog = document.getElementById('courseRegistrationGuide');
const courseGuideCheckbox = document.getElementById('courseGuideAccepted');
const courseGuideContinue = document.getElementById('courseGuideContinue');
let pendingEnrollment = null;
function proceedWithEnrollment(courseId, action) {
    if (action === 'paid') enrollCourse(courseId);
    else freeEnroll(courseId);
}
if (courseGuideDialog) {
    document.getElementById('previewCourseGuide').addEventListener('click', () => {
        pendingEnrollment = null;
        document.getElementById('courseGuideForm').hidden = true;
        courseGuideDialog.showModal();
        courseGuideDialog.scrollTop = 0;
        document.getElementById('courseGuideTitle').focus();
    });
    document.getElementById('courseGuideClose').addEventListener('click', () => courseGuideDialog.close());
    courseGuideCheckbox.addEventListener('change', () => { courseGuideContinue.disabled = !courseGuideCheckbox.checked; });
    document.getElementById('courseGuideCancel').addEventListener('click', () => courseGuideDialog.close());
    courseGuideDialog.addEventListener('close', () => { pendingEnrollment = null; });
    document.getElementById('courseGuideForm').addEventListener('submit', event => {
        event.preventDefault();
        if (!courseGuideCheckbox.checked || !pendingEnrollment) return;
        const request = pendingEnrollment;
        courseGuideAcknowledgment = { ...courseGuideAcknowledgment, guide_accepted: true, guide_version: courseGuideDialog.dataset.version };
        courseGuideDialog.close();
        proceedWithEnrollment(request.courseId, request.action);
    });
}
document.querySelectorAll('[data-enrollment-action]').forEach(button => {
    button.addEventListener('click', function () {
        const courseId = Number(this.dataset.enrollmentCourse);
        if (!Number.isInteger(courseId) || courseId <= 0) return;
        if (courseGuideDialog) {
            document.getElementById('courseGuideForm').hidden = false;
            pendingEnrollment = { courseId, action: this.dataset.enrollmentAction };
            courseGuideAcknowledgment = { learning_plan: this.dataset.learningPlan || 'online' };
            document.getElementById('courseGuidePlan').textContent = this.dataset.learningPlan === 'sunday_physical'
                ? 'Selected: Online + Sunday Physical Class. Total fee: ₦50,000 including online learning. Sundays, 10am–11am (Nigeria time). Venue to be announced.'
                : 'Selected: Online course at the price displayed on this page.';
            courseGuideCheckbox.checked = false;
            courseGuideContinue.disabled = true;
            courseGuideDialog.showModal();
            courseGuideDialog.scrollTop = 0;
            document.getElementById('courseGuideTitle').focus();
        } else {
            proceedWithEnrollment(courseId, this.dataset.enrollmentAction);
        }
    });
});

document.querySelector('.review-jump-link')?.addEventListener('click', function (event) {
    event.preventDefault();
    const reviewTab = document.getElementById('reviews-tab');
    reviewTab?.click();
    window.setTimeout(() => {
        document.querySelector('.course-tab-nav')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 80);
});

document.getElementById('shareCourseButton')?.addEventListener('click', async function () {
    const shareData = {
        title: <?php echo json_encode($course['title']); ?>,
        text: 'Explore this course on Umsad Tech',
        url: window.location.href
    };
    try {
        if (navigator.share) {
            await navigator.share(shareData);
        } else {
            await navigator.clipboard.writeText(shareData.url);
            UmsadTechHelper.showSuccess('Course link copied to your clipboard.');
        }
    } catch (error) {
        if (error.name !== 'AbortError') UmsadTechHelper.showError('Unable to share this course right now.');
    }
});
</script>

<?php require_once 'templates/footer.php'; ?>
