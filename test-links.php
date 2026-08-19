<?php
/**
 * Simple link test to verify routing is working
 */
require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Link Test</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .link-item { margin: 10px 0; padding: 10px; background: #e8f4f8; border-left: 4px solid #007bff; }
        .link-item a { color: #007bff; text-decoration: none; font-weight: bold; }
        .link-item a:hover { text-decoration: underline; }
        .code { background: #f0f0f0; padding: 10px; font-family: monospace; border-radius: 4px; margin: 5px 0; }
        .success { color: #28a745; }
        .info { color: #666; font-size: 12px; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔗 Link Test - Umsad Tech E-Learning</h1>
        <p><strong>APP_URL:</strong> <code><?php echo APP_URL; ?></code></p>
        
        <hr>
        <h2>Public Pages</h2>
        
        <div class="link-item">
            <a href="<?php echo APP_URL; ?>">🏠 Homepage</a>
            <div class="code"><?php echo APP_URL; ?></div>
            <div class="info">Main landing page</div>
        </div>

        <div class="link-item">
            <a href="<?php echo APP_URL; ?>/courses.php">📚 Courses</a>
            <div class="code"><?php echo APP_URL; ?>/courses.php</div>
            <div class="info">Browse all courses</div>
        </div>

        <div class="link-item">
            <a href="<?php echo APP_URL; ?>/login.php">🔑 Login</a>
            <div class="code"><?php echo APP_URL; ?>/login.php</div>
            <div class="info">User login page</div>
        </div>

        <div class="link-item">
            <a href="<?php echo APP_URL; ?>/register.php">✏️ Register</a>
            <div class="code"><?php echo APP_URL; ?>/register.php</div>
            <div class="info">User registration page</div>
        </div>

        <hr>
        <h2>Dashboard Pages</h2>

        <div class="link-item">
            <a href="<?php echo APP_URL; ?>/student/dashboard.php">👤 Student Dashboard</a>
            <div class="code"><?php echo APP_URL; ?>/student/dashboard.php</div>
            <div class="info">Requires login as student</div>
        </div>

        <div class="link-item">
            <a href="<?php echo APP_URL; ?>/instructor/dashboard.php">👨‍🏫 Instructor Dashboard</a>
            <div class="code"><?php echo APP_URL; ?>/instructor/dashboard.php</div>
            <div class="info">Requires login as instructor</div>
        </div>

        <div class="link-item">
            <a href="<?php echo APP_URL; ?>/admin/dashboard.php">⚙️ Admin Dashboard</a>
            <div class="code"><?php echo APP_URL; ?>/admin/dashboard.php</div>
            <div class="info">Requires admin login</div>
        </div>

        <hr>
        <h2>Database Test</h2>
        <?php
        require_once 'includes/Database.php';
        try {
            $db = new Database();
            echo '<div class="link-item" style="border-left-color: #28a745;"><span class="success">✓ Database Connected</span></div>';
            
            $db->query("SELECT COUNT(*) as total FROM users");
            $result = $db->single();
            echo '<div class="link-item"><strong>Users in database:</strong> ' . $result['total'] . '</div>';
        } catch (Exception $e) {
            echo '<div class="link-item" style="border-left-color: #dc3545;"><span style="color: #dc3545;">✗ Database Error: ' . $e->getMessage() . '</span></div>';
        }
        ?>

        <hr>
        <p style="color: #666; font-size: 12px; margin-top: 20px;">
            All links have been updated to use <code>APP_URL</code> for proper routing.
            <br>Try clicking on the links above - they should all work correctly now!
        </p>
    </div>
</body>
</html>
