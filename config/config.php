<?php
// Application configuration
return [
    'app' => [
        'name' => 'LocalPulse',
        'url' => 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'timezone' => 'UTC',
        'debug' => $_ENV['APP_DEBUG'] ?? false,
        'env' => $_ENV['APP_ENV'] ?? 'production',
    ],
    
    // Database configuration
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'database' => $_ENV['DB_DATABASE'] ?? 'piclock',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    
    // Email configuration
    'mail' => [
        'host' => $_ENV['MAIL_HOST'] ?? 'smtp.mailtrap.io',
        'port' => (int) ($_ENV['MAIL_PORT'] ?? 2525),
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $_ENV['MAIL_PASSWORD'] ?? '',
        'from' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@example.com',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'LocalPulse',
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
    ],
    
    // Storage configuration
    'storage' => [
        'path' => __DIR__ . '/../storage/uploads',
        'url' => '/storage/uploads',
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'max_size' => 5 * 1024 * 1024, // 5MB
        'image_sizes' => [
            'thumbnail' => [150, 150],
            'medium' => [800, 600],
            'large' => [1200, 900],
        ],
    ],
    'security' => [
        'jwt_secret' => 'your_jwt_secret_here', // Change this in production
        'password_algo' => PASSWORD_BCRYPT,
        'password_options' => ['cost' => 12],
        'csrf_token_name' => 'csrf_token',
        'session_name' => 'piclock_session',
        'session_lifetime' => 60 * 60 * 24 * 30, // 30 days
    ],
    'cache' => [
        'enabled' => true,
        'driver' => 'file', // file, redis, memcached
        'path' => __DIR__ . '/../storage/cache',
        'prefix' => 'localpulse_',
    ],
];
