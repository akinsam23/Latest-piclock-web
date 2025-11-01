// admin/router.php
<?php
require_once '../includes/auth.php';
requireAdmin();

$path = trim($_SERVER['PATH_INFO'] ?? '', '/');
$pathSegments = $path ? explode('/', $path) : [];
$action = $pathSegments[0] ?? 'dashboard';

$adminPages = [
    'dashboard' => 'dashboard.php',
    'places' => 'places.php',
    'users' => 'users.php',
    'reviews' => 'reviews.php',
    'settings' => 'settings.php'
];

$page = $adminPages[$action] ?? '404.php';
$pagePath = __DIR__ . '/pages/' . $page;

if (file_exists($pagePath)) {
    require $pagePath;
} else {
    http_response_code(404);
    require __DIR__ . '/pages/404.php';
}