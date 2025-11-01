<?php
// login.php
require_once 'bootstrap.php';
require_once 'includes/auth.php';
require_once 'includes/CsrfProtection.php';

// Set security headers
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; object-src \'none\'');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!CsrfProtection::verifyRequest('login_form')) {
        $error = 'Invalid or expired form token. Please try again.';
        http_response_code(403);
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
    
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            $db = getDBConnection();
            $stmt = $db->prepare("SELECT id, username, password_hash, is_admin FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
        
            if ($user && password_verify($password, $user['password_hash'])) {
                // Password is correct, start a new session
                session_regenerate_id();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = (bool)$user['is_admin'];
            
                // Redirect to home page
                $_SESSION['flash_message'] = 'You have been logged in successfully.';
                $_SESSION['flash_type'] = 'success';
                header('Location: /');
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .auth-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 30px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .auth-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .auth-logo i {
            font-size: 3rem;
            color: #0d6efd;
        }
        .auth-title {
            text-align: center;
            margin-bottom: 30px;
            font-weight: 600;
        }
        .form-floating {
            margin-bottom: 1rem;
        }
        .btn-auth {
            width: 100%;
            padding: 12px;
            font-weight: 600;
        }
        .auth-footer {
            text-align: center;
            margin-top: 20px;
        }
        .auth-footer a {
            color: #0d6efd;
            text-decoration: none;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="auth-container">
            <div class="auth-logo">
                <i class="fas fa-map-marked-alt"></i>
                <h1 class="h3 mb-0">Local Places</h1>
            </div>
            
            <h2 class="auth-title">Sign In</h2>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="/login.php">
                <?= CsrfProtection::generateHiddenField('login_form') ?>
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="username" name="username" 
                           placeholder="Username" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <label for="username">Username</label>
                </div>
                
                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="password" 
                           name="password" placeholder="Password" required>
                    <label for="password">Password</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-auth mb-3">
                    <i class="fas fa-sign-in-alt me-2"></i> Sign In
                </button>
                
                <div class="auth-footer">
                    Don't have an account? <a href="/register.php">Sign up</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>