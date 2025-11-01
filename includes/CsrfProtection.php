<?php
class CsrfProtection {
    /**
     * @var string The session key for storing CSRF tokens
     */
    private static $sessionKey = 'csrf_tokens';
    
    /**
     * @var int Maximum number of tokens to store (prevents session flooding)
     */
    private static $maxTokens = 10;
    
    /**
     * @var int Token lifetime in seconds (default: 1 hour)
     */
    private static $tokenLifetime = 3600;
    
    /**
     * Initialize the CSRF protection
     */
    public static function init() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Session must be started before initializing CSRF protection');
        }
        
        // Initialize the token array if it doesn't exist
        if (!isset($_SESSION[self::$sessionKey]) || !is_array($_SESSION[self::$sessionKey])) {
            $_SESSION[self::$sessionKey] = [];
        }
        
        // Clean up expired tokens
        self::cleanupExpiredTokens();
    }
    
    /**
     * Generate a new CSRF token
     * 
     * @param string $formId Optional form identifier for multiple forms
     * @return string The generated token
     */
    public static function generateToken($formId = 'default') {
        self::init();
        
        // Generate a secure random token
        $token = bin2hex(random_bytes(32));
        
        // Store the token with its expiration time
        $_SESSION[self::$sessionKey][$formId] = [
            'token' => $token,
            'expires' => time() + self::$tokenLifetime
        ];
        
        // Enforce maximum number of tokens
        if (count($_SESSION[self::$sessionKey]) > self::$maxTokens) {
            // Remove the oldest token(s)
            array_shift($_SESSION[self::$sessionKey]);
        }
        
        return $token;
    }
    
    /**
     * Validate a CSRF token
     * 
     * @param string $token The token to validate
     * @param string $formId Optional form identifier
     * @param bool $removeAfterValidation Whether to remove the token after validation
     * @return bool True if the token is valid, false otherwise
     */
    public static function validateToken($token, $formId = 'default', $removeAfterValidation = true) {
        self::init();
        
        // Check if the token exists and is not expired
        if (!isset($_SESSION[self::$sessionKey][$formId])) {
            return false;
        }
        
        $storedToken = $_SESSION[self::$sessionKey][$formId];
        
        // Check if the token has expired
        if ($storedToken['expires'] < time()) {
            self::removeToken($formId);
            return false;
        }
        
        // Verify the token
        $isValid = hash_equals($storedToken['token'], $token);
        
        // Remove the token if requested (useful for one-time tokens)
        if ($isValid && $removeAfterValidation) {
            self::removeToken($formId);
        }
        
        return $isValid;
    }
    
    /**
     * Remove a CSRF token
     * 
     * @param string $formId The form identifier
     */
    public static function removeToken($formId) {
        if (isset($_SESSION[self::$sessionKey][$formId])) {
            unset($_SESSION[self::$sessionKey][$formId]);
        }
    }
    
    /**
     * Clean up expired tokens
     */
    private static function cleanupExpiredTokens() {
        $now = time();
        
        foreach ($_SESSION[self::$sessionKey] as $formId => $tokenData) {
            if ($tokenData['expires'] < $now) {
                unset($_SESSION[self::$sessionKey][$formId]);
            }
        }
    }
    
    /**
     * Get the HTML input field for CSRF protection
     * 
     * @param string $formId Optional form identifier
     * @return string HTML input field
     */
    public static function getCsrfField($formId = 'default') {
        $token = self::generateToken($formId);
        return sprintf('<input type="hidden" name="%s" value="%s">', 
            self::getTokenName(), 
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }
    
    /**
     * Get the CSRF token name
     * 
     * @return string The token name
     */
    public static function getTokenName() {
        return 'csrf_token';
    }
    
    /**
     * Verify the CSRF token from the request
     * 
     * @param string $formId Optional form identifier
     * @param string $method HTTP method (GET, POST, etc.)
     * @return bool True if the token is valid, false otherwise
     */
    public static function verifyRequest($formId = 'default', $method = null) {
        $method = strtoupper($method ?: $_SERVER['REQUEST_METHOD']);
        
        // Skip CSRF check for safe methods (GET, HEAD, OPTIONS)
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }
        
        $token = null;
        
        // Get the token from the request
        if (isset($_POST[self::getTokenName()])) {
            $token = $_POST[self::getTokenName()];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (isset($_GET[self::getTokenName()])) {
            $token = $_GET[self::getTokenName()];
        }
        
        // Validate the token
        return $token !== null && self::validateToken($token, $formId, true);
    }
    
    /**
     * Get the CSRF token for use in JavaScript/AJAX requests
     * 
     * @param string $formId Optional form identifier
     * @return string The token
     */
    public static function getTokenForJs($formId = 'default') {
        return self::generateToken($formId);
    }
    
    /**
     * Get the CSRF token meta tag for including in HTML head
     * 
     * @return string HTML meta tag
     */
    public static function getMetaTag() {
        $token = self::generateToken('meta');
        return sprintf('<meta name="csrf-token" content="%s">', 
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }
}
