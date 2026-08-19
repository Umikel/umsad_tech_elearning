<?php
$pageTitle = 'Student Dashboard';
require_once dirname(__DIR__) . '/templates/header.php';

// Check if user is logged in and is a student
if (!$auth->isLoggedIn() || !$auth->isStudent()) {
    redirect('/login.php');
}

$student_id = $auth->getUserId();

// Get dashboard statistics
$db->query('
    SELECT COUNT(*) as total_enrolled FROM student_enrollments WHERE student_id = :student_id
');
$db->bind(':student_id', $student_id);
$total_enrolled = $db->single()['total_enrolled'];

$db->query('
    SELECT COUNT(*) as total_completed FROM student_enrollments 
    WHERE student_id = :student_id AND is_completed = 1
');
$db->bind(':student_id', $student_id);
$total_completed = $db->single()['total_completed'];

$db->query('
    SELECT AVG(progress_percentage) as avg_progress FROM student_enrollments 
    WHERE student_id = :student_id
');
$db->bind(':student_id', $student_id);
$avg_progress = intval($db->single()['avg_progress'] ?? 0);

// Get recent courses
$db->query('
    SELECT c.*, se.progress_percentage, se.is_completed
    FROM student_enrollments se
    JOIN courses c ON se.course_id = c.id
    WHERE se.student_id = :student_id
    ORDER BY se.enrollment_date DESC
    LIMIT 6
');
$db->bind(':student_id', $student_id);
$enrolled_courses = $db->resultSet();
?>

<div class="container my-5">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-2">My Dashboard</h1>
            <p class="text-muted">Welcome back, <?php echo sanitize($currentUser['full_name']); ?>!</p>
        </div>
    </div>

    <!-- Statistics Row -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="dashboard-card">
                <div class="stat-card">
                    <div class="stat-number text-primary"><?php echo $total_enrolled; ?></div>
                    <div class="stat-label">Enrolled Courses</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="dashboard-card">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo $total_completed; ?></div>
                    <div class="stat-label">Completed Courses</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="dashboard-card">
                <div class="stat-card">
                    <div class="stat-number text-info"><?php echo $avg_progress; ?>%</div>
                    <div class="stat-label">Average Progress</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="dashboard-card">
                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo ($total_enrolled - $total_completed); ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>
        </div>
    </div>

    <!-- My Courses Section -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>My Courses</h2>
                    <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Enroll in Course
                    </a>
                </div>

                <?php if (!empty($enrolled_courses)): ?>
                    <div class="row">
                        <?php foreach ($enrolled_courses as $course): ?>
                            <div class="col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title"><?php echo sanitize($course['title']); ?></h5>
                                        <p class="text-muted small"><?php echo substr(sanitize($course['description']), 0, 60) . '...'; ?></p>

                                        <!-- Progress Bar -->
                                        <div class="progress-container">
                                            <div class="d-flex justify-content-between mb-2">
                                                <small class="text-muted">Progress</small>
                                                <small class="fw-bold"><?php echo $course['progress_percentage']; ?>%</small>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $course['progress_percentage']; ?>%"
                                                     aria-valuenow="<?php echo $course['progress_percentage']; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Status Badge -->
                                        <?php if ($course['is_completed']): ?>
                                            <span class="badge bg-success mb-3">
                                                <i class="fas fa-check-circle"></i> Completed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark mb-3">
                                                <i class="fas fa-hourglass-half"></i> In Progress
                                            </span>
                                        <?php endif; ?>

                                        <!-- Action Buttons -->
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo $course['id']; ?>" 
                                               class="btn btn-primary btn-sm flex-grow-1">
                                                <i class="fas fa-play"></i> Continue Learning
                                            </a>
                                            <?php if ($course['is_completed']): ?>
                                                <a href="<?php echo APP_URL; ?>/student/certificate.php?course_id=<?php echo $course['id']; ?>" 
                                                   class="btn btn-success btn-sm">
                                                    <i class="fas fa-certificate"></i> Certificate
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info text-center py-5">
                        <i class="fas fa-graduation-cap fa-3x mb-3 d-block"></i>
                        <h5>No courses yet</h5>
                        <p>Browse and enroll in courses to start learning</p>
                        <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-primary">
                            <i class="fas fa-book"></i> Explore Courses
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Activity Section -->
    <div class="row">
        <div class="col-12">
            <div class="dashboard-card">
                <h2 class="mb-4">Quick Stats</h2>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <p class="text-muted small">Last Login</p>
                        <p class="fw-bold">
                            <?php 
                            echo $currentUser['last_login'] ? formatDate($currentUser['last_login']) : 'N/A';
                            ?>
                        </p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <p class="text-muted small">Member Since</p>
                        <p class="fw-bold">
                            <?php echo formatDate($currentUser['created_at']); ?>
                        </p>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="<?php echo APP_URL; ?>/profile.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
