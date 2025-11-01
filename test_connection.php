<?php
// Test database connection and environment
require_once 'bootstrap.php';

header('Content-Type: text/plain');
echo "=== PiClock System Check ===\n\n";

// Check PHP version
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP Extensions: " . implode(', ', get_loaded_extensions()) . "\n\n";

// Check environment variables
echo "=== Environment Variables ===\n";
echo "APP_ENV: " . getenv('APP_ENV') . "\n";
echo "APP_DEBUG: " . getenv('APP_DEBUG') . "\n";
echo "DB_HOST: " . getenv('DB_HOST') . "\n";
echo "DB_DATABASE: " . getenv('DB_DATABASE') . "\n\n";

// Test database connection
try {
    $db = Database::getInstance()->getConnection();
    echo "✅ Database connection successful!\n";
    
    // Test a simple query
    $stmt = $db->query('SELECT VERSION() as version');
    $version = $stmt->fetch();
    echo "✅ Database version: " . $version['version'] . "\n";
    
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
}

// Check file permissions
echo "\n=== File Permissions ===\n";
$directories = [
    'storage' => 'writable',
    'storage/uploads' => 'writable',
    'config' => 'readable'
];

foreach ($directories as $dir => $requirement) {
    $path = __DIR__ . '/' . $dir;
    if (!file_exists($path)) {
        echo "❌ Directory '$dir' does not exist\n";
        continue;
    }
    
    $isWritable = is_writable($path);
    $status = $isWritable ? '✅' : '❌';
    echo "$status $dir: " . ($isWritable ? 'Writable' : 'Not Writable') . "\n";
}

// Check for required PHP extensions
$requiredExtensions = ['pdo_mysql', 'gd', 'mbstring', 'json'];
echo "\n=== Required PHP Extensions ===\n";

foreach ($requiredExtensions as $ext) {
    $loaded = extension_loaded($ext);
    $status = $loaded ? '✅' : '❌';
    echo "$status $ext: " . ($loaded ? 'Loaded' : 'Not Found') . "\n";
}

// Check security settings
echo "\n=== Security Checks ===\n";
$securityChecks = [
    'display_errors' => !(bool)ini_get('display_errors'),
    'expose_php' => !(bool)ini_get('expose_php'),
    'session.cookie_httponly' => (bool)ini_get('session.cookie_httponly'),
    'session.cookie_secure' => (bool)ini_get('session.cookie_secure'),
    'session.use_only_cookies' => (bool)ini_get('session.use_only_cookies')
];

foreach ($securityChecks as $setting => $expected) {
    $current = ini_get($setting);
    $status = $expected ? '✅' : '⚠️';
    echo "$status $setting: $current" . ($expected ? ' (Secure)' : ' (Warning)') . "\n";
}

echo "\n=== System Check Complete ===\n";
