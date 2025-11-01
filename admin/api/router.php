// api/router.php
<?php
header('Content-Type: application/json');

// Get the request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_SERVER['PATH_INFO'] ?? '', '/');
$pathSegments = $path ? explode('/', $path) : [];

// Simple router
$routes = [
    'GET places' => 'getPlaces',
    'GET places/{id}' => 'getPlace',
    'POST places' => 'createPlace',
    'PUT places/{id}' => 'updatePlace',
    'DELETE places/{id}' => 'deletePlace',
    'GET places/{id}/reviews' => 'getPlaceReviews',
    'POST places/{id}/reviews' => 'createReview',
];

// Find matching route
$handler = null;
$params = [];

foreach ($routes as $route => $handlerName) {
    list($routeMethod, $routePath) = explode(' ', $route, 2);
    $routeSegments = explode('/', $routePath);
    
    if ($routeMethod !== $method || count($routeSegments) !== count($pathSegments)) {
        continue;
    }
    
    $match = true;
    $params = [];
    
    foreach ($routeSegments as $i => $segment) {
        if (strpos($segment, '{') === 0) {
            $paramName = trim($segment, '{}');
            $params[$paramName] = $pathSegments[$i];
        } elseif ($segment !== $pathSegments[$i]) {
            $match = false;
            break;
        }
    }
    
    if ($match) {
        $handler = $handlerName;
        break;
    }
}

// Handle the request
if ($handler && function_exists($handler)) {
    try {
        $response = call_user_func($handler, $params);
        echo json_encode($response);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}

// API Handlers
function getPlaces($params) {
    $db = getDBConnection();
    $query = "SELECT * FROM places WHERE status = 'approved'";
    $params = [];
    
    // Add filters
    if (isset($_GET['category'])) {
        $query .= " AND category = :category";
        $params[':category'] = $_GET['category'];
    }
    
    // Add search
    if (isset($_GET['q'])) {
        $query .= " AND MATCH(place_name, description, city_region) AGAINST(:search IN NATURAL LANGUAGE MODE)";
        $params[':search'] = $_GET['q'];
    }
    
    // Add pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(50, max(1, intval($_GET['per_page'] ?? 10)));
    $offset = ($page - 1) * $perPage;
    
    $query .= " LIMIT :limit OFFSET :offset";
    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $places = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countStmt = $db->query("SELECT FOUND_ROWS()");
    $total = $countStmt->fetchColumn();
    
    return [
        'data' => $places,
        'meta' => [
            'total' => (int)$total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage)
        ]
    ];
}

// Other API handler functions...