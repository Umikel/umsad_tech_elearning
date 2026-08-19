<?php
/**
 * Improved Seed Data Script - Handles Duplicates Gracefully
 * Run this file: http://localhost/umsadtech/seed-data.php
 */

require_once 'includes/config.php';
require_once 'includes/Database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new Database();

echo "🌱 Seeding Umsad Tech Database with Sample Data...\n\n";

try {
    // Check if data already exists
    $db->query("SELECT COUNT(*) as total FROM users");
    $result = $db->single();
    $userCount = $result['total'] ?? 0;

    if ($userCount > 0) {
        echo "✅ Database already has " . $userCount . " user(s).\n\n";
        echo "👤 EXISTING USER CREDENTIALS:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "Admin:\n";
        echo "  Email: admin@example.com\n";
        echo "  Password: admin123\n\n";
        echo "Instructor:\n";
        echo "  Email: instructor@example.com\n";
        echo "  Password: instructor123\n\n";
        echo "Student:\n";
        echo "  Email: student@example.com\n";
        echo "  Password: student123\n\n";

        echo "🎯 NEXT STEPS:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "✅ Database is ready!\n";
        echo "1. Login: http://localhost/umsadtech/login.php\n";
        echo "2. Or Register: http://localhost/umsadtech/register.php\n";
        echo "3. Browse Courses: http://localhost/umsadtech/courses.php\n\n";
        echo "🗑️  To reset database: <a href='http://localhost/umsadtech/reset-database.php'>Reset Database</a>\n";
        exit;
    }

    // 1. INSERT USERS
    echo "1️⃣  Adding Users...\n";
    $users = [
        ['email' => 'admin@example.com', 'password' => password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]), 'full_name' => 'Admin User', 'user_type' => 'admin', 'bio' => 'Platform Administrator'],
        ['email' => 'instructor@example.com', 'password' => password_hash('instructor123', PASSWORD_BCRYPT, ['cost' => 12]), 'full_name' => 'John Instructor', 'user_type' => 'instructor', 'bio' => 'Expert PHP & Web Developer'],
        ['email' => 'instructor2@example.com', 'password' => password_hash('instructor123', PASSWORD_BCRYPT, ['cost' => 12]), 'full_name' => 'Sarah Smith', 'user_type' => 'instructor', 'bio' => 'Digital Marketing Specialist'],
        ['email' => 'student@example.com', 'password' => password_hash('student123', PASSWORD_BCRYPT, ['cost' => 12]), 'full_name' => 'Ahmed Student', 'user_type' => 'student', 'bio' => 'Aspiring Web Developer'],
        ['email' => 'student2@example.com', 'password' => password_hash('student123', PASSWORD_BCRYPT, ['cost' => 12]), 'full_name' => 'Fatima Learner', 'user_type' => 'student', 'bio' => 'Marketing Student'],
    ];

    foreach ($users as $user) {
        $db->query("INSERT INTO users (email, password, full_name, user_type, bio) VALUES (:email, :password, :full_name, :user_type, :bio)");
        $db->bind(':email', $user['email']);
        $db->bind(':password', $user['password']);
        $db->bind(':full_name', $user['full_name']);
        $db->bind(':user_type', $user['user_type']);
        $db->bind(':bio', $user['bio']);
        $db->execute();
    }
    echo "✅ Added 5 users\n";

    // 2. INSERT COURSES
    echo "\n2️⃣  Adding Courses...\n";
    $courses = [
        ['title' => 'PHP Web Development Masterclass', 'slug' => 'php-web-development', 'instructor_id' => 2, 'category' => 'Programming', 'price' => 50.00, 'description' => 'Learn PHP from basics to advanced concepts'],
        ['title' => 'Digital Marketing Essentials', 'slug' => 'digital-marketing', 'instructor_id' => 3, 'category' => 'Marketing', 'price' => 35.00, 'description' => 'Master digital marketing strategies'],
        ['title' => 'JavaScript Fundamentals', 'slug' => 'javascript-fundamentals', 'instructor_id' => 2, 'category' => 'Programming', 'price' => 0.00, 'description' => 'Free course on JavaScript basics'],
        ['title' => 'React Advanced Patterns', 'slug' => 'react-advanced', 'instructor_id' => 2, 'category' => 'Programming', 'price' => 60.00, 'description' => 'Advanced React patterns and best practices'],
    ];

    foreach ($courses as $course) {
        $db->query("INSERT INTO courses (title, slug, instructor_id, category, price, description, is_published) 
                   VALUES (:title, :slug, :instructor_id, :category, :price, :description, 1)");
        $db->bind(':title', $course['title']);
        $db->bind(':slug', $course['slug']);
        $db->bind(':instructor_id', $course['instructor_id']);
        $db->bind(':category', $course['category']);
        $db->bind(':price', $course['price']);
        $db->bind(':description', $course['description']);
        $db->execute();
    }
    echo "✅ Added 4 courses\n";

    // 3. INSERT COURSE MODULES
    echo "\n3️⃣  Adding Course Modules...\n";
    $modules = [
        [1, 'Getting Started with PHP', 'Learn PHP basics and setup your environment', 1],
        [1, 'Variables and Data Types', 'Understand PHP variables and data types', 2],
        [1, 'Working with Databases', 'Connect and manage databases with PHP', 3],
        [2, 'Social Media Marketing', 'Strategies for social media marketing', 1],
        [2, 'Email Marketing Campaigns', 'Create effective email marketing campaigns', 2],
        [3, 'JavaScript Basics', 'Learn JavaScript fundamentals', 1],
        [3, 'DOM Manipulation', 'Master DOM with JavaScript', 2],
        [4, 'Advanced Component Patterns', 'Learn advanced React patterns', 1],
    ];

    foreach ($modules as $module) {
        $db->query("INSERT INTO course_modules (course_id, title, description, sequence) 
                   VALUES (:course_id, :title, :description, :sequence)");
        $db->bind(':course_id', $module[0]);
        $db->bind(':title', $module[1]);
        $db->bind(':description', $module[2]);
        $db->bind(':sequence', $module[3]);
        $db->execute();
    }
    echo "✅ Added 8 modules\n";

    // 4. INSERT COURSE LESSONS
    echo "\n4️⃣  Adding Course Lessons...\n";
    $lessons = [
        [1, 1, 'What is PHP?', 'Introduction to PHP programming language', 'https://www.youtube.com/embed/BUCiSSp_73A', 'youtube', 10, 1, 0],
        [1, 1, 'Setting up PHP Environment', 'How to install and setup PHP', 'https://www.youtube.com/embed/M_cbHiGmxiI', 'youtube', 15, 2, 1],
        [2, 1, 'Variables in PHP', 'Learn about PHP variables', 'https://www.youtube.com/embed/p8qhqzAP2JE', 'youtube', 20, 1, 0],
        [2, 1, 'Data Types', 'Understanding different data types', 'https://www.youtube.com/embed/5gKtVFaYpIQ', 'youtube', 18, 2, 1],
        [3, 1, 'Introduction to Databases', 'Database concepts and MySQL', 'https://www.youtube.com/embed/zbYzn2D-b_s', 'youtube', 25, 1, 0],
        [4, 2, 'Twitter Marketing Strategy', 'How to leverage Twitter for marketing', 'https://www.youtube.com/embed/BUCiSSp_73A', 'youtube', 20, 1, 0],
        [6, 3, 'Hello World in JavaScript', 'Your first JavaScript program', 'https://www.youtube.com/embed/PkZNo7MFNFg', 'youtube', 12, 1, 1],
        [7, 3, 'Selecting DOM Elements', 'How to select elements with JavaScript', 'https://www.youtube.com/embed/geKHrSLMGaQ', 'youtube', 22, 1, 0],
        [8, 4, 'React Hooks Deep Dive', 'Understanding React Hooks', 'https://www.youtube.com/embed/TNhaISOUy6Q', 'youtube', 30, 1, 0],
    ];

    foreach ($lessons as $lesson) {
        $db->query("INSERT INTO course_lessons (module_id, course_id, title, description, video_url, video_type, duration, sequence, is_free) 
                   VALUES (:module_id, :course_id, :title, :description, :video_url, :video_type, :duration, :sequence, :is_free)");
        $db->bind(':module_id', $lesson[0]);
        $db->bind(':course_id', $lesson[1]);
        $db->bind(':title', $lesson[2]);
        $db->bind(':description', $lesson[3]);
        $db->bind(':video_url', $lesson[4]);
        $db->bind(':video_type', $lesson[5]);
        $db->bind(':duration', $lesson[6]);
        $db->bind(':sequence', $lesson[7]);
        $db->bind(':is_free', $lesson[8]);
        $db->execute();
    }
    echo "✅ Added 9 lessons\n";

    // 5. INSERT STUDENT ENROLLMENTS
    echo "\n5️⃣  Adding Student Enrollments...\n";
    $enrollments = [
        [1, 4],
        [1, 5],
        [2, 4],
        [3, 5],
        [4, 4],
    ];

    foreach ($enrollments as $enrollment) {
        $db->query("INSERT INTO student_enrollments (course_id, student_id, enrollment_date, is_completed) 
                   VALUES (:course_id, :student_id, NOW(), 0)");
        $db->bind(':course_id', $enrollment[0]);
        $db->bind(':student_id', $enrollment[1]);
        $db->execute();
    }
    echo "✅ Added 5 enrollments\n";

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ DATABASE SEEDING COMPLETED SUCCESSFULLY!\n";
    echo str_repeat("=", 50) . "\n\n";

    echo "👤 USER CREDENTIALS FOR TESTING:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Admin:\n";
    echo "  Email: admin@example.com\n";
    echo "  Password: admin123\n\n";
    echo "Instructor:\n";
    echo "  Email: instructor@example.com\n";
    echo "  Password: instructor123\n\n";
    echo "Student:\n";
    echo "  Email: student@example.com\n";
    echo "  Password: student123\n\n";

    echo "🎯 QUICK START:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "1. Homepage: http://localhost/umsadtech\n";
    echo "2. Login: http://localhost/umsadtech/login.php\n";
    echo "3. Register New: http://localhost/umsadtech/register.php\n";
    echo "4. Courses: http://localhost/umsadtech/courses.php\n\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
