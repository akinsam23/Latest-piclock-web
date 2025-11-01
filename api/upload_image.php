<?php
// upload_image.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Enable CORS if needed
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Check if the user is authenticated
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

// Maximum file size (5MB)
$maxFileSize = 5 * 1024 * 1024;

// Allowed file types
$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp'
];

try {
    // Check if file was uploaded without errors
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file was uploaded or there was an upload error.');
    }
    
    $file = $_FILES['image'];
    
    // Check file size
    if ($file['size'] > $maxFileSize) {
        throw new Exception('File is too large. Maximum size is 5MB.');
    }
    
    // Check file type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    
    if (!array_key_exists($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = __DIR__ . '/../uploads/' . date('Y/m/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate a unique filename
    $extension = $allowedTypes[$mimeType];
    $filename = uniqid('img_', true) . '.' . $extension;
    $destination = $uploadDir . $filename;
    
    // Move the uploaded file to the destination
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception('Failed to move uploaded file.');
    }
    
    // Get the relative path for the URL
    $relativePath = '/uploads/' . date('Y/m/') . $filename;
    
    // Return success response with file information
    echo json_encode([
        'success' => true,
        'file' => [
            'url' => $relativePath,
            'name' => $filename,
            'size' => $file['size'],
            'type' => $mimeType
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log("Image Upload Error: " . $e->getMessage());
}
