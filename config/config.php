<?php
// Application configuration
return [
    'app' => [
        'name' => 'LocalPulse',
        'url' => 'http://localhost', // Update this in production
        'timezone' => 'UTC',
        'debug' => true, // Set to false in production
    ],
    'db' => [
        'host' => 'localhost',
        'database' => 'localpulse',
        'username' => 'localpulse_user',
        'password' => 'secure_password_here', // Change this in production
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],
    'mail' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'noreply@example.com',
        'password' => 'email_password_here',
        'from' => 'noreply@example.com',
        'from_name' => 'LocalPulse',
    ],
    'storage' => [
        'path' => __DIR__ . '/../storage/uploads',
        'url' => '/storage/uploads',
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif'],
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
        'session_name' => 'localpulse_session',
        'session_lifetime' => 60 * 60 * 24 * 30, // 30 days
    ],
    'cache' => [
        'enabled' => true,
        'driver' => 'file', // file, redis, memcached
        'path' => __DIR__ . '/../storage/cache',
        'prefix' => 'localpulse_',
    ],
];
