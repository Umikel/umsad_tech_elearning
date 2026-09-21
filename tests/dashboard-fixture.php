<?php
// Isolated in-memory fixture: never opens the configured application database.
require_once dirname(__DIR__) . '/includes/Database.php';
function dashboardFixture(): Database {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    $pdo->exec("CREATE TABLE users (id INTEGER, full_name TEXT, email TEXT, user_type TEXT, is_active INTEGER, created_at TEXT);
CREATE TABLE courses (id INTEGER, title TEXT, category TEXT, course_image TEXT, instructor_id INTEGER, is_published INTEGER, updated_at TEXT);
CREATE TABLE student_enrollments (id INTEGER, student_id INTEGER, course_id INTEGER, is_approved INTEGER, is_completed INTEGER, progress_percentage INTEGER, enrollment_date TEXT);
CREATE TABLE course_modules (id INTEGER, sequence INTEGER);
CREATE TABLE course_lessons (id INTEGER, module_id INTEGER, course_id INTEGER, sequence INTEGER);
CREATE TABLE student_progress (id INTEGER, student_id INTEGER, enrollment_id INTEGER, lesson_id INTEGER, is_completed INTEGER, completed_at TEXT);
CREATE TABLE assignments (id INTEGER, lesson_id INTEGER, title TEXT, due_date TEXT, max_score INTEGER, created_at TEXT);
CREATE TABLE assignment_submissions (id INTEGER, assignment_id INTEGER, student_id INTEGER, is_graded INTEGER, submitted_at TEXT);
CREATE TABLE quizzes (id INTEGER, lesson_id INTEGER, title TEXT);
CREATE TABLE quiz_attempts (id INTEGER, quiz_id INTEGER, student_id INTEGER, score INTEGER, passed INTEGER, completed_at TEXT);
CREATE TABLE certificates (id INTEGER, student_id INTEGER);
CREATE TABLE payments (id INTEGER, student_id INTEGER, course_id INTEGER, amount REAL, currency TEXT, status TEXT, created_at TEXT, paid_at TEXT);
CREATE TABLE contact_messages (id INTEGER, is_read INTEGER);
INSERT INTO users VALUES (1, 'Amina Musa', 'amina@example.test', 'student', 1, '2026-01-01'), (2, 'Ibrahim Bello', 'ibrahim@example.test', 'instructor', 1, '2026-01-01'), (3, 'Zainab Ali', 'zainab@example.test', 'student', 1, '2026-01-01');
INSERT INTO courses VALUES (1, 'Website development from the ground up', 'Development', NULL, 2, 1, NOW()), (2, 'Designing digital experiences', 'Design', NULL, 2, 0, NOW()), (3, 'Other instructor course', 'Business', NULL, 99, 1, NOW());
INSERT INTO course_modules VALUES (1,1);
INSERT INTO course_lessons VALUES (1,1,1,1), (2,1,1,2), (3,1,2,3), (4,1,3,4);
INSERT INTO student_enrollments VALUES (1,1,1,1,0,50,NOW()), (2,1,2,0,0,0,NOW()), (3,3,3,1,0,0,NOW());
INSERT INTO student_progress VALUES (1,1,1,1,1,NOW()), (2,1,2,3,1,NOW()), (3,3,3,4,1,NOW());
INSERT INTO assignments VALUES (1,1,'Build a responsive portfolio','2026-01-01',100,NOW()), (2,2,'Your first interactive page',NULL,100,NOW()), (3,3,'Pending course content',NULL,100,NOW()), (4,4,'Other instructor assignment',NULL,100,NOW());
INSERT INTO assignment_submissions VALUES (1,2,1,0,NOW()), (2,4,3,0,NOW());
INSERT INTO quizzes VALUES (1,1,'HTML fundamentals');
INSERT INTO quiz_attempts VALUES (1,1,1,85,1,NOW());
INSERT INTO payments VALUES (1,1,1,25000,'NGN','completed',NOW(),NOW());
");
    $class = new ReflectionClass(Database::class);
    $db = $class->newInstanceWithoutConstructor();
    $connection = $class->getProperty('connection');
    $connection->setAccessible(true);
    $connection->setValue($db, $pdo);
    return $db;
}
