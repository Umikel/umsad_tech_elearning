<?php
// Quick database test
require_once 'includes/config.php';
require_once 'includes/Database.php';

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
