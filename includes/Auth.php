<?php
/**
 * Authentication Class
 * Handles user authentication and session management
 */

require_once __DIR__ . '/NotificationService.php';

class Auth {
    private $db;
    private $currentUser = null;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->startSession();
    }

    /**
     * Start or resume session
     */
    private function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            $started = session_start([
                'use_only_cookies' => 1,
                'use_strict_mode' => 1,
                'cookie_lifetime' => 0,
                'cookie_path' => SESSION_COOKIE_PATH,
                'cookie_secure' => SESSION_COOKIE_SECURE,
                'cookie_httponly' => true,
                'cookie_samesite' => SESSION_COOKIE_SAMESITE,
            ]);

            if (!$started) {
                throw new RuntimeException('Unable to start a secure session.');
            }
        }
    }

    /**
     * Register a new user
     */
    public function register($email, $password, $full_name, $user_type = 'student') {
        $email = strtolower(trim((string) $email));
        $full_name = trim((string) $full_name);
        $user_type = strtolower(trim((string) $user_type));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }

        if ($full_name === '' || strlen($full_name) > 255) {
            return ['success' => false, 'message' => 'Please enter a valid full name'];
        }

        $password = (string) $password;
        if (strlen($password) < 10
            || strlen($password) > 72
            || !preg_match('/[A-Za-z]/', $password)
            || !preg_match('/\d/', $password)) {
            return ['success' => false, 'message' => 'Password must be 10–72 characters with a letter and a number'];
        }

        // Public registration can never create an administrator account.
        if (!in_array($user_type, ['student', 'instructor'], true)) {
            return ['success' => false, 'message' => 'Invalid account type'];
        }

        // Fail closed if application code is deployed before the verification
        // migration, including a partially applied migration where only the
        // users column exists. This prevents a newly inserted account from
        // later being mistaken for a legacy account during backfill.
        try {
            $this->db->query(' 
                SELECT u.email_verified_at,
                       t.user_id, t.email, t.token_hash, t.expires_at,
                       t.sent_at, t.window_started_at, t.request_count
                FROM users u
                LEFT JOIN email_verification_tokens t ON t.user_id = u.id
                WHERE 1 = 0
            ');
            $this->db->execute();
        } catch (Throwable $exception) {
            error_log('Registration blocked because email-verification storage is unavailable.');
            return [
                'success' => false,
                'message' => 'Registration is temporarily unavailable. Please try again later.',
            ];
        }

        $this->db->query('SELECT id FROM users WHERE email = :email');
        $this->db->bind(':email', $email);

        if ($this->db->single()) {
            return ['success' => false, 'message' => 'Email already registered'];
        }

        $hashedPassword = password_hash($password, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);
        if ($hashedPassword === false) {
            error_log('Password hashing failed during registration.');
            return ['success' => false, 'message' => 'Registration failed. Please try again.'];
        }

        try {
            $this->db->beginTransaction();
            $this->db->query(' 
                INSERT INTO users (
                    email, email_verified_at, password, full_name, user_type, created_at
                ) VALUES (
                    :email, NULL, :password, :full_name, :user_type, NOW()
                )
            ');

            $this->db->bind(':email', $email);
            $this->db->bind(':password', $hashedPassword);
            $this->db->bind(':full_name', $full_name);
            $this->db->bind(':user_type', $user_type);
            $this->db->execute();
            $userId = (int) $this->db->lastInsertId();

            $notifications = new NotificationService($this->db);
            $welcomeEventKey = $notifications->queueSignupWelcome(
                $userId,
                $email,
                $full_name
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                return ['success' => false, 'message' => 'Email already registered'];
            }

error_log(
    'Registration failed while creating the account and its welcome notification: '
    . $e->getMessage()
);

return ['success' => false, 'message' => 'Registration failed. Please try again.'];
        }

        return [
            'success' => true,
            'message' => 'Registration successful',
            'user_id' => $userId,
            'notification_event_keys' => [$welcomeEventKey],
        ];
    }

    /**
     * Login user
     */
    public function login($email, $password) {
        $email = strtolower(trim((string) $email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }

        $this->db->query('SELECT * FROM users WHERE email = :email AND is_active = 1');
        $this->db->bind(':email', $email);
        $user = $this->db->single();

        if (!$user || !password_verify((string) $password, $user['password'])) {
            return ['success' => false, 'message' => 'Invalid email or password'];
        }

        // Check verification only after the password succeeds so this response
        // does not expose whether an arbitrary email address is registered.
        if (!array_key_exists('email_verified_at', $user)) {
            error_log('Email verification migration has not been applied.');
            return [
                'success' => false,
                'message' => 'Sign-in is temporarily unavailable.',
                'code' => 'verification_unavailable',
            ];
        }

        if (empty($user['email_verified_at'])) {
            return [
                'success' => false,
                'message' => 'Verify your email address before signing in.',
                'code' => 'email_unverified',
            ];
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to secure the authenticated session.');
        }

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['_last_regenerated'] = time();
        // Reload through getUser() when needed so password data is never returned.
        $this->currentUser = null;

        if (password_needs_rehash($user['password'], PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS)) {
            $newHash = password_hash((string) $password, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);
            if ($newHash !== false) {
                $this->db->query('UPDATE users SET password = :password WHERE id = :id');
                $this->db->bind(':password', $newHash);
                $this->db->bind(':id', (int) $user['id']);
                $this->db->execute();
            }
        }

        $this->db->query('UPDATE users SET last_login = NOW() WHERE id = :id');
        $this->db->bind(':id', (int) $user['id']);
        $this->db->execute();

        return ['success' => true, 'message' => 'Login successful'];
    }

    /**
     * Logout user
     */
    public function logout() {
        $this->currentUser = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => SESSION_COOKIE_PATH,
                    'secure' => SESSION_COOKIE_SECURE,
                    'httponly' => true,
                    'samesite' => SESSION_COOKIE_SAMESITE,
                ]);
            }

            session_destroy();
        }

        return ['success' => true, 'message' => 'Logged out successfully'];
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Get current user ID
     */
    public function getUserId() {
        return $this->isLoggedIn() ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Get current user data
     */
    public function getUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        if (is_array($this->currentUser) && (int) $this->currentUser['id'] === $this->getUserId()) {
            return $this->currentUser;
        }

        $this->db->query(' 
            SELECT id, email, email_verified_at, full_name, phone, profile_image, bio, user_type,
                   is_active, created_at, updated_at, last_login
            FROM users
            WHERE id = :id AND is_active = 1
        ');
        $this->db->bind(':id', $this->getUserId());
        $user = $this->db->single();
        $this->currentUser = $user ?: null;

        return $this->currentUser;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
    }

    /**
     * Check if user is instructor
     */
    public function isInstructor() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'instructor';
    }

    /**
     * Check if user is student
     */
    public function isStudent() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student';
    }

    /**
     * Return the signed-in user's dashboard path.
     */
    public function getDashboardPath(): string {
        if ($this->isAdmin()) {
            return 'admin/dashboard.php';
        }

        if ($this->isInstructor()) {
            return 'instructor/dashboard.php';
        }

        return 'student/dashboard.php';
    }

    /**
     * Verify session timeout
     */
    public function verifySession() {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $now = time();
        $lastActivity = (int) ($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? 0);
        if ($lastActivity <= 0 || $now - $lastActivity > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }

        $user = $this->getUser();
        if (!$user
            || empty($user['email_verified_at'])
            || !in_array($user['user_type'], ['student', 'instructor', 'admin'], true)) {
            $this->logout();
            return false;
        }

        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['last_activity'] = $now;

        $lastRegenerated = (int) ($_SESSION['_last_regenerated'] ?? 0);
        if ($lastRegenerated <= 0 || $now - $lastRegenerated >= 1800) {
            if (!headers_sent() && session_regenerate_id(true)) {
                $_SESSION['_last_regenerated'] = $now;
            }
        }

        return true;
    }
}
