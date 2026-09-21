<?php
// Quick database test
require_once 'includes/config.php';
require_once 'includes/Database.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (PHP_SAPI !== 'cli' && (APP_ENV !== 'development' || !in_array($remoteAddress, ['127.0.0.1', '::1'], true))) {
    http_response_code(404);
    exit;
}

try {
    $db = new Database();
    echo "✅ Database Connected Successfully!<br>";
    
    // Test if users table exists
    $db->query("SELECT COUNT(*) as total FROM users");
    $result = $db->single();
    echo "✅ Users table found. Total users: " . $result['total'] . "<br>";
    
} catch (Exception $e) {
    echo "❌ Database Error: " . $e->getMessage();
}
?>
