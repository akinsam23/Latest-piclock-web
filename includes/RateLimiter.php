<?php
class RateLimiter {
    /**
     * @var string Storage directory for rate limit files
     */
    private $storagePath;
    
    /**
     * @var int Time window in seconds
     */
    private $timeWindow;
    
    /**
     * @var int Maximum number of attempts allowed in the time window
     */
    private $maxAttempts;
    
    /**
     * @var string Identifier for the rate limit (e.g., IP address, user ID, API key)
     */
    private $identifier;
    
    /**
     * @var string Prefix for rate limit files
     */
    private $prefix;
    
    /**
     * RateLimiter constructor
     * 
     * @param string $identifier Unique identifier for the rate limit (e.g., IP, user ID)
     * @param int $maxAttempts Maximum number of attempts allowed
     * @param int $timeWindow Time window in seconds
     * @param string $storagePath Path to store rate limit files
     * @param string $prefix Prefix for rate limit files
     */
    public function __construct($identifier, $maxAttempts = 100, $timeWindow = 3600, $storagePath = null, $prefix = 'rate_limit_') {
        $this->identifier = $this->sanitizeIdentifier($identifier);
        $this->maxAttempts = max(1, (int)$maxAttempts);
        $this->timeWindow = max(1, (int)$timeWindow);
        $this->storagePath = $storagePath ? rtrim($storagePath, '/\\') . '/' : sys_get_temp_dir() . '/rate_limits/';
        $this->prefix = $prefix;
        
        // Create storage directory if it doesn't exist
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }
    
    /**
     * Check if the rate limit has been exceeded
     * 
     * @return bool True if rate limit is exceeded, false otherwise
     */
    public function isRateLimited() {
        $filename = $this->getFilename();
        
        // If the file doesn't exist, create it with initial attempt
        if (!file_exists($filename)) {
            $this->resetAttempts();
            return false;
        }
        
        // Read the file
        $data = $this->readRateLimitFile($filename);
        
        // Check if the time window has expired
        if (time() - $data['timestamp'] > $this->timeWindow) {
            $this->resetAttempts();
            return false;
        }
        
        // Check if max attempts have been reached
        if ($data['attempts'] >= $this->maxAttempts) {
            return true;
        }
        
        // Increment the attempt count
        $this->incrementAttempts($data);
        
        return false;
    }
    
    /**
     * Get the number of remaining attempts
     * 
     * @return int Number of remaining attempts
     */
    public function getRemainingAttempts() {
        $filename = $this->getFilename();
        
        if (!file_exists($filename)) {
            return $this->maxAttempts;
        }
        
        $data = $this->readRateLimitFile($filename);
        
        // If the time window has expired, reset attempts
        if (time() - $data['timestamp'] > $this->timeWindow) {
            return $this->maxAttempts;
        }
        
        return max(0, $this->maxAttempts - $data['attempts']);
    }
    
    /**
     * Get the time when the rate limit will be reset
     * 
     * @return int Unix timestamp when the rate limit will be reset
     */
    public function getResetTime() {
        $filename = $this->getFilename();
        
        if (!file_exists($filename)) {
            return time() + $this->timeWindow;
        }
        
        $data = $this->readRateLimitFile($filename);
        
        return $data['timestamp'] + $this->timeWindow;
    }
    
    /**
     * Reset the rate limit counter
     */
    public function resetAttempts() {
        $filename = $this->getFilename();
        $data = [
            'timestamp' => time(),
            'attempts' => 1
        ];
        
        file_put_contents($filename, json_encode($data), LOCK_EX);
    }
    
    /**
     * Increment the attempt counter
     * 
     * @param array $data Current rate limit data
     */
    private function incrementAttempts($data) {
        $filename = $this->getFilename();
        $data['attempts']++;
        file_put_contents($filename, json_encode($data), LOCK_EX);
    }
    
    /**
     * Read the rate limit file
     * 
     * @param string $filename Path to the rate limit file
     * @return array Rate limit data
     */
    private function readRateLimitFile($filename) {
        $content = file_get_contents($filename);
        $data = json_decode($content, true);
        
        // Default values if the file is corrupted
        return [
            'timestamp' => $data['timestamp'] ?? time(),
            'attempts' => $data['attempts'] ?? 0
        ];
    }
    
    /**
     * Get the full path to the rate limit file
     * 
     * @return string Full path to the rate limit file
     */
    private function getFilename() {
        return $this->storagePath . $this->prefix . md5($this->identifier) . '.json';
    }
    
    /**
     * Sanitize the identifier to prevent directory traversal
     * 
     * @param string $identifier The identifier to sanitize
     * @return string Sanitized identifier
     */
    private function sanitizeIdentifier($identifier) {
        // Remove any characters that aren't letters, numbers, or common symbols
        return preg_replace('/[^a-zA-Z0-9_\-\.@]/', '', $identifier);
    }
    
    /**
     * Get rate limit headers for API responses
     * 
     * @return array Associative array of rate limit headers
     */
    public function getRateLimitHeaders() {
        $remaining = $this->getRemainingAttempts();
        $resetTime = $this->getResetTime();
        
        return [
            'X-RateLimit-Limit' => $this->maxAttempts,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => $resetTime,
            'Retry-After' => $remaining <= 0 ? max(1, $resetTime - time()) : null
        ];
    }
    
    /**
     * Send rate limit headers in the HTTP response
     */
    public function sendRateLimitHeaders() {
        foreach ($this->getRateLimitHeaders() as $header => $value) {
            if ($value !== null) {
                header("$header: $value");
            }
        }
    }
}
