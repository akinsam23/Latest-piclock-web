<?php
// get_states.php
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
    
    // Get country_id from query string, default to Nigeria (NG)
    $countryId = $_GET['country_id'] ?? null;
    $countryCode = $_GET['country_code'] ?? 'NG'; // Default to Nigeria
    
    $query = "SELECT s.id, s.name, s.state_code, s.latitude, s.longitude 
              FROM states s";
    
    $params = [];
    
    if ($countryId) {
        $query .= " WHERE s.country_id = :country_id";
        $params[':country_id'] = $countryId;
    } elseif ($countryCode) {
        $query .= " JOIN countries c ON s.country_id = c.id 
                   WHERE c.iso2 = :country_code OR c.iso3 = :country_code";
        $params[':country_code'] = strtoupper($countryCode);
    }
    
    $query .= " ORDER BY s.name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $states
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    error_log("Get States Error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
