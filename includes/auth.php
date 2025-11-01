<?php
// includes/auth.php
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;
    private $config;
    private $rateLimit = [
        'attempts' => 5,
        'timeframe' => 15 * 60, // 15 minutes
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->config = require __DIR__ . '/../config/config.php';
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'name' => $this->config['security']['session_name'],
                'cookie_lifetime' => $this->config['security']['session_lifetime'],
                'cookie_secure' => isset($_SERVER['HTTPS']),
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax'
            ]);
        }
    }

    public function login($email, $password) {
        // Check rate limiting
        if ($this->isRateLimited()) {
            return ['success' => false, 'message' => 'Too many login attempts. Please try again later.'];
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['is_locked']) {
                    return ['success' => false, 'message' => 'Account is locked. Please contact support.'];
                }

                // Reset failed login attempts on successful login
                $this->resetFailedAttempts($user['id']);

                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $this->getUserRole($user['id']);
                $_SESSION['last_activity'] = time();
                
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);

                return ['success' => true, 'user' => $user];
            }

            // Log failed attempt
            $this->logFailedAttempt($email);
            return ['success' => false, 'message' => 'Invalid email or password.'];
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred. Please try again.'];
        }
    }

    public function register($userData) {
        // Validate input data
        $errors = $this->validateRegistration($userData);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $this->db->beginTransaction();

            // Insert user
            $stmt = $this->db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, verification_token)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $hashedPassword = password_hash($userData['password'], PASSWORD_BCRYPT, $this->config['security']['password_options']);
            $verificationToken = bin2hex(random_bytes(32));
            
            $stmt->execute([
                $userData['username'],
                $userData['email'],
                $hashedPassword,
                $userData['full_name'] ?? '',
                $verificationToken
            ]);
            
            $userId = $this->db->lastInsertId();
            
            // Assign default role
            $this->assignRole($userId, 'user');
            
            $this->db->commit();
            
            // Send verification email (implement this function)
            // $this->sendVerificationEmail($userData['email'], $verificationToken);
            
            return ['success' => true, 'user_id' => $userId];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Registration error: " . $e->getMessage());
            
            if ($e->errorInfo[1] == 1062) {
                return ['success' => false, 'message' => 'Email or username already exists.'];
            }
            
            return ['success' => false, 'message' => 'An error occurred during registration.'];
        }
    }

    public function logout() {
        // Unset all session variables
        $_SESSION = [];
        
        // Delete the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
        
        return true;
    }

    public function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['last_activity'])) {
            return false;
        }
        
        // Check if session has expired
        $idleTimeout = $this->config['security']['session_lifetime'];
        if (time() - $_SESSION['last_activity'] > $idleTimeout) {
            $this->logout();
            return false;
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
        
        return true;
    }

    public function hasRole($role) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        if (is_array($role)) {
            return in_array($_SESSION['user_role'], $role);
        }
        
        return $_SESSION['user_role'] === $role;
    }

    public function requireRole($role) {
        if (!$this->isLoggedIn()) {
            $this->redirectToLogin();
        }
        
        if (is_array($role)) {
            if (!in_array($_SESSION['user_role'], $role)) {
                $this->accessDenied();
            }
        } elseif ($_SESSION['user_role'] !== $role) {
            $this->accessDenied();
        }
    }

    public function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function validateCSRFToken($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // Helper methods
    private function isRateLimited() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $key = 'login_attempts_' . md5($ip);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'timestamp' => time()
            ];
        }
        
        $attempts = &$_SESSION[$key];
        
        // Reset attempts if timeframe has passed
        if (time() - $attempts['timestamp'] > $this->rateLimit['timeframe']) {
            $attempts = [
                'attempts' => 0,
                'timestamp' => time()
            ];
        }
        
        // Increment attempts
        $attempts['attempts']++;
        $attempts['timestamp'] = time();
        
        return $attempts['attempts'] > $this->rateLimit['attempts'];
    }

    private function logFailedAttempt($email) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (email, ip_address, user_agent, success)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$email, $ip, $userAgent]);
            
            // Check if we need to lock the account
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as attempts 
                FROM login_attempts 
                WHERE email = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$email]);
            $result = $stmt->fetch();
            
            if ($result && $result['attempts'] >= $this->rateLimit['attempts']) {
                $this->lockAccount($email);
            }
            
        } catch (PDOException $e) {
            error_log("Failed to log login attempt: " . $e->getMessage());
        }
    }

    private function resetFailedAttempts($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET failed_login_attempts = 0, is_locked = 0, locked_until = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("Failed to reset login attempts: " . $e->getMessage());
        }
    }

    private function lockAccount($email) {
        try {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET is_locked = 1, locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR)
                WHERE email = ?
            ");
            $stmt->execute([$email]);
        } catch (PDOException $e) {
            error_log("Failed to lock account: " . $e->getMessage());
        }
    }

    private function getUserRole($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT role FROM user_roles 
                WHERE user_id = ? 
                ORDER BY FIELD(role, 'admin', 'moderator', 'reporter', 'user') 
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            
            return $result ? $result['role'] : 'user';
        } catch (PDOException $e) {
            error_log("Failed to get user role: " . $e->getMessage());
            return 'user';
        }
    }

    private function assignRole($userId, $role) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_roles (user_id, role) 
                VALUES (?, ?)
            ");
            return $stmt->execute([$userId, $role]);
        } catch (PDOException $e) {
            error_log("Failed to assign role: " . $e->getMessage());
            return false;
        }
    }

    private function validateRegistration($data) {
        $errors = [];
        
        // Validate username
        if (empty($data['username'])) {
            $errors['username'] = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $data['username'])) {
            $errors['username'] = 'Username must be 3-30 characters and contain only letters, numbers, and underscores';
        }
        
        // Validate email
        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }
        
        // Validate password
        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters long';
        } elseif ($data['password'] !== ($data['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        
        return $errors;
    }

    private function redirectToLogin() {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: /login.php');
        exit();
    }

    private function accessDenied() {
        http_response_code(403);
        include __DIR__ . '/../templates/errors/403.php';
        exit();
    }
}

// Create a global instance for backward compatibility
$auth = new Auth();

// Helper functions for backward compatibility
function isLoggedIn() {
    global $auth;
    return $auth->isLoggedIn();
}

function isAdmin() {
    global $auth;
    return $auth->isLoggedIn() && $auth->hasRole('admin');
}

function requireLogin() {
    global $auth;
    if (!$auth->isLoggedIn()) {
        $auth->redirectToLogin();
    }
}

function requireAdmin() {
    global $auth;
    $auth->requireRole('admin');
}

function generateCSRFToken() {
    global $auth;
    return $auth->generateCSRFToken();
}

function validateCSRFToken($token) {
    global $auth;
    return $auth->validateCSRFToken($token);
}