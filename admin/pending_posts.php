<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/NewsManager.php';
require_once __DIR__ . '/../includes/LocationManager.php';

// Initialize auth and check if user is admin
$auth = new Auth($db);
if (!$auth->isLoggedIn() || !$auth->hasRole('admin')) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

$newsManager = new NewsManager($db);
$locationManager = new LocationManager($db);

// Handle approval/rejection
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['post_id'])) {
    $postId = (int)$_POST['post_id'];
    $action = $_POST['action'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        if ($action === 'approve') {
            $result = $newsManager->approveNewsPost($postId, $_SESSION['user_id'], $notes);
            $message = $result['message'];
            $messageType = 'success';
        } elseif ($action === 'reject') {
            $result = $newsManager->rejectNewsPost($postId, $_SESSION['user_id'], $notes);
            $message = $result['message'];
            $messageType = 'success';
        } elseif ($action === 'delete') {
            $result = $newsManager->deleteNewsPost($postId, $_SESSION['user_id']);
            $message = $result['message'];
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'danger';
    }
}

// Get pending posts
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$pendingPosts = $newsManager->getPostsByStatus('pending', $perPage, $offset);
$totalPosts = $newsManager->countPostsByStatus('pending');
$totalPages = ceil($totalPosts / $perPage);

// Include header
$pageTitle = 'Pending Posts';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        
        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Pending Posts</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="/admin/" class="btn btn-sm btn-outline-secondary">Dashboard</a>
                    </div>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (empty($pendingPosts)): ?>
                <div class="alert alert-info">No pending posts to review.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingPosts as $post): ?>
                                <tr>
                                    <td>
                                        <a href="/admin/post_preview.php?id=<?php echo $post['id']; ?>" target="_blank">
                                            <?php echo htmlspecialchars($post['title']); ?>
                                            <?php if ($post['is_breaking']): ?>
                                                <span class="badge bg-danger">Breaking</span>
                                            <?php endif; ?>
                                            <?php if ($post['is_emergency']): ?>
                                                <span class="badge bg-warning text-dark">Emergency</span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php 
                                        $author = $auth->getUserById($post['user_id']);
                                        echo htmlspecialchars($author['username'] ?? 'Unknown');
                                        ?>
                                    </td>
                                    <td><?php echo ucfirst(htmlspecialchars($post['category'])); ?></td>
                                    <td>
                                        <?php 
                                        $location = $locationManager->getLocation($post['location_id']);
                                        echo $location ? htmlspecialchars($location['name']) : 'Unknown';
                                        ?>
                                    </td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($post['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="/admin/post_preview.php?id=<?php echo $post['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-eye"></i> Preview
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-success" 
                                                    data-bs-toggle="modal" data-bs-target="#approveModal<?php echo $post['id']; ?>">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $post['id']; ?>">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </button>
                                        </div>
                                        
                                        <!-- Approve Modal -->
                                        <div class="modal fade" id="approveModal<?php echo $post['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="post" action="">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Approve Post</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to approve this post?</p>
                                                            <div class="mb-3">
                                                                <label for="notes" class="form-label">Notes (optional):</label>
                                                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">Approve</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Reject Modal -->
                                        <div class="modal fade" id="rejectModal<?php echo $post['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="post" action="">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Reject Post</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>Please provide a reason for rejecting this post:</p>
                                                            <div class="mb-3">
                                                                <label for="notes" class="form-label">Reason for rejection:</label>
                                                                <textarea class="form-control" id="notes" name="notes" rows="3" required></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger">Reject</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
                
            <?php endif; ?>
            
        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
