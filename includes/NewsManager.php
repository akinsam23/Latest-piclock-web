<?php
// includes/NewsManager.php
require_once __DIR__ . '/../config/database.php';

class NewsManager {
    private $db;
    private $config;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    /**
     * Create a new news post
     */
    public function createPost($userId, $data, $imageFile = null) {
        $errors = $this->validatePostData($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $this->db->beginTransaction();

            // Handle location
            $locationId = $this->getOrCreateLocation($data);
            
            // Handle image upload
            $imageUrl = null;
            if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
                $imageUrl = $this->handleImageUpload($imageFile);
            } elseif (!empty($data['image_url'])) {
                $imageUrl = $data['image_url'];
            }

            // Insert news post
            $stmt = $this->db->prepare("
                INSERT INTO news_posts (
                    user_id, location_id, title, content, image_url, category, 
                    is_breaking, is_emergency, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $locationId,
                $data['title'],
                $data['content'],
                $imageUrl,
                $data['category'],
                $data['is_breaking'] ?? false,
                $data['is_emergency'] ?? false,
                'pending' // Default status for new posts
            ]);
            
            $postId = $this->db->lastInsertId();
            
            // Handle tags if any
            if (!empty($data['tags'])) {
                $this->addTags($postId, $data['tags']);
            }
            
            $this->db->commit();
            
            return [
                'success' => true, 
                'post_id' => $postId,
                'message' => 'News post submitted successfully and is pending review.'
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("News post creation failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create news post. Please try again.'];
        }
    }
    
    /**
     * Update an existing news post
     */
    public function updatePost($postId, $userId, $data, $imageFile = null) {
        // Verify post exists and belongs to user or user is admin
        $post = $this->getPostById($postId);
        if (!$post) {
            return ['success' => false, 'message' => 'Post not found.'];
        }
        
        // Only the original author or admin can update
        if ($post['user_id'] != $userId && !$this->isAdmin($userId)) {
            return ['success' => false, 'message' => 'Unauthorized to update this post.'];
        }
        
        $errors = $this->validatePostData($data, true);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        try {
            $this->db->beginTransaction();
            
            $locationId = $this->getOrCreateLocation($data);
            
            // Handle image upload if new image is provided
            $imageUrl = $post['image_url'];
            if ($imageFile && $imageFile['error'] === UPLOAD_ERR_OK) {
                // Delete old image if it exists
                if ($imageUrl) {
                    $this->deleteImage($imageUrl);
                }
                $imageUrl = $this->handleImageUpload($imageFile);
            } elseif (!empty($data['image_url']) && $data['image_url'] != $imageUrl) {
                $imageUrl = $data['image_url'];
            }
            
            // Update post
            $stmt = $this->db->prepare("
                UPDATE news_posts 
                SET title = ?, content = ?, category = ?, location_id = ?, 
                    image_url = ?, is_breaking = ?, is_emergency = ?,
                    status = ?, updated_at = NOW()
                WHERE id = ?
            
            ");
            
            $stmt->execute([
                $data['title'],
                $data['content'],
                $data['category'],
                $locationId,
                $imageUrl,
                $data['is_breaking'] ?? false,
                $data['is_emergency'] ?? false,
                $data['status'] ?? $post['status'],
                $postId
            ]);
            
            // Update tags if provided
            if (isset($data['tags'])) {
                $this->updateTags($postId, $data['tags']);
            }
            
            $this->db->commit();
            
            return [
                'success' => true, 
                'message' => 'News post updated successfully.'
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("News post update failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update news post.'];
        }
    }
    
    /**
     * Delete a news post
     */
    public function deletePost($postId, $userId) {
        try {
            $post = $this->getPostById($postId);
            if (!$post) {
                return ['success' => false, 'message' => 'Post not found.'];
            }
            
            // Only the original author or admin can delete
            if ($post['user_id'] != $userId && !$this->isAdmin($userId)) {
                return ['success' => false, 'message' => 'Unauthorized to delete this post.'];
            }
            
            $this->db->beginTransaction();
            
            // Delete associated data first
            $this->db->prepare("DELETE FROM comments WHERE news_id = ?")->execute([$postId]);
            $this->db->prepare("DELETE FROM news_tags WHERE news_id = ?")->execute([$postId]);
            
            // Delete the post
            $stmt = $this->db->prepare("DELETE FROM news_posts WHERE id = ?");
            $stmt->execute([$postId]);
            
            // Delete the image file if it exists
            if ($post['image_url']) {
                $this->deleteImage($post['image_url']);
            }
            
            $this->db->commit();
            
            return ['success' => true, 'message' => 'Post deleted successfully.'];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Failed to delete post: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to delete post.'];
        }
    }
    
    /**
     * Get news posts with filters and pagination
     */
    public function getPosts($filters = [], $page = 1, $perPage = 10) {
        $where = [];
        $params = [];
        $joins = [];
        
        // Apply filters
        if (!empty($filters['category'])) {
            $where[] = "np.category = ?";
            $params[] = $filters['category'];
        }
        
        if (!empty($filters['status'])) {
            $where[] = "np.status = ?";
            $params[] = $filters['status'];
        } else {
            $where[] = "np.status = 'published'";
        }
        
        if (!empty($filters['user_id'])) {
            $where[] = "np.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(np.title LIKE ? OR np.content LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($filters['location_id'])) {
            $where[] = "np.location_id = ?";
            $params[] = $filters['location_id'];
        }
        
        // Add location filters (country, state, city)
        if (!empty($filters['country']) || !empty($filters['state']) || !empty($filters['city'])) {
            $joins[] = "INNER JOIN locations l ON np.location_id = l.id";
            
            if (!empty($filters['country'])) {
                $where[] = "l.country = ?";
                $params[] = $filters['country'];
            }
            
            if (!empty($filters['state'])) {
                $where[] = "l.state_province = ?";
                $params[] = $filters['state'];
            }
            
            if (!empty($filters['city'])) {
                $where[] = "l.city = ?";
                $params[] = $filters['city'];
            }
        }
        
        // Build the query
        $joinClause = !empty($joins) ? implode(" ", $joins) : "";
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        // Count total records for pagination
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total 
            FROM news_posts np
            $joinClause
            $whereClause
        ");
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Get paginated results
        $offset = ($page - 1) * $perPage;
        $orderBy = !empty($filters['order_by']) ? $filters['order_by'] : 'np.created_at DESC';
        
        $stmt = $this->db->prepare("
            SELECT 
                np.*, 
                u.username, 
                u.profile_image as author_image,
                l.country, 
                l.state_province, 
                l.city,
                (SELECT COUNT(*) FROM comments c WHERE c.news_id = np.id) as comment_count,
                (SELECT COUNT(*) FROM news_reactions nr WHERE nr.news_id = np.id) as reaction_count
            FROM news_posts np
            INNER JOIN users u ON np.user_id = u.id
            INNER JOIN locations l ON np.location_id = l.id
            $joinClause
            $whereClause
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        
        ");
        
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $posts = $stmt->fetchAll();
        
        // Get tags for each post
        foreach ($posts as &$post) {
            $post['tags'] = $this->getPostTags($post['id']);
        }
        
        return [
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    /**
     * Get a single post by ID
     */
    public function getPostById($postId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.*, 
                    u.username, 
                    u.profile_image as author_image,
                    l.country, 
                    l.state_province, 
                    l.city,
                    l.latitude,
                    l.longitude
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                WHERE np.id = ?
            
            ");
            
            $stmt->execute([$postId]);
            $post = $stmt->fetch();
            
            if ($post) {
                $post['tags'] = $this->getPostTags($postId);
            }
            
            return $post;
            
        } catch (PDOException $e) {
            error_log("Failed to get post: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get posts by a specific user
     */
    public function getPostsByUser($userId, $status = 'published', $page = 1, $perPage = 10) {
        return $this->getPosts(
            ['user_id' => $userId, 'status' => $status], 
            $page, 
            $perPage
        );
    }
    
    /**
     * Get related posts (same category or location)
     */
    public function getRelatedPosts($postId, $limit = 5) {
        $post = $this->getPostById($postId);
        if (!$post) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.id, 
                    np.title, 
                    np.image_url, 
                    np.created_at,
                    u.username,
                    l.city,
                    l.country
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                WHERE np.id != ? 
                AND np.status = 'published'
                AND (np.category = ? OR np.location_id = ?)
                ORDER BY np.created_at DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$postId, $post['category'], $post['location_id'], $limit]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get related posts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get posts by location (with distance calculation)
     */
    public function getPostsByLocation($lat, $lng, $radiusKm = 10, $limit = 20) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.id, 
                    np.title, 
                    np.content, 
                    np.image_url, 
                    np.created_at,
                    np.category,
                    u.username,
                    l.city,
                    l.country,
                    l.latitude,
                    l.longitude,
                    (6371 * acos( 
                        cos(radians(?)) * 
                        cos(radians(l.latitude)) * 
                        cos(radians(l.longitude) - radians(?)) + 
                        sin(radians(?)) * 
                        sin(radians(l.latitude))
                    )) AS distance
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                WHERE np.status = 'published'
                HAVING distance < ?
                ORDER BY 
                    np.is_breaking DESC,
                    np.is_emergency DESC,
                    distance ASC,
                    np.created_at DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$lat, $lng, $lat, $radiusKm, $limit]);
            $posts = $stmt->fetchAll();
            
            // Get tags for each post
            foreach ($posts as &$post) {
                $post['tags'] = $this->getPostTags($post['id']);
            }
            
            return $posts;
            
        } catch (PDOException $e) {
            error_log("Failed to get posts by location: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get trending posts (most viewed, commented, reacted)
     */
    public function getTrendingPosts($limit = 5, $timeframe = '1 WEEK') {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.id, 
                    np.title, 
                    np.image_url, 
                    np.created_at,
                    u.username,
                    l.city,
                    l.country,
                    COUNT(DISTINCT c.id) as comment_count,
                    COUNT(DISTINCT nr.id) as reaction_count,
                    COUNT(DISTINCT v.id) as view_count
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                LEFT JOIN comments c ON np.id = c.news_id
                LEFT JOIN news_reactions nr ON np.id = nr.news_id
                LEFT JOIN post_views v ON np.id = v.news_id
                WHERE np.status = 'published'
                AND np.created_at >= DATE_SUB(NOW(), INTERVAL $timeframe)
                GROUP BY np.id
                ORDER BY 
                    view_count DESC,
                    comment_count DESC,
                    reaction_count DESC,
                    np.created_at DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get trending posts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get breaking news (recent breaking or emergency posts)
     */
    public function getBreakingNews($limit = 5) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.id, 
                    np.title, 
                    np.content, 
                    np.image_url, 
                    np.created_at,
                    np.category,
                    u.username,
                    l.city,
                    l.country
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                WHERE np.status = 'published'
                AND (np.is_breaking = 1 OR np.is_emergency = 1)
                ORDER BY 
                    np.is_emergency DESC,
                    np.created_at DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get breaking news: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get post categories with count
     */
    public function getCategories() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    category, 
                    COUNT(*) as count
                FROM news_posts
                WHERE status = 'published'
                GROUP BY category
                ORDER BY count DESC
            
            ");
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get categories: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get popular tags
     */
    public function getPopularTags($limit = 20) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    t.name,
                    COUNT(nt.news_id) as count
                FROM tags t
                INNER JOIN news_tags nt ON t.id = nt.tag_id
                INNER JOIN news_posts np ON nt.news_id = np.id
                WHERE np.status = 'published'
                GROUP BY t.id
                ORDER BY count DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get popular tags: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get posts by tag
     */
    public function getPostsByTag($tagName, $page = 1, $perPage = 10) {
        try {
            // First, get the tag ID
            $stmt = $this->db->prepare("SELECT id FROM tags WHERE name = ?");
            $stmt->execute([$tagName]);
            $tag = $stmt->fetch();
            
            if (!$tag) {
                return [
                    'posts' => [],
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => 0,
                    'tag' => $tagName
                ];
            }
            
            // Count total posts with this tag
            $countStmt = $this->db->prepare("
                SELECT COUNT(DISTINCT np.id) as total
                FROM news_posts np
                INNER JOIN news_tags nt ON np.id = nt.news_id
                WHERE nt.tag_id = ?
                AND np.status = 'published'
            
            ");
            $countStmt->execute([$tag['id']]);
            $total = $countStmt->fetch()['total'];
            
            // Get paginated results
            $offset = ($page - 1) * $perPage;
            
            $stmt = $this->db->prepare("
                SELECT 
                    np.*, 
                    u.username, 
                    u.profile_image as author_image,
                    l.country, 
                    l.state_province, 
                    l.city
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                INNER JOIN news_tags nt ON np.id = nt.news_id
                WHERE nt.tag_id = ?
                AND np.status = 'published'
                ORDER BY np.created_at DESC
                LIMIT ? OFFSET ?
            
            ");
            
            $stmt->execute([$tag['id'], $perPage, $offset]);
            $posts = $stmt->fetchAll();
            
            // Get tags for each post
            foreach ($posts as &$post) {
                $post['tags'] = $this->getPostTags($post['id']);
            }
            
            return [
                'posts' => $posts,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
                'tag' => $tagName
            ];
            
        } catch (PDOException $e) {
            error_log("Failed to get posts by tag: " . $e->getMessage());
            return [
                'posts' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => 0,
                'tag' => $tagName
            ];
        }
    }
    
    /**
     * Get posts by category
     */
    public function getPostsByCategory($category, $page = 1, $perPage = 10) {
        return $this->getPosts(
            ['category' => $category, 'status' => 'published'], 
            $page, 
            $perPage
        );
    }
    
    /**
     * Get posts by date range
     */
    public function getPostsByDateRange($startDate, $endDate = null, $page = 1, $perPage = 10) {
        $filters = [
            'status' => 'published',
            'start_date' => $startDate,
            'end_date' => $endDate ?: date('Y-m-d')
        ];
        
        return $this->getPosts($filters, $page, $perPage);
    }
    
    /**
     * Get posts by search query
     */
    public function searchPosts($query, $page = 1, $perPage = 10) {
        return $this->getPosts(
            ['search' => $query, 'status' => 'published'], 
            $page, 
            $perPage
        );
    }
    
    /**
     * Get recent posts
     */
    public function getRecentPosts($limit = 5) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.id, 
                    np.title, 
                    np.image_url, 
                    np.created_at,
                    u.username,
                    l.city,
                    l.country
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                WHERE np.status = 'published'
                ORDER BY np.created_at DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get recent posts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get featured posts (manually selected by admins)
     */
    public function getFeaturedPosts($limit = 5) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    np.id, 
                    np.title, 
                    np.content, 
                    np.image_url, 
                    np.created_at,
                    np.category,
                    u.username,
                    l.city,
                    l.country
                FROM news_posts np
                INNER JOIN users u ON np.user_id = u.id
                INNER JOIN locations l ON np.location_id = l.id
                INNER JOIN featured_posts fp ON np.id = fp.news_id
                WHERE np.status = 'published'
                ORDER BY fp.featured_at DESC
                LIMIT ?
            
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get featured posts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get post statistics for dashboard
     */
    public function getPostStats($userId = null) {
        try {
            $where = '';
            $params = [];
            
            if ($userId) {
                $where = 'WHERE np.user_id = ?';
                $params[] = $userId;
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_posts,
                    SUM(CASE WHEN np.status = 'published' THEN 1 ELSE 0 END) as published_posts,
                    SUM(CASE WHEN np.status = 'pending' THEN 1 ELSE 0 END) as pending_posts,
                    SUM(CASE WHEN np.is_breaking = 1 THEN 1 ELSE 0 END) as breaking_news,
                    SUM(CASE WHEN np.is_emergency = 1 THEN 1 ELSE 0 END) as emergency_alerts,
                    COUNT(DISTINCT np.category) as categories_covered,
                    COUNT(DISTINCT np.location_id) as locations_covered
                FROM news_posts np
                $where
            
            ");
            
            $stmt->execute($params);
            $stats = $stmt->fetch();
            
            // Get recent activity
            $recentActivity = $this->getRecentActivity($userId);
            
            return [
                'stats' => $stats,
                'recent_activity' => $recentActivity
            ];
            
        } catch (PDOException $e) {
            error_log("Failed to get post statistics: " . $e->getMessage());
            return [
                'stats' => [
                    'total_posts' => 0,
                    'published_posts' => 0,
                    'pending_posts' => 0,
                    'breaking_news' => 0,
                    'emergency_alerts' => 0,
                    'categories_covered' => 0,
                    'locations_covered' => 0
                ],
                'recent_activity' => []
            ];
        }
    }
    
    /**
     * Get recent activity (posts, comments, reactions)
     */
    public function getRecentActivity($userId = null, $limit = 10) {
        try {
            $where = '';
            $params = [];
            
            if ($userId) {
                $where = 'WHERE np.user_id = ?';
                $params[] = $userId;
            }
            
            $stmt = $this->db->prepare("
                (
                    SELECT 
                        'post' as type,
                        np.id,
                        np.title,
                        np.created_at as activity_date,
                        u.username,
                        u.profile_image,
                        l.city,
                        l.country
                    FROM news_posts np
                    INNER JOIN users u ON np.user_id = u.id
                    INNER JOIN locations l ON np.location_id = l.id
                    $where
                    ORDER BY np.created_at DESC
                    LIMIT ?
                )
                UNION ALL
                (
                    SELECT 
                        'comment' as type,
                        c.id,
                        c.content as title,
                        c.created_at as activity_date,
                        u.username,
                        u.profile_image,
                        l.city,
                        l.country
                    FROM comments c
                    INNER JOIN users u ON c.user_id = u.id
                    INNER JOIN news_posts np ON c.news_id = np.id
                    INNER JOIN locations l ON np.location_id = l.id
                    " . ($userId ? 'WHERE c.user_id = ?' : '') . "
                    ORDER BY c.created_at DESC
                    LIMIT ?
                )
                UNION ALL
                (
                    SELECT 
                        'reaction' as type,
                        nr.id,
                        CONCAT(nr.reaction_type, ' on ', np.title) as title,
                        nr.created_at as activity_date,
                        u.username,
                        u.profile_image,
                        l.city,
                        l.country
                    FROM news_reactions nr
                    INNER JOIN users u ON nr.user_id = u.id
                    INNER JOIN news_posts np ON nr.news_id = np.id
                    INNER JOIN locations l ON np.location_id = l.id
                    " . ($userId ? 'WHERE nr.user_id = ?' : '') . "
                    ORDER BY nr.created_at DESC
                    LIMIT ?
                )
                ORDER BY activity_date DESC
                LIMIT ?
            
            ");
            
            $limitParam = (int)($limit / 3);
            $params = array_merge($params, $params, $params, [$limit]);
            
            $stmt->execute($params);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get recent activity: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get posts by status (for admin dashboard)
     */
    public function getPostsByStatus($status, $page = 1, $perPage = 10) {
        return $this->getPosts(
            ['status' => $status], 
            $page, 
            $perPage
        );
    }
    
    /**
     * Update post status (approve, reject, etc.)
     */
    public function updatePostStatus($postId, $status, $userId = null) {
        try {
            $validStatuses = ['pending', 'published', 'rejected', 'archived'];
            if (!in_array($status, $validStatuses)) {
                return ['success' => false, 'message' => 'Invalid status.'];
            }
            
            $post = $this->getPostById($postId);
            if (!$post) {
                return ['success' => false, 'message' => 'Post not found.'];
            }
            
            // Only admins/moderators can change status, or the author can only set to 'pending'
            $isAdmin = $this->isAdmin($userId);
            $isModerator = $this->isModerator($userId);
            $isAuthor = $post['user_id'] == $userId;
            
            if (!$isAdmin && !$isModerator && (!$isAuthor || $status !== 'pending')) {
                return ['success' => false, 'message' => 'Unauthorized to perform this action.'];
            }
            
            $stmt = $this->db->prepare("
                UPDATE news_posts 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            
            ");
            
            $stmt->execute([$status, $postId]);
            
            // Log this action
            if ($userId) {
                $this->logModerationAction($userId, $postId, 'status_change', $status);
            }
            
            return ['success' => true, 'message' => 'Post status updated successfully.'];
            
        } catch (PDOException $e) {
            error_log("Failed to update post status: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update post status.'];
        }
    }
    
    /**
     * Feature a post (for homepage)
     */
    public function featurePost($postId, $userId) {
        if (!$this->isAdmin($userId)) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }
        
        try {
            // Check if already featured
            $stmt = $this->db->prepare("SELECT * FROM featured_posts WHERE news_id = ?");
            $stmt->execute([$postId]);
            
            if ($stmt->fetch()) {
                return ['success' => true, 'message' => 'Post is already featured.'];
            }
            
            // Feature the post
            $stmt = $this->db->prepare("
                INSERT INTO featured_posts (news_id, featured_by, featured_at)
                VALUES (?, ?, NOW())
            
            ");
            
            $stmt->execute([$postId, $userId]);
            
            return ['success' => true, 'message' => 'Post featured successfully.'];
            
        } catch (PDOException $e) {
            error_log("Failed to feature post: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to feature post.'];
        }
    }
    
    /**
     * Remove post from featured
     */
    public function unfeaturePost($postId, $userId) {
        if (!$this->isAdmin($userId)) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }
        
        try {
            $stmt = $this->db->prepare("DELETE FROM featured_posts WHERE news_id = ?");
            $stmt->execute([$postId]);
            
            return ['success' => true, 'message' => 'Post removed from featured.'];
            
        } catch (PDOException $e) {
            error_log("Failed to unfeature post: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to unfeature post.'];
        }
    }
    
    /**
     * Log a moderation action
     */
    private function logModerationAction($userId, $postId, $action, $details = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO moderation_logs (user_id, news_id, action, details)
                VALUES (?, ?, ?, ?)
            
            ");
            
            $stmt->execute([
                $userId,
                $postId,
                $action,
                is_array($details) ? json_encode($details) : $details
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Failed to log moderation action: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get moderation logs
     */
    public function getModerationLogs($filters = [], $page = 1, $perPage = 20) {
        $where = [];
        $params = [];
        
        if (!empty($filters['user_id'])) {
            $where[] = 'ml.user_id = ?';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['post_id'])) {
            $where[] = 'ml.news_id = ?';
            $params[] = $filters['post_id'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = 'ml.action = ?';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['start_date'])) {
            $where[] = 'ml.created_at >= ?';
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $where[] = 'ml.created_at <= ?';
            $params[] = $filters['end_date'] . ' 23:59:59';
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        try {
            // Count total records
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total 
                FROM moderation_logs ml
                $whereClause
            
            ");
            $countStmt->execute($params);
            $total = $countStmt->fetch()['total'];
            
            // Get paginated results
            $offset = ($page - 1) * $perPage;
            
            $stmt = $this->db->prepare("
                SELECT 
                    ml.*, 
                    u.username as moderator_name,
                    np.title as post_title
                FROM moderation_logs ml
                LEFT JOIN users u ON ml.user_id = u.id
                LEFT JOIN news_posts np ON ml.news_id = np.id
                $whereClause
                ORDER BY ml.created_at DESC
                LIMIT ? OFFSET ?
            
            ");
            
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            $logs = $stmt->fetchAll();
            
            // Parse details if it's JSON
            foreach ($logs as &$log) {
                if ($log['details'] && ($decoded = json_decode($log['details'], true))) {
                    $log['details'] = $decoded;
                }
            }
            
            return [
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ];
            
        } catch (PDOException $e) {
            error_log("Failed to get moderation logs: " . $e->getMessage());
            return [
                'logs' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => 0
            ];
        }
    }
    
    /**
     * Get post views statistics
     */
    public function getPostViews($postId, $timeframe = '30 DAY') {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as views,
                    COUNT(DISTINCT ip_address) as unique_views
                FROM post_views
                WHERE news_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL $timeframe)
                GROUP BY DATE(created_at)
                ORDER BY date
            
            ");
            
            $stmt->execute([$postId]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get post views: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Track a post view
     */
    public function trackPostView($postId, $userId = null) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'];
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Don't track views from bots
            if ($this->isBot($userAgent)) {
                return false;
            }
            
            // Check if this IP has recently viewed this post (within the last hour)
            $stmt = $this->db->prepare("
                SELECT id 
                FROM post_views 
                WHERE news_id = ? 
                AND ip_address = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                LIMIT 1
            
            ");
            
            $stmt->execute([$postId, $ip]);
            
            if (!$stmt->fetch()) {
                // Insert new view
                $stmt = $this->db->prepare("
                    INSERT INTO post_views (news_id, user_id, ip_address, user_agent)
                    VALUES (?, ?, ?, ?)
                
                ");
                
                $stmt->execute([$postId, $userId, $ip, $userAgent]);
                
                // Update view count in posts table
                $this->db->prepare("
                    UPDATE news_posts 
                    SET view_count = view_count + 1 
                    WHERE id = ?
                
                ")->execute([$postId]);
                
                return true;
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Failed to track post view: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user agent is a bot
     */
    private function isBot($userAgent) {
        if (empty($userAgent)) {
            return true;
        }
        
        $bots = [
            'bot', 'spider', 'crawler', 'spyder', 'crawl', 'googlebot', 'bingbot', 
            'slurp', 'teoma', 'archive', 'track', 'snoopy', 'lwp', 'wget', 'curl', 
            'client', 'python', 'java', 'perl', 'ruby', 'php', 'scraper', 'monitor'
        ];
        
        foreach ($bots as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get or create location
     */
    private function getOrCreateLocation($data) {
        if (empty($data['country'])) {
            throw new Exception('Country is required.');
        }
        
        try {
            // Try to find existing location
            $where = ['country = ?'];
            $params = [$data['country']];
            
            if (!empty($data['state'])) {
                $where[] = 'state_province = ?';
                $params[] = $data['state'];
            } else {
                $where[] = 'state_province IS NULL';
            }
            
            if (!empty($data['city'])) {
                $where[] = 'city = ?';
                $params[] = $data['city'];
            } else {
                $where[] = 'city IS NULL';
            }
            
            $stmt = $this->db->prepare("
                SELECT id FROM locations 
                WHERE " . implode(' AND ', $where) . "
                LIMIT 1
            
            ");
            
            $stmt->execute($params);
            $location = $stmt->fetch();
            
            if ($location) {
                return $location['id'];
            }
            
            // Create new location
            $stmt = $this->db->prepare("
                INSERT INTO locations (
                    country, state_province, city, 
                    latitude, longitude, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            
            ");
            
            $stmt->execute([
                $data['country'],
                $data['state'] ?? null,
                $data['city'] ?? null,
                $data['latitude'] ?? null,
                $data['longitude'] ?? null
            ]);
            
            return $this->db->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Failed to get or create location: " . $e->getMessage());
            throw new Exception('Failed to process location.');
        }
    }
    
    /**
     * Handle image upload
     */
    private function handleImageUpload($file) {
        $uploadDir = $this->config['storage']['path'] . '/uploads/images/' . date('Y/m/d');
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = uniqid('img_') . '.' . $fileExt;
        $targetPath = $uploadDir . '/' . $fileName;
        
        // Validate file type
        $allowedTypes = $this->config['storage']['allowed_types'];
        if (!in_array($fileExt, $allowedTypes)) {
            throw new Exception('Invalid file type. Allowed types: ' . implode(', ', $allowedTypes));
        }
        
        // Validate file size
        $maxSize = $this->config['storage']['max_size'];
        if ($file['size'] > $maxSize) {
            throw new Exception('File is too large. Maximum size: ' . ($maxSize / 1024 / 1024) . 'MB');
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to upload file.');
        }
        
        // Generate different image sizes
        $this->generateImageSizes($targetPath);
        
        // Return relative path
        return '/storage/uploads/images/' . date('Y/m/d') . '/' . $fileName;
    }
    
    /**
     * Generate different image sizes
     */
    private function generateImageSizes($imagePath) {
        if (!extension_loaded('gd')) {
            return; // GD library not available
        }
        
        $sizes = $this->config['storage']['image_sizes'];
        $pathInfo = pathinfo($imagePath);
        
        foreach ($sizes as $sizeName => $dimensions) {
            list($width, $height) = $dimensions;
            $newPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . 
                      '_' . $sizeName . '.' . $pathInfo['extension'];
            
            $this->resizeImage($imagePath, $newPath, $width, $height);
        }
    }
    
    /**
     * Resize an image
     */
    private function resizeImage($sourcePath, $targetPath, $targetWidth, $targetHeight) {
        list($sourceWidth, $sourceHeight, $type) = getimagesize($sourcePath);
        
        // Calculate aspect ratio
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;
        
        if ($sourceRatio > $targetRatio) {
            // Source is wider than target
            $newWidth = $targetWidth;
            $newHeight = $targetWidth / $sourceRatio;
        } else {
            // Source is taller than target or square
            $newHeight = $targetHeight;
            $newWidth = $targetHeight * $sourceRatio;
        }
        
        // Create a new true color image
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        
        // Create a transparent background for PNG images
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($targetImage, false);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefill($targetImage, 0, 0, $transparent);
            imagesavealpha($targetImage, true);
        } else {
            // For JPEG, fill with white background
            $white = imagecolorallocate($targetImage, 255, 255, 255);
            imagefill($targetImage, 0, 0, $white);
        }
        
        // Load the source image
        switch ($type) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            default:
                return false;
        }
        
        // Calculate position to center the image
        $dstX = ($targetWidth - $newWidth) / 2;
        $dstY = ($targetHeight - $newHeight) / 2;
        
        // Resize the image
        imagecopyresampled(
            $targetImage, $sourceImage,
            $dstX, $dstY, 0, 0,
            $newWidth, $newHeight, $sourceWidth, $sourceHeight
        );
        
        // Save the resized image
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($targetImage, $targetPath, 90);
                break;
            case IMAGETYPE_PNG:
                imagepng($targetImage, $targetPath, 9);
                break;
            case IMAGETYPE_GIF:
                imagegif($targetImage, $targetPath);
                break;
        }
        
        // Free up memory
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        
        return true;
    }
    
    /**
     * Delete an image and its variations
     */
    private function deleteImage($imagePath) {
        if (empty($imagePath) || strpos($imagePath, '/storage/') !== 0) {
            return false; // Prevent directory traversal
        }
        
        $basePath = $this->config['storage']['path'] . '/..' . $imagePath;
        $pathInfo = pathinfo($basePath);
        
        // Delete all variations of the image
        $files = glob($pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_*.' . $pathInfo['extension']);
        $files[] = $basePath; // Add the original file
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        return true;
    }
    
    /**
     * Add tags to a post
     */
    private function addTags($postId, $tags) {
        if (empty($tags)) {
            return;
        }
        
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }
        
        try {
            $this->db->beginTransaction();
            
            foreach ($tags as $tagName) {
                $tagName = trim($tagName);
                if (empty($tagName)) continue;
                
                // Get or create tag
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO tags (name, slug) 
                    VALUES (?, ?)
                
                ");
                
                $slug = $this->createSlug($tagName);
                $stmt->execute([$tagName, $slug]);
                
                // Get tag ID
                $tagId = $this->db->lastInsertId();
                if (!$tagId) {
                    $stmt = $this->db->prepare("SELECT id FROM tags WHERE slug = ?");
                    $stmt->execute([$slug]);
                    $tag = $stmt->fetch();
                    $tagId = $tag['id'];
                }
                
                // Link tag to post
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO news_tags (news_id, tag_id)
                    VALUES (?, ?)
                
                ");
                
                $stmt->execute([$postId, $tagId]);
            }
            
            $this->db->commit();
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Failed to add tags: " . $e->getMessage());
        }
    }
    
    /**
     * Update post tags
     */
    private function updateTags($postId, $tags) {
        // First, remove all existing tags
        try {
            $this->db->prepare("DELETE FROM news_tags WHERE news_id = ?")->execute([$postId]);
        } catch (PDOException $e) {
            error_log("Failed to remove old tags: " . $e->getMessage());
            return false;
        }
        
        // Add new tags
        return $this->addTags($postId, $tags);
    }
    
    /**
     * Get tags for a post
     */
    private function getPostTags($postId) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.id, t.name, t.slug
                FROM tags t
                INNER JOIN news_tags nt ON t.id = nt.tag_id
                WHERE nt.news_id = ?
            
            ");
            
            $stmt->execute([$postId]);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Failed to get post tags: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create a URL-friendly slug
     */
    private function createSlug($text) {
        // Replace non-letter or non-number with -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        
        // Transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        
        // Remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);
        
        // Trim
        $text = trim($text, '-');
        
        // Remove duplicate -
        $text = preg_replace('~-+~', '-', $text);
        
        // Convert to lowercase
        $text = strtolower($text);
        
        if (empty($text)) {
            return 'n-a';
        }
        
        return $text;
    }
    
    /**
     * Check if user is admin
     */
    private function isAdmin($userId) {
        if (!$userId) return false;
        
        try {
            $stmt = $this->db->prepare("
                SELECT 1 
                FROM user_roles 
                WHERE user_id = ? AND role = 'admin'
                LIMIT 1
            
            ");
            
            $stmt->execute([$userId]);
            return (bool)$stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Failed to check admin status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user is moderator
     */
    private function isModerator($userId) {
        if (!$userId) return false;
        
        try {
            $stmt = $this->db->prepare("
                SELECT 1 
                FROM user_roles 
                WHERE user_id = ? AND role IN ('admin', 'moderator')
                LIMIT 1
            
            ");
            
            $stmt->execute([$userId]);
            return (bool)$stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Failed to check moderator status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate post data
     */
    private function validatePostData($data, $isUpdate = false) {
        $errors = [];
        
        if (empty($data['title'])) {
            $errors['title'] = 'Title is required';
        } elseif (mb_strlen($data['title']) > 200) {
            $errors['title'] = 'Title cannot be longer than 200 characters';
        }
        
        if (empty($data['content'])) {
            $errors['content'] = 'Content is required';
        } elseif (mb_strlen($data['content']) > 10000) {
            $errors['content'] = 'Content is too long';
        }
        
        if (empty($data['category'])) {
            $errors['category'] = 'Category is required';
        } else {
            $validCategories = [
                'politics', 'business', 'technology', 'health', 'entertainment',
                'sports', 'science', 'world', 'local', 'opinion', 'weather', 'crime'
            ];
            
            if (!in_array($data['category'], $validCategories)) {
                $errors['category'] = 'Invalid category';
            }
        }
        
        if (empty($data['country'])) {
            $errors['country'] = 'Country is required';
        }
        
        if (!empty($data['image_url']) && !filter_var($data['image_url'], FILTER_VALIDATE_URL)) {
            $errors['image_url'] = 'Invalid image URL';
        }
        
        return $errors;
    }
}

// Helper function to get a NewsManager instance
function getNewsManager() {
    static $manager = null;
    if ($manager === null) {
        $manager = new NewsManager();
    }
    return $manager;
}
