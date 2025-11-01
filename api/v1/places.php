<?php
/**
 * Places API Endpoint
 * 
 * This endpoint handles CRUD operations for places
 * Requires authentication via API key or session
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/DatabaseHelper.php';
require_once __DIR__ . '/../../includes/InputValidator.php';

// Set JSON content type
header('Content-Type: application/json');

// Initialize rate limiting (100 requests per hour per IP)
$rateLimiter = new RateLimiter(
    $_SERVER['REMOTE_ADDR'],
    100,  // Max requests
    3600  // Per hour
);

// Apply rate limiting
if ($rateLimiter->isRateLimited()) {
    header('HTTP/1.1 429 Too Many Requests');
    header('Retry-After: ' . $rateLimiter->getResetTime() - time());
    echo json_encode([
        'error' => 'Too many requests',
        'retry_after' => $rateLimiter->getResetTime() - time()
    ]);
    exit;
}

// Add rate limit headers to response
foreach ($rateLimiter->getRateLimitHeaders() as $header => $value) {
    if ($value !== null) {
        header("$header: $value");
    }
}

// Get database connection
$db = Database::getInstance()->getConnection();
$dbHelper = Database::getInstance()->getHelper();

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];
$response = [];
$statusCode = 200;

try {
    switch ($method) {
        case 'GET':
            // Get place(s)
            $placeId = $_GET['id'] ?? null;
            
            if ($placeId) {
                // Get single place
                $place = $dbHelper->fetchOne(
                    "SELECT * FROM places WHERE id = :id AND status = 'approved'",
                    ['id' => $placeId]
                );
                
                if ($place) {
                    $response = $place;
                } else {
                    $statusCode = 404;
                    $response = ['error' => 'Place not found'];
                }
            } else {
                // Get all places with pagination
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = min(20, max(1, (int)($_GET['per_page'] ?? 10)));
                $offset = ($page - 1) * $perPage;
                
                // Build query
                $where = ["status = 'approved'"];
                $params = [];
                
                // Add filters
                if (!empty($_GET['category'])) {
                    $where[] = 'category = :category';
                    $params['category'] = $_GET['category'];
                }
                
                if (!empty($_GET['city_region'])) {
                    $where[] = 'city_region = :city_region';
                    $params['city_region'] = $_GET['city_region'];
                }
                
                $whereClause = implode(' AND ', $where);
                
                // Get total count
                $total = $dbHelper->fetchOne(
                    "SELECT COUNT(*) as count FROM places WHERE $whereClause",
                    $params
                )['count'];
                
                // Get paginated results
                $places = $dbHelper->fetchAll(
                    "SELECT * FROM places 
                     WHERE $whereClause 
                     ORDER BY created_at DESC 
                     LIMIT :limit OFFSET :offset",
                    array_merge($params, [
                        'limit' => $perPage,
                        'offset' => $offset
                    ])
                );
                
                $response = [
                    'data' => $places,
                    'pagination' => [
                        'total' => (int)$total,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => ceil($total / $perPage)
                    ]
                ];
            }
            break;
            
        case 'POST':
            // Create new place (requires authentication)
            if (!isLoggedIn()) {
                $statusCode = 401;
                $response = ['error' => 'Authentication required'];
                break;
            }
            
            // Get and validate input
            $input = json_decode(file_get_contents('php://input'), true);
            
            $validator = new InputValidator($input);
            $validator
                ->required('place_name', 'Place name')
                ->length('place_name', 'Place name', 3, 255)
                ->required('description', 'Description')
                ->length('description', 'Description', 10, 2000)
                ->required('category', 'Category')
                ->required('latitude', 'Latitude')
                ->numeric('latitude', 'Latitude', -90, 90)
                ->required('longitude', 'Longitude')
                ->numeric('longitude', 'Longitude', -180, 180)
                ->required('city_region', 'City/Region');
            
            if (!$validator->isValid()) {
                $statusCode = 400;
                $response = [
                    'error' => 'Validation failed',
                    'errors' => $validator->getErrors()
                ];
                break;
            }
            
            // Sanitize input
            $placeData = [
                'place_name' => InputValidator::sanitizeString($input['place_name']),
                'description' => InputValidator::sanitizeString($input['description']),
                'category' => InputValidator::sanitizeString($input['category']),
                'latitude' => InputValidator::sanitizeFloat($input['latitude']),
                'longitude' => InputValidator::sanitizeFloat($input['longitude']),
                'city_region' => InputValidator::sanitizeString($input['city_region']),
                'user_id' => $_SESSION['user_id'],
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Handle image URL if provided
            if (!empty($input['image_url'])) {
                $placeData['image_url'] = InputValidator::sanitizeUrl($input['image_url']);
            }
            
            // Insert into database
            $placeId = $dbHelper->execute(
                "INSERT INTO places 
                 (place_name, description, category, latitude, longitude, 
                  city_region, image_url, user_id, status, created_at, updated_at) 
                 VALUES 
                 (:place_name, :description, :category, :latitude, :longitude, 
                  :city_region, :image_url, :user_id, :status, :created_at, :updated_at)",
                $placeData
            );
            
            if ($placeId) {
                $statusCode = 201;
                $response = [
                    'message' => 'Place created successfully',
                    'place_id' => $placeId
                ];
            } else {
                $statusCode = 500;
                $response = ['error' => 'Failed to create place'];
            }
            break;
            
        case 'PUT':
        case 'PATCH':
            // Update place (requires authentication and ownership)
            if (!isLoggedIn()) {
                $statusCode = 401;
                $response = ['error' => 'Authentication required'];
                break;
            }
            
            $placeId = $_GET['id'] ?? null;
            if (!$placeId) {
                $statusCode = 400;
                $response = ['error' => 'Place ID is required'];
                break;
            }
            
            // Check if place exists and user has permission
            $place = $dbHelper->fetchOne(
                "SELECT * FROM places WHERE id = :id",
                ['id' => $placeId]
            );
            
            if (!$place) {
                $statusCode = 404;
                $response = ['error' => 'Place not found'];
                break;
            }
            
            if ($place['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
                $statusCode = 403;
                $response = ['error' => 'Permission denied'];
                break;
            }
            
            // Get and validate input
            $input = json_decode(file_get_contents('php://input'), true);
            
            $validator = new InputValidator($input);
            
            // Only validate fields that are being updated
            if (isset($input['place_name'])) {
                $validator->length('place_name', 'Place name', 3, 255);
            }
            
            if (isset($input['description'])) {
                $validator->length('description', 'Description', 10, 2000);
            }
            
            if (isset($input['latitude'])) {
                $validator->numeric('latitude', 'Latitude', -90, 90);
            }
            
            if (isset($input['longitude'])) {
                $validator->numeric('longitude', 'Longitude', -180, 180);
            }
            
            if (!$validator->isValid()) {
                $statusCode = 400;
                $response = [
                    'error' => 'Validation failed',
                    'errors' => $validator->getErrors()
                ];
                break;
            }
            
            // Prepare update data
            $updateData = [
                'id' => $placeId,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Only update fields that were provided
            $allowedFields = [
                'place_name', 'description', 'category', 
                'latitude', 'longitude', 'city_region', 'image_url', 'status'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateData[$field] = $input[$field];
                }
            }
            
            // Build update query
            $setClauses = [];
            foreach (array_keys($updateData) as $field) {
                if ($field !== 'id') {
                    $setClauses[] = "$field = :$field";
                }
            }
            
            $setClause = implode(', ', $setClauses);
            
            // Update database
            $result = $dbHelper->execute(
                "UPDATE places SET $setClause WHERE id = :id",
                $updateData
            );
            
            if ($result !== false) {
                $response = [
                    'message' => 'Place updated successfully',
                    'place_id' => $placeId
                ];
            } else {
                $statusCode = 500;
                $response = ['error' => 'Failed to update place'];
            }
            break;
            
        case 'DELETE':
            // Delete place (requires authentication and ownership)
            if (!isLoggedIn()) {
                $statusCode = 401;
                $response = ['error' => 'Authentication required'];
                break;
            }
            
            $placeId = $_GET['id'] ?? null;
            if (!$placeId) {
                $statusCode = 400;
                $response = ['error' => 'Place ID is required'];
                break;
            }
            
            // Check if place exists and user has permission
            $place = $dbHelper->fetchOne(
                "SELECT * FROM places WHERE id = :id",
                ['id' => $placeId]
            );
            
            if (!$place) {
                $statusCode = 404;
                $response = ['error' => 'Place not found'];
                break;
            }
            
            if ($place['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
                $statusCode = 403;
                $response = ['error' => 'Permission denied'];
                break;
            }
            
            // Soft delete (update status to 'deleted')
            $result = $dbHelper->execute(
                "UPDATE places SET status = 'deleted', updated_at = :updated_at WHERE id = :id",
                [
                    'id' => $placeId,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );
            
            if ($result !== false) {
                $response = ['message' => 'Place deleted successfully'];
            } else {
                $statusCode = 500;
                $response = ['error' => 'Failed to delete place'];
            }
            break;
            
        default:
            $statusCode = 405;
            $response = [
                'error' => 'Method not allowed',
                'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
            ];
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    $statusCode = 500;
    $response = [
        'error' => 'Internal server error',
        'message' => getenv('APP_DEBUG') === 'true' ? $e->getMessage() : null
    ];
}

// Send response
http_response_code($statusCode);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
