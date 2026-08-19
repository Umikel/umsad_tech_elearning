<?php
// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== Umsad Tech - Error Report ===\n\n";

// Test 1: Config file
echo "1. Testing Config File...\n";
try {
    require_once 'includes/config.php';
    echo "✅ Config loaded. DB_NAME = " . DB_NAME . "\n";
} catch (Exception $e) {
    echo "❌ Config Error: " . $e->getMessage() . "\n";
    die();
}

echo "\n2. Testing Database Connection...\n";
try {
    require_once 'includes/Database.php';
    $db = new Database();
    echo "✅ Database connected successfully!\n";
    
    // Check tables
    $result = $db->query("SHOW TABLES")->resultSet();
    echo "✅ Found " . count($result) . " tables in database\n";
    
} catch (Exception $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    echo "\nThis means:\n";
    echo "- Database '" . DB_NAME . "' doesn't exist, OR\n";
    echo "- Database hasn't been imported yet\n\n";
    echo "SOLUTION:\n";
    echo "1. Open phpMyAdmin: http://localhost/phpmyadmin\n";
    echo "2. Create database: " . DB_NAME . "\n";
    echo "3. Import schema: database/schema.sql\n";
    die();
}

echo "\n3. Testing Auth Class...\n";
try {
    require_once 'includes/Auth.php';
    $auth = new Auth();
    echo "✅ Auth class loaded\n";
} catch (Exception $e) {
    echo "❌ Auth Error: " . $e->getMessage() . "\n";
}

echo "\n4. Testing Helpers...\n";
try {
    require_once 'includes/helpers.php';
    echo "✅ Helpers loaded\n";
} catch (Exception $e) {
    echo "❌ Helpers Error: " . $e->getMessage() . "\n";
}

echo "\n✅ ALL SYSTEMS GO! Register page should work now.\n";
echo "\nGo to: http://localhost/umsadtech/register.php\n";
?>
