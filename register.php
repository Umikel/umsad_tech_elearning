<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/helpers.php';

$db = new Database();
$auth = new Auth($db);
$errors = [];

// Check if already logged in
if ($auth->isLoggedIn()) {
    redirect(APP_URL . '/student/dashboard.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $user_type = trim($_POST['user_type'] ?? 'student');
    $terms = isset($_POST['terms']) ? 1 : 0;

    // Validation
    if (empty($full_name)) {
        $errors['full_name'] = 'Full name is required';
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Valid email is required';
    }

    if (empty($password) || strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }

    if ($password !== $password_confirm) {
        $errors['password_confirm'] = 'Passwords do not match';
    }

    if (!$terms) {
        $errors['terms'] = 'You must accept the terms and conditions';
    }

    // If no errors, register user
    if (empty($errors)) {
        try {
            // Check if email already exists
            $db_check = new Database();
            $db_check->query('SELECT id FROM users WHERE email = :email');
            $db_check->bind(':email', $email);
            if ($db_check->single()) {
                $errors['email'] = 'Email already registered';
            } else {
                // Register new user
                $result = $auth->register($email, $password, $full_name, $user_type);
                if ($result['success']) {
                    $_SESSION['success_message'] = 'Registration successful! Please login with your credentials.';
                    redirect(APP_URL . '/login.php');
                } else {
                    $errors['form'] = $result['message'] ?? 'Registration failed. Please try again.';
                }
            }
        } catch (Exception $e) {
            $errors['form'] = 'An error occurred. Please try again later.';
        }
    }
}
?>
<?php include 'templates/header.php'; ?>

<div class="container auth-container">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card auth-card">
                <div class="card-body">
                    <h2 class="card-title text-center mb-4">Create Account</h2>
                    <p class="text-center text-muted small mb-4">Join Umsad Tech E-Learning Platform</p>

                    <?php if (!empty($errors['form'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $errors['form']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" 
                                   class="form-control <?php echo hasError('full_name', $errors) ? 'is-invalid' : ''; ?>" 
                                   id="full_name" 
                                   name="full_name" 
                                   placeholder="Enter your full name"
                                   value="<?php echo isset($_POST['full_name']) ? sanitize($_POST['full_name']) : ''; ?>"
                                   required>
                            <?php if (hasError('full_name', $errors)): ?>
                                <div class="form-error"><?php echo getError('full_name', $errors); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" 
                                   class="form-control <?php echo hasError('email', $errors) ? 'is-invalid' : ''; ?}" 
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
                            <label for="user_type" class="form-label">I want to register as:</label>
                            <select class="form-select" id="user_type" name="user_type">
                                <option value="student" <?php echo isset($_POST['user_type']) && $_POST['user_type'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="instructor" <?php echo isset($_POST['user_type']) && $_POST['user_type'] === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control <?php echo hasError('password', $errors) ? 'is-invalid' : ''; ?}" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Min 8 characters"
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

                        <div class="mb-3">
                            <label for="password_confirm" class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control <?php echo hasError('password_confirm', $errors) ? 'is-invalid' : ''; ?}" 
                                       id="password_confirm" 
                                       name="password_confirm" 
                                       placeholder="Confirm your password"
                                       required>
                                <button class="btn btn-outline-secondary" 
                                        type="button" 
                                        onclick="togglePasswordVisibility('password_confirm', 'passwordConfirmIcon')">
                                    <i id="passwordConfirmIcon" class="fas fa-eye-slash"></i>
                                </button>
                            </div>
                            <?php if (hasError('password_confirm', $errors)): ?>
                                <div class="form-error"><?php echo getError('password_confirm', $errors); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="terms" name="terms" <?php echo isset($_POST['terms']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" class="text-decoration-none">Terms and Conditions</a>
                            </label>
                            <?php if (hasError('terms', $errors)): ?>
                                <div class="form-error d-block"><?php echo getError('terms', $errors); ?></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mb-3">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>
                    </form>

                    <div class="auth-link">
                        <p>Already have an account? <a href="<?php echo APP_URL; ?>/login.php">Sign in here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
