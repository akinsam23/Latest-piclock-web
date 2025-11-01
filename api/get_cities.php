<?php
// get_cities.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Enable CORS if needed
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get state_id from query string (required)
    if (empty($_GET['state_id'])) {
        throw new Exception('State ID is required');
    }
    
    $stateId = $_GET['state_id'];
    $search = $_GET['search'] ?? '';
    
    $query = "SELECT id, name, latitude, longitude 
              FROM cities 
              WHERE state_id = :state_id";
    
    $params = [':state_id' => $stateId];
    
    // Add search filter if provided
    if (!empty($search)) {
        $query .= " AND name LIKE :search";
        $params[':search'] = "%$search%";
    }
    
    $query .= " ORDER BY name ASC";
    
    // Add limit if not searching (for performance with large datasets)
    if (empty($search)) {
        $query .= " LIMIT 100";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $cities
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    error_log("Get Cities Error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
