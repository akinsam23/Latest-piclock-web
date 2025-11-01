<?php
class FileUploader {
    private $allowedImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    
    private $maxFileSize = 5 * 1024 * 1024; // 5MB
    private $uploadDir = 'uploads/';
    private $errors = [];
    
    /**
     * Set maximum file size in bytes
     * @param int $bytes Maximum file size in bytes
     */
    public function setMaxFileSize($bytes) {
        $this->maxFileSize = $bytes;
        return $this;
    }
    
    /**
     * Set allowed MIME types for upload
     * @param array $mimeTypes Associative array of MIME types and their extensions
     */
    public function setAllowedTypes($mimeTypes) {
        $this->allowedImageTypes = $mimeTypes;
        return $this;
    }
    
    /**
     * Set upload directory
     * @param string $dir Directory path (relative to web root)
     */
    public function setUploadDir($dir) {
        $this->uploadDir = rtrim($dir, '/') . '/';
        return $this;
    }
    
    /**
     * Get any errors that occurred during upload
     * @return array Array of error messages
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Upload a file with validation
     * @param array $file $_FILES array element
     * @param string $fieldName Name of the file input field
     * @return string|bool Path to the uploaded file or false on failure
     */
    public function upload($file, $fieldName = 'file') {
        $this->errors = [];
        
        // Check for upload errors
        if (!isset($file['error']) || is_array($file['error'])) {
            $this->errors[] = 'Invalid parameters.';
            return false;
        }
        
        // Check for specific upload errors
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $this->errors[] = 'File is too large.';
                return false;
            case UPLOAD_ERR_PARTIAL:
                $this->errors[] = 'File was only partially uploaded.';
                return false;
            case UPLOAD_ERR_NO_FILE:
                $this->errors[] = 'No file was uploaded.';
                return false;
            default:
                $this->errors[] = 'Unknown upload error.';
                return false;
        }
        
        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            $this->errors[] = 'File is too large. Maximum size is ' . 
                round($this->maxFileSize / (1024 * 1024), 1) . 'MB';
            return false;
        }
        
        // Check MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        if (!array_key_exists($mime, $this->allowedImageTypes)) {
            $this->errors[] = 'Invalid file type. Allowed types: ' . 
                implode(', ', array_values($this->allowedImageTypes));
            return false;
        }
        
        // Generate a secure filename
        $extension = $this->allowedImageTypes[$mime];
        $filename = sprintf('%s_%s.%s',
            bin2hex(random_bytes(8)),
            date('YmdHis'),
            $extension
        );
        
        // Create upload directory if it doesn't exist
        $uploadDir = $this->uploadDir . date('Y/m/');
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                $this->errors[] = 'Failed to create upload directory.';
                return false;
            }
        }
        
        $destination = $uploadDir . $filename;
        
        // Move the uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $this->errors[] = 'Failed to move uploaded file.';
            return false;
        }
        
        // Verify the file was moved and has the correct MIME type
        if (!file_exists($destination)) {
            $this->errors[] = 'Uploaded file could not be verified.';
            return false;
        }
        
        // Additional security check: verify the file's MIME type again after move
        $mime = $finfo->file($destination);
        if (!array_key_exists($mime, $this->allowedImageTypes)) {
            unlink($destination); // Remove the uploaded file
            $this->errors[] = 'File type verification failed after upload.';
            return false;
        }
        
        return $destination;
    }
    
    /**
     * Delete an uploaded file
     * @param string $filePath Path to the file to delete
     * @return bool True on success, false on failure
     */
    public static function delete($filePath) {
        if (file_exists($filePath) && is_file($filePath)) {
            return unlink($filePath);
        }
        return false;
    }
}
