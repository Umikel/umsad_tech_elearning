<?php
/**
 * Error Diagnostic - Show what's breaking
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

ob_start();

echo "<pre style='background: #f5f5f5; padding: 20px; font-family: monospace;'>\n";
echo "=== DIAGNOSTIC TEST ===\n\n";

// Test 1: Config
echo "1. Loading config...\n";
try {
    require_once 'includes/config.php';
    echo "   ✅ Config loaded\n";
    echo "   DB_NAME: " . DB_NAME . "\n";
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    exit;
}

// Test 2: Database
echo "\n2. Loading Database class...\n";
try {
    require_once 'includes/Database.php';
    echo "   ✅ Database class loaded\n";
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    exit;
}

// Test 3: Creating DB instance
echo "\n3. Creating Database instance...\n";
try {
    $db = new Database();
    echo "   ✅ Database connected\n";
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    exit;
}

// Test 4: Auth
echo "\n4. Loading Auth class...\n";
try {
    require_once 'includes/Auth.php';
    echo "   ✅ Auth class loaded\n";
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    exit;
}

// Test 5: Creating Auth instance
echo "\n5. Creating Auth instance...\n";
try {
    $auth = new Auth($db);
    echo "   ✅ Auth initialized\n";
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    exit;
}

// Test 6: Helpers
echo "\n6. Loading helpers...\n";
try {
    require_once 'includes/helpers.php';
    echo "   ✅ Helpers loaded\n";
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
    exit;
}

// Test 7: Check helper functions
echo "\n7. Testing helper functions...\n";
try {
    if (function_exists('sanitize')) {
        echo "   ✅ sanitize() exists\n";
    }
    if (function_exists('hasError')) {
        echo "   ✅ hasError() exists\n";
    }
    if (function_exists('getError')) {
        echo "   ✅ getError() exists\n";
    }
    if (function_exists('redirect')) {
        echo "   ✅ redirect() exists\n";
    }
} catch (Throwable $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n";
}

// Test 8: Header template
echo "\n8. Checking header.php...\n";
if (file_exists('templates/header.php')) {
    echo "   ✅ header.php exists\n";
} else {
    echo "   ❌ header.php NOT FOUND\n";
}

// Test 9: Footer template
echo "\n9. Checking footer.php...\n";
if (file_exists('templates/footer.php')) {
    echo "   ✅ footer.php exists\n";
} else {
    echo "   ❌ footer.php NOT FOUND\n";
}

echo "\n=== ALL CHECKS PASSED ===\n";
echo "</pre>\n";

ob_end_flush();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnostic - Umsad Tech</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f9f9f9; }
        .container { max-width: 800px; margin: 0 auto; }
        .next-steps { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .next-steps h3 { color: #155724; }
    </style>
</head>
<body>
    <div class="container">
        <div class="next-steps">
            <h3>✅ If all checks passed above:</h3>
            <ol>
                <li><a href="http://localhost/umsadtech/register.php">Try Register Page</a></li>
                <li><a href="http://localhost/umsadtech/login.php">Try Login Page</a></li>
                <li><a href="http://localhost/umsadtech/">Go to Homepage</a></li>
            </ol>
        </div>
    </div>
</body>
</html>
