<?php
// Load environment variables
require_once __DIR__ . '/includes/EnvLoader.php';
$envLoader = new EnvLoader(__DIR__);
$envLoader->load();

// Set error reporting based on environment
if (getenv('APP_DEBUG') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Set default timezone
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// Start session with secure settings
$sessionName = getenv('SESSION_NAME') ?: 'piclock_session';
$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
$httpOnly = true;
$sameSite = 'Lax';

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => (int)(getenv('SESSION_LIFETIME') ?: 86400),
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $secure,
    'httponly' => $httpOnly,
    'samesite' => $sameSite
]);

// Start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize CSRF protection
require_once __DIR__ . '/includes/CsrfProtection.php';
CsrfProtection::init();

// Handle CORS if needed
if (getenv('CORS_ENABLED') === 'true') {
    require_once __DIR__ . '/includes/Cors.php';
    Cors::handle();
}

// Load database configuration
require_once __DIR__ . '/config/database.php';

// Set up error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log the error
    error_log("Error [$errno] $errstr in $errfile on line $errline");
    
    // Don't execute PHP internal error handler
    return true;
});

// Set exception handler
set_exception_handler(function($exception) {
    // Log the exception
    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    
    // Return 500 error in production, or show error details in development
    if (getenv('APP_DEBUG') === 'true') {
        header('HTTP/1.1 500 Internal Server Error');
        echo "<h1>Error</h1>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . " (Line: " . $exception->getLine() . ")</p>";
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'An error occurred. Please try again later.';
    }
    exit;
});

// Set shutdown function to handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        // Log the error
        error_log("Fatal error: {$error['message']} in {$error['file']} on line {$error['line']}");
        
        // Return 500 error in production, or show error details in development
        if (getenv('APP_DEBUG') === 'true') {
            header('HTTP/1.1 500 Internal Server Error');
            echo "<h1>Fatal Error</h1>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . " (Line: " . $error['line'] . ")</p>";
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'A fatal error occurred. Please try again later.';
        }
        exit;
    }
});
