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
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$courseId) {
    $_SESSION['error'] = 'Choose a completed course to view its record.';
    header('Location: ' . APP_URL . '/student/my-courses.php');
    exit;
}

$db->query('SELECT c.id AS course_id, c.title AS course_title,
                   learner.full_name AS student_name,
                   instructor.full_name AS instructor_name,
                   se.enrollment_date, se.completion_date, se.progress_percentage,
                   cert.id AS certificate_id, cert.certificate_code, cert.issued_date
            FROM student_enrollments se
            JOIN courses c ON c.id = se.course_id
            JOIN users learner ON learner.id = se.student_id
            LEFT JOIN users instructor ON instructor.id = c.instructor_id
            LEFT JOIN certificates cert ON cert.student_id = se.student_id AND cert.course_id = se.course_id
            WHERE se.is_approved = 1 AND se.student_id = :student_id
              AND se.course_id = :course_id
              AND se.is_completed = 1
            ORDER BY cert.issued_date DESC
            LIMIT 1');
$db->bind(':student_id', $studentId);
$db->bind(':course_id', (int) $courseId);
$record = $db->single();

if (!$record) {
    $_SESSION['error'] = 'A completion record is available only after the course is finished.';
    header('Location: ' . APP_URL . '/student/my-courses.php');
    exit;
}

$hasCertificate = !empty($record['certificate_id']) && trim((string) $record['certificate_code']) !== '';
if (!$hasCertificate) {
    try {
        $certificateCode = 'UMSAD-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(6)));
        $db->query('INSERT INTO certificates (student_id, course_id, certificate_code, issued_date)
                    SELECT :student_id, :course_id, :certificate_code, NOW()
                    WHERE NOT EXISTS (
                        SELECT 1 FROM certificates WHERE student_id = :existing_student_id AND course_id = :existing_course_id
                    )');
        $db->bind(':student_id', $studentId);
        $db->bind(':course_id', (int) $courseId);
        $db->bind(':certificate_code', $certificateCode);
        $db->bind(':existing_student_id', $studentId);
        $db->bind(':existing_course_id', (int) $courseId);
        $db->execute();

        $db->query('SELECT id, certificate_code, issued_date FROM certificates
                    WHERE student_id = :student_id AND course_id = :course_id
                    ORDER BY issued_date DESC LIMIT 1');
        $db->bind(':student_id', $studentId);
        $db->bind(':course_id', (int) $courseId);
        $issuedCertificate = $db->single();
        if ($issuedCertificate) {
            $record['certificate_id'] = $issuedCertificate['id'];
            $record['certificate_code'] = $issuedCertificate['certificate_code'];
            $record['issued_date'] = $issuedCertificate['issued_date'];
            $hasCertificate = true;
        }
    } catch (Throwable $exception) {
        error_log('Unable to issue completion certificate: ' . $exception->getMessage());
    }
}

$pageTitle = $hasCertificate ? 'Certificate of Completion' : 'Course Completion Record';
$pageNoIndex = true;
require_once dirname(__DIR__) . '/templates/header.php';
?>

<style>
    .certificate-page { max-width: 1120px; margin: 1rem auto 4rem; }
    .certificate-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
    .certificate-sheet { position: relative; overflow: hidden; min-height: 650px; display: grid; place-items: center; padding: clamp(2rem, 7vw, 6rem); border: 12px solid #20234e; outline: 2px solid #7779e9; outline-offset: -20px; color: #252743; text-align: center; background: #fff; box-shadow: 0 25px 70px rgba(31,33,75,.16); }
    .certificate-sheet::before, .certificate-sheet::after { content: ''; position: absolute; width: 290px; height: 290px; border-radius: 50%; background: #eeeeff; }
    .certificate-sheet::before { left: -165px; top: -155px; }
    .certificate-sheet::after { right: -175px; bottom: -170px; background: #e7f8f1; }
    .certificate-content { position: relative; z-index: 1; width: 100%; }
    .brand-logo-frame--certificate { width: 176px; margin: 0 auto 1.2rem; border-color: #e1e2ec; border-radius: 12px; box-shadow: 0 10px 28px rgba(31,33,75,.12); }
    .certificate-label { color: #5b5fe8; font-size: .72rem; font-weight: 900; letter-spacing: .2em; text-transform: uppercase; }
    .certificate-sheet h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.3rem, 6vw, 4.8rem); letter-spacing: -.055em; line-height: 1.05; }
    .recipient-name { display: inline-block; min-width: min(100%, 520px); margin: 1.2rem 0 .8rem; padding: 0 1rem .65rem; border-bottom: 1px solid #cfd0de; font-family: 'Space Grotesk', sans-serif; font-size: clamp(1.65rem, 4vw, 2.7rem); color: #5054ce; }
    .course-name { max-width: 700px; margin: .7rem auto 2.2rem; font-family: 'Space Grotesk', sans-serif; font-size: clamp(1.35rem, 3vw, 2rem); }
    .certificate-meta { display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem 4rem; }
    .certificate-meta div { min-width: 160px; }
    .certificate-meta span { display: block; color: #85869a; font-size: .69rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .certificate-meta strong { display: block; margin-top: .35rem; }
    .pending-notice { margin-top: 1.5rem; padding: 1rem 1.2rem; border: 1px solid #e4d6ad; border-radius: 12px; color: #69582b; background: #fff9e9; text-align: left; }
    @media print {
        @page { size: landscape; margin: 8mm; }
        body { background: #fff !important; }
        .site-navbar, footer, .certificate-toolbar, .alert { display: none !important; }
        .main-content { padding: 0 !important; }
        .certificate-page { max-width: none; margin: 0; }
        .certificate-sheet { min-height: 180mm; border-width: 8px; box-shadow: none; break-inside: avoid; }
        .pending-notice { border: 1px solid #8a7850; }
    }
</style>

<div class="container certificate-page">
    <div class="certificate-toolbar">
        <div>
            <a href="<?php echo APP_URL; ?>/student/my-courses.php" class="text-decoration-none fw-bold"><i class="fas fa-arrow-left me-2"></i>My courses</a>
            <p class="text-muted small mb-0 mt-1"><?php echo $hasCertificate ? 'Your official course certificate' : 'Your verified learning progress'; ?></p>
        </div>
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print<?php echo $hasCertificate ? ' certificate' : ' record'; ?></button>
    </div>

    <article class="certificate-sheet">
        <div class="certificate-content">
            <span class="brand-logo-frame brand-logo-frame--certificate" aria-hidden="true">
                <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024">
            </span>
            <span class="certificate-label"><i class="fas <?php echo $hasCertificate ? 'fa-award' : 'fa-circle-check'; ?> me-2" aria-hidden="true"></i>Umsad Tech E-Learning</span>
            <h1 class="mt-3 mb-2"><?php echo $hasCertificate ? 'Certificate of Completion' : 'Course Completion Record'; ?></h1>
            <p class="text-muted mb-0"><?php echo $hasCertificate ? 'This certificate is proudly presented to' : 'This learning record confirms that'; ?></p>
            <div class="recipient-name"><?php echo sanitize($record['student_name']); ?></div>
            <p class="text-muted mb-0">successfully completed</p>
            <div class="course-name"><?php echo sanitize($record['course_title']); ?></div>

            <div class="certificate-meta">
                <div><span>Completion date</span><strong><?php echo $record['completion_date'] ? formatDate($record['completion_date'], 'd F Y') : 'Completion recorded'; ?></strong></div>
                <div><span>Instructor</span><strong><?php echo sanitize($record['instructor_name'] ?: 'Umsad Tech'); ?></strong></div>
                <?php if ($hasCertificate): ?>
                    <div><span>Certificate code</span><strong><?php echo sanitize($record['certificate_code']); ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (!$hasCertificate): ?>
                <div class="pending-notice mx-auto" style="max-width:720px">
                    <strong><i class="fas fa-circle-info me-2"></i>Official certificate not issued yet</strong>
                    <div class="small mt-1">Your completion is confirmed, but certificate issuance is temporarily unavailable. Please try again or contact support.</div>
                </div>
            <?php endif; ?>
        </div>
    </article>
</div>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
