<?php
// submit.php
require_once 'config/database.php';
require_once 'includes/auth.php';
requireLogin();

$db = getDBConnection();
$error = '';
$success = '';

// Get all approved places for nearby suggestions
$places = $db->query("SELECT place_id, place_name, latitude, longitude FROM places WHERE status = 'approved'")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        // Validate inputs
        $place_name = trim($_POST['place_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? '';
        $latitude = floatval($_POST['latitude'] ?? 0);
        $longitude = floatval($_POST['longitude'] ?? 0);
        $city_region = trim($_POST['city_region'] ?? '');
        $nearby_links = $_POST['nearby_links'] ?? [];
        
        // Basic validation
        if (empty($place_name) || empty($description) || empty($category) || 
            empty($latitude) || empty($longitude) || empty($city_region)) {
            $error = 'All fields are required.';
        } elseif (strlen($description) > 500) {
            $error = 'Description must be 500 characters or less.';
        } else {
            // Handle file uploads
            $image_url = '';
            $video_source = null;
            
            // Handle image upload
            if (isset($_FILES['image_upload']) && $_FILES['image_upload']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/' . date('Y/m/');
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image_upload']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (!in_array($file_extension, $allowed_extensions)) {
                    $error = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
                } else {
                    $filename = uniqid() . '.' . $file_extension;
                    $destination = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['image_upload']['tmp_name'], $destination)) {
                        $image_url = '/' . $destination;
                    } else {
                        $error = 'Failed to upload image.';
                    }
                }
            } elseif (!empty($_POST['image_url'])) {
                $image_url = filter_var($_POST['image_url'], FILTER_VALIDATE_URL);
                if ($image_url === false) {
                    $error = 'Invalid image URL.';
                }
            } else {
                $error = 'Please provide either an image upload or URL.';
            }
            
            // Handle video upload if no error so far and video was provided
            if (empty($error) && isset($_FILES['video_upload']) && $_FILES['video_upload']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/videos/' . date('Y/m/');
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['video_upload']['name'], PATHINFO_EXTENSION));
                $allowed_video_extensions = ['mp4', 'webm', 'ogg'];
                
                if (!in_array($file_extension, $allowed_video_extensions)) {
                    $error = 'Invalid video file type. Only MP4, WebM, and OGG are allowed.';
                } else {
                    // Check file size (max 50MB)
                    if ($_FILES['video_upload']['size'] > 50 * 1024 * 1024) {
                        $error = 'Video file is too large. Maximum size is 50MB.';
                    } else {
                        $filename = uniqid() . '.' . $file_extension;
                        $destination = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES['video_upload']['tmp_name'], $destination)) {
                            $video_source = '/' . $destination;
                        } else {
                            $error = 'Failed to upload video.';
                        }
                    }
                }
            } elseif (!empty($_POST['video_url'])) {
                $video_url = filter_var($_POST['video_url'], FILTER_VALIDATE_URL);
                if ($video_url === false) {
                    $error = 'Invalid video URL.';
                } else {
                    $video_source = $video_url;
                }
            }
            
            // If no errors, save to database
            if (empty($error)) {
                try {
                    $db->beginTransaction();
                    
                    // Insert the new place
                    $stmt = $db->prepare("
                        INSERT INTO places 
                        (user_id, place_name, description, category, latitude, longitude, 
                         image_url, video_source, city_region, nearby_links, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    
                    $nearby_links_json = !empty($nearby_links) ? json_encode($nearby_links) : null;
                    
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $place_name,
                        $description,
                        $category,
                        $latitude,
                        $longitude,
                        $image_url,
                        $video_source,
                        $city_region,
                        $nearby_links_json
                    ]);
                    
                    $db->commit();
                    
                    $_SESSION['flash_message'] = 'Your submission has been received and is pending approval.';
                    $_SESSION['flash_type'] = 'success';
                    header('Location: /');
                    exit();
                    
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error = 'An error occurred while saving your submission. Please try again.';
                    error_log("Database error: " . $e->getMessage());
                }
            }
        }
    }
}

// Get categories for the dropdown
$categories = $db->query("SHOW COLUMNS FROM places WHERE Field = 'category'")->fetch(PDO::FETCH_ASSOC);
$category_options = [];
if (preg_match("/^enum\(\'(.*)\'\)$/", $categories['Type'], $matches)) {
    $category_options = explode("','", $matches[1]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit a New Place - Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        #map {
            height: 400px;
            width: 100%;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .form-section h4 {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .tab-content {
            padding: 20px 0;
        }
        .nav-tabs .nav-link {
            font-weight: 500;
        }
        .preview-image {
            max-width: 100%;
            max-height: 200px;
            margin-top: 10px;
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-5">
        <h1 class="mb-4">Submit a New Place</h1>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="placeForm">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            
            <div class="form-section">
                <h4><i class="fas fa-info-circle me-2"></i>Basic Information</h4>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="place_name" class="form-label">Place Name *</label>
                        <input type="text" class="form-control" id="place_name" name="place_name" 
                               value="<?= htmlspecialchars($_POST['place_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="category" class="form-label">Category *</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Select a category</option>
                            <?php foreach ($category_options as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>" 
                                    <?= (isset($_POST['category']) && $_POST['category'] === $option) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="description" class="form-label">Description *</label>
                    <textarea class="form-control" id="description" name="description" rows="4" 
                              maxlength="500" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    <div class="form-text">Maximum 500 characters. <span id="charCount">500</span> remaining.</div>
                </div>
                
                <div class="mb-3">
                    <label for="city_region" class="form-label">City/Region *</label>
                    <input type="text" class="form-control" id="city_region" name="city_region" 
                           value="<?= htmlspecialchars($_POST['city_region'] ?? '') ?>" required>
                </div>
            </div>
            
            <div class="form-section">
                <h4><i class="fas fa-map-marker-alt me-2"></i>Location</h4>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="latitude" class="form-label">Latitude *</label>
                        <input type="number" step="0.000001" class="form-control" id="latitude" 
                               name="latitude" value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="longitude" class="form-label">Longitude *</label>
                        <input type="number" step="0.000001" class="form-control" id="longitude" 
                               name="longitude" value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <div id="map"></div>
                    <div class="form-text">Click on the map to set the location or enter coordinates manually.</div>
                </div>
                
                <button type="button" id="locateMeBtn" class="btn btn-outline-primary">
                    <i class="fas fa-location-arrow me-1"></i> Use My Current Location
                </button>
            </div>
            
            <div class="form-section">
                <h4><i class="fas fa-image me-2"></i>Media</h4>
                
                <h5>Featured Image *</h5>
                <p class="text-muted">Upload an image or provide a URL. At least one image is required.</p>
                
                <ul class="nav nav-tabs mb-3" id="imageTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="upload-tab" data-bs-toggle="tab" 
                                data-bs-target="#upload" type="button" role="tab">Upload Image</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="url-tab" data-bs-toggle="tab" 
                                data-bs-target="#url" type="button" role="tab">Image URL</button>
                    </li>
                </ul>
                
                <div class="tab-content" id="imageTabsContent">
                    <div class="tab-pane fade show active" id="upload" role="tabpanel">
                        <div class="mb-3">
                            <input type="file" class="form-control" id="image_upload" name="image_upload" 
                                   accept="image/jpeg,image/png,image/gif">
                            <div class="form-text">Accepted formats: JPG, PNG, GIF. Max size: 5MB.</div>
                            <img id="imagePreview" src="#" alt="Preview" class="preview-image">
                        </div>
                    </div>
                    <div class="tab-pane fade" id="url" role="tabpanel">
                        <div class="mb-3">
                            <input type="url" class="form-control" id="image_url" name="image_url" 
                                   placeholder="https://example.com/image.jpg" 
                                   value="<?= htmlspecialchars($_POST['image_url'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <h5>Video (Optional)</h5>
                <p class="text-muted">You can upload a video or provide a YouTube/Vimeo URL.</p>
                
                <ul class="nav nav-tabs mb-3" id="videoTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="video-upload-tab" data-bs-toggle="tab" 
                                data-bs-target="#video-upload" type="button" role="tab">Upload Video</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="video-url-tab" data-bs-toggle="tab" 
                                data-bs-target="#video-url" type="button" role="tab">Video URL</button>
                    </li>
                </ul>
                
                <div class="tab-content" id="videoTabsContent">
                    <div class="tab-pane fade show active" id="video-upload" role="tabpanel">
                        <div class="mb-3">
                            <input type="file" class="form-control" id="video_upload" name="video_upload" 
                                   accept="video/mp4,video/webm,video/ogg">
                            <div class="form-text">Accepted formats: MP4, WebM, OGG. Max size: 50MB. Max length: 60 seconds.</div>
                            <video id="videoPreview" controls class="preview-image"></video>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="video-url" role="tabpanel">
                        <div class="mb-3">
                            <input type="url" class="form-control" id="video_url" name="video_url" 
                                   placeholder="https://www.youtube.com/watch?v=..." 
                                   value="<?= htmlspecialchars($_POST['video_url'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h4><i class="fas fa-link me-2"></i>Nearby Places</h4>
                <p class="text-muted">Select up to 5 nearby places to link to this location.</p>
                
                <div id="nearbyPlaces">
                    <!-- Nearby places will be added here via JavaScript -->
                </div>
                
                <div class="input-group mb-3">
                    <input type="text" id="placeSearch" class="form-control" placeholder="Search for nearby places...">
                    <button class="btn btn-outline-secondary" type="button" id="searchPlacesBtn">Search</button>
                </div>
                
                <div id="searchResults" class="list-group" style="max-height: 200px; overflow-y: auto; display: none;">
                    <!-- Search results will be added here via JavaScript -->
                </div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                <a href="/" class="btn btn-outline-secondary me-md-2">Cancel</a>
                <button type="submit" class="btn btn-primary">Submit for Review</button>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Character counter for description
        document.getElementById('description').addEventListener('input', function() {
            const remaining = 500 - this.value.length;
            document.getElementById('charCount').textContent = remaining;
        });
        
        // Initialize map
        let map;
        let marker;
        
        function initMap() {
            const defaultLat = 0; // Default to 0,0 if no coordinates are set
            const defaultLng = 0;
            
            // Try to get coordinates from form or use defaults
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            
            let lat = parseFloat(latInput.value) || defaultLat;
            let lng = parseFloat(lngInput.value) || defaultLng;
            
            // Initialize the map
            map = L.map('map').setView([lat, lng], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Add a marker
            marker = L.marker([lat, lng], {draggable: true}).addTo(map);
            
            // Update form fields when marker is dragged
            marker.on('dragend', function(e) {
                const position = marker.getLatLng();
                latInput.value = position.lat.toFixed(6);
                lngInput.value = position.lng.toFixed(6);
                updateNearbyPlaces(position.lat, position.lng);
            });
            
            // Update marker position when clicking on the map
            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                latInput.value = e.latlng.lat.toFixed(6);
                lngInput.value = e.latlng.lng.toFixed(6);
                updateNearbyPlaces(e.latlng.lat, e.latlng.lng);
            });
            
            // Update marker position when coordinates are manually entered
            latInput.addEventListener('change', updateMarkerFromInputs);
            lngInput.addEventListener('change', updateMarkerFromInputs);
            
            // Initial update of nearby places
            updateNearbyPlaces(lat, lng);
        }
        
        function updateMarkerFromInputs() {
            const lat = parseFloat(document.getElementById('latitude').value);
            const lng = parseFloat(document.getElementById('longitude').value);
            
            if (!isNaN(lat) && !isNaN(lng)) {
                const newLatLng = L.latLng(lat, lng);
                marker.setLatLng(newLatLng);
                map.setView(newLatLng, 13);
                updateNearbyPlaces(lat, lng);
            }
        }
        
        // Use current location button
        document.getElementById('locateMeBtn').addEventListener('click', function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    document.getElementById('latitude').value = lat.toFixed(6);
                    document.getElementById('longitude').value = lng.toFixed(6);
                    
                    const newLatLng = L.latLng(lat, lng);
                    marker.setLatLng(newLatLng);
                    map.setView(newLatLng, 15);
                    
                    // Reverse geocode to get city/region
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`)
                        .then(response => response.json())
                        .then(data => {
                            let city = data.address.city || data.address.town || data.address.village || '';
                            let region = data.address.state || data.address.region || '';
                            let cityRegion = [city, region].filter(Boolean).join(', ');
                            
                            if (cityRegion) {
                                document.getElementById('city_region').value = cityRegion;
                            }
                        });
                    
                    updateNearbyPlaces(lat, lng);
                }, function(error) {
                    alert('Unable to retrieve your location. Please enable location services and try again.');
                });
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        });
        
        // Image preview
        document.getElementById('image_upload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('imagePreview');
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            }
        });
        
        // Video preview
        document.getElementById('video_upload').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const preview = document.getElementById('videoPreview');
                preview.src = URL.createObjectURL(file);
                preview.style.display = 'block';
            }
        });
        
        // Toggle between upload and URL tabs
        const imageTabs = document.querySelectorAll('#imageTabs .nav-link');
        imageTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Reset file input when switching away from upload tab
                if (this.id === 'url-tab') {
                    document.getElementById('image_upload').value = '';
                }
            });
        });
        
        const videoTabs = document.querySelectorAll('#videoTabs .nav-link');
        videoTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Reset file input when switching away from upload tab
                if (this.id === 'video-url-tab') {
                    document.getElementById('video_upload').value = '';
                }
            });
        });
        
        // Nearby places functionality
        let selectedPlaces = [];
        
        function updateNearbyPlaces(lat, lng) {
            // In a real app, you would make an AJAX request to your server
            // to find nearby places based on the coordinates
            // For now, we'll just use the existing places from PHP
            const places = <?= json_encode($places) ?>;
            
            // Sort places by distance to current location
            places.forEach(place => {
                place.distance = calculateDistance(
                    lat, lng, 
                    parseFloat(place.latitude), 
                    parseFloat(place.longitude)
                );
            });
            
            // Sort by distance and take the closest 10
            const nearby = [...places]
                .sort((a, b) => a.distance - b.distance)
                .slice(0, 10);
            
            // Update the search results
            updateSearchResults(nearby);
            
            return nearby;
        }
        
        function calculateDistance(lat1, lon1, lat2, lon2) {
            // Haversine formula to calculate distance between two points
            const R = 6371; // Earth's radius in km
            const dLat = toRad(lat2 - lat1);
            const dLon = toRad(lon2 - lon1);
            const a = 
                Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * 
                Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); 
            return R * c; // Distance in km
        }
        
        function toRad(value) {
            return value * Math.PI / 180;
        }
        
        function updateSearchResults(places) {
            const resultsContainer = document.getElementById('searchResults');
            resultsContainer.innerHTML = '';
            
            if (places.length === 0) {
                resultsContainer.style.display = 'none';
                return;
            }
            
            places.forEach(place => {
                if (selectedPlaces.includes(place.place_id)) return;
                
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action';
                item.innerHTML = `
                    ${place.place_name} 
                    <span class="badge bg-secondary float-end">${place.distance.toFixed(1)} km</span>
                `;
                
                item.addEventListener('click', function() {
                    addNearbyPlace(place);
                });
                
                resultsContainer.appendChild(item);
            });
            
            resultsContainer.style.display = 'block';
        }
        
        function addNearbyPlace(place) {
            if (selectedPlaces.length >= 5) {
                alert('You can only select up to 5 nearby places.');
                return;
            }
            
            if (selectedPlaces.includes(place.place_id)) {
                return;
            }
            
            selectedPlaces.push(place.place_id);
            updateSelectedPlaces();
        }
        
        function removeNearbyPlace(placeId) {
            selectedPlaces = selectedPlaces.filter(id => id !== placeId);
            updateSelectedPlaces();
        }
        
        function updateSelectedPlaces() {
            const container = document.getElementById('nearbyPlaces');
            container.innerHTML = '';
            
            selectedPlaces.forEach(placeId => {
                // Find the place in our existing places
                const places = <?= json_encode($places) ?>;
                const place = places.find(p => p.place_id == placeId);
                
                if (place) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-primary me-2 mb-2 p-2';
                    badge.innerHTML = `
                        ${place.place_name}
                        <button type="button" class="btn-close btn-close-white ms-2" 
                                onclick="event.stopPropagation(); removeNearbyPlace(${place.place_id})"></button>
                        <input type="hidden" name="nearby_links[]" value="${place.place_id}">
                    `;
                    container.appendChild(badge);
                }
            });
            
            // Hide the search results after selecting a place
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('placeSearch').value = '';
        }
        
        // Search places
        document.getElementById('searchPlacesBtn').addEventListener('click', function() {
            const searchTerm = document.getElementById('placeSearch').value.toLowerCase();
            const places = <?= json_encode($places) ?>;
            
            if (!searchTerm.trim()) {
                // If search is empty, show nearby places
                const lat = parseFloat(document.getElementById('latitude').value) || 0;
                const lng = parseFloat(document.getElementById('longitude').value) || 0;
                updateNearbyPlaces(lat, lng);
                return;
            }
            
            const filtered = places.filter(place => 
                place.place_name.toLowerCase().includes(searchTerm) ||
                (place.description && place.description.toLowerCase().includes(searchTerm))
            );
            
            updateSearchResults(filtered);
        });
        
        // Allow pressing Enter to search
        document.getElementById('placeSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchPlacesBtn').click();
            }
        });
        
        // Form validation
        document.getElementById('placeForm').addEventListener('submit', function(e) {
            // Check if at least one image source is provided
            const imageUpload = document.getElementById('image_upload').files.length > 0;
            const imageUrl = document.getElementById('image_url').value.trim() !== '';
            
            if (!imageUpload && !imageUrl) {
                e.preventDefault();
                alert('Please provide either an image upload or image URL.');
                return;
            }
            
            // If video is provided via upload, check it's not too large
            const videoUpload = document.getElementById('video_upload');
            if (videoUpload.files.length > 0) {
                const file = videoUpload.files[0];
                if (file.size > 50 * 1024 * 1024) { // 50MB
                    e.preventDefault();
                    alert('Video file is too large. Maximum size is 50MB.');
                    return;
                }
                
                // Check video duration (simplified client-side check)
                // Note: For a real app, you should validate this server-side
                const video = document.createElement('video');
                video.preload = 'metadata';
                
                video.onloadedmetadata = function() {
                    window.URL.revokeObjectURL(video.src);
                    if (video.duration > 60) { // 60 seconds
                        e.preventDefault();
                        alert('Video is too long. Maximum duration is 60 seconds.');
                    }
                };
                
                video.src = URL.createObjectURL(file);
            }
        });
        
        // Initialize the map when the page loads
        document.addEventListener('DOMContentLoaded', initMap);
        
        // Make functions available globally for inline event handlers
        window.removeNearbyPlace = removeNearbyPlace;
    </script>
</body>
</html>