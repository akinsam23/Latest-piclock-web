<?php
class DatabaseHelper {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Execute a SELECT query with parameters and return all results
     * @param string $sql SQL query with placeholders
     * @param array $params Associative array of parameters
     * @return array Array of results
     */
    public function fetchAll($sql, $params = []) {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Execute a SELECT query with parameters and return a single row
     * @param string $sql SQL query with placeholders
     * @param array $params Associative array of parameters
     * @return array|null Single row or null if not found
     */
    public function fetchOne($sql, $params = []) {
        $result = $this->fetchAll($sql, $params);
        return $result[0] ?? null;
    }
    
    /**
     * Execute an INSERT, UPDATE, or DELETE query with parameters
     * @param string $sql SQL query with placeholders
     * @param array $params Associative array of parameters
     * @return int|bool Number of affected rows or false on failure
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Database Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the last inserted ID
     * @return string Last inserted ID
     */
    public function lastInsertId() {
        return $this->db->lastInsertId();
    }
    
    /**
     * Begin a transaction
     * @return bool True on success, false on failure
     */
    public function beginTransaction() {
        return $this->db->beginTransaction();
    }
    
    /**
     * Commit a transaction
     * @return bool True on success, false on failure
     */
    public function commit() {
        return $this->db->commit();
    }
    
    /**
     * Roll back a transaction
     * @return bool True on success, false on failure
     */
    public function rollBack() {
        return $this->db->rollBack();
    }
}
