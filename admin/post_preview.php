<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/NewsManager.php';
require_once __DIR__ . '/includes/LocationManager.php';

// Initialize auth and check if user is admin
$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->hasRole('admin')) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

$newsManager = new NewsManager($db);
$locationManager = new LocationManager($db);

// Get post ID from query string
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$postId) {
    header('Location: /admin/pending_posts.php');
    exit();
}

// Get post details
$post = $newsManager->getPostById($postId);

if (!$post) {
    $_SESSION['error_message'] = 'Post not found';
    header('Location: /admin/pending_posts.php');
    exit();
}

// Get post author
$author = $auth->getUserById($post['user_id']);

// Get post location
$location = $locationManager->getLocation($post['location_id']);

// Get post images and videos
$images = [];
if (!empty($post['image_url'])) {
    $images[] = [
        'url' => $post['image_url'],
        'is_primary' => true
    ];
}

$videos = $newsManager->getPostVideos($postId);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        if ($action === 'approve') {
            $result = $newsManager->approveNewsPost($postId, $_SESSION['user_id'], $notes);
            $_SESSION['success_message'] = $result['message'];
            header('Location: /admin/pending_posts.php');
            exit();
        } elseif ($action === 'reject') {
            $result = $newsManager->rejectNewsPost($postId, $_SESSION['user_id'], $notes);
            $_SESSION['success_message'] = $result['message'];
            header('Location: /admin/pending_posts.php');
            exit();
        } elseif ($action === 'delete') {
            $result = $newsManager->deleteNewsPost($postId, $_SESSION['user_id']);
            $_SESSION['success_message'] = $result['message'];
            header('Location: /admin/pending_posts.php');
            exit();
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Set page title
$pageTitle = 'Preview: ' . htmlspecialchars($post['title']);

// Include header
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Preview Post</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="/admin/pending_posts.php" class="btn btn-sm btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Pending Posts
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                            <i class="bi bi-check-lg"></i> Approve
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="bi bi-x-lg"></i> Reject
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-lg-8">
                    <!-- Post Content -->
                    <article class="card mb-4">
                        <?php if (!empty($post['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($post['image_url']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($post['title']); ?>">
                        <?php endif; ?>
                        
                        <div class="card-body">
                            <h1 class="card-title"><?php echo htmlspecialchars($post['title']); ?></h1>
                            
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="text-muted small">
                                    <span><i class="bi bi-person"></i> 
                                        <?php echo htmlspecialchars($author['username'] ?? 'Unknown'); ?>
                                    </span>
                                    <span class="mx-2">•</span>
                                    <span><i class="bi bi-calendar"></i> 
                                        <?php echo date('F j, Y', strtotime($post['created_at'])); ?>
                                    </span>
                                    <span class="mx-2">•</span>
                                    <span><i class="bi bi-geo-alt"></i> 
                                        <?php echo htmlspecialchars($location['name'] ?? 'Unknown Location'); ?>
                                    </span>
                                </div>
                                
                                <div>
                                    <?php if ($post['is_breaking']): ?>
                                        <span class="badge bg-danger me-1">Breaking</span>
                                    <?php endif; ?>
                                    <?php if ($post['is_emergency']): ?>
                                        <span class="badge bg-warning text-dark">Emergency</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="post-content mb-4">
                                <?php echo $post['content']; ?>
                            </div>
                            
                            <?php if (!empty($videos)): ?>
                                <div class="mb-4">
                                    <h5>Videos</h5>
                                    <div class="row g-3">
                                        <?php foreach ($videos as $video): ?>
                                            <div class="col-md-6">
                                                <div class="ratio ratio-16x9 mb-3">
                                                    <?php if ($video['video_type'] === 'youtube'): ?>
                                                        <iframe src="https://www.youtube.com/embed/<?php echo htmlspecialchars($video['video_id']); ?>" 
                                                                title="<?php echo htmlspecialchars($video['title']); ?>" 
                                                                allowfullscreen></iframe>
                                                    <?php elseif ($video['video_type'] === 'vimeo'): ?>
                                                        <iframe src="https://player.vimeo.com/video/<?php echo htmlspecialchars($video['video_id']); ?>" 
                                                                title="<?php echo htmlspecialchars($video['title']); ?>" 
                                                                frameborder="0" 
                                                                allow="autoplay; fullscreen; picture-in-picture" 
                                                                allowfullscreen></iframe>
                                                    <?php else: ?>
                                                        <video controls class="w-100">
                                                            <source src="<?php echo htmlspecialchars($video['video_url']); ?>" type="video/mp4">
                                                            Your browser does not support the video tag.
                                                        </video>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($video['title'])): ?>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($video['title']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            // Get post tags
                            $tags = $newsManager->getPostTags($postId);
                            if (!empty($tags)): 
                            ?>
                                <div class="mt-4 pt-3 border-top">
                                    <div class="d-flex align-items-center">
                                        <span class="me-2"><i class="bi bi-tags"></i> Tags:</span>
                                        <div>
                                            <?php foreach ($tags as $tag): ?>
                                                <a href="/tag/<?php echo urlencode($tag['slug']); ?>" class="badge bg-secondary text-decoration-none me-1">
                                                    <?php echo htmlspecialchars($tag['name']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-footer bg-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="text-muted small">
                                    <span>Status: </span>
                                    <?php 
                                    $statusClass = [
                                        'draft' => 'text-secondary',
                                        'pending' => 'text-warning',
                                        'published' => 'text-success',
                                        'rejected' => 'text-danger',
                                        'archived' => 'text-muted'
                                    ][$post['status']] ?? 'text-secondary';
                                    ?>
                                    <span class="<?php echo $statusClass; ?>">
                                        <?php echo ucfirst(htmlspecialchars($post['status'])); ?>
                                    </span>
                                </div>
                                
                                <div>
                                    <a href="/admin/post_edit.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                    
                    <!-- Moderation History -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Moderation History</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php 
                            $moderationLogs = $newsManager->getModerationLogs($postId);
                            if (!empty($moderationLogs)): 
                            ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($moderationLogs as $log): 
                                        $user = $auth->getUserById($log['user_id']);
                                        $actionClass = [
                                            'created' => 'bg-light',
                                            'updated' => 'bg-light',
                                            'submitted' => 'bg-info bg-opacity-10',
                                            'approved' => 'bg-success bg-opacity-10',
                                            'rejected' => 'bg-danger bg-opacity-10',
                                            'published' => 'bg-success bg-opacity-10',
                                            'unpublished' => 'bg-warning bg-opacity-10',
                                            'deleted' => 'bg-danger bg-opacity-10'
                                        ][strtolower($log['action'])] ?? '';
                                    ?>
                                        <div class="list-group-item <?php echo $actionClass; ?>">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">
                                                    <?php echo ucfirst(htmlspecialchars($log['action'])); ?>
                                                    <?php if ($log['action'] === 'rejected'): ?>
                                                        <span class="badge bg-danger">Rejected</span>
                                                    <?php elseif ($log['action'] === 'approved'): ?>
                                                        <span class="badge bg-success">Approved</span>
                                                    <?php endif; ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                                </small>
                                            </div>
                                            <div class="d-flex align-items-center mb-1">
                                                <small class="text-muted">
                                                    <i class="bi bi-person"></i> 
                                                    <?php echo htmlspecialchars($user['username'] ?? 'System'); ?>
                                                </small>
                                            </div>
                                            <?php if (!empty($log['details'])): ?>
                                                <div class="mt-1">
                                                    <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($log['details'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="p-3 text-center text-muted">
                                    No moderation history available for this post.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Post Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Post Details</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Author:</span>
                                        <span>
                                            <a href="/admin/user_edit.php?id=<?php echo $post['user_id']; ?>">
                                                <?php echo htmlspecialchars($author['username'] ?? 'Unknown'); ?>
                                            </a>
                                        </span>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Category:</span>
                                        <span><?php echo ucfirst(htmlspecialchars($post['category'])); ?></span>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Location:</span>
                                        <span>
                                            <?php 
                                            $locationParts = [];
                                            if (!empty($location['city_name'])) $locationParts[] = $location['city_name'];
                                            if (!empty($location['state_name'])) $locationParts[] = $location['state_name'];
                                            if (!empty($location['country_name'])) $locationParts[] = $location['country_name'];
                                            echo htmlspecialchars(implode(', ', $locationParts));
                                            ?>
                                        </span>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Created:</span>
                                        <span><?php echo date('M j, Y g:i A', strtotime($post['created_at'])); ?></span>
                                    </div>
                                </li>
                                <?php if ($post['published_at']): ?>
                                    <li class="list-group-item px-0">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Published:</span>
                                            <span><?php echo date('M j, Y g:i A', strtotime($post['published_at'])); ?></span>
                                        </div>
                                    </li>
                                <?php endif; ?>
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Last Updated:</span>
                                        <span><?php echo date('M j, Y g:i A', strtotime($post['updated_at'])); ?></span>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Status:</span>
                                        <span class="badge bg-<?php 
                                            echo [
                                                'draft' => 'secondary',
                                                'pending' => 'warning',
                                                'published' => 'success',
                                                'rejected' => 'danger',
                                                'archived' => 'dark'
                                            ][$post['status']] ?? 'secondary';
                                        ?>">
                                            <?php echo ucfirst(htmlspecialchars($post['status'])); ?>
                                        </span>
                                    </div>
                                </li>
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Views:</span>
                                        <span><?php echo number_format($post['view_count']); ?></span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Featured Image -->
                    <?php if (!empty($post['image_url'])): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-image me-2"></i>Featured Image</h5>
                            </div>
                            <div class="card-body text-center">
                                <img src="<?php echo htmlspecialchars($post['image_url']); ?>" class="img-fluid rounded" alt="Featured Image">
                                <div class="mt-2">
                                    <a href="<?php echo htmlspecialchars($post['image_url']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-box-arrow-up-right"></i> View Full Size
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($post['status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-success mb-2" data-bs-toggle="modal" data-bs-target="#approveModal">
                                        <i class="bi bi-check-lg"></i> Approve Post
                                    </button>
                                    <button type="button" class="btn btn-danger mb-2" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                        <i class="bi bi-x-lg"></i> Reject Post
                                    </button>
                                <?php elseif ($post['status'] === 'published'): ?>
                                    <a href="/post/<?php echo $post['slug']; ?>" class="btn btn-primary mb-2" target="_blank">
                                        <i class="bi bi-eye"></i> View on Site
                                    </a>
                                    <form method="post" action="" class="d-grid">
                                        <input type="hidden" name="action" value="unpublish">
                                        <button type="submit" class="btn btn-warning mb-2" onclick="return confirm('Are you sure you want to unpublish this post?');">
                                            <i class="bi bi-x-circle"></i> Unpublish
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <a href="/admin/post_edit.php?id=<?php echo $post['id']; ?>" class="btn btn-outline-primary mb-2">
                                    <i class="bi bi-pencil"></i> Edit Post
                                </a>
                                
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="bi bi-trash"></i> Delete Post
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="approveModalLabel">Approve Post</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this post? It will be published on the site.</p>
                    <div class="mb-3">
                        <label for="approveNotes" class="form-label">Notes (optional):</label>
                        <textarea class="form-control" id="approveNotes" name="notes" rows="3" placeholder="Add any notes about this approval"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="approve">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Post</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="rejectModalLabel">Reject Post</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Please provide a reason for rejecting this post. The author will be notified.</p>
                    <div class="mb-3">
                        <label for="rejectReason" class="form-label">Reason for rejection: <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejectReason" name="notes" rows="3" required placeholder="Please specify why this post is being rejected"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="reject">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Post</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="" onsubmit="return confirm('Are you absolutely sure you want to delete this post? This action cannot be undone.');">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">Delete Post</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this post? This action cannot be undone.</p>
                    <div class="mb-3">
                        <label for="deleteReason" class="form-label">Reason for deletion (optional):</label>
                        <textarea class="form-control" id="deleteReason" name="notes" rows="3" placeholder="Add a reason for deletion (optional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="delete">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Post</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
