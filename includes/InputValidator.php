<?php
class InputValidator {
    private $errors = [];
    private $data = [];
    
    /**
     * Set input data to validate
     * @param array $data Input data (usually $_POST or $_GET)
     */
    public function __construct($data = []) {
        $this->data = $data;
    }
    
    /**
     * Get validation errors
     * @return array Array of error messages
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Check if validation passed
     * @return bool True if no errors, false otherwise
     */
    public function isValid() {
        return empty($this->errors);
    }
    
    /**
     * Validate a required field
     * @param string $field Field name
     * @param string $label Human-readable field label
     * @return $this
     */
    public function required($field, $label) {
        if (!isset($this->data[$field]) || trim($this->data[$field]) === '') {
            $this->errors[$field] = "$label is required.";
        }
        return $this;
    }
    
    /**
     * Validate email format
     * @param string $field Field name
     * @param string $label Human-readable field label
     * @return $this
     */
    public function email($field, $label) {
        if (isset($this->data[$field]) && $this->data[$field] !== '') {
            if (!filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field] = "$label must be a valid email address.";
            }
        }
        return $this;
    }
    
    /**
     * Validate string length
     * @param string $field Field name
     * @param string $label Human-readable field label
     * @param int $min Minimum length (0 for no minimum)
     * @param int $max Maximum length (0 for no maximum)
     * @return $this
     */
    public function length($field, $label, $min = 0, $max = 0) {
        if (isset($this->data[$field])) {
            $length = mb_strlen(trim($this->data[$field]));
            if ($min > 0 && $length < $min) {
                $this->errors[$field] = "$label must be at least $min characters long.";
            }
            if ($max > 0 && $length > $max) {
                $this->errors[$field] = "$label cannot exceed $max characters.";
            }
        }
        return $this;
    }
    
    /**
     * Validate numeric value
     * @param string $field Field name
     * @param string $label Human-readable field label
     * @param float $min Minimum value (optional)
     * @param float $max Maximum value (optional)
     * @return $this
     */
    public function numeric($field, $label, $min = null, $max = null) {
        if (isset($this->data[$field]) && $this->data[$field] !== '') {
            if (!is_numeric($this->data[$field])) {
                $this->errors[$field] = "$label must be a number.";
            } else {
                $value = (float)$this->data[$field];
                if ($min !== null && $value < $min) {
                    $this->errors[$field] = "$label must be at least $min.";
                }
                if ($max !== null && $value > $max) {
                    $this->errors[$field] = "$label cannot exceed $max.";
                }
            }
        }
        return $this;
    }
    
    /**
     * Validate that a value is in a specific set of values
     * @param string $field Field name
     * @param string $label Human-readable field label
     * @param array $allowedValues Array of allowed values
     * @return $this
     */
    public function inArray($field, $label, $allowedValues) {
        if (isset($this->data[$field]) && !in_array($this->data[$field], $allowedValues)) {
            $this->errors[$field] = "$label is not valid.";
        }
        return $this;
    }
    
    /**
     * Validate a URL
     * @param string $field Field name
     * @param string $label Human-readable field label
     * @param bool $requireHttps Whether to require HTTPS
     * @return $this
     */
    public function url($field, $label, $requireHttps = false) {
        if (isset($this->data[$field]) && $this->data[$field] !== '') {
            $url = filter_var($this->data[$field], FILTER_SANITIZE_URL);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->errors[$field] = "$label must be a valid URL.";
            } elseif ($requireHttps && strpos($url, 'https://') !== 0) {
                $this->errors[$field] = "$label must use HTTPS.";
            }
        }
        return $this;
    }
    
    /**
     * Sanitize a string
     * @param string $value Input value
     * @return string Sanitized string
     */
    public static function sanitizeString($value) {
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize an integer
     * @param mixed $value Input value
     * @return int Sanitized integer
     */
    public static function sanitizeInt($value) {
        return (int)$value;
    }
    
    /**
     * Sanitize a float
     * @param mixed $value Input value
     * @return float Sanitized float
     */
    public static function sanitizeFloat($value) {
        return (float)$value;
    }
    
    /**
     * Sanitize an email address
     * @param string $email Email address
     * @return string Sanitized email address
     */
    public static function sanitizeEmail($email) {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }
    
    /**
     * Sanitize a URL
     * @param string $url URL
     * @return string Sanitized URL
     */
    public static function sanitizeUrl($url) {
        return filter_var(trim($url), FILTER_SANITIZE_URL);
    }
}
