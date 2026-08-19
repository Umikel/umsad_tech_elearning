<?php
$pageTitle = 'Course Details';
require_once 'templates/header.php';

$course_id = intval($_GET['id'] ?? 0);

if ($course_id <= 0) {
    redirect('/courses.php');
}

// Get course details
$db->query('
    SELECT c.*, u.full_name as instructor_name, u.bio as instructor_bio, u.profile_image,
           COUNT(DISTINCT se.id) as student_count,
           COUNT(DISTINCT cm.id) as module_count,
           COUNT(DISTINCT cl.id) as lesson_count,
           AVG(cr.rating) as avg_rating,
           COUNT(DISTINCT cr.id) as review_count
    FROM courses c
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN student_enrollments se ON c.id = se.course_id
    LEFT JOIN course_modules cm ON c.id = cm.course_id
    LEFT JOIN course_lessons cl ON cm.id = cl.module_id
    LEFT JOIN course_reviews cr ON c.id = cr.course_id AND cr.is_approved = 1
    WHERE c.id = :id AND c.is_published = 1
    GROUP BY c.id
');
$db->bind(':id', $course_id);
$course = $db->single();

if (!$course) {
    redirect('/courses.php');
}

// Check if user is enrolled
$is_enrolled = false;
if ($auth->isLoggedIn()) {
    $db->query('
        SELECT id FROM student_enrollments 
        WHERE student_id = :student_id AND course_id = :course_id
    ');
    $db->bind(':student_id', $auth->getUserId());
    $db->bind(':course_id', $course_id);
    $is_enrolled = $db->single() ? true : false;
}

// Get course modules
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

// Get reviews
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
?>

<div class="container my-5">
    <!-- Course Hero Section -->
    <div class="row mb-5">
        <div class="col-lg-8 mb-4">
            <img src="<?php echo $course['course_image'] ?? 'https://via.placeholder.com/600x400'; ?>" 
                 alt="<?php echo sanitize($course['title']); ?>" 
                 class="img-fluid rounded"
                 style="height: 400px; object-fit: cover;">
        </div>
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-body">
                    <h1 class="card-title mb-3"><?php echo sanitize($course['title']); ?></h1>
                    
                    <!-- Rating -->
                    <div class="mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div>
                                <?php
                                $rating = round($course['avg_rating'] ?? 0);
                                for ($i = 0; $i < 5; $i++) {
                                    if ($i < $rating) {
                                        echo '<i class="fas fa-star text-warning"></i>';
                                    } else {
                                        echo '<i class="far fa-star text-warning"></i>';
                                    }
                                }
                                ?>
                            </div>
                            <span class="text-muted small">
                                (<?php echo $course['review_count']; ?> reviews)
                            </span>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="mb-4">
                        <?php if ($course['discount_price']): ?>
                            <div>
                                <h3 class="text-danger"><?php echo formatCurrency($course['discount_price']); ?></h3>
                                <small class="text-muted text-decoration-line-through">
                                    <?php echo formatCurrency($course['price']); ?>
                                </small>
                            </div>
                        <?php else: ?>
                            <h3><?php echo formatCurrency($course['price']); ?></h3>
                        <?php endif; ?>
                    </div>

                    <!-- Course Info -->
                    <div class="mb-4">
                        <p class="mb-2">
                            <i class="fas fa-book"></i>
                            <strong><?php echo $course['module_count']; ?></strong> Modules
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-video"></i>
                            <strong><?php echo $course['lesson_count']; ?></strong> Lessons
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-users"></i>
                            <strong><?php echo $course['student_count']; ?></strong> Students
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-certificate"></i>
                            Certificate of Completion
                        </p>
                    </div>

                    <!-- CTA Buttons -->
                    <?php if ($auth->isLoggedIn() && $is_enrolled): ?>
                        <a href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo $course_id; ?>" 
                           class="btn btn-primary w-100 mb-2">
                            <i class="fas fa-play"></i> Continue Learning
                        </a>
                    <?php elseif ($auth->isLoggedIn() && !$is_enrolled): ?>
                        <?php if ($course['price'] > 0): ?>
                            <button type="button" class="btn btn-success w-100 mb-2" onclick="enrollCourse(<?php echo $course_id; ?>)">
                                <i class="fas fa-shopping-cart"></i> Enroll Now
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-success w-100 mb-2" onclick="freeEnroll(<?php echo $course_id; ?>)">
                                <i class="fas fa-check"></i> Enroll Free
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?php echo APP_URL; ?>/login.php" class="btn btn-primary w-100 mb-2">
                            <i class="fas fa-sign-in-alt"></i> Login to Enroll
                        </a>
                    <?php endif; ?>

                    <button type="button" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-share-alt"></i> Share Course
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Course Content Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                <i class="fas fa-info-circle"></i> Overview
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="curriculum-tab" data-bs-toggle="tab" data-bs-target="#curriculum" type="button">
                <i class="fas fa-list"></i> Curriculum
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="instructor-tab" data-bs-toggle="tab" data-bs-target="#instructor" type="button">
                <i class="fas fa-user"></i> Instructor
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="reviews-tab" data-bs-toggle="tab" data-bs-target="#reviews" type="button">
                <i class="fas fa-comments"></i> Reviews
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Overview Tab -->
        <div class="tab-pane fade show active" id="overview" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h3 class="card-title mb-3">About this course</h3>
                            <p><?php echo nl2br(sanitize($course['description'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Curriculum Tab -->
        <div class="tab-pane fade" id="curriculum" role="tabpanel">
            <div class="card">
                <div class="card-body">
                    <h3 class="card-title mb-4">Course Curriculum</h3>
                    
                    <?php if (!empty($modules)): ?>
                        <div class="accordion" id="curriculumAccordion">
                            <?php foreach ($modules as $index => $module): ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading<?php echo $module['id']; ?>">
                                        <button class="accordion-button <?php echo $index > 0 ? 'collapsed' : ''; ?>" 
                                                type="button" 
                                                data-bs-toggle="collapse" 
                                                data-bs-target="#collapse<?php echo $module['id']; ?>">
                                            <i class="fas fa-folder"></i>
                                            <strong class="ms-2"><?php echo sanitize($module['title']); ?></strong>
                                            <span class="ms-auto text-muted small">
                                                <?php echo $module['lesson_count']; ?> lessons
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo $module['id']; ?>" 
                                         class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" 
                                         data-bs-parent="#curriculumAccordion">
                                        <div class="accordion-body">
                                            <?php
                                            $db->query('
                                                SELECT * FROM course_lessons 
                                                WHERE module_id = :module_id
                                                ORDER BY sequence
                                            ');
                                            $db->bind(':module_id', $module['id']);
                                            $lessons = $db->resultSet();
                                            ?>

                                            <ul class="list-unstyled">
                                                <?php foreach ($lessons as $lesson): ?>
                                                    <li class="mb-2">
                                                        <i class="fas fa-video text-primary"></i>
                                                        <span class="ms-2"><?php echo sanitize($lesson['title']); ?></span>
                                                        <?php if ($lesson['is_free']): ?>
                                                            <span class="badge bg-success small ms-2">FREE</span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No curriculum available yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Instructor Tab -->
        <div class="tab-pane fade" id="instructor" role="tabpanel">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <img src="<?php echo $course['profile_image'] ?? 'https://via.placeholder.com/100'; ?>" 
                             alt="<?php echo sanitize($course['instructor_name']); ?>" 
                             class="rounded-circle"
                             style="width: 100px; height: 100px; object-fit: cover;">
                        <div class="ms-3">
                            <h4 class="mb-0"><?php echo sanitize($course['instructor_name']); ?></h4>
                            <p class="text-muted small mb-0">Instructor</p>
                        </div>
                    </div>
                    <p><?php echo sanitize($course['instructor_bio']); ?></p>
                </div>
            </div>
        </div>

        <!-- Reviews Tab -->
        <div class="tab-pane fade" id="reviews" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <?php if (!empty($reviews)): ?>
                        <?php foreach ($reviews as $review): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex align-items-start">
                                        <img src="<?php echo $review['profile_image'] ?? 'https://via.placeholder.com/50'; ?>" 
                                             alt="" class="rounded-circle me-3"
                                             style="width: 50px; height: 50px; object-fit: cover;">
                                        <div class="flex-grow-1">
                                            <h6 class="card-title mb-0"><?php echo sanitize($review['full_name']); ?></h6>
                                            <div class="text-warning small mb-2">
                                                <?php
                                                for ($i = 0; $i < 5; $i++) {
                                                    if ($i < $review['rating']) {
                                                        echo '<i class="fas fa-star"></i>';
                                                    } else {
                                                        echo '<i class="far fa-star"></i>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                            <p class="card-text"><?php echo sanitize($review['review_text']); ?></p>
                                            <small class="text-muted">
                                                <?php echo formatDate($review['created_at']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No reviews yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function enrollCourse(courseId) {
    const email = '<?php echo $currentUser['email'] ?? ''; ?>';
    const amount = <?php echo ($course['discount_price'] ?? $course['price']); ?>;
    const publicKey = '<?php echo PAYSTACK_PUBLIC_KEY; ?>';
    
    // Initialize payment
    fetch('<?php echo APP_URL; ?>/api/payments/initialize.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            course_id: courseId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            UmsadTechHelper.initializePaystackPayment(
                data.data.public_key,
                data.data.email,
                data.data.amount,
                data.data.reference
            );
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => console.error('Error:', error));
}

function freeEnroll(courseId) {
    if (confirm('Enroll in this free course?')) {
        // Direct enrollment for free courses
        fetch('<?php echo APP_URL; ?>/api/enrollments/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                course_id: courseId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = '<?php echo APP_URL; ?>/student/course-lessons.php?id=' + courseId;
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}
</script>

<?php require_once 'templates/footer.php'; ?>
