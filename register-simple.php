<?php
/**
 * Minimal Register - No header/footer, just test the form
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/helpers.php';

// Start session manually
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new Database();
$auth = new Auth($db);
$errors = [];

// Check if already logged in
if ($auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/student/dashboard.php');
    exit;
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
            $result = $auth->register($email, $password, $full_name, $user_type);
            if ($result['success']) {
                $_SESSION['success_message'] = 'Registration successful! Please login with your credentials.';
                header('Location: ' . APP_URL . '/login.php');
                exit;
            } else {
                $errors['form'] = $result['message'] ?? 'Registration failed. Please try again.';
            }
        } catch (Exception $e) {
            $errors['form'] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - Umsad Tech</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; padding: 20px; }
        .auth-card { box-shadow: 0 10px 40px rgba(0,0,0,0.3); border: none; }
        .auth-card .card-body { padding: 40px; }
        .form-error { color: #dc3545; font-size: 12px; margin-top: 5px; }
        .is-invalid { border-color: #dc3545; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 500px;">
        <div class="card auth-card">
            <div class="card-body">
                <h2 class="card-title text-center mb-4">Create Account</h2>
                <p class="text-center text-muted small mb-4">Join Umsad Tech E-Learning Platform</p>

                <?php if (!empty($errors['form'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errors['form']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name</label>
                        <input type="text" 
                               class="form-control <?php echo isset($errors['full_name']) ? 'is-invalid' : ''; ?>" 
                               id="full_name" 
                               name="full_name" 
                               placeholder="Enter your full name"
                               value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>"
                               required>
                        <?php if (isset($errors['full_name'])): ?>
                            <div class="form-error"><?php echo htmlspecialchars($errors['full_name']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" 
                               class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" 
                               id="email" 
                               name="email" 
                               placeholder="Enter your email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               required>
                        <?php if (isset($errors['email'])): ?>
                            <div class="form-error"><?php echo htmlspecialchars($errors['email']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="user_type" class="form-label">I want to register as:</label>
                        <select class="form-select" id="user_type" name="user_type">
                            <option value="student">Student</option>
                            <option value="instructor">Instructor</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?}" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Min 8 characters"
                                   required>
                            <button class="btn btn-outline-secondary" type="button" 
                                    onclick="document.getElementById('password').type = document.getElementById('password').type === 'password' ? 'text' : 'password'">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                            <div class="form-error"><?php echo htmlspecialchars($errors['password']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control <?php echo isset($errors['password_confirm']) ? 'is-invalid' : ''; ?}" 
                                   id="password_confirm" 
                                   name="password_confirm" 
                                   placeholder="Confirm your password"
                                   required>
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="document.getElementById('password_confirm').type = document.getElementById('password_confirm').type === 'password' ? 'text' : 'password'">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['password_confirm'])): ?>
                            <div class="form-error"><?php echo htmlspecialchars($errors['password_confirm']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="terms" name="terms" <?php echo isset($_POST['terms']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="terms">
                            I agree to the Terms and Conditions
                        </label>
                        <?php if (isset($errors['terms'])): ?>
                            <div class="form-error d-block"><?php echo htmlspecialchars($errors['terms']); ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mb-3">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </form>

                <p class="text-center small">
                    Already have an account? <a href="<?php echo APP_URL; ?>/login.php">Sign in here</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
