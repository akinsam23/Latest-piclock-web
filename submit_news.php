<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/NewsManager.php';
require_once __DIR__ . '/includes/LocationManager.php';

// Initialize auth and check if user is logged in
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /login.php');
    exit();
}

$newsManager = new NewsManager($db);
$locationManager = new LocationManager($db);

// Get user's recent locations for quick selection
$recentLocations = [];
// This would be populated from the database based on user's previous posts

// Get all countries for the location dropdown
$countries = $locationManager->getAllCountries();

// Initialize variables
$errors = [];
$success = false;
$formData = [
    'title' => '',
    'content' => '',
    'category' => '',
    'country_id' => '',
    'state_id' => '',
    'city_id' => '',
    'location_name' => '',
    'is_breaking' => false,
    'is_emergency' => false,
    'tags' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        // Sanitize and validate input
        $formData = array_map('trim', $_POST);
        $formData['is_breaking'] = isset($_POST['is_breaking']) ? true : false;
        $formData['is_emergency'] = isset($_POST['is_emergency']) ? true : false;
        
        // Basic validation
        if (empty($formData['title'])) {
            $errors[] = 'Title is required';
        } elseif (strlen($formData['title']) > 200) {
            $errors[] = 'Title must be less than 200 characters';
        }
        
        if (empty($formData['content'])) {
            $errors[] = 'Content is required';
        }
        
        if (empty($formData['category']) || !in_array($formData['category'], 
            ['crime', 'accident', 'event', 'weather', 'traffic', 'business', 'politics', 'health', 'education', 'sports', 'entertainment', 'technology', 'environment', 'science', 'other'])) {
            $errors[] = 'Please select a valid category';
        }
        
        // Location validation
        if (empty($formData['country_id'])) {
            $errors[] = 'Please select a country';
        }
        
        if (empty($formData['location_name'])) {
            $errors[] = 'Please enter a location name';
        }
        
        // Process file uploads if any
        $uploadedFiles = [];
        if (isset($_FILES['images'])) {
            foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['images']['name'][$key],
                        'type' => $_FILES['images']['type'][$key],
                        'tmp_name' => $tmpName,
                        'error' => $_FILES['images']['error'][$key],
                        'size' => $_FILES['images']['size'][$key]
                    ];
                    $uploadedFiles[] = $file;
                }
            }
        }
        
        // Process video uploads if any
        $uploadedVideos = [];
        if (isset($_FILES['videos'])) {
            foreach ($_FILES['videos']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['videos']['error'][$key] === UPLOAD_ERR_OK) {
                    $file = [
                        'name' => $_FILES['videos']['name'][$key],
                        'type' => $_FILES['videos']['type'][$key],
                        'tmp_name' => $tmpName,
                        'error' => $_FILES['videos']['error'][$key],
                        'size' => $_FILES['videos']['size'][$key]
                    ];
                    $uploadedVideos[] = $file;
                }
            }
        }
        
        // Process video URLs
        $videoUrls = [];
        if (!empty($formData['video_urls'])) {
            $videoUrls = array_filter(array_map('trim', explode("\n", $formData['video_urls'])));
        }
        
        // If no errors, proceed with submission
        if (empty($errors)) {
            try {
                // Start transaction
                $db->beginTransaction();
                
                // Prepare location data
                $locationData = [
                    'country_id' => $formData['country_id'],
                    'state_id' => !empty($formData['state_id']) ? $formData['state_id'] : null,
                    'city_id' => !empty($formData['city_id']) ? $formData['city_id'] : null,
                    'name' => $formData['location_name'],
                    'address' => $formData['location_address'] ?? '',
                    'latitude' => $formData['latitude'] ?? null,
                    'longitude' => $formData['longitude'] ?? null
                ];
                
                // Create or get location ID
                $locationId = $locationManager->createLocation($locationData);
                
                // Prepare post data
                $postData = [
                    'user_id' => $_SESSION['user_id'],
                    'location_id' => $locationId,
                    'title' => $formData['title'],
                    'content' => $formData['content'],
                    'category' => $formData['category'],
                    'is_breaking' => $formData['is_breaking'],
                    'is_emergency' => $formData['is_emergency'],
                    'status' => 'pending' // All posts require admin approval
                ];
                
                // Handle image uploads
                $imageUrl = null;
                if (!empty($uploadedFiles)) {
                    // For simplicity, we'll just use the first image as the featured image
                    $imageUrl = $newsManager->handleImageUpload($uploadedFiles[0]);
                    $postData['image_url'] = $imageUrl;
                }
                
                // Create the post
                $postId = $newsManager->createPost($postData);
                
                // Handle additional images (if any)
                if (count($uploadedFiles) > 1) {
                    for ($i = 1; $i < count($uploadedFiles); $i++) {
                        $imageUrl = $newsManager->handleImageUpload($uploadedFiles[$i]);
                        // Store additional images in a separate table
                        $newsManager->addPostImage($postId, $imageUrl, 'Additional image');
                    }
                }
                
                // Handle video uploads
                foreach ($uploadedVideos as $video) {
                    $videoPath = $newsManager->handleVideoUpload($video);
                    $newsManager->addPostVideo($postId, [
                        'video_url' => $videoPath,
                        'video_type' => 'upload',
                        'title' => pathinfo($video['name'], PATHINFO_FILENAME)
                    ]);
                }
                
                // Handle video URLs (YouTube, Vimeo, etc.)
                foreach ($videoUrls as $videoUrl) {
                    if (!empty($videoUrl)) {
                        $videoInfo = $newsManager->parseVideoUrl($videoUrl);
                        if ($videoInfo) {
                            $newsManager->addPostVideo($postId, [
                                'video_url' => $videoUrl,
                                'video_type' => $videoInfo['type'],
                                'title' => $videoInfo['title'] ?? 'Video',
                                'thumbnail_url' => $videoInfo['thumbnail_url'] ?? null,
                                'embed_code' => $videoInfo['embed_code'] ?? null
                            ]);
                        }
                    }
                }
                
                // Handle tags
                if (!empty($formData['tags'])) {
                    $tags = array_filter(array_map('trim', explode(',', $formData['tags'])));
                    foreach ($tags as $tagName) {
                        $newsManager->addPostTag($postId, $tagName);
                    }
                }
                
                // Log the submission
                $newsManager->logModerationAction($_SESSION['user_id'], $postId, 'submitted', 'Post submitted for review');
                
                // Commit transaction
                $db->commit();
                
                // Send notification to admins
                $newsManager->notifyAdmins("New post submitted for review: " . $formData['title'], "/admin/post_preview.php?id=$postId");
                
                // Set success message
                $_SESSION['success_message'] = 'Your news post has been submitted and is pending approval. Thank you for your contribution!';
                
                // Redirect to success page or show success message
                header('Location: /submit_success.php');
                exit();
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollBack();
                $errors[] = 'An error occurred while submitting your post: ' . $e->getMessage();
                error_log('News submission error: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
            }
        }
    }
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Set page title
$pageTitle = 'Submit News';

// Include header
include __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h2 class="h4 mb-0">Submit a News Story</h2>
                    <p class="mb-0">Share what's happening in your area</p>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <h5 class="alert-heading">Please fix the following errors:</h5>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" enctype="multipart/form-data" id="newsForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Basic Information -->
                        <div class="mb-4">
                            <h4 class="mb-3">Story Details</h4>
                            
                            <div class="mb-3">
                                <label for="title" class="form-label fw-bold">Headline <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg" id="title" name="title" 
                                       value="<?php echo htmlspecialchars($formData['title']); ?>" required 
                                       placeholder="Enter a clear, concise headline">
                                <div class="form-text">Keep it short and descriptive (max 200 characters)</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="content" class="form-label fw-bold">Story <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="content" name="content" rows="10" 
                                          required placeholder="Tell us what happened..."><?php echo htmlspecialchars($formData['content']); ?></textarea>
                                <div class="form-text">Provide all the relevant details of the story</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="category" class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                        <select class="form-select" id="category" name="category" required>
                                            <option value="" disabled <?php echo empty($formData['category']) ? 'selected' : ''; ?>>Select a category</option>
                                            <option value="crime" <?php echo $formData['category'] === 'crime' ? 'selected' : ''; ?>>Crime</option>
                                            <option value="accident" <?php echo $formData['category'] === 'accident' ? 'selected' : ''; ?>>Accident</option>
                                            <option value="event" <?php echo $formData['category'] === 'event' ? 'selected' : ''; ?>>Event</option>
                                            <option value="weather" <?php echo $formData['category'] === 'weather' ? 'selected' : ''; ?>>Weather</option>
                                            <option value="traffic" <?php echo $formData['category'] === 'traffic' ? 'selected' : ''; ?>>Traffic</option>
                                            <option value="business" <?php echo $formData['category'] === 'business' ? 'selected' : ''; ?>>Business</option>
                                            <option value="politics" <?php echo $formData['category'] === 'politics' ? 'selected' : ''; ?>>Politics</option>
                                            <option value="health" <?php echo $formData['category'] === 'health' ? 'selected' : ''; ?>>Health</option>
                                            <option value="education" <?php echo $formData['category'] === 'education' ? 'selected' : ''; ?>>Education</option>
                                            <option value="sports" <?php echo $formData['category'] === 'sports' ? 'selected' : ''; ?>>Sports</option>
                                            <option value="entertainment" <?php echo $formData['category'] === 'entertainment' ? 'selected' : ''; ?>>Entertainment</option>
                                            <option value="technology" <?php echo $formData['category'] === 'technology' ? 'selected' : ''; ?>>Technology</option>
                                            <option value="environment" <?php echo $formData['category'] === 'environment' ? 'selected' : ''; ?>>Environment</option>
                                            <option value="science" <?php echo $formData['category'] === 'science' ? 'selected' : ''; ?>>Science</option>
                                            <option value="other" <?php echo $formData['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Tags</label>
                                        <input type="text" class="form-control" id="tags" name="tags" 
                                               value="<?php echo htmlspecialchars($formData['tags']); ?>" 
                                               placeholder="e.g., flood, emergency, help">
                                        <div class="form-text">Separate tags with commas</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_breaking" name="is_breaking" 
                                       <?php echo $formData['is_breaking'] ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="is_breaking">Breaking News</label>
                                <div class="form-text">Check this if this is urgent or time-sensitive news</div>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_emergency" name="is_emergency" 
                                       <?php echo $formData['is_emergency'] ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-danger" for="is_emergency">Emergency Alert</label>
                                <div class="form-text text-danger">Only check for life-threatening situations requiring immediate attention</div>
                            </div>
                        </div>
                        
                        <!-- Location Information -->
                        <div class="mb-4 pt-4 border-top">
                            <h4 class="mb-3">Location Details</h4>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="country_id" class="form-label fw-bold">Country <span class="text-danger">*</span></label>
                                        <select class="form-select" id="country_id" name="country_id" required>
                                            <option value="" disabled selected>Select a country</option>
                                            <?php foreach ($countries as $country): ?>
                                                <option value="<?php echo $country['id']; ?>" 
                                                    <?php echo $formData['country_id'] == $country['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($country['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="state_id" class="form-label fw-bold">State/Province</label>
                                        <select class="form-select" id="state_id" name="state_id" disabled>
                                            <option value="" selected>Select a state/province</option>
                                            <!-- States will be loaded via AJAX -->
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="city_id" class="form-label fw-bold">City/Town</label>
                                        <select class="form-select" id="city_id" name="city_id" disabled>
                                            <option value="" selected>Select a city/town</option>
                                            <!-- Cities will be loaded via AJAX -->
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="location_name" class="form-label fw-bold">Specific Location <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="location_name" name="location_name"
                                               value="<?php echo htmlspecialchars($formData['location_name']); ?>" required
                                               placeholder="e.g., Main Street, Central Park, etc.">
                                        <div class="form-text">Be as specific as possible about where this happened</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="location_address" class="form-label fw-bold">Full Address</label>
                                <input type="text" class="form-control" id="location_address" name="location_address"
                                       value="<?php echo htmlspecialchars($formData['location_address'] ?? ''); ?>"
                                       placeholder="Full street address (optional)">
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="latitude" class="form-label">Latitude</label>
                                        <input type="text" class="form-control" id="latitude" name="latitude" 
                                               value="<?php echo htmlspecialchars($formData['latitude'] ?? ''); ?>"
                                               placeholder="e.g., 40.7128">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="longitude" class="form-label">Longitude</label>
                                        <input type="text" class="form-control" id="longitude" name="longitude"
                                               value="<?php echo htmlspecialchars($formData['longitude'] ?? ''); ?>"
                                               placeholder="e.g., -74.0060">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="getLocationBtn">
                                    <i class="bi bi-geo-alt"></i> Use My Current Location
                                </button>
                                <div class="form-text">Allow location access to automatically fill coordinates</div>
                                <div id="locationStatus" class="small text-muted mt-1"></div>
                            </div>
                            
                            <div id="map" style="height: 250px;" class="mb-3 border rounded d-none">
                                <!-- Map will be rendered here -->
                            </div>
                            
                            <?php if (!empty($recentLocations)): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Recent Locations</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($recentLocations as $location): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary location-suggestion" 
                                                    data-location='<?php echo json_encode($location); ?>'>
                                                <?php echo htmlspecialchars($location['name']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Media Uploads -->
                        <div class="mb-4 pt-4 border-top">
                            <h4 class="mb-3">Add Media</h4>
                            
                            <!-- Image Uploads -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Photos</label>
                                <div class="file-upload-wrapper">
                                    <input type="file" class="form-control" id="images" name="images[]" 
                                           accept="image/*" multiple>
                                    <div class="form-text">Upload photos related to your story (JPEG, PNG, GIF, max 5MB each)</div>
                                </div>
                                
                                <div id="imagePreview" class="row mt-3 g-2">
                                    <!-- Image previews will be shown here -->
                                </div>
                            </div>
                            
                            <!-- Video Uploads -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">Videos</label>
                                <div class="file-upload-wrapper mb-2">
                                    <input type="file" class="form-control" id="videos" name="videos[]" 
                                           accept="video/*" multiple>
                                    <div class="form-text">Upload video files (MP4, WebM, OGG, max 50MB each)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="video_urls" class="form-label">Or add video links (YouTube, Vimeo, etc.)</label>
                                    <textarea class="form-control" id="video_urls" name="video_urls" 
                                              rows="2" placeholder="Paste one video URL per line"><?php echo htmlspecialchars($formData['video_urls'] ?? ''); ?></textarea>
                                    <div class="form-text">Enter one video URL per line</div>
                                </div>
                                
                                <div id="videoPreview" class="row g-2">
                                    <!-- Video previews will be shown here -->
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submission -->
                        <div class="d-flex justify-content-between pt-3 border-top">
                            <div>
                                <button type="button" class="btn btn-outline-secondary" id="saveDraftBtn">
                                    <i class="bi bi-save"></i> Save as Draft
                                </button>
                            </div>
                            <div>
                                <button type="submit" name="submit" value="submit" class="btn btn-primary">
                                    <i class="bi bi-send"></i> Submit for Review
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="alert alert-info mt-4">
                <h5><i class="bi bi-info-circle"></i> Submission Guidelines</h5>
                <ul class="mb-0">
                    <li>All submissions are reviewed by our team before being published.</li>
                    <li>Provide accurate and factual information.</li>
                    <li>Include clear photos or videos when possible (with permission).</li>
                    <li>Respect privacy and avoid sharing personal information without consent.</li>
                    <li>Breaking news and emergency alerts will be prioritized.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Image Preview Template (hidden) -->
<template id="imagePreviewTemplate">
    <div class="col-6 col-md-4 col-lg-3">
        <div class="card">
            <img src="" class="card-img-top" alt="Preview">
            <div class="card-body p-2 text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-image">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
        </div>
    </div>
</template>

<!-- Video Preview Template (hidden) -->
<template id="videoPreviewTemplate">
    <div class="col-12 mb-3">
        <div class="card">
            <div class="card-body p-2">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0 me-3">
                        <div class="ratio ratio-16x9" style="width: 120px;">
                            <img src="" class="img-fluid rounded" alt="Video thumbnail">
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 video-title"></h6>
                        <p class="mb-1 small text-muted video-duration"></p>
                    </div>
                    <div class="flex-shrink-0">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-video">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<!-- Include required JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bs5-lightbox@1.8.3/dist/index.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script>
    // Initialize Summernote WYSIWYG editor
    $(document).ready(function() {
        // Initialize Summernote
        $('#content').summernote({
            height: 300,
            minHeight: 200,
            maxHeight: 500,
            focus: true,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'italic', 'underline', 'clear']],
                ['fontname', ['fontname']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['height', ['height']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'video']],
                ['view', ['fullscreen', 'codeview', 'help']]
            ],
            callbacks: {
                onImageUpload: function(files) {
                    // Handle image upload
                    for (let i = 0; i < files.length; i++) {
                        uploadImage(files[i], this);
                    }
                }
            }
        });
        
        // Function to handle image upload
        function uploadImage(file, editor) {
            const formData = new FormData();
            formData.append('image', file);
            formData.append('_token', '<?php echo $_SESSION['csrf_token']; ?>');
            
            $.ajax({
                url: '/api/upload_image.php',
                method: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    if (response.success) {
                        const imgNode = $('<img>').attr('src', response.url);
                        $(editor).summernote('insertNode', imgNode[0]);
                    } else {
                        alert('Error uploading image: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    alert('Error uploading image. Please try again.');
                }
            });
        }
        
        // Handle image previews
        const imageInput = document.getElementById('images');
        const imagePreview = document.getElementById('imagePreview');
        const imageTemplate = document.getElementById('imagePreviewTemplate');
        
        imageInput.addEventListener('change', function(e) {
            const files = e.target.files;
            
            // Clear previous previews
            imagePreview.innerHTML = '';
            
            // Add new previews
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                if (!file.type.startsWith('image/')) {
                    continue;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const clone = imageTemplate.content.cloneNode(true);
                    const img = clone.querySelector('img');
                    img.src = e.target.result;
                    
                    // Set up remove button
                    const removeBtn = clone.querySelector('.remove-image');
                    removeBtn.addEventListener('click', function() {
                        // Create a new DataTransfer object to update the file input
                        const dataTransfer = new DataTransfer();
                        const input = document.getElementById('images');
                        
                        // Add all files except the one being removed
                        for (let j = 0; j < files.length; j++) {
                            if (j !== i) {
                                dataTransfer.items.add(files[j]);
                            }
                        }
                        
                        // Update the files property of the input
                        input.files = dataTransfer.files;
                        
                        // Remove the preview
                        this.closest('.col').remove();
                    });
                    
                    imagePreview.appendChild(clone);
                };
                
                reader.readAsDataURL(file);
            }
        });
        
        // Handle video previews (for file uploads)
        const videoInput = document.getElementById('videos');
        const videoPreview = document.getElementById('videoPreview');
        const videoTemplate = document.getElementById('videoPreviewTemplate');
        
        videoInput.addEventListener('change', function(e) {
            const files = e.target.files;
            
            // Note: We can't preview video files directly due to browser security restrictions
            // So we'll just show the file names for now
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                
                if (!file.type.startsWith('video/')) {
                    continue;
                }
                
                const clone = videoTemplate.content.cloneNode(true);
                const title = clone.querySelector('.video-title');
                const duration = clone.querySelector('.video-duration');
                const thumbnail = clone.querySelector('img');
                
                title.textContent = file.name;
                duration.textContent = formatFileSize(file.size);
                
                // Generate a thumbnail (simplified - in a real app, you'd use a library)
                thumbnail.src = 'data:image/svg+xml;base64,' + btoa(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 16 16" class="bi bi-file-earmark-play" fill="currentColor">' +
                    '<path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5L14 4.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5h-2z"/>' +
                    '<path d="M6 6.883v4.234a.5.5 0 0 0 .757.429l3.528-2.117a.5.5 0 0 0 0-.858L6.757 6.454a.5.5 0 0 0-.757.429z"/>' +
                    '</svg>'
                );
                
                // Set up remove button
                const removeBtn = clone.querySelector('.remove-video');
                removeBtn.addEventListener('click', function() {
                    // Create a new DataTransfer object to update the file input
                    const dataTransfer = new DataTransfer();
                    const input = document.getElementById('videos');
                    
                    // Add all files except the one being removed
                    for (let j = 0; j < files.length; j++) {
                        if (j !== i) {
                            dataTransfer.items.add(files[j]);
                        }
                    }
                    
                    // Update the files property of the input
                    input.files = dataTransfer.files;
                    
                    // Remove the preview
                    this.closest('.col-12').remove();
                });
                
                videoPreview.appendChild(clone);
            }
        });
        
        // Handle video URL previews (for YouTube, Vimeo, etc.)
        const videoUrlsInput = document.getElementById('video_urls');
        
        videoUrlsInput.addEventListener('change', function() {
            const urls = this.value.split('\n').filter(url => url.trim() !== '');
            
            // Clear existing previews (except file upload previews)
            const existingPreviews = videoPreview.querySelectorAll('.video-url-preview');
            existingPreviews.forEach(el => el.remove());
            
            // Add previews for each URL
            urls.forEach((url, index) => {
                if (!url) return;
                
                // Check if it's a YouTube URL
                const youtubeMatch = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
                
                // Check if it's a Vimeo URL
                const vimeoMatch = url.match(/(?:vimeo\.com\/|player\.vimeo\.com\/video\/)(\d+)/);
                
                const clone = videoTemplate.content.cloneNode(true);
                const card = clone.querySelector('.card');
                const title = clone.querySelector('.video-title');
                const duration = clone.querySelector('.video-duration');
                const thumbnail = clone.querySelector('img');
                
                // Mark as URL preview (not file upload)
                card.classList.add('video-url-preview');
                
                if (youtubeMatch) {
                    const videoId = youtubeMatch[1];
                    const thumbnailUrl = `https://img.youtube.com/vi/${videoId}/hqdefault.jpg`;
                    
                    title.textContent = 'YouTube Video';
                    duration.textContent = 'Click to preview';
                    thumbnail.src = thumbnailUrl;
                    
                    // Make the preview clickable to open the video in a lightbox
                    card.addEventListener('click', function(e) {
                        if (!e.target.closest('.remove-video')) {
                            window.open(`https://www.youtube.com/watch?v=${videoId}`, '_blank');
                        }
                    });
                    
                } else if (vimeoMatch) {
                    const videoId = vimeoMatch[1];
                    
                    // Vimeo requires an API call to get the thumbnail, so we'll use a placeholder
                    title.textContent = 'Vimeo Video';
                    duration.textContent = 'Click to preview';
                    thumbnail.src = 'data:image/svg+xml;base64,' + btoa(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 16 16" class="bi bi-play-circle" fill="currentColor">' +
                        '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>' +
                        '<path d="M6.271 5.055a.5.5 0 0 1 .52.038l3.5 2.5a.5.5 0 0 1 0 .814l-3.5 2.5A.5.5 0 0 1 6 10.5v-5a.5.5 0 0 1 .271-.445z"/>' +
                        '</svg>'
                    );
                    
                    // Make the preview clickable to open the video in a lightbox
                    card.addEventListener('click', function(e) {
                        if (!e.target.closest('.remove-video')) {
                            window.open(`https://vimeo.com/${videoId}`, '_blank');
                        }
                    });
                    
                } else {
                    // Generic video URL
                    title.textContent = 'Video Link';
                    duration.textContent = url;
                    thumbnail.src = 'data:image/svg+xml;base64,' + btoa(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 16 16" class="bi bi-link-45deg" fill="currentColor">' +
                        '<path d="M4.715 6.542L3.343 7.914a3 3 0 1 0 4.243 4.243l1.828-1.829A3 3 0 0 0 8.586 5.5L8 6.086a1 1 0 0 0-.154.199 2 2 0 0 1 .861 3.337L6.88 11.45a2 2 0 1 1-2.83-2.83l.793-.792a4 4 0 0 1-.128-1.287z"/>' +
                        '<path d="M6.586 4.672A3 3 0 0 0 7.414 9.5l.775-.776a2 2 0 0 1-.896-3.346L9.12 3.55a2 2 0 1 1 2.83 2.83l-.793.792c.112.42.155.855.128 1.287l1.372-1.372a3 3 0 1 0-4.243-4.243L6.586 4.672z"/>' +
                        '</svg>'
                    );
                    
                    // Make the preview clickable to open the URL
                    card.addEventListener('click', function(e) {
                        if (!e.target.closest('.remove-video')) {
                            window.open(url, '_blank');
                        }
                    });
                }
                
                // Set up remove button
                const removeBtn = clone.querySelector('.remove-video');
                removeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    
                    // Remove the URL from the textarea
                    const urlsArray = videoUrlsInput.value.split('\n').filter(u => u.trim() !== url.trim());
                    videoUrlsInput.value = urlsArray.join('\n');
                    
                    // Remove the preview
                    this.closest('.col-12').remove();
                });
                
                videoPreview.appendChild(clone);
            });
        });
        
        // Trigger change event to show initial previews if there are any URLs
        if (videoUrlsInput.value.trim() !== '') {
            videoUrlsInput.dispatchEvent(new Event('change'));
        }
        
        // Helper function to format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Handle country/state/city dropdowns
        $('#country_id').change(function() {
            const countryId = $(this).val();
            const stateSelect = $('#state_id');
            const citySelect = $('#city_id');
            
            // Reset state and city dropdowns
            stateSelect.empty().append('<option value="">Select a state/province</option>').prop('disabled', true);
            citySelect.empty().append('<option value="">Select a city/town</option>').prop('disabled', true);
            
            if (!countryId) {
                return;
            }
            
            // Load states for the selected country
            $.ajax({
                url: '/api/get_states.php',
                method: 'GET',
                data: { country_id: countryId },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        stateSelect.prop('disabled', false);
                        
                        response.data.forEach(function(state) {
                            stateSelect.append(`<option value="${state.id}">${state.name}</option>`);
                        });
                        
                        // If there's a previously selected state, try to select it
                        const selectedStateId = '<?php echo $formData['state_id'] ?? ''; ?>';
                        if (selectedStateId) {
                            stateSelect.val(selectedStateId).trigger('change');
                        }
                    } else {
                        stateSelect.prop('disabled', true);
                        citySelect.prop('disabled', true);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading states:', error);
                    alert('Error loading states. Please try again.');
                }
            });
        });
        
        // Handle state change to load cities
        $('#state_id').change(function() {
            const stateId = $(this).val();
            const citySelect = $('#city_id');
            
            // Reset city dropdown
            citySelect.empty().append('<option value="">Select a city/town</option>').prop('disabled', true);
            
            if (!stateId) {
                return;
            }
            
            // Load cities for the selected state
            $.ajax({
                url: '/api/get_cities.php',
                method: 'GET',
                data: { state_id: stateId },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        citySelect.prop('disabled', false);
                        
                        response.data.forEach(function(city) {
                            citySelect.append(`<option value="${city.id}">${city.name}</option>`);
                        });
                        
                        // If there's a previously selected city, try to select it
                        const selectedCityId = '<?php echo $formData['city_id'] ?? ''; ?>';
                        if (selectedCityId) {
                            citySelect.val(selectedCityId);
                        }
                    } else {
                        citySelect.prop('disabled', true);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading cities:', error);
                    alert('Error loading cities. Please try again.');
                }
            });
        });
        
        // Trigger country change on page load if a country is selected
        const selectedCountryId = '<?php echo $formData['country_id'] ?? ''; ?>';
        if (selectedCountryId) {
            $('#country_id').val(selectedCountryId).trigger('change');
        }
        
        // Handle "Use My Current Location" button
        $('#getLocationBtn').click(function() {
            const status = $('#locationStatus');
            const button = $(this);
            const originalText = button.html();
            
            status.html('<i class="bi bi-gear-fill fa-spin"></i> Detecting your location...');
            button.prop('disabled', true).html('<i class="bi bi-gear-fill fa-spin"></i> Detecting...');
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const latitude = position.coords.latitude;
                        const longitude = position.coords.longitude;
                        
                        // Update the form fields
                        $('#latitude').val(latitude);
                        $('#longitude').val(longitude);
                        
                        // Reverse geocode to get address details
                        $.ajax({
                            url: 'https://nominatim.openstreetmap.org/reverse',
                            method: 'GET',
                            data: {
                                format: 'json',
                                lat: latitude,
                                lon: longitude,
                                addressdetails: 1,
                                'accept-language': 'en'
                            },
                            success: function(response) {
                                const address = response.address;
                                
                                // Update location name with the most specific available
                                let locationName = '';
                                
                                if (address.road) locationName += address.road;
                                if (address.suburb && !locationName.includes(address.suburb)) {
                                    if (locationName) locationName += ', ';
                                    locationName += address.suburb;
                                }
                                
                                if (locationName) {
                                    $('#location_name').val(locationName);
                                }
                                
                                // Update address field
                                let fullAddress = [];
                                if (address.house_number) fullAddress.push(address.house_number);
                                if (address.road) fullAddress.push(address.road);
                                if (fullAddress.length > 0) {
                                    $('#location_address').val(fullAddress.join(' '));
                                }
                                
                                // Update country/state/city if possible
                                if (address.country_code) {
                                    const countryCode = address.country_code.toUpperCase();
                                    
                                    // Find the country in the dropdown
                                    const countryOption = $(`#country_id option:contains("${address.country}")`).first();
                                    if (countryOption.length > 0) {
                                        countryOption.prop('selected', true).trigger('change');
                                        
                                        // After country loads, try to set state
                                        setTimeout(function() {
                                            if (address.state) {
                                                const stateOption = $(`#state_id option:contains("${address.state}")`).first();
                                                if (stateOption.length > 0) {
                                                    stateOption.prop('selected', true).trigger('change');
                                                    
                                                    // After state loads, try to set city
                                                    setTimeout(function() {
                                                        if (address.city || address.town || address.village) {
                                                            const cityName = address.city || address.town || address.village;
                                                            const cityOption = $(`#city_id option:contains("${cityName}")`).first();
                                                            if (cityOption.length > 0) {
                                                                cityOption.prop('selected', true);
                                                            }
                                                        }
                                                    }, 500);
                                                }
                                            }
                                        }, 500);
                                    }
                                }
                                
                                status.html('<i class="bi bi-check-circle-fill text-success"></i> Location detected and updated!');
                                button.html(originalText).prop('disabled', false);
                                
                                // Show the map
                                initMap(latitude, longitude);
                            },
                            error: function() {
                                status.html('<i class="bi bi-check-circle-fill text-success"></i> Coordinates updated, but could not get address details.');
                                button.html(originalText).prop('disabled', false);
                                
                                // Show the map anyway
                                initMap(latitude, longitude);
                            }
                        });
                    },
                    function(error) {
                        console.error('Geolocation error:', error);
                        let errorMessage = 'Error getting your location: ';
                        
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage += 'Permission denied. Please enable location access in your browser settings.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage += 'Location information is unavailable.';
                                break;
                            case error.TIMEOUT:
                                errorMessage += 'The request to get your location timed out.';
                                break;
                            default:
                                errorMessage += 'An unknown error occurred.';
                        }
                        
                        status.html(`<span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> ${errorMessage}</span>`);
                        button.html(originalText).prop('disabled', false);
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 0
                    }
                );
            } else {
                status.html('<span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Geolocation is not supported by your browser.</span>');
                button.html(originalText).prop('disabled', false);
            }
        });
        
        // Initialize map (will be shown when location is detected)
        function initMap(latitude, longitude) {
            const mapElement = document.getElementById('map');
            mapElement.classList.remove('d-none');
            
            // In a real implementation, you would initialize a map here using Leaflet, Google Maps, etc.
            // For example:
            /*
            const map = L.map('map').setView([latitude, longitude], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            const marker = L.marker([latitude, longitude]).addTo(map);
            marker.bindPopup('Your location').openPopup();
            
            // Make marker draggable
            marker.dragging.enable();
            marker.on('dragend', function(e) {
                const newPos = marker.getLatLng();
                $('#latitude').val(newPos.lat);
                $('#longitude').val(newPos.lng);
                
                // You could also reverse geocode the new position here
            });
            */
            
            // For now, we'll just show a placeholder
            mapElement.innerHTML = `
                <div class="d-flex align-items-center justify-content-center h-100 bg-light">
                    <div class="text-center p-3">
                        <i class="bi bi-map" style="font-size: 2rem; opacity: 0.5;"></i>
                        <p class="mt-2 mb-0">Map would be displayed here with your location</p>
                        <small class="text-muted">(Lat: ${latitude.toFixed(6)}, Lng: ${longitude.toFixed(6)})</small>
                    </div>
                </div>
            `;
        }
        
        // Handle save as draft
        $('#saveDraftBtn').click(function() {
            // In a real implementation, this would save the post as a draft
            // For now, we'll just show an alert
            alert('Draft functionality will be implemented in a future update.');
            
            // You would typically:
            // 1. Serialize the form data
            // 2. Send it to the server via AJAX
            // 3. Show a success message
            // 4. Optionally redirect to the drafts page
        });
        
        // Handle form submission
        $('#newsForm').on('submit', function(e) {
            // Client-side validation
            const title = $('#title').val().trim();
            const content = $('#content').val().trim();
            const category = $('#category').val();
            const countryId = $('#country_id').val();
            const locationName = $('#location_name').val().trim();
            
            if (!title) {
                alert('Please enter a headline for your story.');
                $('#title').focus();
                e.preventDefault();
                return false;
            }
            
            if (!content) {
                alert('Please provide the content of your story.');
                $('#content').focus();
                e.preventDefault();
                return false;
            }
            
            if (!category) {
                alert('Please select a category for your story.');
                $('#category').focus();
                e.preventDefault();
                return false;
            }
            
            if (!countryId) {
                alert('Please select a country.');
                $('#country_id').focus();
                e.preventDefault();
                return false;
            }
            
            if (!locationName) {
                alert('Please specify the location where this happened.');
                $('#location_name').focus();
                e.preventDefault();
                return false;
            }
            
            // If we get here, the form is valid and will be submitted
            // Show loading state on the submit button
            const submitBtn = $('button[type="submit"]');
            const originalText = submitBtn.html();
            submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...');
            
            // The form will now submit normally
            return true;
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
