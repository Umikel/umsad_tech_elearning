<?php
/**
 * Header Template
 * Included at the top of every page
 */

// Include configuration and classes
require_once dirname(__DIR__) . '/includes/config.php';

// Only include if not already included
if (!class_exists('Database')) {
    require_once dirname(__DIR__) . '/includes/Database.php';
}
if (!class_exists('Auth')) {
    require_once dirname(__DIR__) . '/includes/Auth.php';
}
if (!function_exists('sanitize')) {
    require_once dirname(__DIR__) . '/includes/helpers.php';
}

// Initialize database and auth if not already done
if (!isset($db)) {
    $db = new Database();
}
if (!isset($auth)) {
    $auth = new Auth($db);
}

// Verify session
if ($auth->isLoggedIn() && !$auth->verifySession()) {
    redirect(APP_URL . '/login.php');
}

// Get current user
$currentUser = $auth->isLoggedIn() ? $auth->getUser() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' - ' . APP_NAME : APP_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    
    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo APP_URL . $css; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark site-navbar">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo APP_URL; ?>">
                <span class="brand-mark"><i class="fas fa-bolt"></i></span> Umsad <span>Tech</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo APP_URL; ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo APP_URL; ?>/courses.php">Courses</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo APP_URL; ?>/about.php">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo APP_URL; ?>/contact.php">Contact</a>
                    </li>

                    <?php if ($auth->isLoggedIn()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user"></i> <?php echo sanitize($currentUser['full_name']); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <?php if ($auth->isStudent()): ?>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/student/dashboard.php">My Dashboard</a></li>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/student/my-courses.php">My Courses</a></li>
                                <?php elseif ($auth->isInstructor()): ?>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/instructor/dashboard.php">Instructor Dashboard</a></li>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/instructor/courses.php">My Courses</a></li>
                                <?php elseif ($auth->isAdmin()): ?>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/admin/dashboard.php">Admin Dashboard</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/profile.php">Profile</a></li>
                                <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/settings.php">Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo APP_URL; ?>/login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link nav-cta ms-lg-2" href="<?php echo APP_URL; ?>/register.php">Get started <i class="fas fa-arrow-right ms-1"></i></a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-top: 1rem;">
            <?php echo sanitize($_SESSION['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin-top: 1rem;">
            <?php echo sanitize($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="main-content">
