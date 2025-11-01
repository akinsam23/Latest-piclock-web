<?php

class NewsSubmissionHandler {
    private $db;
    private $uploadDir;
    private $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private $allowedVideoTypes = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv'
    ];
    private $maxFileSize = 50 * 1024 * 1024; // 50MB
    private $maxImageSize = 5 * 1024 * 1024; // 5MB
    
    public function __construct($db, $uploadDir = null) {
        $this->db = $db;
        $this->uploadDir = $uploadDir ?? __DIR__ . '/../uploads';
        
        // Create upload directories if they don't exist
        $this->ensureDirectoryExists($this->uploadDir);
        $this->ensureDirectoryExists($this->uploadDir . '/images');
        $this->ensureDirectoryExists($this->uploadDir . '/videos');
        $this->ensureDirectoryExists($this->uploadDir . '/thumbnails');
    }
    
    /**
     * Submit a new news post
     * 
     * @param int $userId The ID of the user submitting the post
     * @param array $postData Array containing post data (title, content, etc.)
     * @param array $files Array of uploaded files (image, videos)
     * @return array Result of the submission
     */
    public function submitNewsPost($userId, $postData, $files = []) {
        try {
            // Validate required fields
            $required = ['title', 'content', 'category', 'location_id'];
            $this->validateRequiredFields($postData, $required);
            
            // Check if location exists
            $locationManager = new LocationManager($this->db);
            $location = $locationManager->getLocation($postData['location_id']);
            
            if (!$location) {
                throw new Exception('Invalid location selected');
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            // Handle image upload if present
            $imageUrl = null;
            if (!empty($files['image']['name'])) {
                $imageUrl = $this->handleImageUpload($files['image']);
            } elseif (!empty($postData['image_url'])) {
                $imageUrl = $this->handleRemoteImage($postData['image_url']);
            }
            
            // Generate slug from title
            $slug = $this->createSlug($postData['title']);
            
            // Insert the news post
            $sql = "INSERT INTO news_posts (
                        user_id, location_id, title, slug, excerpt, content, 
                        image_url, category, status, is_breaking, is_emergency,
                        created_at, updated_at
                    ) VALUES (
                        :user_id, :location_id, :title, :slug, :excerpt, :content, 
                        :image_url, :category, :status, :is_breaking, :is_emergency,
                        NOW(), NOW()
                    )";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':location_id' => $postData['location_id'],
                ':title' => $postData['title'],
                ':slug' => $slug,
                ':excerpt' => $this->createExcerpt($postData['content']),
                ':content' => $postData['content'],
                ':image_url' => $imageUrl,
                ':category' => $postData['category'],
                ':status' => 'pending', // All posts require admin approval
                ':is_breaking' => !empty($postData['is_breaking']) ? 1 : 0,
                ':is_emergency' => !empty($postData['is_emergency']) ? 1 : 0
            ]);
            
            $postId = $this->db->lastInsertId();
            
            // Handle video uploads
            if (!empty($files['videos'])) {
                $this->handleVideoUploads($postId, $files['videos']);
            }
            
            // Handle video URLs
            if (!empty($postData['video_urls'])) {
                $this->handleVideoUrls($postId, $postData['video_urls']);
            }
            
            // Handle tags if provided
            if (!empty($postData['tags'])) {
                $this->processTags($postId, $postData['tags']);
            }
            
            // Log the submission
            $this->logModerationAction($userId, $postId, 'submitted', 'New post submitted for review');
            
            // Notify admins (you would implement this)
            // $this->notifyAdmins($postId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'post_id' => $postId,
                'message' => 'Your news post has been submitted and is pending approval.',
                'requires_approval' => true
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Failed to submit news post: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update an existing news post
     */
    public function updateNewsPost($postId, $userId, $postData, $files = []) {
        // Similar to submitNewsPost but with update logic
        // Includes permission checks, update post, handle new/changed media, etc.
    }
    
    /**
     * Delete a news post
     */
    public function deleteNewsPost($postId, $userId) {
        // Check permissions, delete associated files, update database
    }
    
    /**
     * Approve a pending news post
     */
    public function approveNewsPost($postId, $adminId, $notes = '') {
        try {
            $this->db->beginTransaction();
            
            // Update post status
            $stmt = $this->db->prepare("
                UPDATE news_posts 
                SET status = 'published', 
                    published_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND status = 'pending'
            ");
            $stmt->execute([$postId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Post not found or already processed');
            }
            
            // Log the approval
            $this->logModerationAction($adminId, $postId, 'approved', $notes);
            
            // Notify the post author (you would implement this)
            // $this->notifyPostAuthor($postId, 'approved');
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'News post approved and published.'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => 'Failed to approve post: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Reject a pending news post
     */
    public function rejectNewsPost($postId, $adminId, $reason = '') {
        // Similar to approveNewsPost but updates status to 'rejected'
    }
    
    /**
     * Handle image upload and processing
     */
    private function handleImageUpload($file) {
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        if (!in_array($file['type'], $this->allowedImageTypes)) {
            throw new Exception('Invalid file type. Allowed types: ' . implode(', ', $this->allowedImageTypes));
        }
        
        if ($file['size'] > $this->maxImageSize) {
            throw new Exception('Image size exceeds maximum allowed size of 5MB');
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('img_') . '.' . $extension;
        $targetPath = $this->uploadDir . '/images/' . $filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        // Create thumbnail
        $this->createThumbnail($targetPath, $this->uploadDir . '/thumbnails/' . $filename, 300, 200);
        
        // Return relative path
        return '/uploads/images/' . $filename;
    }
    
    /**
     * Handle video uploads
     */
    private function handleVideoUploads($postId, $videos) {
        if (!is_array($videos) || !isset($videos['name'][0]) || empty($videos['name'][0])) {
            return;
        }
        
        $count = count($videos['name']);
        
        for ($i = 0; $i < $count; $i++) {
            if ($videos['error'][$i] !== UPLOAD_ERR_OK) {
                continue; // Skip invalid uploads
            }
            
            $fileType = $videos['type'][$i];
            $fileSize = $videos['size'][$i];
            $tmpName = $videos['tmp_name'][$i];
            $originalName = $videos['name'][$i];
            
            // Validate file type
            if (!in_array($fileType, array_keys($this->allowedVideoTypes))) {
                continue;
            }
            
            // Validate file size
            if ($fileSize > $this->maxFileSize) {
                continue;
            }
            
            // Generate unique filename
            $extension = $this->allowedVideoTypes[$fileType];
            $filename = uniqid('vid_') . '.' . $extension;
            $targetPath = $this->uploadDir . '/videos/' . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($tmpName, $targetPath)) {
                // Get video metadata
                $metadata = $this->getVideoMetadata($targetPath);
                
                // Generate thumbnail
                $thumbnailPath = $this->generateVideoThumbnail($targetPath, $filename);
                
                // Save to database
                $this->saveVideoToPost($postId, [
                    'video_url' => '/uploads/videos/' . $filename,
                    'thumbnail_url' => $thumbnailPath ? '/uploads/thumbnails/' . basename($thumbnailPath) : null,
                    'video_type' => 'upload',
                    'title' => pathinfo($originalName, PATHINFO_FILENAME),
                    'duration' => $metadata['duration'] ?? null,
                    'width' => $metadata['width'] ?? null,
                    'height' => $metadata['height'] ?? null
                ]);
            }
        }
    }
    
    /**
     * Handle video URLs (YouTube, Vimeo, etc.)
     */
    private function handleVideoUrls($postId, $urls) {
        if (empty($urls) || !is_array($urls)) {
            return;
        }
        
        foreach ($urls as $url) {
            if (empty(trim($url))) {
                continue;
            }
            
            $videoInfo = $this->parseVideoUrl($url);
            
            if ($videoInfo) {
                $this->saveVideoToPost($postId, [
                    'video_url' => $url,
                    'thumbnail_url' => $videoInfo['thumbnail_url'] ?? null,
                    'video_type' => $videoInfo['type'],
                    'title' => $videoInfo['title'] ?? 'Video',
                    'embed_code' => $videoInfo['embed_code'] ?? null
                ]);
            }
        }
    }
    
    /**
     * Parse video URL to get metadata and embed code
     */
    private function parseVideoUrl($url) {
        // YouTube
        if (preg_match('/(youtube\.com\/watch\?v=|youtu\.be\/)([^\&\?\/]+)/', $url, $matches)) {
            $videoId = $matches[2];
            return [
                'type' => 'youtube',
                'video_id' => $videoId,
                'thumbnail_url' => "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
                'embed_code' => "<iframe width='560' height='315' src='https://www.youtube.com/embed/{$videoId}' frameborder='0' allowfullscreen></iframe>"
            ];
        }
        
        // Vimeo
        if (preg_match('/vimeo\.com\/([0-9]+)/', $url, $matches)) {
            $videoId = $matches[1];
            // You would typically make an API call to Vimeo to get thumbnail and other info
            return [
                'type' => 'vimeo',
                'video_id' => $videoId,
                'embed_code' => "<iframe src='https://player.vimeo.com/video/{$videoId}' width='560' height='315' frameborder='0' webkitallowfullscreen mozallowfullscreen allowfullscreen></iframe>"
            ];
        }
        
        // Add more video providers as needed
        
        return null;
    }
    
    /**
     * Save video information to the database
     */
    private function saveVideoToPost($postId, $videoData) {
        $sql = "INSERT INTO post_videos (
                    news_id, video_url, thumbnail_url, video_type, 
                    title, description, duration, width, height, embed_code
                ) VALUES (
                    :news_id, :video_url, :thumbnail_url, :video_type, 
                    :title, :description, :duration, :width, :height, :embed_code
                )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':news_id' => $postId,
            ':video_url' => $videoData['video_url'],
            ':thumbnail_url' => $videoData['thumbnail_url'] ?? null,
            ':video_type' => $videoData['video_type'],
            ':title' => $videoData['title'] ?? null,
            ':description' => $videoData['description'] ?? null,
            ':duration' => $videoData['duration'] ?? null,
            ':width' => $videoData['width'] ?? null,
            ':height' => $videoData['height'] ?? null,
            ':embed_code' => $videoData['embed_code'] ?? null
        ]);
    }
    
    /**
     * Process and save tags for a news post
     */
    private function processTags($postId, $tags) {
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }
        
        if (!is_array($tags) || empty($tags)) {
            return;
        }
        
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;
            
            $slug = $this->createSlug($tagName);
            
            // Check if tag exists
            $stmt = $this->db->prepare("SELECT id FROM tags WHERE slug = ?");
            $stmt->execute([$slug]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tag) {
                $tagId = $tag['id'];
            } else {
                // Create new tag
                $stmt = $this->db->prepare("INSERT INTO tags (name, slug) VALUES (?, ?)");
                $stmt->execute([$tagName, $slug]);
                $tagId = $this->db->lastInsertId();
            }
            
            // Link tag to post
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO news_tags (news_id, tag_id) 
                VALUES (?, ?)
            ");
            $stmt->execute([$postId, $tagId]);
        }
    }
    
    /**
     * Log moderation actions
     */
    private function logModerationAction($userId, $postId, $action, $details = '') {
        $stmt = $this->db->prepare("
            INSERT INTO moderation_logs (user_id, news_id, action, details, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $postId, $action, $details]);
    }
    
    /**
     * Helper methods for file handling, validation, etc.
     */
    private function ensureDirectoryExists($dir) {
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    private function createSlug($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        
        if (empty($text)) {
            return 'n-a';
        }
        
        return $text;
    }
    
    private function createExcerpt($content, $length = 200) {
        $content = strip_tags($content);
        if (mb_strlen($content) > $length) {
            $content = mb_substr($content, 0, $length) . '...';
        }
        return $content;
    }
    
    private function validateRequiredFields($data, $required) {
        $missing = [];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new InvalidArgumentException('Missing required fields: ' . implode(', ', $missing));
        }
    }
    
    private function createThumbnail($sourcePath, $targetPath, $width, $height) {
        // Implementation for creating image thumbnails using GD or Imagick
        // This is a simplified example
        list($srcWidth, $srcHeight, $type) = getimagesize($sourcePath);
        
        $src = null;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $src = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $src = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $src = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $src = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }
        
        if (!$src) return false;
        
        // Calculate aspect ratio
        $srcAspect = $srcWidth / $srcHeight;
        $thumbAspect = $width / $height;
        
        if ($srcAspect > $thumbAspect) {
            // Source is wider than thumbnail
            $newHeight = $height;
            $newWidth = (int)($height * $srcAspect);
        } else {
            // Source is taller than thumbnail
            $newWidth = $width;
            $newHeight = (int)($width / $srcAspect);
        }
        
        $thumb = imagecreatetruecolor($width, $height);
        
        // Handle transparency for PNG/GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagecolortransparent($thumb, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        
        // Resize and crop
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        
        // Save the thumbnail
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($thumb, $targetPath, 90);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($thumb, $targetPath, 9);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($thumb, $targetPath);
                break;
            case IMAGETYPE_WEBP:
                $result = imagewebp($thumb, $targetPath, 90);
                break;
        }
        
        imagedestroy($src);
        imagedestroy($thumb);
        
        return $result;
    }
    
    private function getVideoMetadata($filePath) {
        // This is a placeholder. In a real application, you would use FFmpeg or similar
        // to extract video metadata like duration, resolution, etc.
        return [
            'duration' => null,
            'width' => null,
            'height' => null
        ];
    }
    
    private function generateVideoThumbnail($videoPath, $outputFilename) {
        // This is a placeholder. In a real application, you would use FFmpeg
        // to generate a thumbnail from the video
        $thumbnailPath = $this->uploadDir . '/thumbnails/' . pathinfo($outputFilename, PATHINFO_FILENAME) . '.jpg';
        
        // Example FFmpeg command (would be executed with exec() or similar):
        // ffmpeg -i "$videoPath" -ss 00:00:01 -vframes 1 -q:v 2 "$thumbnailPath"
        
        return file_exists($thumbnailPath) ? $thumbnailPath : null;
    }
}
