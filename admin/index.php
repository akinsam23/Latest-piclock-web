<?php
// admin/index.php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Only allow admins to access this page
requireAdmin();

$db = getDBConnection();

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['place_id'])) {
    $place_id = intval($_POST['place_id']);
    $action = $_POST['action'];
    
    if (in_array($action, ['approve', 'reject'])) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        
        $stmt = $db->prepare("
            UPDATE places 
            SET status = ? 
            WHERE place_id = ? AND status = 'pending'
        ");
        
        if ($stmt->execute([$status, $place_id]) && $stmt->rowCount() > 0) {
            $_SESSION['flash_message'] = "Place {$status} successfully.";
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = "Failed to update place status.";
            $_SESSION['flash_type'] = 'danger';
        }
        
        // Redirect to avoid form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get pending submissions
$stmt = $db->query("
    SELECT p.*, u.username 
    FROM places p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.status = 'pending'
    ORDER BY p.created_at DESC
");
$pending_submissions = $stmt->fetchAll();

// Get recent approvals/rejections
$stmt = $db->query("
    SELECT p.*, u.username, p.updated_at as action_date 
    FROM places p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.status IN ('approved', 'rejected')
    ORDER BY p.updated_at DESC 
    LIMIT 10
");
$recent_actions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .sidebar {
            min-height: calc(100vh - 56px);
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }
        .sidebar .nav-link {
            color: #333;
            border-radius: 0.25rem;
            margin: 0.25rem 0;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: #e9ecef;
        }
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }
        .card {
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .card-header {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        .media-preview {
            width: 100px;
            height: 70px;
            object-fit: cover;
            border-radius: 4px;
        }
        .badge-pending {
            background-color: #ffc107;
            color: #000;
        }
        .badge-approved {
            background-color: #198754;
        }
        .badge-rejected {
            background-color: #dc3545;
        }
        .action-buttons .btn {
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/admin_header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block sidebar py-3">
                <div class="position-sticky">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="/admin/">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/admin/submissions.php">
                                <i class="fas fa-list"></i> All Submissions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/admin/users.php">
                                <i class="fas fa-users"></i> Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/admin/categories.php">
                                <i class="fas fa-tags"></i> Categories
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/admin/settings.php">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                        </li>
                        <li class="nav-item mt-4">
                            <a class="nav-link text-danger" href="/admin/logout.php">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">Export</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">Print</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle">
                            <i class="fas fa-calendar"></i> This week
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Pending Submissions</h6>
                                        <h2 class="mb-0"><?= count($pending_submissions) ?></h2>
                                    </div>
                                    <i class="fas fa-clock fa-3x opacity-50"></i>
                                </div>
                            </div>
                            <div class="card-footer d-flex align-items-center justify-content-between">
                                <a class="small text-white stretched-link" href="#pending-submissions">View Details</a>
                                <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Approved Places</h6>
                                        <?php
                                        $stmt = $db->query("SELECT COUNT(*) as count FROM places WHERE status = 'approved'");
                                        $approved_count = $stmt->fetch()['count'];
                                        ?>
                                        <h2 class="mb-0"><?= $approved_count ?></h2>
                                    </div>
                                    <i class="fas fa-check-circle fa-3x opacity-50"></i>
                                </div>
                            </div>
                            <div class="card-footer d-flex align-items-center justify-content-between">
                                <a class="small text-white stretched-link" href="/admin/submissions.php?status=approved">View All</a>
                                <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Rejected Submissions</h6>
                                        <?php
                                        $stmt = $db->query("SELECT COUNT(*) as count FROM places WHERE status = 'rejected'");
                                        $rejected_count = $stmt->fetch()['count'];
                                        ?>
                                        <h2 class="mb-0"><?= $rejected_count ?></h2>
                                    </div>
                                    <i class="fas fa-times-circle fa-3x opacity-50"></i>
                                </div>
                            </div>
                            <div class="card-footer d-flex align-items-center justify-content-between">
                                <a class="small text-white stretched-link" href="/admin/submissions.php?status=rejected">View All</a>
                                <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Submissions -->
                <div class="card mb-4" id="pending-submissions">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-clock me-2"></i> Pending Submissions</span>
                        <span class="badge bg-primary rounded-pill"><?= count($pending_submissions) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_submissions)): ?>
                            <div class="alert alert-info mb-0">No pending submissions at the moment.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Place</th>
                                            <th>Category</th>
                                            <th>Submitted By</th>
                                            <th>Date Submitted</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_submissions as $submission): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($submission['image_url'])): ?>
                                                            <img src="<?= htmlspecialchars($submission['image_url']) ?>" 
                                                                 class="me-3 media-preview" 
                                                                 alt="<?= htmlspecialchars($submission['place_name']) ?>">
                                                        <?php elseif (!empty($submission['video_source'])): ?>
                                                            <div class="me-3" style="width: 100px; height: 70px; background: #f0f0f0; 
                                                                                    display: flex; align-items: center; justify-content: center; 
                                                                                    border-radius: 4px;">
                                                                <i class="fas fa-video text-muted"></i>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="me-3" style="width: 100px; height: 70px; background: #f0f0f0; 
                                                                                    display: flex; align-items: center; justify-content: center; 
                                                                                    border-radius: 4px;">
                                                                <i class="fas fa-image text-muted"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($submission['place_name']) ?></h6>
                                                            <small class="text-muted"><?= htmlspecialchars($submission['city_region']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($submission['category']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($submission['username']) ?></td>
                                                <td><?= date('M j, Y', strtotime($submission['created_at'])) ?></td>
                                                <td class="action-buttons">
                                                    <a href="/place-preview.php?id=<?= $submission['place_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="fas fa-eye"></i> Preview
                                                    </a>
                                                    <form method="POST" style="display: inline-block;" 
                                                          onsubmit="return confirm('Are you sure you want to approve this submission?');">
                                                        <input type="hidden" name="place_id" value="<?= $submission['place_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn btn-sm btn-success">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline-block;" 
                                                          onsubmit="return confirm('Are you sure you want to reject this submission?');">
                                                        <input type="hidden" name="place_id" value="<?= $submission['place_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Actions -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history me-2"></i> Recent Actions
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_actions)): ?>
                            <div class="alert alert-info mb-0">No recent actions to display.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Place</th>
                                            <th>Action</th>
                                            <th>By</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_actions as $action): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($action['image_url'])): ?>
                                                            <img src="<?= htmlspecialchars($action['image_url']) ?>" 
                                                                 class="me-3 media-preview" 
                                                                 alt="<?= htmlspecialchars($action['place_name']) ?>">
                                                        <?php else: ?>
                                                            <div class="me-3" style="width: 60px; height: 40px; background: #f0f0f0; 
                                                                                    display: flex; align-items: center; justify-content: center; 
                                                                                    border-radius: 4px;">
                                                                <i class="fas fa-image text-muted"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($action['place_name']) ?></h6>
                                                            <small class="text-muted"><?= htmlspecialchars($action['city_region']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($action['status'] === 'approved'): ?>
                                                        <span class="badge bg-success">Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Rejected</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($action['username']) ?></td>
                                                <td><?= date('M j, Y g:i A', strtotime($action['action_date'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enable tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>