<?php
/**
 * Shared site header.
 *
 * Authentication and session validation intentionally happen before any HTML
 * is emitted so redirects can always send a valid HTTP Location header.
 */
require_once dirname(__DIR__) . '/includes/config.php';

if (!class_exists('Database')) {
    require_once dirname(__DIR__) . '/includes/Database.php';
}
if (!class_exists('Auth')) {
    require_once dirname(__DIR__) . '/includes/Auth.php';
}
if (!function_exists('sanitize')) {
    require_once dirname(__DIR__) . '/includes/helpers.php';
}

if (!isset($db)) {
    $db = new Database();
}
if (!isset($auth)) {
    $auth = new Auth($db);
}

if ($auth->isLoggedIn() && !$auth->verifySession()) {
    redirect('/login.php');
}

$currentUser = $auth->isLoggedIn() ? $auth->getUser() : null;
$currentScriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$currentScript = basename($currentScriptPath);
$isHomePage = $currentScript === 'index.php';
$siteUrl = rtrim(APP_URL, '/');

$dashboardPath = '/student/dashboard.php';
if ($auth->isInstructor()) {
    $dashboardPath = '/instructor/dashboard.php';
} elseif ($auth->isAdmin()) {
    $dashboardPath = '/admin/dashboard.php';
}

$accountName = $currentUser['full_name'] ?? ($_SESSION['full_name'] ?? 'My account');
$accountInitial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr((string) $accountName, 0, 1))
    : strtoupper(substr((string) $accountName, 0, 1));
$accountInitial = $accountInitial !== '' ? $accountInitial : 'U';

// Authenticated product pages share one responsive workspace shell. Keeping
// the navigation map here gives every role the same predictable information
// architecture while preserving the marketing navigation on public pages.
$useWorkspaceNavigation = false;
$workspaceRole = '';
$workspaceLabel = 'Learning workspace';
$workspaceHome = $dashboardPath;
$workspaceNavItems = [];
$workspaceNavActive = '';

if ($auth->isStudent()) {
    $workspaceRole = 'student';
    $workspaceLabel = 'Learner workspace';
    $workspaceNavItems = [
        'home' => ['label' => 'Home', 'path' => '/student/dashboard.php', 'icon' => 'fa-house'],
        'learning' => ['label' => 'My courses', 'path' => '/student/my-courses.php', 'icon' => 'fa-book-open'],
        'explore' => ['label' => 'Explore', 'path' => '/courses.php', 'icon' => 'fa-compass'],
    ];
    $studentWorkspaceRoutes = [
        '/student/dashboard.php', '/student/my-courses.php', '/student/course-lessons.php',
        '/student/certificate.php', '/student/quiz.php', '/student/assignment.php',
        '/courses.php', '/course-detail.php', '/profile.php', '/settings.php',
    ];
    foreach ($studentWorkspaceRoutes as $route) {
        if (str_ends_with($currentScriptPath, $route)) {
            $useWorkspaceNavigation = true;
            break;
        }
    }
    if (str_ends_with($currentScriptPath, '/student/dashboard.php')) {
        $workspaceNavActive = 'home';
    } elseif (str_contains($currentScriptPath, '/student/')) {
        $workspaceNavActive = 'learning';
    } elseif (in_array($currentScript, ['courses.php', 'course-detail.php'], true)) {
        $workspaceNavActive = 'explore';
    }
} elseif ($auth->isInstructor() && (str_contains($currentScriptPath, '/instructor/') || in_array($currentScript, ['profile.php', 'settings.php'], true))) {
    $useWorkspaceNavigation = true;
    $workspaceRole = 'instructor';
    $workspaceLabel = 'Instructor studio';
    $workspaceNavItems = [
        'overview' => ['label' => 'Overview', 'path' => '/instructor/dashboard.php', 'icon' => 'fa-chart-line'],
        'courses' => ['label' => 'Courses', 'path' => '/instructor/courses.php', 'icon' => 'fa-layer-group'],
        'learners' => ['label' => 'Learners', 'path' => '/instructor/learners.php', 'icon' => 'fa-user-group'],
        'assessments' => ['label' => 'Assessments', 'path' => '/instructor/assessments.php', 'icon' => 'fa-list-check'],
        'submissions' => ['label' => 'Submissions', 'path' => '/instructor/submissions.php', 'icon' => 'fa-inbox'],
    ];
    if ($currentScript === 'dashboard.php') {
        $workspaceNavActive = 'overview';
    } elseif (in_array($currentScript, ['courses.php', 'course-edit.php'], true)) {
        $workspaceNavActive = 'courses';
    } elseif ($currentScript === 'learners.php') {
        $workspaceNavActive = 'learners';
    } elseif ($currentScript === 'assessments.php') {
        $workspaceNavActive = 'assessments';
    } elseif ($currentScript === 'submissions.php') {
        $workspaceNavActive = 'submissions';
    }
} elseif ($auth->isAdmin() && (str_contains($currentScriptPath, '/admin/') || in_array($currentScript, ['profile.php', 'settings.php'], true))) {
    $useWorkspaceNavigation = true;
    $workspaceRole = 'admin';
    $workspaceLabel = 'Admin control centre';
    $workspaceNavItems = [
        'overview' => ['label' => 'Overview', 'path' => '/admin/dashboard.php', 'icon' => 'fa-chart-pie'],
        'users' => ['label' => 'Users', 'path' => '/admin/users.php', 'icon' => 'fa-users'],
        'courses' => ['label' => 'Courses', 'path' => '/admin/courses.php', 'icon' => 'fa-layer-group'],
        'enrollments' => ['label' => 'Enrollments', 'path' => '/admin/enrollments.php', 'icon' => 'fa-user-check'],
        'engagement' => ['label' => 'Engagement', 'path' => '/admin/engagement.php', 'icon' => 'fa-comments'],
        'payments' => ['label' => 'Payments', 'path' => '/admin/payments.php', 'icon' => 'fa-credit-card'],
    ];
    $workspaceNavActive = match ($currentScript) {
        'dashboard.php' => 'overview',
        'users.php' => 'users',
        'courses.php' => 'courses',
        'enrollments.php' => 'enrollments',
        'engagement.php' => 'engagement',
        'payments.php' => 'payments',
        default => '',
    };
}

if ($currentScript === 'profile.php') {
    $workspaceNavActive = 'profile';
} elseif ($currentScript === 'settings.php') {
    $workspaceNavActive = 'settings';
}

// Backwards-compatible aliases are retained for page styles that predate the
// shared workspace shell.
$useStudentAppNavigation = $useWorkspaceNavigation;
$studentNavActive = $workspaceNavActive;

$isDashboardOverview = $useWorkspaceNavigation && $currentScript === 'dashboard.php';

$documentTitle = isset($pageTitle) ? sanitize($pageTitle) . ' · ' . APP_NAME : APP_NAME;

// Keep critical interface assets on the current origin and invalidate stale
// browser caches whenever a local file changes.
$versionedAsset = static function (string $relativePath): string {
    $relativePath = '/' . ltrim($relativePath, '/');
    $filePath = APP_ROOT . $relativePath;
    $version = is_file($filePath) ? '?v=' . (string) filemtime($filePath) : '';

    return APP_BASE_PATH . $relativePath . $version;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo $useWorkspaceNavigation ? '#ffffff' : '#171936'; ?>">
    <meta name="description" content="Practical, career-focused technology courses from Umsad Tech.">
    <?php if (($pageReferrerPolicy ?? '') === 'no-referrer'): ?>
        <meta name="referrer" content="no-referrer">
    <?php endif; ?>
    <?php if (!empty($pageNoIndex)): ?>
        <meta name="robots" content="noindex, nofollow, noarchive">
    <?php endif; ?>
    <meta name="app-url" content="<?php echo sanitize(APP_URL); ?>">
    <?php if (function_exists('csrfToken')): ?>
        <meta name="csrf-token" content="<?php echo sanitize(csrfToken()); ?>">
    <?php endif; ?>
    <title><?php echo $documentTitle; ?></title>

    <link rel="icon" type="image/png" href="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>">
    <link rel="apple-touch-icon" href="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Space+Grotesk:wght@500;600;700&amp;display=swap" rel="stylesheet">
    <link href="<?php echo sanitize($versionedAsset('/assets/vendor/bootstrap/bootstrap.min.css')); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo sanitize($versionedAsset('/assets/vendor/fontawesome/css/all.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo sanitize($versionedAsset('/assets/css/style.css')); ?>">

    <?php if ($isDashboardOverview): ?><link rel="stylesheet" href="<?php echo sanitize($versionedAsset('/assets/css/dashboard.css')); ?>"><?php endif; ?>

    <?php if (!empty($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo $siteUrl . sanitize($css); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body<?php echo $useWorkspaceNavigation ? ' class="student-app workspace-app workspace-app--' . sanitize($workspaceRole) . ($isDashboardOverview ? ' dashboard-overview' : '') . '"' : ''; ?>>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <?php if ($useWorkspaceNavigation): ?>
    <header class="site-header student-app-header">
        <nav class="student-app-topbar workspace-topbar" aria-label="<?php echo sanitize($workspaceLabel); ?> navigation">
            <div class="container student-app-topbar__inner">
                <button class="student-app-topbar__menu-button" type="button" data-bs-toggle="collapse" data-bs-target="#studentAppMenu" aria-controls="studentAppMenu" aria-expanded="false" aria-label="Open navigation menu">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>

                <a class="student-app-topbar__brand" href="<?php echo $siteUrl . $workspaceHome; ?>" aria-label="Umsad Tech <?php echo sanitize($workspaceLabel); ?>">
                    <span class="brand-logo-frame brand-logo-frame--student-app" aria-hidden="true">
                        <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024">
                    </span>
                    <span class="workspace-brand-label"><?php echo sanitize($workspaceLabel); ?></span>
                </a>

                <div class="student-app-topbar__links" aria-label="Workspace shortcuts">
                    <?php foreach ($workspaceNavItems as $navKey => $navItem): ?>
                        <a class="student-app-topbar__link<?php echo $workspaceNavActive === $navKey ? ' is-active' : ''; ?>" href="<?php echo $siteUrl . $navItem['path']; ?>"<?php echo $workspaceNavActive === $navKey ? ' aria-current="page"' : ''; ?>><?php echo sanitize($navItem['label']); ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="student-app-topbar__actions">
                    <a class="workspace-logout" href="<?php echo $siteUrl; ?>/logout.php" aria-label="Log out" title="Log out"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a>
                    <a class="student-app-topbar__action" href="<?php echo $siteUrl; ?>/courses.php" aria-label="View public course catalog" title="Course catalog">
                        <i class="fas fa-globe" aria-hidden="true"></i>
                    </a>
                    <a class="student-app-topbar__action<?php echo $workspaceNavActive === 'settings' ? ' is-active' : ''; ?>" href="<?php echo $siteUrl; ?>/settings.php" aria-label="Account settings" title="Account settings">
                        <i class="fas fa-gear" aria-hidden="true"></i>
                    </a>
                    <a class="student-app-topbar__avatar<?php echo $workspaceNavActive === 'profile' ? ' is-active' : ''; ?>" href="<?php echo $siteUrl; ?>/profile.php" aria-label="Open <?php echo sanitize($accountName); ?>'s profile" title="Profile">
                        <span aria-hidden="true"><?php echo sanitize($accountInitial); ?></span>
                    </a>
                </div>
            </div>

            <div class="collapse student-app-menu" id="studentAppMenu">
                <div class="container student-app-menu__grid">
                    <?php foreach ($workspaceNavItems as $navKey => $navItem): ?>
                        <a class="student-app-menu__link<?php echo $workspaceNavActive === $navKey ? ' is-active' : ''; ?>" href="<?php echo $siteUrl . $navItem['path']; ?>"<?php echo $workspaceNavActive === $navKey ? ' aria-current="page"' : ''; ?>><i class="fas <?php echo sanitize($navItem['icon']); ?>" aria-hidden="true"></i><span><?php echo sanitize($navItem['label']); ?></span></a>
                    <?php endforeach; ?>
                    <a class="student-app-menu__link<?php echo $workspaceNavActive === 'profile' ? ' is-active' : ''; ?>" href="<?php echo $siteUrl; ?>/profile.php"<?php echo $workspaceNavActive === 'profile' ? ' aria-current="page"' : ''; ?>><i class="fas fa-user" aria-hidden="true"></i><span>Profile</span></a>
                    <a class="student-app-menu__link<?php echo $workspaceNavActive === 'settings' ? ' is-active' : ''; ?>" href="<?php echo $siteUrl; ?>/settings.php"<?php echo $workspaceNavActive === 'settings' ? ' aria-current="page"' : ''; ?>><i class="fas fa-gear" aria-hidden="true"></i><span>Settings</span></a>
                    <a class="student-app-menu__link student-app-menu__link--danger" href="<?php echo $siteUrl; ?>/logout.php"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a>
                </div>
            </div>
        </nav>
    </header>
    <?php else: ?>
    <header class="site-header">
        <nav class="navbar navbar-expand-lg navbar-dark site-navbar" aria-label="Primary navigation">
            <div class="container">
                <a class="navbar-brand" href="<?php echo $siteUrl; ?>/" aria-label="Umsad Tech home">
                    <span class="brand-logo-frame brand-logo-frame--nav" aria-hidden="true">
                        <img class="brand-logo-image" src="<?php echo sanitize($versionedAsset('/assets/images/umsad-tech-logo.png')); ?>" alt="" width="1536" height="1024">
                    </span>
                </a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#siteNavigation" aria-controls="siteNavigation" aria-expanded="false" aria-label="Open navigation menu">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="siteNavigation">
                    <ul class="navbar-nav mx-auto site-nav-links">
                        <li class="nav-item">
                            <a class="nav-link<?php echo $isHomePage ? ' active' : ''; ?>" href="<?php echo $siteUrl; ?>/"<?php echo $isHomePage ? ' aria-current="page"' : ''; ?>>Home</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?php echo in_array($currentScript, ['courses.php', 'course-detail.php'], true) ? ' active' : ''; ?>" href="<?php echo $siteUrl; ?>/courses.php"<?php echo in_array($currentScript, ['courses.php', 'course-detail.php'], true) ? ' aria-current="page"' : ''; ?>>Courses</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $siteUrl; ?>/#learning-experience">Why Umsad</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $siteUrl; ?>/#learning-path">How it works</a>
                        </li>
                    </ul>

                    <div class="navbar-actions">
                        <?php if ($auth->isLoggedIn()): ?>
                            <a class="nav-dashboard-link" href="<?php echo $siteUrl . $dashboardPath; ?>">
                                <i class="fas fa-table-cells-large" aria-hidden="true"></i> Dashboard
                            </a>
                            <div class="dropdown account-menu">
                                <button class="account-trigger dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="account-avatar" aria-hidden="true"><?php echo sanitize(strtoupper(substr($accountName, 0, 1))); ?></span>
                                    <span class="account-label"><?php echo sanitize($accountName); ?></span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                    <li class="dropdown-header">Your learning space</li>
                                    <li><a class="dropdown-item" href="<?php echo $siteUrl . $dashboardPath; ?>"><i class="fas fa-chart-line" aria-hidden="true"></i> Dashboard</a></li>
                                    <?php if ($auth->isStudent()): ?>
                                        <li><a class="dropdown-item" href="<?php echo $siteUrl; ?>/student/my-courses.php"><i class="fas fa-book-open" aria-hidden="true"></i> My courses</a></li>
                                    <?php elseif ($auth->isInstructor()): ?>
                                        <li><a class="dropdown-item" href="<?php echo $siteUrl; ?>/instructor/courses.php"><i class="fas fa-layer-group" aria-hidden="true"></i> My courses</a></li>
                                    <?php endif; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?php echo $siteUrl; ?>/profile.php"><i class="fas fa-user" aria-hidden="true"></i> Profile</a></li>
                                    <li><a class="dropdown-item" href="<?php echo $siteUrl; ?>/settings.php"><i class="fas fa-gear" aria-hidden="true"></i> Settings</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item dropdown-item-danger" href="<?php echo $siteUrl; ?>/logout.php"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <a class="nav-login-link" href="<?php echo $siteUrl; ?>/login.php">Log in</a>
                            <a class="nav-cta" href="<?php echo $siteUrl; ?>/register.php">Start learning <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </nav>
    </header>
    <?php endif; ?>

    <?php if ($isDashboardOverview) require __DIR__ . '/dashboard-sidebar.php'; ?>

    <?php if (isset($_SESSION['message']) || isset($_SESSION['error']) || isset($_SESSION['success_message']) || isset($_SESSION['success'])): ?>
        <div class="flash-region container" aria-live="polite">
            <?php if (isset($_SESSION['message']) || isset($_SESSION['success_message']) || isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="status">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <span><?php echo sanitize($_SESSION['message'] ?? $_SESSION['success_message'] ?? $_SESSION['success']); ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
                </div>
                <?php unset($_SESSION['message'], $_SESSION['success_message'], $_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?php echo sanitize($_SESSION['error']); ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss error"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <main id="main-content" class="main-content">
