<?php
class Cors {
    /**
     * Handle CORS preflight requests and set CORS headers
     */
    public static function handle() {
        $allowedOrigins = self::getAllowedOrigins();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::setCorsHeaders($allowedOrigins, $origin);
            http_response_code(200);
            exit();
        }
        
        // Set CORS headers for actual requests
        self::setCorsHeaders($allowedOrigins, $origin);
    }
    
    /**
     * Get allowed origins from configuration
     * @return array Array of allowed origins
     */
    private static function getAllowedOrigins() {
        $config = require __DIR__ . '/../config/security.php';
        $allowedOrigins = $config['cors']['allowed_origins'] ?? [];
        
        // Allow all origins in development
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $allowedOrigins[] = '*'; // Be cautious with this in production
        }
        
        return $allowedOrigins;
    }
    
    /**
     * Set CORS headers
     * @param array $allowedOrigins Array of allowed origins
     * @param string $origin The request origin
     */
    private static function setCorsHeaders($allowedOrigins, $origin) {
        $config = require __DIR__ . '/../config/security.php';
        
        // Check if the origin is allowed
        $allowedOrigin = in_array('*', $allowedOrigins) ? '*' : (
            in_array($origin, $allowedOrigins) ? $origin : ''
        );
        
        if ($allowedOrigin) {
            header("Access-Control-Allow-Origin: $allowedOrigin");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400'); // 24 hours
            
            // Handle allowed headers
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            } else {
                $allowedHeaders = $config['cors']['allowed_headers'] ?? [];
                if (!empty($allowedHeaders)) {
                    header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
                }
            }
            
            // Handle allowed methods
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header("Access-Control-Allow-Methods: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']}");
            } else {
                $allowedMethods = $config['cors']['allowed_methods'] ?? [];
                if (!empty($allowedMethods)) {
                    header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
                }
            }
            
            // Handle exposed headers
            $exposedHeaders = $config['cors']['exposed_headers'] ?? [];
            if (!empty($exposedHeaders)) {
                header('Access-Control-Expose-Headers: ' . implode(', ', $exposedHeaders));
            }
        }
    }
    
    /**
     * Check if the current request is a CORS preflight request
     * @return bool True if it's a preflight request, false otherwise
     */
    public static function isPreflight() {
        return $_SERVER['REQUEST_METHOD'] === 'OPTIONS' && isset(
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'],
            $_SERVER['HTTP_ORIGIN']
        );
    }
}
