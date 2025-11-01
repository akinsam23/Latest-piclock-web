<?php
// register.php
require_once 'config/database.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Basic validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDBConnection();
        
        // Check if username already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Username already taken. Please choose another.';
        } else {
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Email already registered. Please use another email or <a href="/login.php">login</a>.';
            } else {
                // Create new user
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                
                try {
                    $stmt->execute([$username, $email, $password_hash]);
                    
                    // Log the user in automatically
                    $user_id = $db->lastInsertId();
                    
                    session_regenerate_id();
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['is_admin'] = false;
                    
                    // Set success message
                    $_SESSION['flash_message'] = 'Registration successful! Welcome to Local Places.';
                    $_SESSION['flash_type'] = 'success';
                    
                    // Redirect to home page
                    header('Location: /');
                    exit();
                    
                } catch (PDOException $e) {
                    $error = 'An error occurred during registration. Please try again.';
                    error_log("Registration error: " . $e->getMessage());
                }
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
    <title>Register - Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .auth-container {
            max-width: 500px;
            margin: 50px auto;
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
        .password-strength {
            height: 5px;
            margin-top: 5px;
            margin-bottom: 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .strength-0 { width: 0%; background-color: #dc3545; }
        .strength-1 { width: 25%; background-color: #ff6b6b; }
        .strength-2 { width: 50%; background-color: #ffd166; }
        .strength-3 { width: 75%; background-color: #51cf66; }
        .strength-4 { width: 100%; background-color: #20c997; }
        .password-requirements {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .requirement {
            margin-bottom: 5px;
        }
        .requirement.valid {
            color: #198754;
        }
        .requirement.valid::before {
            content: "✓ ";
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
            
            <h2 class="auth-title">Create an Account</h2>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <form method="POST" action="/register.php" id="registrationForm">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="username" name="username" 
                           placeholder="Username" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <label for="username">Username</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="Email" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <label for="email">Email</label>
                </div>
                
                <div class="form-floating mb-1">
                    <input type="password" class="form-control" id="password" 
                           name="password" placeholder="Password" required
                           oninput="checkPasswordStrength(this.value)">
                    <label for="password">Password</label>
                </div>
                
                <div class="password-strength" id="passwordStrength"></div>
                
                <div class="password-requirements" id="passwordRequirements">
                    <div class="requirement" id="reqLength">At least 8 characters</div>
                    <div class="requirement" id="reqUppercase">At least one uppercase letter</div>
                    <div class="requirement" id="reqLowercase">At least one lowercase letter</div>
                    <div class="requirement" id="reqNumber">At least one number</div>
                    <div class="requirement" id="reqSpecial">At least one special character</div>
                </div>
                
                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="confirm_password" 
                           name="confirm_password" placeholder="Confirm Password" required>
                    <label for="confirm_password">Confirm Password</label>
                    <div class="invalid-feedback" id="passwordMatchFeedback">
                        Passwords do not match.
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-auth mb-3">
                    <i class="fas fa-user-plus me-2"></i> Create Account
                </button>
                
                <div class="auth-footer">
                    Already have an account? <a href="/login.php">Sign in</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
            
            // Update requirement indicators
            document.getElementById('reqLength').classList.toggle('valid', requirements.length);
            document.getElementById('reqUppercase').classList.toggle('valid', requirements.uppercase);
            document.getElementById('reqLowercase').classList.toggle('valid', requirements.lowercase);
            document.getElementById('reqNumber').classList.toggle('valid', requirements.number);
            document.getElementById('reqSpecial').classList.toggle('valid', requirements.special);
            
            // Calculate strength score
            const strength = Object.values(requirements).filter(Boolean).length;
            strengthBar.className = 'password-strength strength-' + (strength - 1);
            
            return strength;
        }
        
        // Validate password match on form submission
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const feedback = document.getElementById('passwordMatchFeedback');
            
            if (password !== confirmPassword) {
                e.preventDefault();
                document.getElementById('confirm_password').classList.add('is-invalid');
                feedback.style.display = 'block';
            } else {
                document.getElementById('confirm_password').classList.remove('is-invalid');
                feedback.style.display = 'none';
            }
        });
        
        // Real-time password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const feedback = document.getElementById('passwordMatchFeedback');
            
            if (password !== confirmPassword) {
                this.classList.add('is-invalid');
                feedback.style.display = 'block';
            } else {
                this.classList.remove('is-invalid');
                feedback.style.display = 'none';
            }
        });
    </script>
</body>
</html>