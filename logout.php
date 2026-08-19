<?php
require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$db = new Database();
$auth = new Auth($db);

// Logout user
$result = $auth->logout();

// Redirect to home
$_SESSION['message'] = 'You have been logged out successfully.';
header('Location: ' . APP_URL);
exit;
