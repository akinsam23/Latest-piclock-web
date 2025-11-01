<?php
class EnvLoader {
    /**
     * The directory where the .env file is located
     * @var string
     */
    protected $path;
    
    /**
     * Create a new EnvLoader instance
     * @param string $path Path to the directory containing the .env file
     */
    public function __construct($path = null) {
        $this->path = $path ?: dirname(__DIR__);
    }
    
    /**
     * Load environment variables from .env file
     * @return bool True if the file was loaded successfully, false otherwise
     */
    public function load() {
        $envFile = $this->path . DIRECTORY_SEPARATOR . '.env';
        
        if (!file_exists($envFile)) {
            return false;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse the line
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = $this->parseValue(trim($value));
                
                // Set the environment variable if not already set
                if (!array_key_exists($name, $_ENV)) {
                    $_ENV[$name] = $value;
                    putenv("$name=$value");
                }
            }
        }
        
        return true;
    }
    
    /**
     * Parse the value from the .env file
     * @param string $value The value to parse
     * @return mixed The parsed value
     */
    protected function parseValue($value) {
        // Remove surrounding quotes
        if (strpos($value, '"') === 0 && substr($value, -1) === '"' || 
            strpos($value, "'") === 0 && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }
        
        // Handle boolean values
        $lowerValue = strtolower($value);
        if (in_array($lowerValue, ['true', 'false', 'null'])) {
            return json_decode($lowerValue);
        }
        
        // Handle arrays
        if (strpos($value, ',') !== false) {
            return array_map('trim', explode(',', $value));
        }
        
        return $value;
    }
    
    /**
     * Get an environment variable
     * @param string $key The environment variable name
     * @param mixed $default Default value if the variable is not set
     * @return mixed The environment variable value or default
     */
    public static function get($key, $default = null) {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        // Convert string "true", "false", "null" to appropriate types
        switch (strtolower($value)) {
            case 'true':
                return true;
            case 'false':
                return false;
            case 'null':
                return null;
        }
        
        // Handle numeric values
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float) $value;
            }
            return (int) $value;
        }
        
        return $value;
    }
}
