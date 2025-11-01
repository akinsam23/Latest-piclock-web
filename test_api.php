<?php
// Test API endpoints
require_once 'bootstrap.php';

header('Content-Type: text/plain');
echo "=== API Endpoint Tests ===\n\n";

// Helper function to make API requests
function testApiEndpoint($method, $endpoint, $data = null, $headers = []) {
    $url = 'http://' . $_SERVER['HTTP_HOST'] . $endpoint;
    $ch = curl_init($url);
    
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json',
            'Accept: application/json'
        ], $headers)
    ];
    
    if ($data !== null) {
        $options[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
    }
    
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => json_decode($body, true) ?: $body
    ];
}

// Test 1: Get places (should be public)
echo "Testing GET /api/v1/places...\n";
$response = testApiEndpoint('GET', '/latest-piclock-web2/Latest-piclock-web/api/v1/places');
echo "Status: {$response['code']}\n";
if (isset($response['body']['data'])) {
    echo "✅ Found " . count($response['body']['data']) . " places\n\n";
} else {
    echo "❌ Failed to get places\n";
    echo "Response: " . print_r($response['body'], true) . "\n\n";
}

// Test 2: Try to create a place without authentication (should fail)
echo "Testing unauthorized POST /api/v1/places...\n";
$response = testApiEndpoint('POST', '/latest-piclock-web2/Latest-piclock-web/api/v1/places', [
    'place_name' => 'Test Place',
    'description' => 'This is a test place',
    'category' => 'test',
    'latitude' => 0,
    'longitude' => 0,
    'city_region' => 'Test City'
]);

echo "Status: {$response['code']} (Expected: 401)\n";
if ($response['code'] === 401) {
    echo "✅ Correctly rejected unauthorized request\n";
} else {
    echo "❌ Security issue: Should require authentication\n";
}

echo "\n=== API Tests Complete ===\n";
