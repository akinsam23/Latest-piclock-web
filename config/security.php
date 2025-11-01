<?php
return [
    // Session configuration
    'session' => [
        'name' => 'piclock_session',
        'lifetime' => 86400, // 24 hours
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ],
    
    // CSRF protection
    'csrf' => [
        'key' => 'csrf_token',
        'expire' => 3600, // 1 hour
    ],
    
    // Rate limiting
    'rate_limit' => [
        'enabled' => true,
        'attempts' => 5, // Number of attempts
        'timeframe' => 900, // 15 minutes in seconds
        'storage' => 'session', // Can be 'session' or 'database'
    ],
    
    // CORS configuration
    'cors' => [
        'enabled' => true,
        'allowed_origins' => [
            // Add your allowed origins here, e.g.,
            // 'https://yourdomain.com',
            // 'http://localhost:3000',
        ],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'exposed_headers' => [],
        'max_age' => 86400, // 24 hours
        'credentials' => true,
    ],
    
    // Security headers
    'headers' => [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => [
            'default-src' => "'self'",
            'script-src' => [
                "'self'",
                "'unsafe-inline'",
                "'unsafe-eval'",
                'https://unpkg.com',
                'https://cdn.jsdelivr.net',
            ],
            'style-src' => [
                "'self'",
                "'unsafe-inline'",
                'https://cdn.jsdelivr.net',
                'https://unpkg.com',
            ],
            'img-src' => [
                "'self'",
                'data:',
                'https:',
                'http:',
            ],
            'font-src' => [
                "'self'",
                'data:',
                'https://cdn.jsdelivr.net',
                'https://unpkg.com',
            ],
            'connect-src' => [
                "'self'",
                'https://api.yourdomain.com',
            ],
        ],
    ],
    
    // Password hashing options
    'password' => [
        'algo' => PASSWORD_BCRYPT,
        'options' => [
            'cost' => 12,
        ],
    ],
    
    // File uploads
    'uploads' => [
        'max_file_size' => 5 * 1024 * 1024, // 5MB
        'allowed_mime_types' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ],
        'upload_dir' => 'uploads/',
    ],
];
