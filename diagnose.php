<?php
/**
 * Database Diagnostic Tool - Check if database is set up correctly
 */
require_once 'includes/config.php';

$issues = [];
$success = [];

// 1. Check if database exists
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST,
        DB_USER,
        DB_PASS
    );
    $success[] = "✅ MySQL Connection Successful";
} catch (Exception $e) {
    $issues[] = "❌ MySQL Connection Failed: " . $e->getMessage();
}

// 2. Check if database exists
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $success[] = "✅ Database '" . DB_NAME . "' Exists";
    
    // 3. Check if users table exists
    $result = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($result->rowCount() > 0) {
        $success[] = "✅ 'users' Table Exists";
        
        // Count users
        $result = $pdo->query("SELECT COUNT(*) as total FROM users");
        $row = $result->fetch();
        $success[] = "✅ Database has " . $row['total'] . " user(s)";
    } else {
        $issues[] = "❌ 'users' Table NOT Found - Database schema not imported";
    }
} catch (Exception $e) {
    $issues[] = "❌ Database '" . DB_NAME . "' NOT Found: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Diagnostic</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 40px 20px; }
        .container { max-width: 600px; }
        .card { box-shadow: 0 10px 30px rgba(0,0,0,0.2); border: none; }
        .status-item { padding: 15px; margin: 10px 0; border-radius: 8px; font-size: 16px; }
        .success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .fix-section { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 20px; border-radius: 8px; margin-top: 20px; }
        .step { margin: 15px 0; padding: 15px; background: white; border-radius: 6px; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 6px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-body">
                <h1 class="card-title text-center mb-4">🔍 Database Diagnostic</h1>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <strong>Database Host:</strong> <?php echo DB_HOST; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Database Name:</strong> <?php echo DB_NAME; ?>
                    </div>
                </div>

                <hr>
                <h4 class="mb-3">Status Check</h4>

                <?php foreach ($success as $msg): ?>
                    <div class="status-item success">
                        <?php echo $msg; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($issues as $msg): ?>
                    <div class="status-item error">
                        <?php echo $msg; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($issues)): ?>
                    <div class="fix-section">
                        <h4 class="mb-3">🔧 How to Fix</h4>
                        
                        <div class="step">
                            <strong>Step 1: Open phpMyAdmin</strong>
                            <p class="mb-0 mt-2">Go to: <a href="http://localhost/phpmyadmin" target="_blank">http://localhost/phpmyadmin</a></p>
                        </div>

                        <div class="step">
                            <strong>Step 2: Create Database</strong>
                            <ol>
                                <li>Click "New" on the left sidebar</li>
                                <li>Enter database name: <code><?php echo DB_NAME; ?></code></li>
                                <li>Collation: <code>utf8mb4_unicode_ci</code></li>
                                <li>Click "Create"</li>
                            </ol>
                        </div>

                        <div class="step">
                            <strong>Step 3: Import Schema</strong>
                            <ol>
                                <li>Click on the newly created database <code><?php echo DB_NAME; ?></code></li>
                                <li>Click "Import" tab at the top</li>
                                <li>Click "Choose File" and select: <code>database/schema.sql</code></li>
                                <li>Scroll down and click "Import" button</li>
                            </ol>
                            <p class="mt-2 mb-0"><strong>File location:</strong></p>
                            <pre>/Applications/XAMPP/xamppfiles/htdocs/umsadtech/database/schema.sql</pre>
                        </div>

                        <div class="step">
                            <strong>Step 4: Verify</strong>
                            <p class="mb-0">After importing, refresh this page to see if all tables are created correctly.</p>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3">
                        <strong>⚠️ Why registration isn't working:</strong> The registration page needs to query the database to check if an email already exists. Without the database and users table, it fails silently or shows an error.
                    </div>
                <?php else: ?>
                    <div class="alert alert-success mt-3">
                        <strong>✅ All Set!</strong> Your database is properly configured. Try the registration page now: <a href="<?php echo APP_URL; ?>/register.php">Register</a>
                    </div>
                <?php endif; ?>

                <hr class="mt-4">
                <p class="text-center text-muted small">
                    <a href="http://localhost/umsadtech">← Back Home</a> | 
                    <a href="http://localhost/phpmyadmin" target="_blank">phpMyAdmin</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
