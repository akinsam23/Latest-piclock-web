<?php
// Test database connection
require_once 'config/database.php';

header('Content-Type: text/plain');
echo "=== Database Connection Test ===\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Test connection
    $stmt = $db->query('SELECT DATABASE() as db, USER() as user, VERSION() as version');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "✅ Connected to database successfully!\n";
    echo "Database: " . ($result['db'] ?: 'None selected') . "\n";
    echo "User: " . $result['user'] . "\n";
    echo "MySQL Version: " . $result['version'] . "\n\n";
    
    // List tables
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "✅ Found " . count($tables) . " tables:\n";
        foreach ($tables as $table) {
            echo "- $table\n";
        }
    } else {
        echo "⚠️  No tables found in the database.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
    
    // Show connection details for debugging
    $config = require 'config/config.php';
    echo "\nConnection details:\n";
    echo "Host: " . $config['db']['host'] . "\n";
    echo "Database: " . $config['db']['database'] . "\n";
    echo "Username: " . $config['db']['username'] . "\n";
    echo "Password: " . ($config['db']['password'] ? '*****' : '[empty]') . "\n";
    
    // Check if MySQL is running
    echo "\nTroubleshooting tips:\n";
    echo "1. Make sure MySQL is running in XAMPP\n";
    echo "2. Check if the database 'piclock' exists\n";
    echo "3. Verify the username and password in .env\n";
    echo "4. Try connecting with MySQL Workbench or phpMyAdmin\n";
}
