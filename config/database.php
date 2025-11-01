<?php
// config/database.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/DatabaseHelper.php';
require_once __DIR__ . '/../includes/EnvLoader.php';

class Database {
    private static $instance = null;
    private $connection;
    private $dbHelper;
    private $config;

    private function __construct() {
        $this->config = require __DIR__ . '/config.php';
        $this->connect();
        $this->dbHelper = new DatabaseHelper($this->connection);
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get the database helper instance
     * @return DatabaseHelper
     */
    public function getHelper() {
        return $this->dbHelper;
    }

    private function connect() {
        $dbConfig = $this->config['db'];
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['charset']} COLLATE {$dbConfig['collation']}"
        ];

        try {
            $this->connection = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        } catch (PDOException $e) {
            // Log the error
            error_log("Database Connection Error: " . $e->getMessage());
            
            // Show user-friendly message in production
            if ($this->config['app']['debug']) {
                die("Database connection failed: " . $e->getMessage());
            } else {
                die("Unable to connect to the database. Please try again later.");
            }
        }
    }

    public function getConnection() {
        // Check if connection is still alive
        try {
            $this->connection->query('SELECT 1');
            return $this->connection;
        } catch (PDOException $e) {
            // Log the connection issue
            error_log("Database connection lost, attempting to reconnect: " . $e->getMessage());
            
            try {
                $this->connect(); // Attempt to reconnect
                return $this->connection;
            } catch (PDOException $e) {
                // Log the reconnection failure
                error_log("Database reconnection failed: " . $e->getMessage());
                
                if ($this->config['app']['debug']) {
                    throw new PDOException("Database connection failed: " . $e->getMessage());
                } else {
                    throw new PDOException("Unable to connect to the database. Please try again later.");
                }
            }
        }
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollBack() {
        return $this->connection->rollBack();
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    // Prevent cloning of the instance
    private function __clone() {}

    // Prevent unserializing of the instance
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Helper function for backward compatibility
function getDBConnection() {
    return Database::getInstance()->getConnection();
}