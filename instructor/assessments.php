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
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$courseId = $courseId ? (int) $courseId : 0;
$errors = [];

$db->query('SELECT id, title FROM courses WHERE instructor_id = :instructor_id ORDER BY title');
$db->bind(':instructor_id', $instructorId);
$courses = $db->resultSet();
$courseIds = array_map(static fn(array $course): int => (int) $course['id'], $courses);
if ($courseId > 0 && !in_array($courseId, $courseIds, true)) {
    $courseId = 0;
}

function instructorAssessmentLesson(Database $db, int $lessonId, int $instructorId): ?array
{
    $db->query('SELECT cl.id, cl.title, cl.course_id, c.title AS course_title
                FROM course_lessons cl
                JOIN courses c ON c.id = cl.course_id
                WHERE cl.id = :lesson_id AND c.instructor_id = :instructor_id
                LIMIT 1');
    $db->bind(':lesson_id', $lessonId);
    $db->bind(':instructor_id', $instructorId);
    $row = $db->single();
    return $row ?: null;
}

function instructorOwnedQuiz(Database $db, int $quizId, int $instructorId): ?array
{
    $db->query('SELECT q.*, cl.course_id, c.title AS course_title, cl.title AS lesson_title
                FROM quizzes q
                JOIN course_lessons cl ON cl.id = q.lesson_id
                JOIN courses c ON c.id = cl.course_id
                WHERE q.id = :quiz_id AND c.instructor_id = :instructor_id
                LIMIT 1');
    $db->bind(':quiz_id', $quizId);
    $db->bind(':instructor_id', $instructorId);
    $row = $db->single();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'create_quiz') {
        $lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $lesson = $lessonId ? instructorAssessmentLesson($db, (int) $lessonId, $instructorId) : null;
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $passingScore = filter_var($_POST['passing_score'] ?? 70, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $duration = filter_var($_POST['duration'] ?? 15, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
        if (!$lesson) {
            $errors['quiz'] = 'Choose a lesson from one of your courses.';
        } elseif ($title === '' || mb_strlen($title) > 255 || mb_strlen($description) > 5000) {
            $errors['quiz'] = 'Check the quiz title and description.';
        } elseif ($passingScore === false || $duration === false) {
            $errors['quiz'] = 'Use a passing score from 1–100 and duration from 1–300 minutes.';
        } else {
            $db->query('INSERT INTO quizzes (lesson_id, title, description, passing_score, total_questions, duration)
                        VALUES (:lesson_id, :title, :description, :passing_score, 0, :duration)');
            $db->bind(':lesson_id', (int) $lessonId);
            $db->bind(':title', $title);
            $db->bind(':description', $description === '' ? null : $description);
            $db->bind(':passing_score', (int) $passingScore);
            $db->bind(':duration', (int) $duration);
            $db->execute();
            $_SESSION['message'] = 'Quiz created. Add the first question below.';
            redirect('/instructor/assessments.php?course_id=' . (int) $lesson['course_id'] . '#quiz-' . (int) $db->lastInsertId(), 303);
        }
    } elseif ($action === 'add_question') {
        $quizId = filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quiz = $quizId ? instructorOwnedQuiz($db, (int) $quizId, $instructorId) : null;
        $question = trim((string) ($_POST['question'] ?? ''));
        $options = $_POST['options'] ?? [];
        $options = is_array($options) ? array_values(array_map(static fn($value): string => trim((string) $value), $options)) : [];
        $options = array_values(array_filter($options, static fn(string $value): bool => $value !== ''));
        $correctOption = filter_var($_POST['correct_option'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3]]);
        if (!$quiz) {
            $errors['question'] = 'That quiz is unavailable.';
        } elseif ($question === '' || mb_strlen($question) > 5000) {
            $errors['question'] = 'Add a question of up to 5,000 characters.';
        } elseif (count($options) < 2 || count($options) > 4 || array_filter($options, static fn(string $option): bool => mb_strlen($option) > 2000)) {
            $errors['question'] = 'Add between two and four concise answer options.';
        } elseif ($correctOption === false || !isset($options[(int) $correctOption])) {
            $errors['question'] = 'Choose the correct answer.';
        } else {
            try {
                $db->beginTransaction();
                $db->query('SELECT COALESCE(MAX(sequence), 0) + 1 AS next_sequence FROM quiz_questions WHERE quiz_id = :quiz_id');
                $db->bind(':quiz_id', (int) $quizId);
                $sequence = (int) ($db->single()['next_sequence'] ?? 1);
                $db->query("INSERT INTO quiz_questions (quiz_id, question, question_type, sequence)
                            VALUES (:quiz_id, :question, 'multiple_choice', :sequence)");
                $db->bind(':quiz_id', (int) $quizId);
                $db->bind(':question', $question);
                $db->bind(':sequence', $sequence);
                $db->execute();
                $questionId = (int) $db->lastInsertId();
                foreach ($options as $index => $option) {
                    $db->query('INSERT INTO quiz_question_options (question_id, option_text, is_correct, sequence)
                                VALUES (:question_id, :option_text, :is_correct, :sequence)');
                    $db->bind(':question_id', $questionId);
                    $db->bind(':option_text', $option);
                    $db->bind(':is_correct', $index === (int) $correctOption ? 1 : 0);
                    $db->bind(':sequence', $index + 1);
                    $db->execute();
                }
                $db->query('UPDATE quizzes SET total_questions = (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = :count_quiz_id) WHERE id = :quiz_id');
                $db->bind(':count_quiz_id', (int) $quizId);
                $db->bind(':quiz_id', (int) $quizId);
                $db->execute();
                $db->commit();
                $_SESSION['message'] = 'Question added to the quiz.';
                redirect('/instructor/assessments.php?course_id=' . (int) $quiz['course_id'] . '#quiz-' . (int) $quizId, 303);
            } catch (Throwable $exception) {
                $db->rollBack();
                error_log('Unable to add quiz question: ' . $exception->getMessage());
                $errors['question'] = 'The question could not be saved.';
            }
        }
    } elseif ($action === 'create_assignment') {
        $lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $lesson = $lessonId ? instructorAssessmentLesson($db, (int) $lessonId, $instructorId) : null;
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $maxScore = filter_var($_POST['max_score'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $dueDateRaw = trim((string) ($_POST['due_date'] ?? ''));
        $dueDate = null;
        if ($dueDateRaw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $dueDateRaw);
            if ($parsed instanceof DateTimeImmutable) {
                $dueDate = $parsed->format('Y-m-d H:i:s');
            }
        }
        if (!$lesson) {
            $errors['assignment'] = 'Choose a lesson from one of your courses.';
        } elseif ($title === '' || mb_strlen($title) > 255 || $description === '' || mb_strlen($description) > 20000) {
            $errors['assignment'] = 'Add a title and clear assignment brief.';
        } elseif ($maxScore === false || ($dueDateRaw !== '' && $dueDate === null)) {
            $errors['assignment'] = 'Check the maximum score and due date.';
        } else {
            $db->query('INSERT INTO assignments (lesson_id, title, description, due_date, max_score)
                        VALUES (:lesson_id, :title, :description, :due_date, :max_score)');
            $db->bind(':lesson_id', (int) $lessonId);
            $db->bind(':title', $title);
            $db->bind(':description', $description);
            $db->bind(':due_date', $dueDate);
            $db->bind(':max_score', (int) $maxScore);
            $db->execute();
            $_SESSION['message'] = 'Assignment created.';
            redirect('/instructor/assessments.php?course_id=' . (int) $lesson['course_id'] . '#assignments', 303);
        }
    } else {
        $errors['form'] = 'That assessment action is not supported.';
    }
}

$lessonWhere = 'c.instructor_id = :instructor_id';
if ($courseId > 0) {
    $lessonWhere .= ' AND c.id = :course_id';
}
$db->query('SELECT cl.id, cl.title, c.id AS course_id, c.title AS course_title, cm.title AS module_title
            FROM course_lessons cl
            JOIN courses c ON c.id = cl.course_id
            JOIN course_modules cm ON cm.id = cl.module_id
            WHERE ' . $lessonWhere . '
            ORDER BY c.title, cm.sequence, cl.sequence');
$db->bind(':instructor_id', $instructorId);
if ($courseId > 0) {
    $db->bind(':course_id', $courseId);
}
$lessons = $db->resultSet();

$assessmentWhere = 'c.instructor_id = :instructor_id';
if ($courseId > 0) {
    $assessmentWhere .= ' AND c.id = :course_id';
}
$db->query('SELECT q.*, cl.title AS lesson_title, c.id AS course_id, c.title AS course_title,
                   (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_count,
                   (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.passed = 1) AS pass_count
            FROM quizzes q
            JOIN course_lessons cl ON cl.id = q.lesson_id
            JOIN courses c ON c.id = cl.course_id
            WHERE ' . $assessmentWhere . '
            ORDER BY q.updated_at DESC');
$db->bind(':instructor_id', $instructorId);
if ($courseId > 0) {
    $db->bind(':course_id', $courseId);
}
$quizzes = $db->resultSet();

$questionsByQuiz = [];
if ($quizzes) {
    $quizIds = array_map(static fn(array $quiz): int => (int) $quiz['id'], $quizzes);
    $placeholders = implode(',', array_fill(0, count($quizIds), '?'));
    $db->query('SELECT qq.id, qq.quiz_id, qq.question, qq.sequence,
                       GROUP_CONCAT(CASE WHEN qo.is_correct = 1 THEN qo.option_text END ORDER BY qo.sequence SEPARATOR " / ") AS correct_answer
                FROM quiz_questions qq
                LEFT JOIN quiz_question_options qo ON qo.question_id = qq.id
                WHERE qq.quiz_id IN (' . $placeholders . ')
                GROUP BY qq.id
                ORDER BY qq.quiz_id, qq.sequence');
    foreach ($quizIds as $index => $quizId) {
        $db->bind($index + 1, $quizId);
    }
    foreach ($db->resultSet() as $question) {
        $questionsByQuiz[(int) $question['quiz_id']][] = $question;
    }
}

$db->query('SELECT a.*, cl.title AS lesson_title, c.id AS course_id, c.title AS course_title,
                   (SELECT COUNT(*) FROM assignment_submissions ass WHERE ass.assignment_id = a.id) AS submission_count,
                   (SELECT COUNT(*) FROM assignment_submissions ass WHERE ass.assignment_id = a.id AND ass.is_graded = 0) AS ungraded_count
            FROM assignments a
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            WHERE ' . $assessmentWhere . '
            ORDER BY a.created_at DESC');
$db->bind(':instructor_id', $instructorId);
if ($courseId > 0) {
    $db->bind(':course_id', $courseId);
}
$assignments = $db->resultSet();

$pageTitle = 'Assessments';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page">
    <header class="workspace-page__header">
        <div><span class="workspace-eyebrow">Assessment studio</span><h1 class="workspace-title">Assessments</h1><p class="workspace-subtitle">Create self-marking quizzes and practical assignments directly inside the lessons where learners need them.</p></div>
        <div class="workspace-actions"><a class="btn btn-outline-secondary" href="<?php echo APP_URL; ?>/instructor/submissions.php<?php echo $courseId ? '?course_id=' . $courseId : ''; ?>"><i class="fas fa-inbox me-2"></i>Grade submissions</a></div>
    </header>

    <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($error); ?></div><?php endforeach; ?>

    <section class="workspace-toolbar">
        <form method="get">
            <label for="assessment-course" class="form-label mb-0 text-nowrap">Course</label>
            <select id="assessment-course" name="course_id" class="form-select" onchange="this.form.submit()"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?php echo (int) $course['id']; ?>" <?php echo $courseId === (int) $course['id'] ? 'selected' : ''; ?>><?php echo sanitize($course['title']); ?></option><?php endforeach; ?></select>
        </form>
        <?php if ($courseId): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo APP_URL; ?>/instructor/course-edit.php?id=<?php echo $courseId; ?>"><i class="fas fa-pen me-1"></i>Edit curriculum</a><?php endif; ?>
    </section>

    <?php if (!$lessons): ?>
        <section class="workspace-panel"><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-list-check"></i></span><h2>Add a lesson before creating assessments</h2><p>Every quiz and assignment belongs to a lesson so learners encounter it in the right context.</p><a class="btn btn-primary" href="<?php echo APP_URL; ?>/instructor/course-edit.php<?php echo $courseId ? '?id=' . $courseId : ''; ?>">Open course studio</a></div></section>
    <?php else: ?>
        <div class="workspace-grid--equal workspace-grid mb-4">
            <section class="workspace-panel">
                <div class="workspace-panel__header"><div><h2>Create quiz</h2><p>Automatic scoring for multiple-choice questions.</p></div><span class="workspace-metric-card__icon"><i class="fas fa-circle-question"></i></span></div>
                <div class="workspace-panel__body">
                    <form method="post">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="create_quiz">
                        <div class="mb-3"><label class="form-label" for="quiz-lesson">Lesson</label><select id="quiz-lesson" class="form-select" name="lesson_id" required><option value="">Choose lesson</option><?php foreach ($lessons as $lesson): ?><option value="<?php echo (int) $lesson['id']; ?>"><?php echo sanitize($lesson['course_title'] . ' · ' . $lesson['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label class="form-label" for="quiz-title">Quiz title</label><input id="quiz-title" class="form-control" name="title" maxlength="255" required></div>
                        <div class="mb-3"><label class="form-label" for="quiz-description">Instructions <span class="text-muted fw-normal">(optional)</span></label><textarea id="quiz-description" class="form-control" name="description" rows="3" maxlength="5000"></textarea></div>
                        <div class="workspace-form-grid"><div><label class="form-label" for="passing-score">Passing score (%)</label><input id="passing-score" class="form-control" name="passing_score" type="number" min="1" max="100" value="70" required></div><div><label class="form-label" for="quiz-duration">Duration (minutes)</label><input id="quiz-duration" class="form-control" name="duration" type="number" min="1" max="300" value="15" required></div></div>
                        <div class="workspace-form-actions"><button class="btn btn-primary" type="submit">Create quiz</button></div>
                    </form>
                </div>
            </section>

            <section class="workspace-panel" id="assignments">
                <div class="workspace-panel__header"><div><h2>Create assignment</h2><p>Collect written work or PDF submissions.</p></div><span class="workspace-metric-card__icon"><i class="fas fa-file-pen"></i></span></div>
                <div class="workspace-panel__body">
                    <form method="post">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="create_assignment">
                        <div class="mb-3"><label class="form-label" for="assignment-lesson">Lesson</label><select id="assignment-lesson" class="form-select" name="lesson_id" required><option value="">Choose lesson</option><?php foreach ($lessons as $lesson): ?><option value="<?php echo (int) $lesson['id']; ?>"><?php echo sanitize($lesson['course_title'] . ' · ' . $lesson['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label class="form-label" for="assignment-title">Assignment title</label><input id="assignment-title" class="form-control" name="title" maxlength="255" required></div>
                        <div class="mb-3"><label class="form-label" for="assignment-brief">Assignment brief</label><textarea id="assignment-brief" class="form-control" name="description" rows="4" maxlength="20000" required></textarea></div>
                        <div class="workspace-form-grid"><div><label class="form-label" for="due-date">Due date <span class="text-muted fw-normal">(optional)</span></label><input id="due-date" class="form-control" name="due_date" type="datetime-local"></div><div><label class="form-label" for="max-score">Maximum score</label><input id="max-score" class="form-control" name="max_score" type="number" min="1" max="10000" value="100" required></div></div>
                        <div class="workspace-form-actions"><button class="btn btn-primary" type="submit">Create assignment</button></div>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <section class="workspace-panel mb-4">
        <div class="workspace-panel__header"><div><h2>Quiz library</h2><p><?php echo count($quizzes); ?> quiz<?php echo count($quizzes) === 1 ? '' : 'zes'; ?> across the selected courses.</p></div></div>
        <div class="workspace-panel__body">
            <?php if ($quizzes): ?><div class="builder-stack">
                <?php foreach ($quizzes as $quiz): $questionCount = count($questionsByQuiz[(int) $quiz['id']] ?? []); ?>
                    <section class="builder-section" id="quiz-<?php echo (int) $quiz['id']; ?>">
                        <div class="builder-section__header"><div><span class="workspace-eyebrow mb-1"><?php echo sanitize($quiz['course_title']); ?></span><h3><?php echo sanitize($quiz['title']); ?></h3><small class="text-muted"><?php echo sanitize($quiz['lesson_title']); ?></small></div><div class="text-end"><span class="status-pill status-pill--info"><?php echo $questionCount; ?> questions</span><small class="d-block mt-2 text-muted"><?php echo (int) $quiz['attempt_count']; ?> attempts · <?php echo (int) $quiz['pass_count']; ?> passed</small></div></div>
                        <div class="builder-section__body">
                            <?php foreach ($questionsByQuiz[(int) $quiz['id']] ?? [] as $question): ?><div class="builder-item"><div><h4><?php echo (int) $question['sequence']; ?>. <?php echo sanitize($question['question']); ?></h4><p>Correct: <?php echo sanitize($question['correct_answer']); ?></p></div></div><?php endforeach; ?>
                            <details class="mt-3"><summary class="btn btn-outline-primary btn-sm"><i class="fas fa-plus me-1"></i>Add question</summary>
                                <form method="post" class="mt-3">
                                    <?php echo csrfField(); ?><input type="hidden" name="action" value="add_question"><input type="hidden" name="quiz_id" value="<?php echo (int) $quiz['id']; ?>">
                                    <div class="mb-3"><label class="form-label">Question</label><textarea class="form-control" name="question" rows="3" maxlength="5000" required></textarea></div>
                                    <div class="workspace-form-grid">
                                        <?php for ($option = 0; $option < 4; $option++): ?><div><label class="form-label">Option <?php echo $option + 1; ?><?php echo $option > 1 ? ' (optional)' : ''; ?></label><input class="form-control" name="options[]" maxlength="2000" <?php echo $option < 2 ? 'required' : ''; ?>></div><?php endfor; ?>
                                        <div class="form-span-2"><label class="form-label">Correct answer</label><select class="form-select" name="correct_option" required><option value="0">Option 1</option><option value="1">Option 2</option><option value="2">Option 3</option><option value="3">Option 4</option></select></div>
                                    </div>
                                    <div class="workspace-form-actions"><button class="btn btn-primary btn-sm" type="submit">Save question</button></div>
                                </form>
                            </details>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-circle-question"></i></span><h3>No quizzes yet</h3><p>Create a quiz above, then add its answer choices and correct answers.</p></div><?php endif; ?>
        </div>
    </section>

    <section class="workspace-panel">
        <div class="workspace-panel__header"><div><h2>Assignment library</h2><p>Practical work and current grading demand.</p></div></div>
        <div class="workspace-panel__body--flush">
            <?php if ($assignments): ?><div class="workspace-table-wrap"><table class="workspace-table"><thead><tr><th>Assignment</th><th>Course & lesson</th><th>Due</th><th>Score</th><th>Submissions</th><th></th></tr></thead><tbody>
                <?php foreach ($assignments as $assignment): ?><tr><td><strong class="text-dark"><?php echo sanitize($assignment['title']); ?></strong></td><td><?php echo sanitize($assignment['course_title']); ?><small class="d-block text-muted"><?php echo sanitize($assignment['lesson_title']); ?></small></td><td><?php echo $assignment['due_date'] ? formatDate($assignment['due_date'], 'd M Y, H:i') : 'No deadline'; ?></td><td><?php echo (int) $assignment['max_score']; ?> pts</td><td><span class="status-pill <?php echo (int) $assignment['ungraded_count'] > 0 ? 'status-pill--warning' : 'status-pill--success'; ?>"><?php echo (int) $assignment['submission_count']; ?> total · <?php echo (int) $assignment['ungraded_count']; ?> pending</span></td><td><a class="btn btn-outline-primary btn-sm" href="<?php echo APP_URL; ?>/instructor/submissions.php?assignment_id=<?php echo (int) $assignment['id']; ?>">Review</a></td></tr><?php endforeach; ?>
            </tbody></table></div><?php else: ?><div class="workspace-empty"><span class="workspace-empty__icon"><i class="fas fa-file-pen"></i></span><h3>No assignments yet</h3><p>Create an assignment above to collect learner work.</p></div><?php endif; ?>
        </div>
    </section>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
