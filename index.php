<?php
// index.php
require_once 'config/database.php';
require_once 'includes/auth.php';

$db = getDBConnection();

// Handle search and filters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;
$radius = 10; // Default 10km radius

$query = "SELECT p.*, u.username 
          FROM places p 
          JOIN users u ON p.user_id = u.id 
          WHERE p.status = 'approved'";
$params = [];

if ($search) {
    $query .= " AND (p.place_name LIKE ? OR p.city_region LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($category) {
    $query .= " AND p.category = ?";
    $params[] = $category;
}

if ($lat && $lng) {
    $query .= " HAVING (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?";
    $params = array_merge($params, [$lat, $lng, $lat, $radius]);
}

$query .= " ORDER BY p.created_at DESC LIMIT 20";

$stmt = $db->prepare($query);
$stmt->execute($params);
$places = $stmt->fetchAll();

// Get all categories for the filter dropdown
$categories = $db->query("SELECT DISTINCT category FROM places WHERE status = 'approved' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .place-card {
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .place-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .place-image {
            height: 200px;
            object-fit: cover;
            width: 100%;
        }
        .category-icon {
            font-size: 1.5rem;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-8 mx-auto">
                <form id="searchForm" class="d-flex">
                    <input type="text" name="search" class="form-control me-2" placeholder="Search places or locations..." value="<?= htmlspecialchars($search) ?>">
                    <select name="category" class="form-select me-2" style="max-width: 200px;">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="nearbyBtn" class="btn btn-outline-primary me-2">
                        <i class="fas fa-location-arrow"></i> Near Me
                    </button>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
        </div>

        <div class="row">
            <?php if (empty($places)): ?>
                <div class="col-12 text-center">
                    <div class="alert alert-info">No places found. Be the first to add one!</div>
                </div>
            <?php else: ?>
                <?php foreach ($places as $place): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card place-card h-100">
                            <?php if (!empty($place['video_source'])): ?>
                                <div class="ratio ratio-16x9">
                                    <?php if (strpos($place['video_source'], 'youtube.com') !== false || strpos($place['video_source'], 'youtu.be') !== false): ?>
                                        <iframe src="<?= htmlspecialchars($place['video_source']) ?>" 
                                                title="<?= htmlspecialchars($place['place_name']) ?>" 
                                                allowfullscreen></iframe>
                                    <?php else: ?>
                                        <video controls class="card-img-top">
                                            <source src="<?= htmlspecialchars($place['video_source']) ?>" type="video/mp4">
                                            Your browser does not support the video tag.
                                        </video>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($place['image_url']) ?>" 
                                     class="card-img-top place-image" 
                                     alt="<?= htmlspecialchars($place['place_name']) ?>">
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($place['place_name']) ?></h5>
                                <p class="card-text text-muted">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?= htmlspecialchars($place['city_region']) ?>
                                    <span class="badge bg-primary float-end">
                                        <i class="<?= getCategoryIcon($place['category']) ?>"></i>
                                        <?= htmlspecialchars($place['category']) ?>
                                    </span>
                                </p>
                                <p class="card-text"><?= nl2br(htmlspecialchars(substr($place['description'], 0, 150))) ?>...</p>
                                <a href="place.php?id=<?= $place['place_id'] ?>" class="btn btn-outline-primary">View Details</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('nearbyBtn').addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    const form = document.getElementById('searchForm');
                    const latInput = document.createElement('input');
                    latInput.type = 'hidden';
                    latInput.name = 'lat';
                    latInput.value = position.coords.latitude;
                    
                    const lngInput = document.createElement('input');
                    lngInput.type = 'hidden';
                    lngInput.name = 'lng';
                    lngInput.value = position.coords.longitude;
                    
                    form.appendChild(latInput);
                    form.appendChild(lngInput);
                    form.submit();
                }, function(error) {
                    alert('Unable to retrieve your location. Please enable location services and try again.');
                });
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        });
    </script>
</body>
</html>

<?php
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