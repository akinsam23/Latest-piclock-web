<?php
// admin/place-preview.php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Only allow admins to access this page
requireAdmin();

$db = getDBConnection();
$place_id = intval($_GET['id'] ?? 0);

// Get the place details
$stmt = $db->prepare("
    SELECT p.*, u.username 
    FROM places p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.place_id = ?
");
$stmt->execute([$place_id]);
$place = $stmt->fetch();

if (!$place) {
    header('HTTP/1.0 404 Not Found');
    die('Place not found.');
}

// Get nearby places
$nearby_places = [];
if (!empty($place['nearby_links'])) {
    $nearby_ids = json_decode($place['nearby_links'], true);
    if (is_array($nearby_ids) && !empty($nearby_ids)) {
        $placeholders = str_repeat('?,', count($nearby_ids) - 1) . '?';
        $stmt = $db->prepare("
            SELECT place_id, place_name, category, image_url, city_region 
            FROM places 
            WHERE place_id IN ($placeholders) AND status = 'approved'
        ");
        $stmt->execute($nearby_ids);
        $nearby_places = $stmt->fetchAll();
    }
}

// Get category icon
function getCategoryIcon($category) {
    $icons = [
        'Restaurant' => 'fas fa-utensils',
        'Cafe/Bar' => 'fas fa-coffee',
        'Park/Outdoor' => 'fas fa-tree',
        'Church' => 'fas fa-church',
        'Mosque' => 'fas fa-mosque',
        'Temple' => 'fas fa-place-of-worship',
        'Retail Store' => 'fas fa-shopping-bag',
        'Other' => 'fas fa-map-marker-alt'
    ];
    return $icons[$category] ?? 'fas fa-map-marker-alt';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: <?= htmlspecialchars($place['place_name']) ?> - Admin Panel</title>
    <link href="[https://cdn.jsdelivr.net/npm/bootstrap[5.3.0/dist/css/bootstrap.min.css](https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css)"](cci:4://file://5.3.0/dist/css/bootstrap.min.css](https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css)":0:0-0:0) rel="stylesheet">
    <link rel="stylesheet" href="[https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css](https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css)">
    <link rel="stylesheet" href="[https://unpkg.com/leaflet[1.9.4/dist/leaflet.css](https://unpkg.com/leaflet@1.9.4/dist/leaflet.css)"](cci:4://file://1.9.4/dist/leaflet.css](https://unpkg.com/leaflet@1.9.4/dist/leaflet.css)":0:0-0:0) />
    <style>
        .preview-header {
            position: relative;
            margin-bottom: 2rem;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .preview-media {
            width: 100%;
            max-height: 500px;
            object-fit: cover;
        }
        .video-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 Aspect Ratio */
            height: 0;
            overflow: hidden;
        }
        .video-container iframe,
        .video-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
        .map-container {
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .nearby-place-card {
            transition: transform 0.2s;
            margin-bottom: 1rem;
        }
        .nearby-place-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .nearby-place-img {
            height: 120px;
            object-fit: cover;
        }
        .category-badge {
            font-size: 0.9rem;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            margin-bottom: 1.5rem;
            color: #6c757d;
            text-decoration: none;
        }
        .back-link:hover {
            color: #0d6efd;
        }
        .admin-actions {
            position: sticky;
            top: 20px;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <?php include 'includes/admin_header.php'; ?>
    
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="/admin/" class="back-link">
                <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
            </a>
            
            <div class="btn-group">
                <?php if ($place['status'] === 'pending'): ?>
                    <form method="POST" action="/admin/update-status.php" class="me-2">
                        <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-1"></i> Approve
                        </button>
                    </form>
                    
                    <form method="POST" action="/admin/update-status.php">
                        <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger" 
                                onclick="return confirm('Are you sure you want to reject this submission?');">
                            <i class="fas fa-times me-1"></i> Reject
                        </button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-<?= $place['status'] === 'approved' ? 'success' : 'danger' ?> p-2">
                        <?= ucfirst($place['status']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="preview-header">
            <?php if (!empty($place['video_source'])): ?>
                <?php if (strpos($place['video_source'], 'youtube.com') !== false || strpos($place['video_source'], 'youtu.be') !== false): ?>
                    <div class="video-container">
                        <iframe src="<?= htmlspecialchars($place['video_source']) ?>" 
                                title="<?= htmlspecialchars($place['place_name']) ?>" 
                                allowfullscreen></iframe>
                    </div>
                <?php else: ?>
                    <div class="video-container">
                        <video controls>
                            <source src="<?= htmlspecialchars($place['video_source']) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <img src="<?= htmlspecialchars($place['image_url']) ?>" 
                     class="preview-media" 
                     alt="<?= htmlspecialchars($place['place_name']) ?>">
            <?php endif; ?>
        </div>
        
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h1 class="h3 mb-0"><?= htmlspecialchars($place['place_name']) ?></h1>
                            <span class="badge bg-primary category-badge">
                                <i class="<?= getCategoryIcon($place['category']) ?> me-1"></i>
                                <?= htmlspecialchars($place['category']) ?>
                            </span>
                        </div>
                        
                        <div class="d-flex align-items-center text-muted mb-4">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <span class="me-3"><?= htmlspecialchars($place['city_region']) ?></span>
                            
                            <i class="fas fa-user me-2"></i>
                            <span class="me-3">Submitted by <?= htmlspecialchars($place['username']) ?></span>
                            
                            <i class="far fa-calendar-alt me-2"></i>
                            <span><?= date('M j, Y', strtotime($place['created_at'])) ?></span>
                        </div>
                        
                        <div class="mb-4">
                            <h5 class="mb-3">Description</h5>
                            <p class="lead"><?= nl2br(htmlspecialchars($place['description'])) ?></p>
                        </div>
                        
                        <div class="mb-4">
                            <h5 class="mb-3">Location</h5>
                            <div class="map-container" id="map"></div>
                            <div class="mt-2 text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Coordinates: <?= number_format($place['latitude'], 6) ?>, <?= number_format($place['longitude'], 6) ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($nearby_places)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Linked Nearby Places</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($nearby_places as $nearby): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card h-100 nearby-place-card">
                                            <div class="row g-0">
                                                <div class="col-md-4">
                                                    <?php if (!empty($nearby['image_url'])): ?>
                                                        <img src="<?= htmlspecialchars($nearby['image_url']) ?>" 
                                                             class="img-fluid rounded-start nearby-place-img" 
                                                             alt="<?= htmlspecialchars($nearby['place_name']) ?>">
                                                    <?php else: ?>
                                                        <div class="bg-light d-flex align-items-center justify-content-center" 
                                                             style="height: 100%; min-height: 120px;">
                                                            <i class="fas fa-image fa-2x text-muted"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-8">
                                                    <div class="card-body">
                                                        <h6 class="card-title"><?= htmlspecialchars($nearby['place_name']) ?></h6>
                                                        <p class="card-text">
                                                            <small class="text-muted">
                                                                <i class="<?= getCategoryIcon($nearby['category']) ?> me-1"></i>
                                                                <?= htmlspecialchars($nearby['category']) ?>
                                                            </small>
                                                        </p>
                                                        <a href="/admin/place-preview.php?id=<?= $nearby['place_id'] ?>" 
                                                           class="btn btn-sm btn-outline-primary">
                                                            View Details
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="col-lg-4">
                <div class="card mb-4 admin-actions">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Admin Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <?php if ($place['status'] === 'pending'): ?>
                                <form method="POST" action="/admin/update-status.php" class="mb-2">
                                    <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn btn-success w-100 mb-2">
                                        <i class="fas fa-check me-1"></i> Approve
                                    </button>
                                </form>
                                
                                <form method="POST" action="/admin/update-status.php" class="mb-2">
                                    <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn btn-danger w-100 mb-2" 
                                            onclick="return confirm('Are you sure you want to reject this submission?');">
                                        <i class="fas fa-times me-1"></i> Reject
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-<?= $place['status'] === 'approved' ? 'success' : 'danger' ?> text-center">
                                    <i class="fas fa-<?= $place['status'] === 'approved' ? 'check-circle' : 'times-circle' ?> me-1"></i>
                                    This place has been <?= $place['status'] ?>.
                                </div>
                                
                                <?php if ($place['status'] === 'rejected'): ?>
                                    <form method="POST" action="/admin/update-status.php" class="mb-2">
                                        <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-success w-100 mb-2">
                                            <i class="fas fa-check me-1"></i> Approve Anyway
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="/admin/update-status.php" class="mb-2">
                                        <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-danger w-100 mb-2" 
                                                onclick="return confirm('Are you sure you want to reject this place?');">
                                            <i class="fas fa-times me-1"></i> Reject
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <a href="/admin/edit-place.php?id=<?= $place['place_id'] ?>" 
                               class="btn btn-outline-primary w-100 mb-2">
                                <i class="fas fa-edit me-1"></i> Edit
                            </a>
                            
                            <button class="btn btn-outline-danger w-100" 
                                    onclick="if(confirm('Are you sure you want to delete this place? This cannot be undone.')) { 
                                        document.getElementById('deleteForm').submit(); 
                                    }">
                                <i class="fas fa-trash-alt me-1"></i> Delete
                            </button>
                            <form id="deleteForm" method="POST" action="/admin/delete-place.php" class="d-none">
                                <input type="hidden" name="place_id" value="<?= $place['place_id'] ?>">
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Submission Details</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Status</span>
                                <span class="badge bg-<?= $place['status'] === 'approved' ? 'success' : ($place['status'] === 'pending' ? 'warning' : 'danger') ?>">
                                    <?= ucfirst($place['status']) ?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Submitted By</span>
                                <span><?= htmlspecialchars($place['username']) ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Submitted On</span>
                                <span><?= date('M j, Y g:i A', strtotime($place['created_at'])) ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span>Last Updated</span>
                                <span><?= date('M j, Y g:i A', strtotime($place['updated_at'])) ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <div