<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$pageTitle = 'Login';
require_once 'templates/header.php';

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    }
    if (empty($password)) {
        $errors['password'] = 'Password is required';
    }

    // If no validation errors, attempt login
    if (empty($errors)) {
        $result = $auth->login($email, $password);
        
        if ($result['success']) {
            $_SESSION['message'] = $result['message'];
            
            // Redirect based on user type
            if ($auth->isAdmin()) {
                redirect('/admin/dashboard.php');
            } elseif ($auth->isInstructor()) {
                redirect('/instructor/dashboard.php');
            } else {
                redirect('/student/dashboard.php');
            }
        } else {
            $errors['general'] = $result['message'];
        }
    }
}

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    redirect('/');
}
?>

<div class="container">
    <div class="auth-container">
        <div class="auth-header">
            <h1><i class="fas fa-sign-in-alt"></i> Login</h1>
            <p class="text-muted">Sign in to your Umsad Tech account</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo sanitize($errors['general']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" 
                       class="form-control <?php echo hasError('email', $errors) ? 'is-invalid' : ''; ?>" 
                       id="email" 
                       name="email" 
                       placeholder="Enter your email"
                       value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>"
                       required>
                <?php if (hasError('email', $errors)): ?>
                    <div class="form-error"><?php echo getError('email', $errors); ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <input type="password" 
                           class="form-control <?php echo hasError('password', $errors) ? 'is-invalid' : ''; ?}" 
                           id="password" 
                           name="password" 
                           placeholder="Enter your password"
                           required>
                    <button class="btn btn-outline-secondary" 
                            type="button" 
                            onclick="togglePasswordVisibility('password', 'passwordIcon')">
                        <i id="passwordIcon" class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <?php if (hasError('password', $errors)): ?>
                    <div class="form-error"><?php echo getError('password', $errors); ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                <label class="form-check-label" for="remember">
                    Remember me
                </label>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>

            <div class="auth-link">
                <p>Don't have an account? <a href="<?php echo APP_URL; ?>/register.php">Create one now</a></p>
                <p><a href="<?php echo APP_URL; ?>/forgot-password.php" class="small">Forgot your password?</a></p>
            </div>
        </form>

        <!-- Demo Account Info -->
        <div class="alert alert-info small mt-4">
            <strong>Demo Account:</strong><br>
            Email: student@example.com<br>
            Password: password123
        </div>
    </div>
</div>

<?php require_once 'templates/footer.php'; ?>
