<?php
class FlashMessage {
    const SESSION_KEY = 'flash_messages';
    
    /**
     * Add a flash message
     * 
     * @param string $message The message to display
     * @param string $type The type of message (e.g., 'success', 'error', 'warning', 'info')
     * @return void
     */
    public static function add($message, $type = 'info') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        
        $_SESSION[self::SESSION_KEY][] = [
            'message' => $message,
            'type' => $type,
            'timestamp' => time()
        ];
    }
    
    /**
     * Get all flash messages
     * 
     * @param bool $clear Whether to clear messages after retrieving them
     * @return array Array of flash messages
     */
    public static function get($clear = true) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        
        if ($clear) {
            self::clear();
        }
        
        return $messages;
    }
    
    /**
     * Check if there are any flash messages
     * 
     * @return bool True if there are messages, false otherwise
     */
    public static function hasMessages() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        return !empty($_SESSION[self::SESSION_KEY]);
    }
    
    /**
     * Clear all flash messages
     * 
     * @return void
     */
    public static function clear() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        unset($_SESSION[self::SESSION_KEY]);
    }
    
    /**
     * Display flash messages as Bootstrap alerts
     * 
     * @param bool $clear Whether to clear messages after displaying them
     * @return string HTML for the flash messages
     */
    public static function display($clear = true) {
        if (!self::hasMessages()) {
            return '';
        }
        
        $messages = self::get($clear);
        $output = [];
        
        foreach ($messages as $message) {
            $type = htmlspecialchars($message['type'], ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8');
            
            $output[] = <<<HTML
                <div class="alert alert-{$type} alert-dismissible fade show" role="alert">
                    {$text}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            HTML;
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Add a success message
     * 
     * @param string $message The success message
     * @return void
     */
    public static function success($message) {
        self::add($message, 'success');
    }
    
    /**
     * Add an error message
     * 
     * @param string $message The error message
     * @return void
     */
    public static function error($message) {
        self::add($message, 'danger');
    }
    
    /**
     * Add a warning message
     * 
     * @param string $message The warning message
     * @return void
     */
    public static function warning($message) {
        self::add($message, 'warning');
    }
    
    /**
     * Add an info message
     * 
     * @param string $message The info message
     * @return void
     */
    public static function info($message) {
        self::add($message, 'info');
    }
}
