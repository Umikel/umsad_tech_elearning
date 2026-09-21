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

$studentId = (int) $auth->getUserId();
$quizId = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$quizId) {
    $_SESSION['error'] = 'Choose a valid quiz from an enrolled course.';
    redirect('/student/my-courses.php');
}
$quizId = (int) $quizId;

$db->query('SELECT q.*, cl.id AS lesson_id, cl.title AS lesson_title,
                   c.id AS course_id, c.title AS course_title,
                   se.id AS enrollment_id
            FROM quizzes q
            JOIN course_lessons cl ON cl.id = q.lesson_id
            JOIN courses c ON c.id = cl.course_id
            JOIN student_enrollments se ON se.course_id = c.id AND se.is_approved = 1 AND se.student_id = :student_id
            WHERE q.id = :quiz_id
            LIMIT 1');
$db->bind(':student_id', $studentId);
$db->bind(':quiz_id', $quizId);
$quiz = $db->single();
if (!$quiz) {
    $_SESSION['error'] = 'That quiz is unavailable for your account.';
    redirect('/student/my-courses.php');
}

$db->query('SELECT qq.id, qq.question, qq.sequence, qo.id AS option_id, qo.option_text, qo.sequence AS option_sequence
            FROM quiz_questions qq
            LEFT JOIN quiz_question_options qo ON qo.question_id = qq.id
            WHERE qq.quiz_id = :quiz_id
            ORDER BY qq.sequence, qq.id, qo.sequence, qo.id');
$db->bind(':quiz_id', $quizId);
$rows = $db->resultSet();
$questions = [];
foreach ($rows as $row) {
    $questionId = (int) $row['id'];
    if (!isset($questions[$questionId])) {
        $questions[$questionId] = [
            'id' => $questionId,
            'question' => $row['question'],
            'sequence' => (int) $row['sequence'],
            'options' => [],
        ];
    }
    if ($row['option_id'] !== null) {
        $questions[$questionId]['options'][] = [
            'id' => (int) $row['option_id'],
            'text' => $row['option_text'],
        ];
    }
}
$questions = array_values($questions);
$errors = [];
$requestedAttemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!isset($_SESSION['_quiz_started']) || !is_array($_SESSION['_quiz_started'])) {
    $_SESSION['_quiz_started'] = [];
}
$startedAt = time();
if (!$requestedAttemptId) {
    if (!isset($_SESSION['_quiz_started'][$quizId])) {
        $_SESSION['_quiz_started'][$quizId] = time();
    }
    $startedAt = (int) $_SESSION['_quiz_started'][$quizId];
}
$durationSeconds = max(60, (int) ($quiz['duration'] ?? 15) * 60);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif (!$questions) {
        $errors['form'] = 'This quiz does not contain any questions yet.';
    } elseif (time() - $startedAt > $durationSeconds + 60) {
        unset($_SESSION['_quiz_started'][$quizId]);
        $errors['form'] = 'The quiz time has expired. Start a new attempt when you are ready.';
    } else {
        $answers = $_POST['answers'] ?? [];
        $answers = is_array($answers) ? $answers : [];
        $questionIds = array_map(static fn(array $question): int => (int) $question['id'], $questions);
        $selectedOptionIds = [];
        foreach ($questionIds as $questionId) {
            $selected = filter_var($answers[$questionId] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($selected !== false) {
                $selectedOptionIds[$questionId] = (int) $selected;
            }
        }
        if (count($selectedOptionIds) !== count($questionIds)) {
            $errors['form'] = 'Answer every question before submitting the quiz.';
        } else {
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $db->query('SELECT qo.id, qo.question_id, qo.option_text, qo.is_correct
                        FROM quiz_question_options qo
                        JOIN quiz_questions qq ON qq.id = qo.question_id
                        WHERE qq.quiz_id = ? AND qq.id IN (' . $placeholders . ')');
            $db->bind(1, $quizId);
            foreach ($questionIds as $index => $questionId) {
                $db->bind($index + 2, $questionId);
            }
            $optionRows = $db->resultSet();
            $optionMap = [];
            foreach ($optionRows as $optionRow) {
                $optionMap[(int) $optionRow['question_id']][(int) $optionRow['id']] = $optionRow;
            }
            $valid = true;
            $correct = 0;
            foreach ($selectedOptionIds as $questionId => $optionId) {
                if (!isset($optionMap[$questionId][$optionId])) {
                    $valid = false;
                    break;
                }
                if ((int) $optionMap[$questionId][$optionId]['is_correct'] === 1) {
                    $correct++;
                }
            }
            if (!$valid) {
                $errors['form'] = 'One of the selected answers is invalid. Please try again.';
            } else {
                $score = (int) round(($correct / count($questionIds)) * 100);
                $passed = $score >= (int) $quiz['passing_score'] ? 1 : 0;
                try {
                    $db->beginTransaction();
                    $db->query('SELECT COALESCE(MAX(attempt_number), 0) + 1 AS next_attempt
                                FROM quiz_attempts WHERE student_id = :student_id AND quiz_id = :quiz_id');
                    $db->bind(':student_id', $studentId);
                    $db->bind(':quiz_id', $quizId);
                    $attemptNumber = (int) ($db->single()['next_attempt'] ?? 1);
                    $db->query('INSERT INTO quiz_attempts
                                (student_id, quiz_id, enrollment_id, score, passed, attempt_number, started_at, completed_at)
                                VALUES (:student_id, :quiz_id, :enrollment_id, :score, :passed, :attempt_number, :started_at, NOW())');
                    $db->bind(':student_id', $studentId);
                    $db->bind(':quiz_id', $quizId);
                    $db->bind(':enrollment_id', (int) $quiz['enrollment_id']);
                    $db->bind(':score', $score);
                    $db->bind(':passed', $passed);
                    $db->bind(':attempt_number', $attemptNumber);
                    $db->bind(':started_at', date('Y-m-d H:i:s', $startedAt));
                    $db->execute();
                    $attemptId = (int) $db->lastInsertId();
                    foreach ($selectedOptionIds as $questionId => $optionId) {
                        $option = $optionMap[$questionId][$optionId];
                        $db->query('INSERT INTO quiz_answers (attempt_id, question_id, answer_text, is_correct)
                                    VALUES (:attempt_id, :question_id, :answer_text, :is_correct)');
                        $db->bind(':attempt_id', $attemptId);
                        $db->bind(':question_id', $questionId);
                        $db->bind(':answer_text', (string) $option['option_text']);
                        $db->bind(':is_correct', (int) $option['is_correct']);
                        $db->execute();
                    }

                    if ($passed === 1) {
                        $db->query('INSERT INTO student_progress (student_id, lesson_id, enrollment_id, is_completed, completed_at, time_spent)
                                    VALUES (:student_id, :lesson_id, :enrollment_id, 1, NOW(), 0)
                                    ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()');
                        $db->bind(':student_id', $studentId);
                        $db->bind(':lesson_id', (int) $quiz['lesson_id']);
                        $db->bind(':enrollment_id', (int) $quiz['enrollment_id']);
                        $db->execute();

                        $db->query('SELECT COUNT(cl.id) AS total_lessons,
                                           COALESCE(SUM(CASE WHEN sp.is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_lessons
                                    FROM course_lessons cl
                                    LEFT JOIN student_progress sp ON sp.lesson_id = cl.id AND sp.student_id = :student_id
                                    WHERE cl.course_id = :course_id');
                        $db->bind(':student_id', $studentId);
                        $db->bind(':course_id', (int) $quiz['course_id']);
                        $counts = $db->single() ?: [];
                        $totalLessons = (int) ($counts['total_lessons'] ?? 0);
                        $completedLessons = (int) ($counts['completed_lessons'] ?? 0);
                        $progress = $totalLessons > 0 ? min(100, (int) floor(($completedLessons / $totalLessons) * 100)) : 0;
                        $courseCompleted = $totalLessons > 0 && $completedLessons >= $totalLessons ? 1 : 0;
                        $db->query('UPDATE student_enrollments
                                    SET progress_percentage = :progress, is_completed = :is_completed,
                                        completion_date = CASE WHEN :completion_flag = 1 THEN COALESCE(completion_date, NOW()) ELSE completion_date END
                                    WHERE id = :enrollment_id AND student_id = :student_id');
                        $db->bind(':progress', $progress);
                        $db->bind(':is_completed', $courseCompleted);
                        $db->bind(':completion_flag', $courseCompleted);
                        $db->bind(':enrollment_id', (int) $quiz['enrollment_id']);
                        $db->bind(':student_id', $studentId);
                        $db->execute();

                        if ($courseCompleted === 1) {
                            $certificateCode = 'UMSAD-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(6)));
                            $db->query('INSERT INTO certificates (student_id, course_id, certificate_code, issued_date)
                                        SELECT :student_id, :course_id, :certificate_code, NOW()
                                        WHERE NOT EXISTS (
                                            SELECT 1 FROM certificates WHERE student_id = :existing_student_id AND course_id = :existing_course_id
                                        )');
                            $db->bind(':student_id', $studentId);
                            $db->bind(':course_id', (int) $quiz['course_id']);
                            $db->bind(':certificate_code', $certificateCode);
                            $db->bind(':existing_student_id', $studentId);
                            $db->bind(':existing_course_id', (int) $quiz['course_id']);
                            $db->execute();
                        }
                    }
                    $db->commit();
                    unset($_SESSION['_quiz_started'][$quizId]);
                    redirect('/student/quiz.php?' . http_build_query(['quiz_id' => $quizId, 'attempt_id' => $attemptId]), 303);
                } catch (Throwable $exception) {
                    $db->rollBack();
                    error_log('Unable to save quiz attempt: ' . $exception->getMessage());
                    $errors['form'] = 'Your quiz could not be submitted. Please try again.';
                }
            }
        }
    }
}

$attempt = null;
$attemptId = $requestedAttemptId;
if ($attemptId) {
    $db->query('SELECT * FROM quiz_attempts WHERE id = :attempt_id AND quiz_id = :quiz_id AND student_id = :student_id LIMIT 1');
    $db->bind(':attempt_id', (int) $attemptId);
    $db->bind(':quiz_id', $quizId);
    $db->bind(':student_id', $studentId);
    $attempt = $db->single() ?: null;
}

$db->query('SELECT COUNT(*) AS attempts, MAX(score) AS best_score, COALESCE(MAX(passed), 0) AS has_passed
            FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id');
$db->bind(':quiz_id', $quizId);
$db->bind(':student_id', $studentId);
$history = $db->single() ?: [];

$remainingSeconds = max(0, $durationSeconds - (time() - $startedAt));
$pageTitle = $quiz['title'];
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page" style="max-width:1050px">
    <header class="workspace-page__header">
        <div><span class="workspace-eyebrow"><?php echo sanitize($quiz['course_title']); ?> · <?php echo sanitize($quiz['lesson_title']); ?></span><h1 class="workspace-title"><?php echo sanitize($quiz['title']); ?></h1><p class="workspace-subtitle"><?php echo sanitize($quiz['description'] ?: 'Choose the best answer for every question. Your result is calculated automatically.'); ?></p></div>
        <div class="workspace-actions"><a class="btn btn-outline-secondary" href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo (int) $quiz['course_id']; ?>&amp;lesson_id=<?php echo (int) $quiz['lesson_id']; ?>"><i class="fas fa-arrow-left me-2"></i>Back to lesson</a></div>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <?php if ($attempt): ?>
        <section class="workspace-hero">
            <div><span class="workspace-eyebrow text-white">Attempt <?php echo (int) $attempt['attempt_number']; ?> complete</span><h2 class="display-5"><?php echo (int) $attempt['passed'] === 1 ? 'You passed—well done.' : 'Keep going—you are close.'; ?></h2><p><?php echo (int) $attempt['passed'] === 1 ? 'This lesson has been marked complete and your course progress was updated.' : 'Review the lesson, then try another attempt when you feel ready.'; ?></p></div>
            <div class="workspace-hero__aside"><div class="workspace-hero__badge"><small>Your score</small><strong><?php echo (int) $attempt['score']; ?>%</strong><small>Pass mark <?php echo (int) $quiz['passing_score']; ?>%</small></div></div>
        </section>
        <div class="workspace-grid--equal workspace-grid">
            <a class="workspace-card text-decoration-none" href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo (int) $quiz['course_id']; ?>&amp;lesson_id=<?php echo (int) $quiz['lesson_id']; ?>"><span class="workspace-metric-card__icon"><i class="fas fa-book-open"></i></span><h3>Return to the lesson</h3><p>Review the learning material and continue through the course.</p></a>
            <a class="workspace-card text-decoration-none" href="<?php echo APP_URL; ?>/student/quiz.php?quiz_id=<?php echo $quizId; ?>"><span class="workspace-metric-card__icon"><i class="fas fa-rotate-right"></i></span><h3>Start another attempt</h3><p>Your best score is <?php echo (int) ($history['best_score'] ?? 0); ?>% across <?php echo (int) ($history['attempts'] ?? 0); ?> attempt<?php echo (int) ($history['attempts'] ?? 0) === 1 ? '' : 's'; ?>.</p></a>
        </div>
    <?php elseif (!$questions): ?>
        <section class="workspace-panel"><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-hourglass-half"></i></span><h2>This quiz is being prepared</h2><p>Your instructor has not added questions yet. Return to the lesson and continue learning.</p></div></section>
    <?php else: ?>
        <div class="workspace-callout mb-4"><i class="fas fa-clock"></i><div><strong class="d-block mb-1">Time remaining: <span id="quizTimer"><?php echo sprintf('%02d:%02d', intdiv($remainingSeconds, 60), $remainingSeconds % 60); ?></span></strong><?php echo count($questions); ?> questions · Pass mark <?php echo (int) $quiz['passing_score']; ?>% · Attempt <?php echo (int) ($history['attempts'] ?? 0) + 1; ?></div></div>
        <form method="post" id="quizForm">
            <?php echo csrfField(); ?>
            <div class="builder-stack">
                <?php foreach ($questions as $index => $question): ?>
                    <fieldset class="workspace-panel">
                        <legend class="workspace-panel__header w-100"><span class="workspace-eyebrow mb-0">Question <?php echo $index + 1; ?> of <?php echo count($questions); ?></span></legend>
                        <div class="workspace-panel__body">
                            <h2 class="h5 mb-4"><?php echo sanitize($question['question']); ?></h2>
                            <div class="d-grid gap-2">
                                <?php foreach ($question['options'] as $option): ?>
                                    <label class="border rounded-3 p-3 d-flex gap-3 align-items-start bg-white"><input class="form-check-input mt-1" type="radio" name="answers[<?php echo (int) $question['id']; ?>]" value="<?php echo (int) $option['id']; ?>" required><span><?php echo sanitize($option['text']); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="workspace-form-actions mt-4"><button class="btn btn-primary btn-lg px-5" type="submit"><i class="fas fa-paper-plane me-2"></i>Submit quiz</button></div>
        </form>
        <script>
        (() => {
            let remaining = <?php echo $remainingSeconds; ?>;
            const timer = document.getElementById('quizTimer');
            const form = document.getElementById('quizForm');
            const tick = () => {
                remaining = Math.max(0, remaining - 1);
                const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
                const seconds = String(remaining % 60).padStart(2, '0');
                if (timer) timer.textContent = minutes + ':' + seconds;
                if (remaining === 0 && form) {
                    const button = form.querySelector('button[type="submit"]');
                    if (button) button.disabled = true;
                }
            };
            window.setInterval(tick, 1000);
        })();
        </script>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
