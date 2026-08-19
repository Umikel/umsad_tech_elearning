<?php
$pageTitle = 'Courses';
require_once 'templates/header.php';

// Get filters
$category = sanitize($_GET['category'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$page = intval($_GET['page'] ?? 1);
$limit = 12;
$offset = ($page - 1) * $limit;

// Build query
$query = '
    SELECT c.*, u.full_name as instructor_name, 
           COUNT(DISTINCT se.id) as student_count,
           AVG(cr.rating) as avg_rating
    FROM courses c
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN student_enrollments se ON c.id = se.course_id
    LEFT JOIN course_reviews cr ON c.id = cr.course_id AND cr.is_approved = 1
    WHERE c.is_published = 1
';

$countQuery = 'SELECT COUNT(DISTINCT c.id) as total FROM courses c WHERE c.is_published = 1';

// Apply filters
if (!empty($category)) {
    $query .= ' AND c.category = :category';
    $countQuery .= ' AND c.category = :category';
}

if (!empty($search)) {
    $query .= ' AND (c.title LIKE :search OR c.description LIKE :search)';
    $countQuery .= ' AND (c.title LIKE :search OR c.description LIKE :search)';
}

// Group and order
$query .= ' GROUP BY c.id ORDER BY c.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

// Get total count
$db->query($countQuery);
if (!empty($category)) {
    $db->bind(':category', $category);
}
if (!empty($search)) {
    $db->bind(':search', '%' . $search . '%');
}
$totalResult = $db->single();
$totalCourses = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCourses / $limit);

// Get courses
$db->query($query);
if (!empty($category)) {
    $db->bind(':category', $category);
}
if (!empty($search)) {
    $db->bind(':search', '%' . $search . '%');
}
$courses = $db->resultSet();

// Get unique categories
$db->query('SELECT DISTINCT category FROM courses WHERE is_published = 1 AND category IS NOT NULL ORDER BY category');
$categories = $db->resultSet();
?>

<div class="container my-5">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-2">Explore Our Courses</h1>
            <p class="text-muted">Choose from our wide range of professional courses</p>
        </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="row mb-4">
        <div class="col-md-8">
            <form method="GET" class="d-flex gap-2">
                <input type="text" 
                       class="form-control" 
                       name="search" 
                       placeholder="Search courses..."
                       value="<?php echo $search; ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        <div class="col-md-4">
            <form method="GET">
                <select class="form-select" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo sanitize($cat['category']); ?>" 
                                <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($cat['category']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Results Info -->
    <div class="row mb-4">
        <div class="col-12">
            <p class="text-muted">
                Showing <strong><?php echo count($courses); ?></strong> of <strong><?php echo $totalCourses; ?></strong> courses
                <?php if (!empty($search)): ?>
                    for "<strong><?php echo $search; ?></strong>"
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Courses Grid -->
    <div class="row mb-5">
        <?php if (!empty($courses)): ?>
            <?php foreach ($courses as $course): ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card course-card h-100">
                        <div class="position-relative">
                            <img src="<?php echo $course['course_image'] ?? 'https://via.placeholder.com/300x200'; ?>" 
                                 alt="<?php echo sanitize($course['title']); ?>" 
                                 class="card-img-top course-image"
                                 style="object-fit: cover;">
                            <?php if ($course['discount_price']): ?>
                                <span class="course-badge">
                                    <?php 
                                    $discount = (($course['price'] - $course['discount_price']) / $course['price']) * 100;
                                    echo round($discount) . '% OFF';
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <span class="badge bg-info mb-2"><?php echo sanitize($course['category'] ?? 'General'); ?></span>
                            <h5 class="card-title"><?php echo sanitize($course['title']); ?></h5>
                            <p class="text-muted small mb-2">
                                <i class="fas fa-chalkboard-user"></i>
                                <?php echo sanitize($course['instructor_name'] ?? 'Unknown Instructor'); ?>
                            </p>
                            <p class="card-text text-truncate small">
                                <?php echo substr(sanitize($course['description']), 0, 80) . '...'; ?>
                            </p>

                            <!-- Rating -->
                            <div class="course-rating mb-2">
                                <?php
                                $rating = round($course['avg_rating'] ?? 0);
                                for ($i = 0; $i < 5; $i++) {
                                    if ($i < $rating) {
                                        echo '<i class="fas fa-star"></i>';
                                    } elseif ($i < $rating - 0.5) {
                                        echo '<i class="fas fa-star-half-alt"></i>';
                                    } else {
                                        echo '<i class="far fa-star"></i>';
                                    }
                                }
                                ?>
                                <span class="ms-1 small">(<?php echo $course['student_count']; ?>)</span>
                            </div>

                            <!-- Price -->
                            <div class="mb-3">
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
                        <div class="card-footer bg-white border-top">
                            <a href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo $course['id']; ?>" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-eye"></i> View Course
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-search fa-3x mb-3 d-block"></i>
                    <h5>No courses found</h5>
                    <p>Try adjusting your search or filters</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mb-5">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=1<?php echo !empty($category) ? '&category=' . $category : ''; ?><?php echo !empty($search) ? '&search=' . $search : ''; ?>">First</a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($category) ? '&category=' . $category : ''; ?><?php echo !empty($search) ? '&search=' . $search : ''; ?>">Previous</a>
                    </li>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($category) ? '&category=' . $category : ''; ?><?php echo !empty($search) ? '&search=' . $search : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($category) ? '&category=' . $category : ''; ?><?php echo !empty($search) ? '&search=' . $search : ''; ?>">Next</a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo !empty($category) ? '&category=' . $category : ''; ?><?php echo !empty($search) ? '&search=' . $search : ''; ?>">Last</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require_once 'templates/footer.php'; ?>
