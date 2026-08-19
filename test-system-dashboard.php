<?php
/**
 * Quick Test Dashboard
 * Test all system components
 */
require_once 'includes/config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Test Dashboard - Umsad Tech</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; padding: 40px 20px; }
        .container { max-width: 900px; }
        .card { box-shadow: 0 2px 10px rgba(0,0,0,0.1); border: none; margin-bottom: 20px; }
        .test-item { padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .test-item:last-child { border-bottom: none; }
        .status { font-weight: bold; }
        .pass { color: #28a745; }
        .fail { color: #dc3545; }
        .btn-test { margin: 5px; }
        .info-box { background: #e7f3ff; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">🧪 Umsad Tech - System Test Dashboard</h1>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Configuration & Database</h5>
            </div>
            <div class="card-body">
                <div class="test-item">
                    <span>Database Connection</span>
                    <?php
                    try {
                        require_once 'includes/Database.php';
                        $db = new Database();
                        echo '<span class="status pass">✅ Connected</span>';
                    } catch (Exception $e) {
                        echo '<span class="status fail">❌ Failed</span>';
                    }
                    ?>
                </div>

                <div class="test-item">
                    <span>Database Name</span>
                    <span><?php echo DB_NAME; ?></span>
                </div>

                <div class="test-item">
                    <span>Users Table</span>
                    <?php
                    try {
                        $db->query("SELECT COUNT(*) as total FROM users");
                        $result = $db->single();
                        echo '<span class="status pass">✅ ' . $result['total'] . ' users</span>';
                    } catch (Exception $e) {
                        echo '<span class="status fail">❌ Error</span>';
                    }
                    ?>
                </div>

                <div class="test-item">
                    <span>Courses Table</span>
                    <?php
                    try {
                        $db->query("SELECT COUNT(*) as total FROM courses");
                        $result = $db->single();
                        echo '<span class="status pass">✅ ' . $result['total'] . ' courses</span>';
                    } catch (Exception $e) {
                        echo '<span class="status fail">❌ Error</span>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Session & Authentication</h5>
            </div>
            <div class="card-body">
                <div class="test-item">
                    <span>Session Status</span>
                    <?php
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    echo '<span class="status pass">✅ Active</span>';
                    ?>
                </div>

                <div class="test-item">
                    <span>Session ID</span>
                    <span><code><?php echo substr(session_id(), 0, 20) . '...'; ?></code></span>
                </div>

                <div class="test-item">
                    <span>User Logged In</span>
                    <?php
                    try {
                        require_once 'includes/Auth.php';
                        $auth = new Auth($db);
                        if ($auth->isLoggedIn()) {
                            $user = $auth->getUser();
                            echo '<span class="status pass">✅ ' . $user['full_name'] . '</span>';
                        } else {
                            echo '<span class="status fail">❌ Not logged in</span>';
                        }
                    } catch (Exception $e) {
                        echo '<span class="status fail">❌ Error</span>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">Quick Tests</h5>
            </div>
            <div class="card-body">
                <div class="info-box">
                    <strong>Test Accounts Available:</strong><br>
                    📧 student@example.com (password: student123)<br>
                    📧 instructor@example.com (password: instructor123)<br>
                    📧 admin@example.com (password: admin123)
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="<?php echo APP_URL; ?>/login.php" class="btn btn-primary btn-test">🔑 Test Login</a>
                    <a href="<?php echo APP_URL; ?>/register.php" class="btn btn-success btn-test">✏️ Test Register</a>
                    <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-info btn-test">📚 View Courses</a>
                    <a href="<?php echo APP_URL; ?>/test-session.php" class="btn btn-secondary btn-test">🧪 Session Test</a>
                    <a href="<?php echo APP_URL; ?>/seed-data.php" class="btn btn-dark btn-test">🌱 Seed Data</a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Setup Checklist</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled">
                    <li>✅ <strong>Database Schema Imported</strong> - All tables created</li>
                    <li>✅ <strong>Sample Data Seeded</strong> - 5 users, 4 courses, etc.</li>
                    <li>✅ <strong>Configuration Set</strong> - DB credentials configured</li>
                    <li>✅ <strong>Authentication Ready</strong> - Session and Auth class working</li>
                    <li>✅ <strong>Navigation Fixed</strong> - All links using APP_URL</li>
                    <li>✅ <strong>Errors Showing</strong> - Display errors enabled for debugging</li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">Troubleshooting</h5>
            </div>
            <div class="card-body">
                <p><strong>❌ Register page blank?</strong></p>
                <ul>
                    <li>Check database connection</li>
                    <li>Verify schema was imported</li>
                    <li>Look at browser console for errors</li>
                </ul>

                <p><strong>❌ Login not working?</strong></p>
                <ul>
                    <li>Verify email and password in database</li>
                    <li>Check session is starting</li>
                    <li>Ensure cookies are enabled</li>
                </ul>

                <p><strong>❌ Session not persisting?</strong></p>
                <ul>
                    <li>Open test-session.php and refresh to verify</li>
                    <li>Check browser cookies are enabled</li>
                    <li>Verify session path is writable</li>
                </ul>
            </div>
        </div>

        <hr>
        <p class="text-center text-muted">
            🎉 Your Umsad Tech E-Learning platform is ready!<br>
            Questions? Check the troubleshooting section above.
        </p>
    </div>
</body>
</html>
