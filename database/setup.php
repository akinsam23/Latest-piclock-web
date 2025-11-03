<?php
/**
 * Database Setup Script
 * Run this once to create the database and tables
 */

// Load configuration
require_once __DIR__ . '/../config/database.php';

// Get database connection without selecting a database first
$config = require __DIR__ . '/../config/config.php';
$dbConfig = $config['db'];

try {
    // Connect to MySQL server without selecting a database
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Create database if not exists
    $dbName = $dbConfig['database'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    echo "✅ Database '$dbName' created successfully\n";
    
    // Select the database
    $pdo->exec("USE `$dbName`");
    
    // Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create places table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `places` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `place_name` VARCHAR(255) NOT NULL,
        `description` TEXT,
        `category` VARCHAR(50) NOT NULL,
        `latitude` DECIMAL(10, 8) NOT NULL,
        `longitude` DECIMAL(11, 8) NOT NULL,
        `address` TEXT,
        `city_region` VARCHAR(100),
        `image_url` VARCHAR(255),
        `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create sessions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sessions` (
        `session_id` VARCHAR(255) PRIMARY KEY,
        `user_id` INT UNSIGNED,
        `ip_address` VARCHAR(45),
        `user_agent` TEXT,
        `payload` TEXT NOT NULL,
        `last_activity` INT NOT NULL,
        `expires_at` INT NOT NULL,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create password_resets table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
        `email` VARCHAR(100) NOT NULL,
        `token` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create an admin user (password: admin123 - change this in production!)
    $passwordHash = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("INSERT IGNORE INTO `users` (username, email, password_hash, is_admin) VALUES (?, ?, ?, 1)");
    $stmt->execute(['admin', 'admin@example.com', $passwordHash]);
    
    echo "✅ Database tables created successfully\n";
    echo "✅ Admin user created (username: admin, password: admin123)\n";
    echo "\n🎉 Database setup completed successfully!\n";
    
} catch (PDOException $e) {
    die("❌ Database setup failed: " . $e->getMessage() . "\n");
}
