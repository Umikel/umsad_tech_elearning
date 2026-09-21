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
if (!$auth->isStudent()) {
    redirect('/' . $auth->getDashboardPath());
}

if (!function_exists('umsadRouteCsrfToken')) {
    function umsadRouteCsrfToken(): string
    {
        if (function_exists('csrfToken')) {
            return (string) csrfToken();
        }
        if (function_exists('csrf_token')) {
            return (string) csrf_token();
        }
        if (empty($_SESSION['_umsad_route_csrf'])) {
            $_SESSION['_umsad_route_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_umsad_route_csrf'];
    }
}

if (!function_exists('umsadRouteCsrfValid')) {
    function umsadRouteCsrfValid(?string $token): bool
    {
        if (function_exists('verifyCsrfToken')) {
            return (bool) verifyCsrfToken($token);
        }
        if (function_exists('verify_csrf_token')) {
            return (bool) verify_csrf_token($token ?? '');
        }
        $expected = (string) ($_SESSION['_umsad_route_csrf'] ?? '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }
}

function umsadLessonEmbedUrl(?string $url, ?string $type): ?string
{
    $url = trim((string) $url);
    $type = strtolower(trim((string) $type));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    if ($type === 'youtube') {
        $youtubeHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
        if (!in_array($host, $youtubeHosts, true)) {
            return null;
        }
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $videoId = null;
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $videoId = $segments[0] ?? null;
        } elseif (($segments[0] ?? '') === 'embed' || ($segments[0] ?? '') === 'shorts') {
            $videoId = $segments[1] ?? null;
        } else {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = $query['v'] ?? null;
        }
        if (!is_string($videoId) || !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
            return null;
        }
        return 'https://www.youtube-nocookie.com/embed/' . $videoId;
    }

    if ($type === 'vimeo') {
        if (!in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));
        $videoId = end($segments);
        if (!is_string($videoId) || !preg_match('/^\d{5,12}$/', $videoId)) {
            return null;
        }
        return 'https://player.vimeo.com/video/' . $videoId;
    }

    return null;
}

$studentId = (int) $auth->getUserId();
$courseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$courseId) {
    $_SESSION['error'] = 'Choose a valid course from your learning library.';
    header('Location: ' . APP_URL . '/student/my-courses.php');
    exit;
}
$courseId = (int) $courseId;

$db->query('SELECT c.id, c.title, c.description, c.category, c.course_image,
                   u.full_name AS instructor_name,
                   se.id AS enrollment_id, se.progress_percentage, se.is_completed, se.enrollment_date
            FROM student_enrollments se
            JOIN courses c ON c.id = se.course_id
            LEFT JOIN users u ON u.id = c.instructor_id
            WHERE se.is_approved = 1 AND se.student_id = :student_id AND se.course_id = :course_id
            LIMIT 1');
$db->bind(':student_id', $studentId);
$db->bind(':course_id', $courseId);
$course = $db->single();
if (!$course) {
    $_SESSION['error'] = 'Your course is awaiting admin approval. You can start learning once approved.';
    header('Location: ' . APP_URL . '/student/my-courses.php');
    exit;
}

$actionError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!umsadRouteCsrfValid($_POST['csrf_token'] ?? null)) {
        $actionError = 'Your session expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') !== 'complete' || !$lessonId) {
        $actionError = 'That lesson update could not be processed.';
    } else {
        $db->query('SELECT id FROM course_lessons WHERE id = :lesson_id AND course_id = :course_id LIMIT 1');
        $db->bind(':lesson_id', (int) $lessonId);
        $db->bind(':course_id', $courseId);
        $ownedLesson = $db->single();
        if (!$ownedLesson) {
            $actionError = 'That lesson is not part of this course.';
        } else {
            try {
                $db->beginTransaction();
                $db->query('INSERT INTO student_progress (student_id, lesson_id, enrollment_id, is_completed, completed_at, time_spent)
                            VALUES (:student_id, :lesson_id, :enrollment_id, 1, NOW(), 0)
                            ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()');
                $db->bind(':student_id', $studentId);
                $db->bind(':lesson_id', (int) $lessonId);
                $db->bind(':enrollment_id', (int) $course['enrollment_id']);
                $db->execute();

                $db->query('SELECT COUNT(cl.id) AS total_lessons,
                                   COALESCE(SUM(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_lessons
                            FROM course_lessons cl
                            LEFT JOIN student_progress sp ON sp.lesson_id = cl.id AND sp.student_id = :student_id
                            WHERE cl.course_id = :course_id');
                $db->bind(':student_id', $studentId);
                $db->bind(':course_id', $courseId);
                $counts = $db->single() ?: ['total_lessons' => 0, 'completed_lessons' => 0];
                $totalLessons = (int) $counts['total_lessons'];
                $completedLessons = (int) $counts['completed_lessons'];
                $progress = $totalLessons > 0 ? min(100, (int) floor(($completedLessons / $totalLessons) * 100)) : 0;
                $isCompleted = $totalLessons > 0 && $completedLessons >= $totalLessons ? 1 : 0;

                $db->query('UPDATE student_enrollments
                            SET progress_percentage = :progress,
                                is_completed = :is_completed,
                                completion_date = CASE
                                    WHEN :completion_flag = 1 THEN COALESCE(completion_date, NOW())
                                    ELSE completion_date
                                END
                            WHERE id = :enrollment_id AND student_id = :student_id');
                $db->bind(':progress', $progress);
                $db->bind(':is_completed', $isCompleted);
                $db->bind(':completion_flag', $isCompleted);
                $db->bind(':enrollment_id', (int) $course['enrollment_id']);
                $db->bind(':student_id', $studentId);
                $db->execute();

                if ($isCompleted === 1) {
                    $certificateCode = 'UMSAD-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(6)));
                    $db->query('INSERT INTO certificates (student_id, course_id, certificate_code, issued_date)
                                SELECT :student_id, :course_id, :certificate_code, NOW()
                                WHERE NOT EXISTS (
                                    SELECT 1 FROM certificates
                                    WHERE student_id = :existing_student_id AND course_id = :existing_course_id
                                )');
                    $db->bind(':student_id', $studentId);
                    $db->bind(':course_id', $courseId);
                    $db->bind(':certificate_code', $certificateCode);
                    $db->bind(':existing_student_id', $studentId);
                    $db->bind(':existing_course_id', $courseId);
                    $db->execute();
                }
                $db->commit();

                $_SESSION['message'] = $isCompleted ? 'Course completed—excellent work!' : 'Lesson marked as complete.';
                header('Location: ' . APP_URL . '/student/course-lessons.php?' . http_build_query(['id' => $courseId, 'lesson_id' => (int) $lessonId]));
                exit;
            } catch (Throwable $exception) {
                error_log('Unable to update lesson progress: ' . $exception->getMessage());
                try {
                    $db->rollBack();
                } catch (Throwable $ignored) {
                }
                $actionError = 'We could not save your progress. Please try again.';
            }
        }
    }
}

$db->query('SELECT cm.id AS module_id, cm.title AS module_title, cm.description AS module_description, cm.sequence AS module_sequence,
                   cl.id AS lesson_id, cl.title AS lesson_title, cl.description AS lesson_description,
                   cl.video_url, cl.video_type, cl.content, cl.duration, cl.sequence AS lesson_sequence,
                   COALESCE(sp.is_completed, 0) AS lesson_completed
            FROM course_modules cm
            LEFT JOIN course_lessons cl ON cl.module_id = cm.id AND cl.course_id = cm.course_id
            LEFT JOIN student_progress sp ON sp.lesson_id = cl.id AND sp.student_id = :student_id
            WHERE cm.course_id = :course_id
            ORDER BY cm.sequence ASC, cm.id ASC, cl.sequence ASC, cl.id ASC');
$db->bind(':student_id', $studentId);
$db->bind(':course_id', $courseId);
$rows = $db->resultSet();

$modules = [];
$lessonList = [];
foreach ($rows as $row) {
    $moduleId = (int) $row['module_id'];
    if (!isset($modules[$moduleId])) {
        $modules[$moduleId] = [
            'id' => $moduleId,
            'title' => $row['module_title'],
            'description' => $row['module_description'],
            'lessons' => [],
        ];
    }
    if ($row['lesson_id'] !== null) {
        $lesson = [
            'id' => (int) $row['lesson_id'],
            'title' => $row['lesson_title'],
            'description' => $row['lesson_description'],
            'video_url' => $row['video_url'],
            'video_type' => $row['video_type'],
            'content' => $row['content'],
            'duration' => (int) ($row['duration'] ?? 0),
            'completed' => (int) $row['lesson_completed'] === 1,
            'module_title' => $row['module_title'],
        ];
        $modules[$moduleId]['lessons'][] = $lesson;
        $lessonList[] = $lesson;
    }
}

$requestedLessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedIndex = 0;
if ($requestedLessonId) {
    foreach ($lessonList as $index => $lesson) {
        if ($lesson['id'] === (int) $requestedLessonId) {
            $selectedIndex = $index;
            break;
        }
    }
} else {
    foreach ($lessonList as $index => $lesson) {
        if (!$lesson['completed']) {
            $selectedIndex = $index;
            break;
        }
    }
}
$selectedLesson = $lessonList[$selectedIndex] ?? null;
$previousLesson = $selectedIndex > 0 ? $lessonList[$selectedIndex - 1] : null;
$nextLesson = isset($lessonList[$selectedIndex + 1]) ? $lessonList[$selectedIndex + 1] : null;
$completedCount = count(array_filter($lessonList, static fn($lesson) => $lesson['completed']));
$calculatedProgress = count($lessonList) > 0 ? (int) floor(($completedCount / count($lessonList)) * 100) : 0;
$embedUrl = $selectedLesson ? umsadLessonEmbedUrl($selectedLesson['video_url'], $selectedLesson['video_type']) : null;

$lessonMaterials = [];
$lessonQuizzes = [];
$lessonAssignments = [];
if ($selectedLesson) {
    $db->query('SELECT * FROM course_materials WHERE lesson_id = :lesson_id ORDER BY created_at DESC');
    $db->bind(':lesson_id', (int) $selectedLesson['id']);
    $lessonMaterials = $db->resultSet();

    $db->query('SELECT q.*,
                       (SELECT qa.score FROM quiz_attempts qa
                        WHERE qa.quiz_id = q.id AND qa.student_id = :score_student_id
                        ORDER BY qa.completed_at DESC, qa.id DESC LIMIT 1) AS latest_score,
                       (SELECT MAX(qa2.passed) FROM quiz_attempts qa2
                        WHERE qa2.quiz_id = q.id AND qa2.student_id = :pass_student_id) AS has_passed,
                       (SELECT COUNT(*) FROM quiz_attempts qa3
                        WHERE qa3.quiz_id = q.id AND qa3.student_id = :attempt_student_id) AS attempt_count
                FROM quizzes q
                WHERE q.lesson_id = :lesson_id
                ORDER BY q.created_at');
    $db->bind(':score_student_id', $studentId);
    $db->bind(':pass_student_id', $studentId);
    $db->bind(':attempt_student_id', $studentId);
    $db->bind(':lesson_id', (int) $selectedLesson['id']);
    $lessonQuizzes = $db->resultSet();

    $db->query('SELECT a.*,
                       (SELECT ass.id FROM assignment_submissions ass
                        WHERE ass.assignment_id = a.id AND ass.student_id = :submission_student_id
                        ORDER BY ass.submitted_at DESC, ass.id DESC LIMIT 1) AS submission_id,
                       (SELECT ass2.is_graded FROM assignment_submissions ass2
                        WHERE ass2.assignment_id = a.id AND ass2.student_id = :graded_student_id
                        ORDER BY ass2.submitted_at DESC, ass2.id DESC LIMIT 1) AS is_graded,
                       (SELECT ass3.score FROM assignment_submissions ass3
                        WHERE ass3.assignment_id = a.id AND ass3.student_id = :score_student_id
                        ORDER BY ass3.submitted_at DESC, ass3.id DESC LIMIT 1) AS latest_score
                FROM assignments a
                WHERE a.lesson_id = :lesson_id
                ORDER BY a.created_at');
    $db->bind(':submission_student_id', $studentId);
    $db->bind(':graded_student_id', $studentId);
    $db->bind(':score_student_id', $studentId);
    $db->bind(':lesson_id', (int) $selectedLesson['id']);
    $lessonAssignments = $db->resultSet();
}

$pageTitle = $course['title'];
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .lesson-wrap { margin: 0 auto 4rem; }
    .lesson-topbar { margin-bottom: 1.5rem; padding: 1.25rem 1.4rem; border: 1px solid #e7e7f1; border-radius: 16px; background: #fff; }
    .lesson-topbar h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(1.3rem, 3vw, 1.8rem); letter-spacing: -.035em; }
    .lesson-layout { display: grid; grid-template-columns: minmax(280px, 340px) minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .curriculum-panel { max-height: calc(100vh - 120px); overflow: auto; position: sticky; top: 1rem; border: 1px solid #e6e6f0; border-radius: 18px; background: #fff; box-shadow: 0 10px 30px rgba(35,37,82,.055); }
    .curriculum-head { position: sticky; top: 0; z-index: 2; padding: 1.25rem; border-bottom: 1px solid #ececf3; background: rgba(255,255,255,.96); backdrop-filter: blur(10px); }
    .curriculum-module { padding: 1.1rem 1.15rem .5rem; border-bottom: 1px solid #eeeef4; }
    .curriculum-module h2 { color: #626477; font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .lesson-link { display: flex; gap: .7rem; align-items: flex-start; margin: .45rem -.45rem; padding: .7rem; border-radius: 10px; color: #55576c; text-decoration: none; }
    .lesson-link:hover, .lesson-link.active { color: #484ccd; background: #eeeeff; }
    .lesson-link__state { flex: 0 0 25px; display: grid; place-items: center; width: 25px; height: 25px; border: 1px solid #d8d9e7; border-radius: 50%; font-size: .65rem; }
    .lesson-link.completed .lesson-link__state { border-color: #bfe6d0; color: #168950; background: #e9f8ef; }
    .lesson-link strong { display: block; font-size: .82rem; line-height: 1.4; }
    .lesson-link small { color: #9697a7; font-size: .7rem; }
    .player-card, .lesson-copy { overflow: hidden; border: 1px solid #e6e6f0; border-radius: 18px; background: #fff; box-shadow: 0 10px 30px rgba(35,37,82,.05); }
    .video-frame { position: relative; width: 100%; aspect-ratio: 16 / 9; background: #111329; }
    .video-frame iframe { width: 100%; height: 100%; border: 0; }
    .video-unavailable { height: 100%; display: grid; place-items: center; padding: 2rem; color: #c5c7d9; text-align: center; }
    .video-unavailable i { display: block; margin-bottom: 1rem; font-size: 2rem; }
    .player-details { padding: clamp(1.25rem, 3vw, 2rem); }
    .player-details h2 { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.035em; }
    .lesson-copy { margin-top: 1.25rem; padding: clamp(1.25rem, 3vw, 2rem); }
    .lesson-nav a { color: #5659d5; font-weight: 700; text-decoration: none; }
    .progress-track { height: 7px; overflow: hidden; border-radius: 999px; background: #e9e9f2; }
    .progress-track span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #5b5fe8, #8b64de); }
    @media (max-width: 991.98px) { .lesson-layout { grid-template-columns: 1fr; } .curriculum-panel { max-height: 440px; position: static; order: 2; } }
</style>

<div class="container-fluid px-3 px-lg-4 lesson-wrap">
    <header class="lesson-topbar">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div class="d-flex align-items-start gap-3">
                <a href="<?php echo APP_URL; ?>/student/my-courses.php" class="btn btn-light border" aria-label="Back to my courses"><i class="fas fa-arrow-left"></i></a>
                <div><span class="text-primary small fw-bold"><?php echo sanitize($course['category'] ?: 'Course'); ?></span><h1 class="mb-1"><?php echo sanitize($course['title']); ?></h1><small class="text-muted">with <?php echo sanitize($course['instructor_name'] ?: 'Umsad Tech'); ?></small></div>
            </div>
            <div style="min-width:min(100%,260px)">
                <div class="d-flex justify-content-between small mb-2"><span class="text-muted">Course progress</span><strong><?php echo $calculatedProgress; ?>%</strong></div>
                <div class="progress-track"><span style="width:<?php echo $calculatedProgress; ?>%"></span></div>
            </div>
        </div>
    </header>

    <?php if ($actionError): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($actionError); ?></div><?php endif; ?>

    <div class="lesson-layout">
        <aside class="curriculum-panel">
            <div class="curriculum-head">
                <div class="d-flex justify-content-between align-items-center"><strong>Course content</strong><span class="badge text-bg-light"><?php echo $completedCount; ?>/<?php echo count($lessonList); ?></span></div>
            </div>
            <?php if ($modules): ?>
                <?php $moduleNumber = 0; foreach ($modules as $module): $moduleNumber++; ?>
                    <section class="curriculum-module">
                        <h2>Module <?php echo $moduleNumber; ?> · <?php echo sanitize($module['title']); ?></h2>
                        <?php if ($module['lessons']): ?>
                            <?php foreach ($module['lessons'] as $lesson): ?>
                                <a class="lesson-link <?php echo $lesson['completed'] ? 'completed' : ''; ?> <?php echo $selectedLesson && $lesson['id'] === $selectedLesson['id'] ? 'active' : ''; ?>" href="?<?php echo http_build_query(['id' => $courseId, 'lesson_id' => $lesson['id']]); ?>">
                                    <span class="lesson-link__state"><i class="fas <?php echo $lesson['completed'] ? 'fa-check' : 'fa-play'; ?>"></i></span>
                                    <span><strong><?php echo sanitize($lesson['title']); ?></strong><small><?php echo $lesson['duration'] > 0 ? $lesson['duration'] . ' min' : 'Self-paced'; ?></small></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?><p class="text-muted small">Lessons are being prepared.</p><?php endif; ?>
                    </section>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-4 text-center text-muted"><i class="fas fa-layer-group fa-2x mb-3"></i><p class="mb-0">Course content is being prepared.</p></div>
            <?php endif; ?>
        </aside>

        <section>
            <?php if ($selectedLesson): ?>
                <article class="player-card">
                    <div class="video-frame">
                        <?php if ($embedUrl): ?>
                            <iframe src="<?php echo sanitize($embedUrl); ?>" title="<?php echo sanitize($selectedLesson['title']); ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                        <?php else: ?>
                            <div class="video-unavailable"><div><i class="fas fa-video-slash"></i><strong>Video unavailable</strong><p class="small mb-0 mt-2">This lesson does not have a supported YouTube or Vimeo video yet.</p></div></div>
                        <?php endif; ?>
                    </div>
                    <div class="player-details">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
                            <div><span class="text-primary small fw-bold"><?php echo sanitize($selectedLesson['module_title']); ?></span><h2 class="h3 mt-1 mb-2"><?php echo sanitize($selectedLesson['title']); ?></h2><p class="text-muted mb-0"><?php echo sanitize((string) $selectedLesson['description']); ?></p></div>
                            <?php if (!$selectedLesson['completed']): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(umsadRouteCsrfToken()); ?>">
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="lesson_id" value="<?php echo (int) $selectedLesson['id']; ?>">
                                    <button class="btn btn-success text-nowrap" type="submit"><i class="far fa-circle-check me-2"></i>Mark complete</button>
                                </form>
                            <?php else: ?>
                                <span class="badge rounded-pill text-bg-success px-3 py-2"><i class="fas fa-check me-1"></i>Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>

                <?php if (trim((string) $selectedLesson['content']) !== ''): ?>
                    <section class="lesson-copy"><h3 class="h5 mb-3">Lesson notes</h3><div class="text-muted" style="line-height:1.8"><?php echo nl2br(sanitize($selectedLesson['content'])); ?></div></section>
                <?php endif; ?>

                <?php if ($lessonMaterials || $lessonQuizzes || $lessonAssignments): ?>
                    <section class="lesson-copy">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4"><div><span class="workspace-eyebrow">Practice & resources</span><h3 class="h4 mb-1">Continue beyond the video</h3><p class="text-muted mb-0">Use the resources and complete the activities for this lesson.</p></div></div>
                        <?php if ($lessonMaterials): ?>
                            <div class="mb-4"><h4 class="h6 mb-3">Downloads</h4><div class="d-grid gap-2">
                                <?php foreach ($lessonMaterials as $material): ?>
                                    <a class="border rounded-3 p-3 d-flex align-items-center justify-content-between gap-3 text-decoration-none" href="<?php echo APP_URL . '/' . sanitize(ltrim((string) $material['file_path'], '/')); ?>" target="_blank" rel="noopener">
                                        <span class="d-flex align-items-center gap-3"><span class="workspace-metric-card__icon"><i class="fas fa-file-arrow-down"></i></span><span><strong class="d-block text-dark"><?php echo sanitize($material['title']); ?></strong><small class="text-muted"><?php echo sanitize(strtoupper((string) ($material['file_type'] ?: 'FILE'))); ?><?php echo (int) $material['file_size'] > 0 ? ' · ' . number_format(((int) $material['file_size']) / 1048576, 1) . ' MB' : ''; ?></small></span></span><i class="fas fa-arrow-up-right-from-square text-muted"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div></div>
                        <?php endif; ?>

                        <?php if ($lessonQuizzes || $lessonAssignments): ?>
                            <div class="workspace-card-grid">
                                <?php foreach ($lessonQuizzes as $quiz): ?>
                                    <a class="workspace-card text-decoration-none" href="<?php echo APP_URL; ?>/student/quiz.php?quiz_id=<?php echo (int) $quiz['id']; ?>">
                                        <div class="d-flex align-items-center justify-content-between gap-2"><span class="workspace-metric-card__icon"><i class="fas fa-circle-question"></i></span><span class="status-pill <?php echo (int) $quiz['has_passed'] === 1 ? 'status-pill--success' : 'status-pill--info'; ?>"><?php echo (int) $quiz['has_passed'] === 1 ? 'Passed' : ((int) $quiz['attempt_count'] > 0 ? 'Try again' : 'Ready'); ?></span></div>
                                        <h3><?php echo sanitize($quiz['title']); ?></h3><p><?php echo (int) $quiz['total_questions']; ?> questions · <?php echo (int) $quiz['duration']; ?> min · Pass mark <?php echo (int) $quiz['passing_score']; ?>%</p>
                                        <div class="workspace-card__meta"><span><?php echo $quiz['latest_score'] !== null ? 'Latest score ' . (int) $quiz['latest_score'] . '%' : 'Start quiz'; ?></span><i class="fas fa-arrow-right ms-auto"></i></div>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach ($lessonAssignments as $assignment): ?>
                                    <a class="workspace-card text-decoration-none" href="<?php echo APP_URL; ?>/student/assignment.php?assignment_id=<?php echo (int) $assignment['id']; ?>">
                                        <div class="d-flex align-items-center justify-content-between gap-2"><span class="workspace-metric-card__icon"><i class="fas fa-file-pen"></i></span><span class="status-pill <?php echo $assignment['submission_id'] ? ((int) $assignment['is_graded'] === 1 ? 'status-pill--success' : 'status-pill--warning') : 'status-pill--info'; ?>"><?php echo $assignment['submission_id'] ? ((int) $assignment['is_graded'] === 1 ? 'Graded' : 'Submitted') : 'To do'; ?></span></div>
                                        <h3><?php echo sanitize($assignment['title']); ?></h3><p><?php echo (int) $assignment['max_score']; ?> points · <?php echo $assignment['due_date'] ? 'Due ' . formatDate($assignment['due_date'], 'd M Y') : 'No deadline'; ?></p>
                                        <div class="workspace-card__meta"><span><?php echo $assignment['latest_score'] !== null ? 'Score ' . (int) $assignment['latest_score'] . '/' . (int) $assignment['max_score'] : 'Open assignment'; ?></span><i class="fas fa-arrow-right ms-auto"></i></div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <nav class="lesson-nav d-flex justify-content-between gap-3 mt-4" aria-label="Lesson navigation">
                    <span><?php if ($previousLesson): ?><a href="?<?php echo http_build_query(['id' => $courseId, 'lesson_id' => $previousLesson['id']]); ?>"><i class="fas fa-arrow-left me-2"></i>Previous lesson</a><?php endif; ?></span>
                    <span><?php if ($nextLesson): ?><a href="?<?php echo http_build_query(['id' => $courseId, 'lesson_id' => $nextLesson['id']]); ?>">Next lesson<i class="fas fa-arrow-right ms-2"></i></a><?php elseif ($calculatedProgress === 100): ?><a href="<?php echo APP_URL; ?>/student/certificate.php?course_id=<?php echo $courseId; ?>">View completion record<i class="fas fa-award ms-2"></i></a><?php endif; ?></span>
                </nav>
            <?php else: ?>
                <section class="player-card p-5 text-center"><i class="fas fa-book-open fa-3x text-primary mb-3"></i><h2 class="h4">Lessons are coming soon</h2><p class="text-muted mb-0">Your instructor has not published lesson content for this course yet.</p></section>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
