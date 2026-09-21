<?php
/**
 * Development seed data. Run from the project root with: php seed-data.php
 */

require_once __DIR__ . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to seed data while APP_ENV=production.\n");
    exit(1);
}

require_once __DIR__ . '/includes/Database.php';

$db = new Database();

echo "🌱 Seeding Umsad Tech Database with Sample Data...\n\n";

try {
    // Check if data already exists
    $db->query("SELECT COUNT(*) as total FROM users");
    $result = $db->single();
    $userCount = $result['total'] ?? 0;

    if ($userCount > 0) {
        echo "✅ Database already has " . $userCount . " user(s).\n\n";
        echo "No changes were made. Seed credentials are only shown when accounts are first created.\n";
        exit;
    }

    $seedPassword = static function (string $environmentKey): string {
        $configured = (string) umsadEnv($environmentKey, '');
        if (strlen($configured) >= 12 && strlen($configured) <= 72) {
            return $configured;
        }

        return 'A9!' . rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
    };
    $adminPassword = $seedPassword('SEED_ADMIN_PASSWORD');
    $instructorPassword = $seedPassword('SEED_INSTRUCTOR_PASSWORD');
    $studentPassword = $seedPassword('SEED_STUDENT_PASSWORD');

    $db->beginTransaction();

    // 1. INSERT USERS
    echo "1️⃣  Adding Users...\n";
    $users = [
        ['email' => 'admin@example.com', 'password' => password_hash($adminPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS), 'full_name' => 'Admin User', 'user_type' => 'admin', 'bio' => 'Platform Administrator'],
        ['email' => 'instructor@example.com', 'password' => password_hash($instructorPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS), 'full_name' => 'John Instructor', 'user_type' => 'instructor', 'bio' => 'Expert PHP & Web Developer'],
        ['email' => 'instructor2@example.com', 'password' => password_hash($instructorPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS), 'full_name' => 'Sarah Smith', 'user_type' => 'instructor', 'bio' => 'Digital Marketing Specialist'],
        ['email' => 'student@example.com', 'password' => password_hash($studentPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS), 'full_name' => 'Ahmed Student', 'user_type' => 'student', 'bio' => 'Aspiring Web Developer'],
        ['email' => 'student2@example.com', 'password' => password_hash($studentPassword, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS), 'full_name' => 'Fatima Learner', 'user_type' => 'student', 'bio' => 'Marketing Student'],
    ];

    $userIds = [];
    foreach ($users as $index => $user) {
        $db->query("INSERT INTO users (email, email_verified_at, password, full_name, user_type, bio) VALUES (:email, NOW(), :password, :full_name, :user_type, :bio)");
        $db->bind(':email', $user['email']);
        $db->bind(':password', $user['password']);
        $db->bind(':full_name', $user['full_name']);
        $db->bind(':user_type', $user['user_type']);
        $db->bind(':bio', $user['bio']);
        $db->execute();
        $userIds[$index + 1] = (int) $db->lastInsertId();
    }
    echo "✅ Added 5 users\n";

    // 2. INSERT COURSES
    echo "\n2️⃣  Adding Courses...\n";
    $courses = [
        ['title' => 'PHP Web Development Masterclass', 'slug' => 'php-web-development', 'instructor_id' => 2, 'category' => 'Programming', 'price' => 50.00, 'description' => 'Learn PHP from basics to advanced concepts'],
        ['title' => 'Digital Marketing Essentials', 'slug' => 'digital-marketing', 'instructor_id' => 3, 'category' => 'Marketing', 'price' => 35.00, 'description' => 'Master digital marketing strategies'],
        ['title' => 'JavaScript Fundamentals', 'slug' => 'javascript-fundamentals', 'instructor_id' => 2, 'category' => 'Programming', 'price' => 0.00, 'description' => 'Free course on JavaScript basics'],
        ['title' => 'React Advanced Patterns', 'slug' => 'react-advanced', 'instructor_id' => 2, 'category' => 'Programming', 'price' => 60.00, 'description' => 'Advanced React patterns and best practices'],
        ['title' => 'Website Development for Beginners', 'slug' => 'website-development-for-beginners', 'instructor_id' => 2, 'category' => 'Web Development', 'price' => 50000.00, 'description' => 'Learn to plan, build and publish responsive websites from scratch through four weeks of live, practical instruction. No previous coding experience is required.'],
    ];

    $courseIds = [];
    foreach ($courses as $index => $course) {
        $db->query("INSERT INTO courses (title, slug, instructor_id, category, price, description, is_published) 
                   VALUES (:title, :slug, :instructor_id, :category, :price, :description, 1)");
        $db->bind(':title', $course['title']);
        $db->bind(':slug', $course['slug']);
        $db->bind(':instructor_id', $userIds[$course['instructor_id']]);
        $db->bind(':category', $course['category']);
        $db->bind(':price', $course['price']);
        $db->bind(':description', $course['description']);
        $db->execute();
        $courseIds[$index + 1] = (int) $db->lastInsertId();
    }
    echo "✅ Added 5 courses\n";

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
        [5, 'Week 1: Web Foundations and HTML', 'Understand how websites work, prepare the development environment and build accessible pages with semantic HTML.', 1],
        [5, 'Week 2: CSS and Responsive Layouts', 'Style web pages, create modern layouts with Flexbox and Grid, and adapt designs for phones, tablets and computers.', 2],
        [5, 'Week 3: JavaScript and User Interaction', 'Learn JavaScript fundamentals, work with the DOM and browser events, and build interactive form behaviour.', 3],
        [5, 'Week 4: Final Website Project', 'Plan, build, refine, test, publish and present a complete responsive website.', 4],
    ];

    $moduleIds = [];
    foreach ($modules as $index => $module) {
        $db->query("INSERT INTO course_modules (course_id, title, description, sequence) 
                   VALUES (:course_id, :title, :description, :sequence)");
        $db->bind(':course_id', $courseIds[$module[0]]);
        $db->bind(':title', $module[1]);
        $db->bind(':description', $module[2]);
        $db->bind(':sequence', $module[3]);
        $db->execute();
        $moduleIds[$index + 1] = (int) $db->lastInsertId();
    }
    echo "✅ Added 12 modules\n";

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
        [9, 5, 'How Websites Work and Course Setup', 'Learn how browsers, servers, domain names and hosting work together, then prepare the tools used throughout the course.', null, 'youtube', null, 1, 0, 'Identify the main parts of a website request, organise a project folder, install Visual Studio Code and confirm that the browser can open a local HTML file.'],
        [9, 5, 'Build Your First HTML Page', 'Create a complete HTML document and use common elements for headings, text, links, images and lists.', null, 'youtube', null, 2, 0, 'Build a valid HTML page from an empty file, add meaningful page content and practise using relative file paths for links and images.'],
        [9, 5, 'Semantic HTML and Accessible Structure', 'Organise content with semantic HTML and apply beginner accessibility practices.', null, 'youtube', null, 3, 0, 'Use header, nav, main, section and footer elements appropriately; add useful alternative text; and structure a form with connected labels.'],
        [10, 5, 'Style Pages with CSS', 'Connect a stylesheet and control typography, colour, spacing, borders and reusable visual components.', null, 'youtube', null, 1, 0, 'Practise selectors, the cascade and the box model while turning the Week 1 HTML page into a clear, consistent visual design.'],
        [10, 5, 'Build Layouts with Flexbox and Grid', 'Create practical one-dimensional and two-dimensional page layouts with modern CSS.', null, 'youtube', null, 2, 0, 'Use Flexbox for navigation and aligned components, use Grid for page sections and card layouts, and choose the appropriate layout tool for each task.'],
        [10, 5, 'Make Websites Responsive', 'Adapt layouts and content to work comfortably across phones, tablets and desktop screens.', null, 'youtube', null, 3, 0, 'Apply a mobile-first approach, flexible sizing, responsive images and focused media queries, then test the page at several viewport widths.'],
        [11, 5, 'JavaScript Fundamentals', 'Use variables, values, conditions, functions and arrays to solve small browser-based tasks.', null, 'youtube', null, 1, 0, 'Write and run JavaScript in the browser, inspect results with developer tools and break a simple requirement into reusable functions.'],
        [11, 5, 'Work with the DOM and Events', 'Select page elements, respond to user actions and update content safely with JavaScript.', null, 'youtube', null, 2, 0, 'Use DOM query methods and event listeners to create menu, button and content interactions without relying on a JavaScript framework.'],
        [11, 5, 'Build and Validate Interactive Forms', 'Create helpful client-side form behaviour and clear validation feedback.', null, 'youtube', null, 3, 0, 'Read user input, prevent invalid submission, display understandable messages and preserve accessible labels and focus behaviour.'],
        [12, 5, 'Plan and Structure the Final Website', 'Turn a simple project brief into a page plan, content outline and realistic build checklist.', null, 'youtube', null, 1, 0, 'Define the website goal and audience, sketch the required sections, organise assets and create the semantic HTML foundation for the final project.'],
        [12, 5, 'Build and Refine the Final Website', 'Combine HTML, responsive CSS and useful JavaScript interactions in one complete project.', null, 'youtube', null, 2, 0, 'Develop the planned sections, apply a consistent design system, add the required interactions and improve the project through instructor feedback.'],
        [12, 5, 'Test, Publish and Present Your Website', 'Review quality across browsers and screen sizes, publish the project and explain the completed work.', null, 'youtube', null, 3, 0, 'Check links, forms, responsive behaviour and accessibility basics; resolve visible defects; publish the project; and present the final result and learning decisions.'],
    ];

    foreach ($lessons as $lesson) {
        $db->query("INSERT INTO course_lessons (module_id, course_id, title, description, video_url, video_type, duration, sequence, is_free, content) 
                   VALUES (:module_id, :course_id, :title, :description, :video_url, :video_type, :duration, :sequence, :is_free, :content)");
        $db->bind(':module_id', $moduleIds[$lesson[0]]);
        $db->bind(':course_id', $courseIds[$lesson[1]]);
        $db->bind(':title', $lesson[2]);
        $db->bind(':description', $lesson[3]);
        $db->bind(':video_url', $lesson[4]);
        $db->bind(':video_type', $lesson[5]);
        $db->bind(':duration', $lesson[6]);
        $db->bind(':sequence', $lesson[7]);
        $db->bind(':is_free', $lesson[8]);
        $db->bind(':content', $lesson[9] ?? null);
        $db->execute();
    }
    echo "✅ Added 21 lessons\n";

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
        $db->bind(':course_id', $courseIds[$enrollment[0]]);
        $db->bind(':student_id', $userIds[$enrollment[1]]);
        $db->execute();
    }
    echo "✅ Added 5 enrollments\n";

    $db->commit();

    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ DATABASE SEEDING COMPLETED SUCCESSFULLY!\n";
    echo str_repeat("=", 50) . "\n\n";

    echo "👤 USER CREDENTIALS FOR TESTING:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Admin:\n";
    echo "  Email: admin@example.com\n";
    echo "  Password: " . $adminPassword . "\n\n";
    echo "Instructor:\n";
    echo "  Email: instructor@example.com\n";
    echo "  Password: " . $instructorPassword . "\n\n";
    echo "Student:\n";
    echo "  Email: student@example.com\n";
    echo "  Password: " . $studentPassword . "\n\n";

    echo "🎯 QUICK START:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "1. Homepage: " . APP_URL . "\n";
    echo "2. Login: " . APP_URL . "/login.php\n";
    echo "3. Register New: " . APP_URL . "/register.php\n";
    echo "4. Courses: " . APP_URL . "/courses.php\n\n";

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Seeding failed. Check the application error log for details.\n");
    error_log('Database seeding failed: ' . $e->getMessage());
    exit(1);
}
