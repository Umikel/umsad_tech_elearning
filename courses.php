<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);

$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 12;

if (mb_strlen($category) > 100) {
    $category = mb_substr($category, 0, 100);
}
if (mb_strlen($search) > 120) {
    $search = mb_substr($search, 0, 120);
}

$filters = ' WHERE c.is_published = 1';
if ($category !== '') {
    $filters .= ' AND c.category = :category';
}
if ($search !== '') {
    $filters .= ' AND (c.title LIKE :search OR c.description LIKE :search)';
}

$bindFilters = static function (Database $database) use ($category, $search): void {
    if ($category !== '') {
        $database->bind(':category', $category);
    }
    if ($search !== '') {
        $database->bind(':search', '%' . $search . '%');
    }
};

$db->query('SELECT COUNT(DISTINCT c.id) AS total FROM courses c' . $filters);
$bindFilters($db);
$totalCourses = (int) (($db->single()['total'] ?? 0));
$totalPages = max(1, (int) ceil($totalCourses / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$query = '
    SELECT c.*, u.full_name AS instructor_name,
           (SELECT COUNT(*) FROM student_enrollments se WHERE se.course_id = c.id) AS student_count,
           (SELECT AVG(cr.rating) FROM course_reviews cr WHERE cr.course_id = c.id AND cr.is_approved = 1) AS avg_rating,
           (SELECT COUNT(*) FROM course_reviews cr WHERE cr.course_id = c.id AND cr.is_approved = 1) AS review_count
    FROM courses c
    LEFT JOIN users u ON c.instructor_id = u.id
' . $filters . '
    ORDER BY c.created_at DESC
    LIMIT ' . $limit . ' OFFSET ' . $offset;

$db->query($query);
$bindFilters($db);
$courses = $db->resultSet();

$db->query('SELECT DISTINCT category FROM courses WHERE is_published = 1 AND category IS NOT NULL AND category <> "" ORDER BY category');
$categories = $db->resultSet();

$paginationUrl = static function (int $targetPage) use ($category, $search): string {
    $params = ['page' => $targetPage];
    if ($category !== '') {
        $params['category'] = $category;
    }
    if ($search !== '') {
        $params['search'] = $search;
    }
    return '?' . http_build_query($params);
};

$pageTitle = 'Explore courses';
require_once __DIR__ . '/templates/header.php';
?>

<section class="page-hero page-hero-courses">
    <div class="container">
        <div class="page-hero-content">
            <span class="eyebrow text-white">Learn with direction</span>
            <h1>Find the course that moves your career forward.</h1>
            <p>Explore practical, project-led learning designed for ambitious people building modern digital skills.</p>
        </div>
    </div>
</section>

<section class="catalog-section">
    <div class="container">
        <form method="GET" class="filter-panel" aria-label="Filter courses">
            <div class="filter-search">
                <label class="visually-hidden" for="course-search">Search courses</label>
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="course-search" name="search" value="<?php echo sanitize($search); ?>"
                       placeholder="Search by skill, topic or keyword">
            </div>

            <div class="filter-category">
                <label class="visually-hidden" for="course-category">Course category</label>
                <select id="course-category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $item): ?>
                        <option value="<?php echo sanitize($item['category']); ?>"
                            <?php echo $category === $item['category'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($item['category']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">
                Find courses <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </button>

            <?php if ($category !== '' || $search !== ''): ?>
                <a href="<?php echo APP_URL; ?>/courses.php" class="filter-clear">Clear filters</a>
            <?php endif; ?>
        </form>

        <div class="catalog-heading">
            <div>
                <span class="eyebrow eyebrow-dark"><?php echo $totalCourses; ?> learning path<?php echo $totalCourses === 1 ? '' : 's'; ?></span>
                <h2><?php echo $search !== '' ? 'Results for “' . sanitize($search) . '”' : 'Courses built for real progress'; ?></h2>
            </div>
            <p>Learn at your pace. Apply each idea through practical work.</p>
        </div>

        <?php if (!empty($courses)): ?>
            <div class="course-grid">
                <?php foreach ($courses as $course): ?>
                    <?php
                    $rating = (float) ($course['avg_rating'] ?? 0);
                    $image = $course['course_image'] ?: 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=85';
                    $pricing = coursePriceDetails($course);
                    $effectivePrice = (float) $pricing['effective_amount'];
                    ?>
                    <article class="course-card">
                        <a class="course-card-media" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>"
                           aria-label="View <?php echo sanitize($course['title']); ?>">
                            <img src="<?php echo sanitize($image); ?>" alt="" loading="lazy">
                            <span class="course-category"><?php echo sanitize($course['category'] ?: 'Digital skills'); ?></span>
                            <?php if ($effectivePrice === 0.0): ?>
                                <span class="course-badge">Free</span>
                            <?php elseif ($pricing['has_discount']): ?>
                                <span class="course-badge">Special offer</span>
                            <?php endif; ?>
                        </a>

                        <div class="course-card-body">
                            <div class="course-meta">
                                <span><i class="far fa-user" aria-hidden="true"></i> <?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech'); ?></span>
                                <span><i class="fas fa-users" aria-hidden="true"></i> <?php echo (int) $course['student_count']; ?></span>
                            </div>

                            <h3><a href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>"><?php echo sanitize($course['title']); ?></a></h3>
                            <p><?php echo sanitize(mb_strimwidth((string) ($course['description'] ?? ''), 0, 112, '…')); ?></p>

                            <div class="course-rating" aria-label="<?php echo number_format($rating, 1); ?> out of 5 stars">
                                <span aria-hidden="true">
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
                                <small><?php echo $course['review_count'] ? number_format($rating, 1) . ' (' . (int) $course['review_count'] . ')' : 'New course'; ?></small>
                            </div>
                        </div>

                        <div class="course-card-footer">
                            <div class="course-price-wrap">
                                <?php if ($effectivePrice === 0.0): ?>
                                    <strong class="course-price">Free</strong>
                                <?php else: ?>
                                    <strong class="course-price"><?php echo formatCurrency($effectivePrice); ?></strong>
                                    <?php if ($pricing['has_discount']): ?>
                                        <del><?php echo formatCurrency($pricing['base_amount']); ?></del>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <a class="round-link" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo (int) $course['id']; ?>" aria-label="Open course">
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-state-icon"><i class="fas fa-compass" aria-hidden="true"></i></span>
                <h2>No matching courses yet</h2>
                <p>Try a broader keyword or clear your filters to see every learning path.</p>
                <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-primary">Browse all courses</a>
            </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination-wrap" aria-label="Course results pages">
                <?php if ($page > 1): ?>
                    <a class="page-arrow" href="<?php echo sanitize($paginationUrl($page - 1)); ?>">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="page-arrow is-disabled" aria-disabled="true">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i> Previous
                    </span>
                <?php endif; ?>
                <div class="page-numbers">
                    <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
                        <a href="<?php echo sanitize($paginationUrl($number)); ?>"
                           class="<?php echo $number === $page ? 'is-active' : ''; ?>"
                           <?php echo $number === $page ? 'aria-current="page"' : ''; ?>>
                            <?php echo $number; ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php if ($page < $totalPages): ?>
                    <a class="page-arrow" href="<?php echo sanitize($paginationUrl($page + 1)); ?>">
                        Next <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <span class="page-arrow is-disabled" aria-disabled="true">
                        Next <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
