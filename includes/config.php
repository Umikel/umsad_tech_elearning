<?php
/**
 * Umsad Tech E-Learning Platform
 * Configuration File
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'umsad_tech_elearning');

// Application Configuration
define('APP_NAME', 'Umsad Tech E-Learning');
define('APP_URL', 'http://localhost/umsadtech');
define('APP_ENV', 'development'); // development, production

// Session Configuration
define('SESSION_LIFETIME', 3600 * 24); // 24 hours
define('SESSION_COOKIE_NAME', 'umsad_session');

// Security
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_HASH_OPTIONS', ['cost' => 12]);

// Paystack Configuration
define('PAYSTACK_PUBLIC_KEY', 'pk_test_your_key_here');
define('PAYSTACK_SECRET_KEY', 'sk_test_your_key_here');

// Timezone
date_default_timezone_set('Africa/Lagos');

// Display Errors (disable in production)
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
