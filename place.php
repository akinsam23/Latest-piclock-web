<?php
// place.php
require_once 'config/database.php';
require_once 'includes/auth.php';

$db = getDBConnection();

$place_id = intval($_GET['id'] ?? 0);

// Get the place details
$stmt = $db->prepare("
    SELECT p.*, u.username 
    FROM places p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.place_id = ? AND p.status = 'approved'
");
$stmt->execute([$place_id]);
$place = $stmt->fetch();

if (!$place) {
    header('HTTP/1.0 404 Not Found');
    die('Place not found or not approved yet.');
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

// Get other nearby places based on coordinates
$stmt = $db->prepare("
    SELECT place_id, place_name, category, image_url, city_region,
           (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * 
           cos(radians(longitude) - radians(?)) + 
           sin(radians(?)) * sin(radians(latitude)))) AS distance
    FROM places 
    WHERE place_id != ? AND status = 'approved'
    HAVING distance < 10  -- Within 10km
    ORDER BY distance ASC
    LIMIT 5
");
$stmt->execute([
    $place['latitude'], 
    $place['longitude'],
    $place['latitude'],
    $place['place_id']
]);
$geographic_nearby = $stmt->fetchAll();

// Combine both nearby places lists, removing duplicates
$all_nearby = [];
$added_ids = [$place['place_id']]; // Don't include the current place

foreach (array_merge($nearby_places, $geographic_nearby) as $np) {
    if (!in_array($np['place_id'], $added_ids)) {
        $all_nearby[] = $np;
        $added_ids[] = $np['place_id'];
        if (count($all_nearby) >= 5) break;
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
    <title><?= htmlspecialchars($place['place_name']) ?> - Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .place-header {
            position: relative;
            margin-bottom: 2rem;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .place-media {
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
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-5">
        <a href="/" class="back-link">
            <i class="fas fa-arrow-left me-2"></i> Back to all places
        </a>
        
        <div class="place-header mb-4">
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
                     class="place-media" 
                     alt="<?= htmlspecialchars($place['place_name']) ?>">
            <?php endif; ?>
        </div>
        
        <div class="row">
            <div class="col-lg-8">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1><?= htmlspecialchars($place['place_name']) ?></h1>
                    <span class="badge bg-primary category-badge">
                        <i class="<?= getCategoryIcon($place['category']) ?> me-1"></i>
                        <?= htmlspecialchars($place['category']) ?>
                    </span>
                </div>
                
                <div class="d-flex align-items-center text-muted mb-4">
                    <i class="fas fa-map-marker-alt me-2"></i>
                    <span class="me-3"><?= htmlspecialchars($place['city_region']) ?></span>
                    
                    <i class="fas fa-user me-2"></i>
                    <span class="me-3">Posted by <?= htmlspecialchars($place['username']) ?></span>
                    
                    <i class="far fa-calendar-alt me-2"></i>
                    <span><?= date('M j, Y', strtotime($place['created_at'])) ?></span>
                </div>
                
                <div class="mb-5">
                    <h4 class="mb-3">About This Place</h4>
                    <p class="lead"><?= nl2br(htmlspecialchars($place['description'])) ?></p>
                </div>
                
                <div class="mb-5">
                    <h4 class="mb-3">Location</h4>
                    <div class="map-container" id="map"></div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="https://www.google.com/maps?q=<?= $place['latitude'] ?>,<?= $place['longitude'] ?>" 
                           target="_blank" class="btn btn-outline-primary">
                            <i class="fas fa-external-link-alt me-2"></i>Open in Google Maps
                        </a>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $place['latitude'] ?>,<?= $place['longitude'] ?>" 
                           target="_blank" class="btn btn-outline-secondary">
                            <i class="fas fa-directions me-2"></i>Get Directions
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Nearby Places</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($all_nearby)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($all_nearby as $nearby): ?>
                                    <a href="place.php?id=<?= $nearby['place_id'] ?>" 
                                       class="list-group-item list-group-item-action">
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($nearby['image_url'])): ?>
                                                <img src="<?= htmlspecialchars($nearby['image_url']) ?>" 
                                                     class="rounded me-3" 
                                                     alt="<?= htmlspecialchars($nearby['place_name']) ?>"
                                                     style="width: 60px; height: 60px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="bg-light rounded me-3 d-flex align-items-center justify-content-center" 
                                                     style="width: 60px; height: 60px;">
                                                    <i class="<?= getCategoryIcon($nearby['category']) ?> text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h6 class="mb-1"><?= htmlspecialchars($nearby['place_name']) ?></h6>
                                                <small class="text-muted">
                                                    <i class="<?= getCategoryIcon($nearby['category']) ?> me-1"></i>
                                                    <?= htmlspecialchars($nearby['category']) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-3 text-center text-muted">
                                No nearby places found.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Share This Place</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $share_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
                        $share_text = urlencode("Check out " . $place['place_name'] . " on Local Places");
                        ?>
                        <div class="d-flex gap-2">
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($share_url) ?>" 
                               target="_blank" class="btn btn-outline-primary btn-sm">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="https://twitter.com/intent/tweet?url=<?= urlencode($share_url) ?>&text=<?= $share_text ?>" 
                               target="_blank" class="btn btn-outline-info btn-sm">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="https://wa.me/?text=<?= $share_text . ' ' . urlencode($share_url) ?>" 
                               target="_blank" class="btn btn-outline-success btn-sm">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <a href="mailto:?subject=<?= urlencode($place['place_name']) ?>&body=<?= $share_text . ' ' . $share_url ?>" 
                               class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-envelope"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize map
        document.addEventListener('DOMContentLoaded', function() {
            const lat = <?= $place['latitude'] ?>;
            const lng = <?= $place['longitude'] ?>;
            
            const map = L.map('map').setView([lat, lng], 15);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Add a marker
            L.marker([lat, lng]).addTo(map)
                .bindPopup('<?= addslashes($place['place_name']) ?>')
                .openPopup();
        });
    </script>
</body>
</html>