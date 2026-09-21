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
$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$assignmentId) {
    $_SESSION['error'] = 'Choose a valid assignment from an enrolled course.';
    redirect('/student/my-courses.php');
}
$assignmentId = (int) $assignmentId;

$db->query('SELECT a.*, cl.id AS lesson_id, cl.title AS lesson_title,
                   c.id AS course_id, c.title AS course_title, se.id AS enrollment_id,
                   u.full_name AS instructor_name
            FROM assignments a
            JOIN course_lessons cl ON cl.id = a.lesson_id
            JOIN courses c ON c.id = cl.course_id
            LEFT JOIN users u ON u.id = c.instructor_id
            JOIN student_enrollments se ON se.course_id = c.id AND se.is_approved = 1 AND se.student_id = :student_id
            WHERE a.id = :assignment_id
            LIMIT 1');
$db->bind(':student_id', $studentId);
$db->bind(':assignment_id', $assignmentId);
$assignment = $db->single();
if (!$assignment) {
    $_SESSION['error'] = 'That assignment is unavailable for your account.';
    redirect('/student/my-courses.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = 'Your session expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') !== 'submit_assignment') {
        $errors['form'] = 'That assignment action is not supported.';
    } else {
        $submissionText = trim((string) ($_POST['submission_text'] ?? ''));
        $hasFile = isset($_FILES['submission_file'])
            && is_array($_FILES['submission_file'])
            && (int) ($_FILES['submission_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($submissionText === '' && !$hasFile) {
            $errors['form'] = 'Add a written response or attach a PDF or image.';
        } elseif (mb_strlen($submissionText) > 50000) {
            $errors['form'] = 'Keep your written response under 50,000 characters.';
        } else {
            $filePath = null;
            if ($hasFile) {
                $upload = uploadFile($_FILES['submission_file'], 'uploads/assignments/');
                if (!$upload['success']) {
                    $errors['form'] = (string) $upload['message'];
                } else {
                    $filePath = (string) $upload['file'];
                }
            }

            if (!$errors) {
                try {
                    $db->query('SELECT id, submission_file
                                FROM assignment_submissions
                                WHERE assignment_id = :assignment_id AND student_id = :student_id AND is_graded = 0
                                ORDER BY submitted_at DESC, id DESC LIMIT 1');
                    $db->bind(':assignment_id', $assignmentId);
                    $db->bind(':student_id', $studentId);
                    $existing = $db->single();
                    if ($existing) {
                        $db->query('UPDATE assignment_submissions
                                    SET submission_text = :submission_text,
                                        submission_file = :submission_file,
                                        submitted_at = NOW(), score = NULL, feedback = NULL, graded_at = NULL
                                    WHERE id = :id AND student_id = :student_id');
                        $db->bind(':submission_file', $filePath ?: ($existing['submission_file'] ?: null));
                        $db->bind(':id', (int) $existing['id']);
                    } else {
                        $db->query('INSERT INTO assignment_submissions
                                    (assignment_id, student_id, submission_file, submission_text, submitted_at, is_graded)
                                    VALUES (:assignment_id, :student_id, :submission_file, :submission_text, NOW(), 0)');
                        $db->bind(':assignment_id', $assignmentId);
                        $db->bind(':submission_file', $filePath);
                    }
                    $db->bind(':student_id', $studentId);
                    $db->bind(':submission_text', $submissionText === '' ? null : $submissionText);
                    $db->execute();
                    $_SESSION['message'] = $existing ? 'Your assignment submission was updated.' : 'Assignment submitted successfully.';
                    redirect('/student/assignment.php?assignment_id=' . $assignmentId, 303);
                } catch (Throwable $exception) {
                    error_log('Unable to save assignment submission: ' . $exception->getMessage());
                    $errors['form'] = 'Your assignment could not be submitted. Please try again.';
                }
            }
        }
    }
}

$db->query('SELECT * FROM assignment_submissions
            WHERE assignment_id = :assignment_id AND student_id = :student_id
            ORDER BY submitted_at DESC, id DESC');
$db->bind(':assignment_id', $assignmentId);
$db->bind(':student_id', $studentId);
$submissions = $db->resultSet();
$latestSubmission = $submissions[0] ?? null;
$isOverdue = $assignment['due_date'] && strtotime((string) $assignment['due_date']) < time();

$pageTitle = $assignment['title'];
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="workspace-page" style="max-width:1120px">
    <header class="workspace-page__header">
        <div><span class="workspace-eyebrow"><?php echo sanitize($assignment['course_title']); ?> · <?php echo sanitize($assignment['lesson_title']); ?></span><h1 class="workspace-title"><?php echo sanitize($assignment['title']); ?></h1><p class="workspace-subtitle">Submit your work for feedback from <?php echo sanitize($assignment['instructor_name'] ?: 'your instructor'); ?>.</p></div>
        <div class="workspace-actions"><a class="btn btn-outline-secondary" href="<?php echo APP_URL; ?>/student/course-lessons.php?id=<?php echo (int) $assignment['course_id']; ?>&amp;lesson_id=<?php echo (int) $assignment['lesson_id']; ?>"><i class="fas fa-arrow-left me-2"></i>Back to lesson</a></div>
    </header>

    <?php if (isset($errors['form'])): ?><div class="alert alert-danger" role="alert"><?php echo sanitize($errors['form']); ?></div><?php endif; ?>

    <div class="workspace-grid">
        <div>
            <section class="workspace-panel mb-4">
                <div class="workspace-panel__header"><div><h2>Assignment brief</h2><p>Read the instructions carefully before submitting.</p></div><span class="status-pill <?php echo $isOverdue ? 'status-pill--danger' : 'status-pill--info'; ?>"><?php echo $assignment['due_date'] ? ($isOverdue ? 'Due date passed' : 'Due ' . formatDate($assignment['due_date'], 'd M Y, H:i')) : 'No deadline'; ?></span></div>
                <div class="workspace-panel__body"><div style="white-space:pre-wrap;line-height:1.8" class="text-secondary"><?php echo sanitize($assignment['description']); ?></div><div class="workspace-callout mt-4"><i class="fas fa-star"></i><div><strong class="d-block mb-1"><?php echo (int) $assignment['max_score']; ?> points available</strong>You can submit a written response, attach a PDF/image, or use both.</div></div></div>
            </section>

            <section class="workspace-panel">
                <div class="workspace-panel__header"><div><h2><?php echo $latestSubmission && (int) $latestSubmission['is_graded'] === 0 ? 'Update your submission' : 'Submit your work'; ?></h2><p>Ungraded work can be revised. A new submission is created after grading.</p></div></div>
                <div class="workspace-panel__body">
                    <form method="post" enctype="multipart/form-data">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="submit_assignment">
                        <div class="mb-3"><label class="form-label" for="submission-text">Written response</label><textarea id="submission-text" class="form-control" name="submission_text" rows="10" maxlength="50000" placeholder="Write or paste your response here..."><?php echo sanitize($latestSubmission && (int) $latestSubmission['is_graded'] === 0 ? $latestSubmission['submission_text'] : ''); ?></textarea><div class="form-text">Up to 50,000 characters.</div></div>
                        <div class="mb-3"><label class="form-label" for="submission-file">Attachment <span class="text-muted fw-normal">(PDF, JPG, or PNG; max 5 MB)</span></label><input id="submission-file" class="form-control" name="submission_file" type="file" accept=".pdf,.jpg,.jpeg,.png"><?php if ($latestSubmission && (int) $latestSubmission['is_graded'] === 0 && $latestSubmission['submission_file']): ?><div class="form-text">Your current attachment is kept unless you upload a new one.</div><?php endif; ?></div>
                        <div class="workspace-form-actions"><button class="btn btn-primary btn-lg" type="submit"><i class="fas fa-paper-plane me-2"></i><?php echo $latestSubmission && (int) $latestSubmission['is_graded'] === 0 ? 'Update submission' : 'Submit assignment'; ?></button></div>
                    </form>
                </div>
            </section>
        </div>

        <aside>
            <section class="workspace-panel">
                <div class="workspace-panel__header"><div><h2>Submission history</h2><p><?php echo count($submissions); ?> submission<?php echo count($submissions) === 1 ? '' : 's'; ?>.</p></div></div>
                <div class="workspace-panel__body">
                    <?php if ($submissions): ?><div class="builder-stack">
                        <?php foreach ($submissions as $index => $submission): ?>
                            <article class="builder-section">
                                <div class="builder-section__header"><div><h3>Submission <?php echo count($submissions) - $index; ?></h3><small class="text-muted"><?php echo formatDate($submission['submitted_at'], 'd M Y, H:i'); ?></small></div><span class="status-pill <?php echo (int) $submission['is_graded'] === 1 ? 'status-pill--success' : 'status-pill--warning'; ?>"><?php echo (int) $submission['is_graded'] === 1 ? 'Graded' : 'Pending'; ?></span></div>
                                <div class="builder-section__body">
                                    <?php if ((int) $submission['is_graded'] === 1): ?><strong class="d-block h4 mb-2"><?php echo (int) $submission['score']; ?>/<?php echo (int) $assignment['max_score']; ?></strong><p class="text-muted mb-0" style="white-space:pre-wrap"><?php echo sanitize($submission['feedback'] ?: 'No written feedback was added.'); ?></p><?php else: ?><p class="text-muted mb-0">Your instructor has not graded this submission yet.</p><?php endif; ?>
                                    <?php if ($submission['submission_file']): ?><a class="btn btn-outline-secondary btn-sm mt-3" href="<?php echo APP_URL . '/' . sanitize(ltrim((string) $submission['submission_file'], '/')); ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i>Attachment</a><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="workspace-empty p-3"><span class="workspace-empty__icon"><i class="fas fa-file-arrow-up"></i></span><h3>No submission yet</h3><p>Your work and feedback history will appear here.</p></div><?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
