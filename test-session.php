<?php
/**
 * Session Test - Verify session functionality
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test 1: Session Status
echo "🔍 SESSION DIAGNOSTIC TEST\n";
echo str_repeat("=", 50) . "\n\n";

echo "1️⃣  PHP Session Status:\n";
echo "   Status: " . (session_status() === PHP_SESSION_NONE ? "❌ Not Started" : "✅ Active") . "\n";
echo "   Session ID: " . (session_id() ?: "❌ None") . "\n";

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    echo "   ✅ Session started\n";
}

echo "\n2️⃣  Testing Session Variables:\n";

// Test setting a session variable
$_SESSION['test_key'] = 'test_value';
$_SESSION['test_time'] = time();
$_SESSION['test_user'] = ['id' => 1, 'name' => 'Test User'];

echo "   ✅ Set 3 test variables\n";
echo "   Variables stored:\n";
foreach ($_SESSION as $key => $value) {
    if (strpos($key, 'test_') === 0) {
        echo "     - $key = " . json_encode($value) . "\n";
    }
}

echo "\n3️⃣  Testing Session Persistence:\n";

// Test if we can retrieve the variables
if (isset($_SESSION['test_key']) && $_SESSION['test_key'] === 'test_value') {
    echo "   ✅ Session variables persist\n";
} else {
    echo "   ❌ Session variables NOT persisting\n";
}

echo "\n4️⃣  Testing Session Storage Location:\n";
$session_path = session_save_path();
echo "   Session Path: " . ($session_path ?: "Default PHP temp directory") . "\n";
echo "   Session Cookie Name: " . session_name() . "\n";
echo "   Session Cookie Lifetime: " . ini_get('session.cookie_lifetime') . " seconds\n";

echo "\n5️⃣  Testing Auth Class:\n";
require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

try {
    $db = new Database();
    $auth = new Auth($db);
    echo "   ✅ Auth class initialized\n";
    
    // Check if user is logged in
    $isLoggedIn = $auth->isLoggedIn();
    echo "   Currently Logged In: " . ($isLoggedIn ? "✅ YES" : "❌ NO") . "\n";
    
    if ($isLoggedIn) {
        $user = $auth->getUser();
        echo "   Current User: " . ($user ? $user['full_name'] . " (" . $user['user_type'] . ")" : "Unknown") . "\n";
    }
} catch (Exception $e) {
    echo "   ❌ Auth Error: " . $e->getMessage() . "\n";
}

echo "\n6️⃣  Testing $_SESSION Content:\n";
if (!empty($_SESSION)) {
    echo "   Total session variables: " . count($_SESSION) . "\n";
    echo "   Content:\n";
    foreach ($_SESSION as $key => $value) {
        $val = is_array($value) ? json_encode($value) : $value;
        echo "     - $key: $val\n";
    }
} else {
    echo "   ❌ No session data\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ SESSION TEST COMPLETE\n";
echo str_repeat("=", 50) . "\n\n";

echo "🎯 NEXT STEPS:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. Close this page\n";
echo "2. Open this test page AGAIN (refresh)\n";
echo "3. Check if session variables still exist\n";
echo "4. If YES - sessions are working ✅\n";
echo "5. If NO - session issue needs fixing ❌\n\n";

echo "🔗 QUICK LINKS:\n";
echo "   Login: <a href='http://localhost/umsadtech/login.php'>http://localhost/umsadtech/login.php</a>\n";
echo "   Register: <a href='http://localhost/umsadtech/register.php'>http://localhost/umsadtech/register.php</a>\n";
echo "   Seed Data: <a href='http://localhost/umsadtech/seed-data.php'>http://localhost/umsadtech/seed-data.php</a>\n";
?>
