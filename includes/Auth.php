<?php
/**
 * Authentication Class
 * Handles user authentication and session management
 */

class Auth {
    private $db;

    public function __construct(Database $db) {
        $this->db = $db;
        $this->startSession();
    }

    /**
     * Start or resume session
     */
    private function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Register a new user
     */
    public function register($email, $password, $full_name, $user_type = 'student') {
        // Validate input
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters'];
        }

        // Check if user already exists
        $this->db->query('SELECT id FROM users WHERE email = :email');
        $this->db->bind(':email', $email);
        
        if ($this->db->single()) {
            return ['success' => false, 'message' => 'Email already registered'];
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);

        // Insert new user
        $this->db->query('
            INSERT INTO users (email, password, full_name, user_type, created_at)
            VALUES (:email, :password, :full_name, :user_type, NOW())
        ');
        
        $this->db->bind(':email', $email);
        $this->db->bind(':password', $hashedPassword);
        $this->db->bind(':full_name', $full_name);
        $this->db->bind(':user_type', $user_type);

        if ($this->db->execute()) {
            return ['success' => true, 'message' => 'Registration successful'];
        } else {
            return ['success' => false, 'message' => 'Registration failed'];
        }
    }

    /**
     * Login user
     */
    public function login($email, $password) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }

        // Get user
        $this->db->query('SELECT * FROM users WHERE email = :email AND is_active = 1');
        $this->db->bind(':email', $email);
        $user = $this->db->single();

        if (!$user) {
            return ['success' => false, 'message' => 'User not found or inactive'];
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => 'Incorrect password'];
        }

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['login_time'] = time();

        // Update last login
        $this->db->query('UPDATE users SET last_login = NOW() WHERE id = :id');
        $this->db->bind(':id', $user['id']);
        $this->db->execute();

        return ['success' => true, 'message' => 'Login successful'];
    }

    /**
     * Logout user
     */
    public function logout() {
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    /**
     * Get current user ID
     */
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current user data
     */
    public function getUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $this->db->query('SELECT * FROM users WHERE id = :id');
        $this->db->bind(':id', $this->getUserId());
        return $this->db->single();
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
     * Verify session timeout
     */
    public function verifySession() {
        if (!$this->isLoggedIn()) {
            return false;
        }

        if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }

        $_SESSION['login_time'] = time();
        return true;
    }
}
