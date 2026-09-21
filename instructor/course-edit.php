<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = new Database();
$auth = new Auth($db);

if (!$auth->verifySession()) {
    redirect('/login.php');
}
if (!$auth->isInstructor()) {
    redirect('/' . $auth->getDashboardPath());
}

$instructorId = (int) $auth->getUserId();
$courseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$courseId = $courseId ? (int) $courseId : 0;
$errors = [];

function instructorCourseSlug(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    return trim($value, '-');
}

function instructorOwnedCourse(Database $db, int $courseId, int $instructorId): ?array
{
    if ($courseId <= 0) {
        return null;
    }
    $db->query('SELECT * FROM courses WHERE id = :id AND instructor_id = :instructor_id LIMIT 1');
    $db->bind(':id', $courseId);
    $db->bind(':instructor_id', $instructorId);
    $row = $db->single();
    return $row ?: null;
}

function instructorOwnedModule(Database $db, int $moduleId, int $courseId, int $instructorId): ?array
{
    $db->query('SELECT cm.*
                FROM course_modules cm
                JOIN courses c ON c.id = cm.course_id
                WHERE cm.id = :module_id AND cm.course_id = :course_id AND c.instructor_id = :instructor_id
                LIMIT 1');
    $db->bind(':module_id', $moduleId);
    $db->bind(':course_id', $courseId);
    $db->bind(':instructor_id', $instructorId);
    $row = $db->single();
    return $row ?: null;
}

function instructorOwnedLesson(Database $db, int $lessonId, int $courseId, int $instructorId): ?array
{
    $db->query('SELECT cl.*
                FROM course_lessons cl
                JOIN courses c ON c.id = cl.course_id
                WHERE cl.id = :lesson_id AND cl.course_id = :course_id AND c.instructor_id = :instructor_id
                LIMIT 1');
    $db->bind(':lesson_id', $lessonId);
    $db->bind(':course_id', $courseId);
    $db->bind(':instructor_id', $instructorId);
    $row = $db->single();
    return $row ?: null;
}

$course = instructorOwnedCourse($db, $courseId, $instructorId);
if ($courseId > 0 && !$course) {
    $_SESSION['error'] = 'That course is unavailable or is not assigned to you.';
    redirect('/instructor/courses.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'save_course') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = instructorCourseSlug((string) ($_POST['slug'] ?? $title));
        $description = trim((string) ($_POST['description'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $priceRaw = trim((string) ($_POST['price'] ?? '0'));
        $discountRaw = trim((string) ($_POST['discount_price'] ?? ''));
        $courseImage = trim((string) ($_POST['course_image'] ?? ''));
        $publish = isset($_POST['is_published']) ? 1 : 0;

        if (mb_strlen($title) < 3 || mb_strlen($title) > 255) {
            $errors['title'] = 'Use a course title between 3 and 255 characters.';
        }
        if ($slug === '' || strlen($slug) > 255 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors['slug'] = 'Use a short URL made from letters, numbers, and hyphens.';
        }
        if ($description === '' || mb_strlen($description) > 10000) {
            $errors['description'] = 'Add a clear course description of up to 10,000 characters.';
        }
        if ($category === '' || mb_strlen($category) > 100) {
            $errors['category'] = 'Add a category of up to 100 characters.';
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $priceRaw)) {
            $errors['price'] = 'Enter a valid non-negative course price.';
        }
        if ($discountRaw !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $discountRaw)) {
            $errors['discount_price'] = 'Enter a valid discount price or leave it blank.';
        }
        $price = (float) $priceRaw;
        $discount = $discountRaw === '' ? null : (float) $discountRaw;
        if ($price > 99999999.99) {
            $errors['price'] = 'The course price is too large.';
        }
        if ($discount !== null && ($discount < 0 || $discount >= $price || $price <= 0)) {
            $errors['discount_price'] = 'The discount must be lower than the regular price.';
        }
        if ($courseImage !== '') {
            $parts = parse_url($courseImage);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (strlen($courseImage) > 500 || !filter_var($courseImage, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                $errors['course_image'] = 'Use a valid HTTP or HTTPS image URL.';
            }
        }

        if (!$errors && $publish === 1 && $courseId > 0) {
            $db->query('SELECT COUNT(*) AS lesson_count FROM course_lessons WHERE course_id = :course_id');
            $db->bind(':course_id', $courseId);
            if ((int) (($db->single()['lesson_count'] ?? 0)) < 1) {
                $errors['is_published'] = 'Add at least one lesson before publishing this course.';
            }
        } elseif ($publish === 1 && $courseId === 0) {
            $publish = 0;
            $_SESSION['message'] = 'Course created as a draft. Add a lesson before publishing it.';
        }

        if (!$errors) {
            $db->query('SELECT id FROM courses WHERE slug = :slug AND id <> :id LIMIT 1');
            $db->bind(':slug', $slug);
            $db->bind(':id', $courseId);
            if ($db->single()) {
                $errors['slug'] = 'That course URL is already in use.';
            }
        }

        if (!$errors) {
            try {
                if ($courseId > 0) {
                    $db->query('UPDATE courses
                                SET title = :title, slug = :slug, description = :description,
                                    category = :category, price = :price, discount_price = :discount_price,
                                    course_image = :course_image, is_published = :is_published
                                WHERE id = :id AND instructor_id = :instructor_id');
                    $db->bind(':id', $courseId);
                    $db->bind(':instructor_id', $instructorId);
                } else {
                    $db->query('INSERT INTO courses
                                (title, slug, description, instructor_id, category, price, discount_price, course_image, is_published)
                                VALUES (:title, :slug, :description, :instructor_id, :category, :price, :discount_price, :course_image, :is_published)');
                    $db->bind(':instructor_id', $instructorId);
                }
                $db->bind(':title', $title);
                $db->bind(':slug', $slug);
                $db->bind(':description', $description);
                $db->bind(':category', $category);
                $db->bind(':price', number_format($price, 2, '.', ''));
                $db->bind(':discount_price', $discount === null ? null : number_format($discount, 2, '.', ''));
                $db->bind(':course_image', $courseImage === '' ? null : $courseImage);
                $db->bind(':is_published', $publish);
                $db->execute();
                if ($courseId === 0) {
                    $courseId = (int) $db->lastInsertId();
                }
                $_SESSION['message'] = $publish === 1 ? 'Course saved and published.' : 'Course draft saved.';
                redirect('/instructor/course-edit.php?id=' . $courseId, 303);
            } catch (Throwable $exception) {
                error_log('Unable to save instructor course: ' . $exception->getMessage());
                $errors['form'] = 'The course could not be saved. Please try again.';
            }
        }

        $course = array_merge($course ?: [], [
            'title' => $title,
            'slug' => $slug,
            'description' => $description,
            'category' => $category,
            'price' => $priceRaw,
            'discount_price' => $discountRaw,
            'course_image' => $courseImage,
            'is_published' => $publish,
        ]);
    } elseif ($courseId <= 0 || !$course) {
        $errors['form'] = 'Save the course details before building its curriculum.';
    } elseif ($action === 'add_module') {
        $title = trim((string) ($_POST['module_title'] ?? ''));
        $description = trim((string) ($_POST['module_description'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['module'] = 'Add a module title of up to 255 characters.';
        } elseif (mb_strlen($description) > 3000) {
            $errors['module'] = 'Keep the module description under 3,000 characters.';
        } else {
            $db->query('SELECT COALESCE(MAX(sequence), 0) + 1 AS next_sequence FROM course_modules WHERE course_id = :course_id');
            $db->bind(':course_id', $courseId);
            $sequence = (int) ($db->single()['next_sequence'] ?? 1);
            $db->query('INSERT INTO course_modules (course_id, title, description, sequence)
                        VALUES (:course_id, :title, :description, :sequence)');
            $db->bind(':course_id', $courseId);
            $db->bind(':title', $title);
            $db->bind(':description', $description === '' ? null : $description);
            $db->bind(':sequence', $sequence);
            $db->execute();
            $_SESSION['message'] = 'Module added to the curriculum.';
            redirect('/instructor/course-edit.php?id=' . $courseId . '#curriculum', 303);
        }
    } elseif ($action === 'save_module') {
        $moduleId = filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $module = $moduleId ? instructorOwnedModule($db, (int) $moduleId, $courseId, $instructorId) : null;
        $title = trim((string) ($_POST['module_title'] ?? ''));
        $description = trim((string) ($_POST['module_description'] ?? ''));
        if (!$module) {
            $errors['module'] = 'That module is unavailable.';
        } elseif ($title === '' || mb_strlen($title) > 255 || mb_strlen($description) > 3000) {
            $errors['module'] = 'Check the module title and description, then try again.';
        } else {
            $db->query('UPDATE course_modules SET title = :title, description = :description WHERE id = :id AND course_id = :course_id');
            $db->bind(':title', $title);
            $db->bind(':description', $description === '' ? null : $description);
            $db->bind(':id', (int) $moduleId);
            $db->bind(':course_id', $courseId);
            $db->execute();
            $_SESSION['message'] = 'Module updated.';
            redirect('/instructor/course-edit.php?id=' . $courseId . '#module-' . (int) $moduleId, 303);
        }
    } elseif ($action === 'add_lesson' || $action === 'save_lesson') {
        $moduleId = filter_input(INPUT_POST, 'module_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $module = $moduleId ? instructorOwnedModule($db, (int) $moduleId, $courseId, $instructorId) : null;
        $lessonId = $action === 'save_lesson'
            ? filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        $lesson = $lessonId ? instructorOwnedLesson($db, (int) $lessonId, $courseId, $instructorId) : null;
        $title = trim((string) ($_POST['lesson_title'] ?? ''));
        $description = trim((string) ($_POST['lesson_description'] ?? ''));
        $content = trim((string) ($_POST['lesson_content'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $videoType = trim((string) ($_POST['video_type'] ?? 'youtube'));
        $duration = filter_var($_POST['duration'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
        $isFree = isset($_POST['is_free']) ? 1 : 0;

        if (!$module || ($action === 'save_lesson' && !$lesson)) {
            $errors['lesson'] = 'That lesson or module is unavailable.';
        } elseif ($title === '' || mb_strlen($title) > 255) {
            $errors['lesson'] = 'Add a lesson title of up to 255 characters.';
        } elseif (mb_strlen($description) > 3000 || mb_strlen($content) > 50000) {
            $errors['lesson'] = 'The lesson description or notes are too long.';
        } elseif (!in_array($videoType, ['youtube', 'vimeo', 'file'], true)) {
            $errors['lesson'] = 'Choose a supported video type.';
        } elseif ($videoUrl !== '' && (strlen($videoUrl) > 500 || !filter_var($videoUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($videoUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $errors['lesson'] = 'Use a valid HTTP or HTTPS video URL.';
        } elseif ($duration === false) {
            $errors['lesson'] = 'Lesson duration must be between 0 and 1,440 minutes.';
        } else {
            if ($action === 'add_lesson') {
                $db->query('SELECT COALESCE(MAX(sequence), 0) + 1 AS next_sequence FROM course_lessons WHERE module_id = :module_id');
                $db->bind(':module_id', (int) $moduleId);
                $sequence = (int) ($db->single()['next_sequence'] ?? 1);
                $db->query('INSERT INTO course_lessons
                            (module_id, course_id, title, description, video_url, video_type, content, duration, sequence, is_free)
                            VALUES (:module_id, :course_id, :title, :description, :video_url, :video_type, :content, :duration, :sequence, :is_free)');
                $db->bind(':sequence', $sequence);
            } else {
                $db->query('UPDATE course_lessons
                            SET module_id = :module_id, title = :title, description = :description,
                                video_url = :video_url, video_type = :video_type, content = :content,
                                duration = :duration, is_free = :is_free
                            WHERE id = :lesson_id AND course_id = :course_id');
                $db->bind(':lesson_id', (int) $lessonId);
            }
            $db->bind(':module_id', (int) $moduleId);
            $db->bind(':course_id', $courseId);
            $db->bind(':title', $title);
            $db->bind(':description', $description === '' ? null : $description);
            $db->bind(':video_url', $videoUrl === '' ? null : $videoUrl);
            $db->bind(':video_type', $videoType);
            $db->bind(':content', $content === '' ? null : $content);
            $db->bind(':duration', (int) $duration);
            $db->bind(':is_free', $isFree);
            $db->execute();
            $_SESSION['message'] = $action === 'add_lesson' ? 'Lesson added.' : 'Lesson updated.';
            redirect('/instructor/course-edit.php?id=' . $courseId . '#module-' . (int) $moduleId, 303);
        }
    } elseif ($action === 'add_material') {
        $lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $lesson = $lessonId ? instructorOwnedLesson($db, (int) $lessonId, $courseId, $instructorId) : null;
        $title = trim((string) ($_POST['material_title'] ?? ''));
        if (!$lesson || $title === '' || mb_strlen($title) > 255 || !isset($_FILES['material_file'])) {
            $errors['material'] = 'Choose a lesson, title, and valid resource file.';
        } else {
            $upload = uploadFile($_FILES['material_file'], 'uploads/materials/');
            if (!$upload['success']) {
                $errors['material'] = (string) $upload['message'];
            } else {
                $extension = strtolower((string) pathinfo((string) $upload['file'], PATHINFO_EXTENSION));
                $db->query('INSERT INTO course_materials (lesson_id, title, file_path, file_type, file_size)
                            VALUES (:lesson_id, :title, :file_path, :file_type, :file_size)');
                $db->bind(':lesson_id', (int) $lessonId);
                $db->bind(':title', $title);
                $db->bind(':file_path', (string) $upload['file']);
                $db->bind(':file_type', $extension);
                $db->bind(':file_size', (int) ($_FILES['material_file']['size'] ?? 0));
                $db->execute();
                $_SESSION['message'] = 'Lesson resource uploaded.';
                redirect('/instructor/course-edit.php?id=' . $courseId . '#lesson-' . (int) $lessonId, 303);
            }
        }
    } else {
        $errors['form'] = 'That course action is not supported.';
    }
}

if ($courseId > 0) {
    $course = instructorOwnedCourse($db, $courseId, $instructorId);
}

$modules = [];
$materialsByLesson = [];
if ($course) {
    $db->query('SELECT cm.id AS module_id, cm.title AS module_title, cm.description AS module_description,
                       cm.sequence AS module_sequence,
                       cl.id AS lesson_id, cl.title AS lesson_title, cl.description AS lesson_description,
                       cl.video_url, cl.video_type, cl.content, cl.duration, cl.sequence AS lesson_sequence, cl.is_free
                FROM course_modules cm
                LEFT JOIN course_lessons cl ON cl.module_id = cm.id AND cl.course_id = cm.course_id
                WHERE cm.course_id = :course_id
                ORDER BY cm.sequence, cm.id, cl.sequence, cl.id');
    $db->bind(':course_id', $courseId);
    foreach ($db->resultSet() as $row) {
        $moduleId = (int) $row['module_id'];
        if (!isset($modules[$moduleId])) {
            $modules[$moduleId] = [
                'id' => $moduleId,
                'title' => $row['module_title'],
                'description' => $row['module_description'],
                'sequence' => (int) $row['module_sequence'],
                'lessons' => [],
            ];
        }
        if ($row['lesson_id'] !== null) {
            $modules[$moduleId]['lessons'][] = [
                'id' => (int) $row['lesson_id'],
                'title' => $row['lesson_title'],
                'description' => $row['lesson_description'],
                'video_url' => $row['video_url'],
                'video_type' => $row['video_type'],
                'content' => $row['content'],
                'duration' => (int) ($row['duration'] ?? 0),
                'sequence' => (int) $row['lesson_sequence'],
                'is_free' => (int) $row['is_free'] === 1,
            ];
        }
    }

    $db->query('SELECT cm.*
                FROM course_materials cm
                JOIN course_lessons cl ON cl.id = cm.lesson_id
                WHERE cl.course_id = :course_id
                ORDER BY cm.created_at DESC');
    $db->bind(':course_id', $courseId);
    foreach ($db->resultSet() as $material) {
        $materialsByLesson[(int) $material['lesson_id']][] = $material;
    }
}

$pageTitle = $course ? 'Edit ' . $course['title'] : 'Create Course';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page">
    <header class="workspace-page__header">
        <div>
            <span class="workspace-eyebrow">Course studio</span>
            <h1 class="workspace-title"><?php echo $course ? 'Build your course' : 'Create a new course'; ?></h1>
            <p class="workspace-subtitle"><?php echo $course ? 'Shape the course details, curriculum, lesson notes, videos, and downloadable resources from one workspace.' : 'Start with the core course details. You can add modules, lessons, assessments, and resources immediately after saving.'; ?></p>
        </div>
        <div class="workspace-actions">
            <a class="btn btn-outline-secondary" href="<?php echo APP_URL; ?>/instructor/courses.php"><i class="fas fa-arrow-left me-2"></i>All courses</a>
            <?php if ($course && (int) $course['is_published'] === 1): ?>
                <a class="btn btn-outline-primary" href="<?php echo APP_URL; ?>/course-detail.php?id=<?php echo $courseId; ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square me-2"></i>Preview</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>
    <?php foreach (['module', 'lesson', 'material'] as $area): if (isset($errors[$area])): ?>
        <div class="alert alert-danger" role="alert"><?php echo sanitize($errors[$area]); ?></div>
    <?php endif; endforeach; ?>

    <div class="workspace-grid">
        <div>
            <section class="workspace-panel">
                <div class="workspace-panel__header">
                    <div><h2>Course details</h2><p>The information learners see in the public catalog.</p></div>
                    <?php if ($course): ?><span class="status-pill <?php echo (int) $course['is_published'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $course['is_published'] === 1 ? 'Published' : 'Draft'; ?></span><?php endif; ?>
                </div>
                <div class="workspace-panel__body">
                    <form method="post" novalidate>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="save_course">
                        <div class="workspace-form-grid">
                            <div class="form-span-2">
                                <label for="title" class="form-label">Course title</label>
                                <input id="title" name="title" maxlength="255" class="form-control <?php echo isset($errors['title']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($course['title'] ?? ''); ?>" required>
                                <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['title']); ?></div><?php endif; ?>
                            </div>
                            <div>
                                <label for="slug" class="form-label">Course URL</label>
                                <input id="slug" name="slug" maxlength="255" class="form-control <?php echo isset($errors['slug']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($course['slug'] ?? ''); ?>" placeholder="generated-from-title">
                                <?php if (isset($errors['slug'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['slug']); ?></div><?php endif; ?>
                            </div>
                            <div>
                                <label for="category" class="form-label">Category</label>
                                <input id="category" name="category" maxlength="100" class="form-control <?php echo isset($errors['category']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($course['category'] ?? ''); ?>" placeholder="Web Development" required>
                                <?php if (isset($errors['category'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['category']); ?></div><?php endif; ?>
                            </div>
                            <div class="form-span-2">
                                <label for="description" class="form-label">Description</label>
                                <textarea id="description" name="description" rows="6" maxlength="10000" class="form-control <?php echo isset($errors['description']) ? 'is-invalid' : ''; ?>" required><?php echo sanitize($course['description'] ?? ''); ?></textarea>
                                <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['description']); ?></div><?php endif; ?>
                            </div>
                            <div>
                                <label for="price" class="form-label">Regular price (NGN)</label>
                                <input id="price" name="price" type="number" min="0" max="99999999.99" step="0.01" class="form-control <?php echo isset($errors['price']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($course['price'] ?? '0'); ?>" required>
                                <?php if (isset($errors['price'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['price']); ?></div><?php endif; ?>
                            </div>
                            <div>
                                <label for="discount_price" class="form-label">Discount price <span class="text-muted fw-normal">(optional)</span></label>
                                <input id="discount_price" name="discount_price" type="number" min="0" max="99999999.99" step="0.01" class="form-control <?php echo isset($errors['discount_price']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($course['discount_price'] ?? ''); ?>">
                                <?php if (isset($errors['discount_price'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['discount_price']); ?></div><?php endif; ?>
                            </div>
                            <div class="form-span-2">
                                <label for="course_image" class="form-label">Cover image URL <span class="text-muted fw-normal">(optional)</span></label>
                                <input id="course_image" name="course_image" type="url" maxlength="500" class="form-control <?php echo isset($errors['course_image']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($course['course_image'] ?? ''); ?>" placeholder="https://example.com/course-cover.jpg">
                                <?php if (isset($errors['course_image'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['course_image']); ?></div><?php endif; ?>
                            </div>
                            <div class="form-span-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" id="is_published" name="is_published" type="checkbox" value="1" <?php echo (int) ($course['is_published'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="is_published">Publish in the course catalog</label>
                                </div>
                                <?php if (isset($errors['is_published'])): ?><div class="text-danger small mt-2"><?php echo sanitize($errors['is_published']); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="workspace-form-actions"><button class="btn btn-primary px-4" type="submit"><i class="fas fa-floppy-disk me-2"></i>Save course</button></div>
                    </form>
                </div>
            </section>

            <?php if ($course): ?>
                <section class="workspace-panel mt-4" id="curriculum">
                    <div class="workspace-panel__header">
                        <div><h2>Curriculum builder</h2><p>Organize the learning journey into clear modules and lessons.</p></div>
                        <span class="status-pill status-pill--info"><?php echo count($modules); ?> modules</span>
                    </div>
                    <div class="workspace-panel__body">
                        <?php if ($modules): ?>
                            <div class="builder-stack">
                                <?php foreach ($modules as $module): ?>
                                    <section class="builder-section" id="module-<?php echo (int) $module['id']; ?>">
                                        <div class="builder-section__header">
                                            <div><span class="workspace-eyebrow mb-1">Module <?php echo (int) $module['sequence']; ?></span><h3><?php echo sanitize($module['title']); ?></h3></div>
                                            <span class="status-pill"><?php echo count($module['lessons']); ?> lessons</span>
                                        </div>
                                        <div class="builder-section__body">
                                            <details class="mb-3">
                                                <summary class="small fw-bold text-primary">Edit module details</summary>
                                                <form method="post" class="workspace-form-grid mt-3">
                                                    <?php echo csrfField(); ?><input type="hidden" name="action" value="save_module"><input type="hidden" name="module_id" value="<?php echo (int) $module['id']; ?>">
                                                    <div><label class="form-label">Module title</label><input class="form-control" name="module_title" maxlength="255" value="<?php echo sanitize($module['title']); ?>" required></div>
                                                    <div><label class="form-label">Description</label><input class="form-control" name="module_description" maxlength="3000" value="<?php echo sanitize($module['description']); ?>"></div>
                                                    <div class="form-span-2 text-end"><button class="btn btn-outline-primary btn-sm" type="submit">Update module</button></div>
                                                </form>
                                            </details>

                                            <?php foreach ($module['lessons'] as $lesson): ?>
                                                <article class="builder-item" id="lesson-<?php echo (int) $lesson['id']; ?>">
                                                    <div>
                                                        <h4><i class="fas fa-circle-play text-primary me-2"></i><?php echo sanitize($lesson['title']); ?></h4>
                                                        <p><?php echo $lesson['duration'] > 0 ? (int) $lesson['duration'] . ' min' : 'Self-paced'; ?> · <?php echo sanitize(ucfirst($lesson['video_type'])); ?><?php echo $lesson['is_free'] ? ' · Free preview' : ''; ?> · <?php echo count($materialsByLesson[(int) $lesson['id']] ?? []); ?> resources</p>
                                                    </div>
                                                    <details>
                                                        <summary class="btn btn-outline-secondary btn-sm">Edit</summary>
                                                        <div class="mt-3" style="min-width:min(76vw,620px)">
                                                            <form method="post">
                                                                <?php echo csrfField(); ?><input type="hidden" name="action" value="save_lesson"><input type="hidden" name="lesson_id" value="<?php echo (int) $lesson['id']; ?>"><input type="hidden" name="module_id" value="<?php echo (int) $module['id']; ?>">
                                                                <div class="workspace-form-grid">
                                                                    <div class="form-span-2"><label class="form-label">Lesson title</label><input class="form-control" name="lesson_title" maxlength="255" value="<?php echo sanitize($lesson['title']); ?>" required></div>
                                                                    <div class="form-span-2"><label class="form-label">Short description</label><textarea class="form-control" name="lesson_description" rows="2" maxlength="3000"><?php echo sanitize($lesson['description']); ?></textarea></div>
                                                                    <div><label class="form-label">Video type</label><select class="form-select" name="video_type"><?php foreach (['youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'file' => 'Hosted file'] as $type => $label): ?><option value="<?php echo $type; ?>" <?php echo $lesson['video_type'] === $type ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
                                                                    <div><label class="form-label">Duration (minutes)</label><input class="form-control" name="duration" type="number" min="0" max="1440" value="<?php echo (int) $lesson['duration']; ?>"></div>
                                                                    <div class="form-span-2"><label class="form-label">Video URL</label><input class="form-control" name="video_url" type="url" maxlength="500" value="<?php echo sanitize($lesson['video_url']); ?>"></div>
                                                                    <div class="form-span-2"><label class="form-label">Lesson notes</label><textarea class="form-control" name="lesson_content" rows="6" maxlength="50000"><?php echo sanitize($lesson['content']); ?></textarea></div>
                                                                    <div class="form-span-2"><label class="form-check"><input class="form-check-input" name="is_free" type="checkbox" value="1" <?php echo $lesson['is_free'] ? 'checked' : ''; ?>><span class="form-check-label">Allow a free preview</span></label></div>
                                                                </div>
                                                                <div class="workspace-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save lesson</button></div>
                                                            </form>
                                                            <hr>
                                                            <form method="post" enctype="multipart/form-data">
                                                                <?php echo csrfField(); ?><input type="hidden" name="action" value="add_material"><input type="hidden" name="lesson_id" value="<?php echo (int) $lesson['id']; ?>">
                                                                <div class="workspace-form-grid">
                                                                    <div><label class="form-label">Resource title</label><input class="form-control" name="material_title" maxlength="255" required></div>
                                                                    <div><label class="form-label">File (PDF or image, max 5 MB)</label><input class="form-control" name="material_file" type="file" accept=".pdf,.png,.jpg,.jpeg" required></div>
                                                                </div>
                                                                <div class="workspace-form-actions"><button class="btn btn-outline-primary btn-sm" type="submit"><i class="fas fa-paperclip me-1"></i>Add resource</button></div>
                                                            </form>
                                                        </div>
                                                    </details>
                                                </article>
                                            <?php endforeach; ?>

                                            <details class="mt-3">
                                                <summary class="btn btn-outline-primary btn-sm"><i class="fas fa-plus me-1"></i>Add lesson</summary>
                                                <form method="post" class="mt-3">
                                                    <?php echo csrfField(); ?><input type="hidden" name="action" value="add_lesson"><input type="hidden" name="module_id" value="<?php echo (int) $module['id']; ?>">
                                                    <div class="workspace-form-grid">
                                                        <div class="form-span-2"><label class="form-label">Lesson title</label><input class="form-control" name="lesson_title" maxlength="255" required></div>
                                                        <div class="form-span-2"><label class="form-label">Short description</label><textarea class="form-control" name="lesson_description" rows="2" maxlength="3000"></textarea></div>
                                                        <div><label class="form-label">Video type</label><select class="form-select" name="video_type"><option value="youtube">YouTube</option><option value="vimeo">Vimeo</option><option value="file">Hosted file</option></select></div>
                                                        <div><label class="form-label">Duration (minutes)</label><input class="form-control" name="duration" type="number" min="0" max="1440" value="0"></div>
                                                        <div class="form-span-2"><label class="form-label">Video URL <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" name="video_url" type="url" maxlength="500"></div>
                                                        <div class="form-span-2"><label class="form-label">Lesson notes</label><textarea class="form-control" name="lesson_content" rows="5" maxlength="50000"></textarea></div>
                                                        <div class="form-span-2"><label class="form-check"><input class="form-check-input" name="is_free" type="checkbox" value="1"><span class="form-check-label">Allow a free preview</span></label></div>
                                                    </div>
                                                    <div class="workspace-form-actions"><button class="btn btn-primary" type="submit">Add lesson</button></div>
                                                </form>
                                            </details>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-layer-group"></i></span><h3>Start with your first module</h3><p>Modules keep related lessons together and make the learning path easy to follow.</p></div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside>
            <?php if ($course): ?>
                <section class="workspace-panel">
                    <div class="workspace-panel__header"><div><h2>Add a module</h2><p>Create the next stage in this learning path.</p></div></div>
                    <div class="workspace-panel__body">
                        <form method="post">
                            <?php echo csrfField(); ?><input type="hidden" name="action" value="add_module">
                            <div class="mb-3"><label for="module_title" class="form-label">Module title</label><input id="module_title" name="module_title" maxlength="255" class="form-control" required></div>
                            <div class="mb-3"><label for="module_description" class="form-label">Description <span class="text-muted fw-normal">(optional)</span></label><textarea id="module_description" name="module_description" maxlength="3000" rows="3" class="form-control"></textarea></div>
                            <button class="btn btn-primary w-100" type="submit"><i class="fas fa-plus me-2"></i>Add module</button>
                        </form>
                    </div>
                </section>

                <section class="workspace-panel mt-4">
                    <div class="workspace-panel__header"><div><h2>Next steps</h2><p>Complete the teaching setup for this course.</p></div></div>
                    <div class="workspace-panel__body d-grid gap-2">
                        <a class="btn btn-outline-primary text-start" href="<?php echo APP_URL; ?>/instructor/assessments.php?course_id=<?php echo $courseId; ?>"><i class="fas fa-list-check me-2"></i>Create assessments</a>
                        <a class="btn btn-outline-primary text-start" href="<?php echo APP_URL; ?>/instructor/learners.php?course_id=<?php echo $courseId; ?>"><i class="fas fa-user-group me-2"></i>View learners</a>
                        <a class="btn btn-outline-primary text-start" href="<?php echo APP_URL; ?>/instructor/submissions.php?course_id=<?php echo $courseId; ?>"><i class="fas fa-inbox me-2"></i>Grade submissions</a>
                    </div>
                </section>
            <?php else: ?>
                <div class="workspace-callout"><i class="fas fa-lightbulb"></i><div><strong class="d-block mb-1">Build in draft first</strong>Save the details, add at least one module and lesson, preview the experience, then publish when it is ready for learners.</div></div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
