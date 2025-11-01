<?php
// This file is included in header.php, so no need to repeat the opening HTML or container divs
?>
<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-3 sidebar-sticky">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="/admin/">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['posts.php', 'post_edit.php', 'post_new.php']) ? 'active' : ''; ?>" href="/admin/posts.php">
                    <i class="bi bi-newspaper"></i>
                    Posts
                </a>
            </li>
            
            <li class="nav-item">
                <?php 
                // Get count of pending posts for the badge
                $pendingCount = 0; // This would come from your database
                // $pendingCount = $newsManager->countPostsByStatus('pending');
                ?>
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'pending_posts.php' ? 'active' : ''; ?>" href="/admin/pending_posts.php">
                    <i class="bi bi-clock-history"></i>
                    Pending Posts
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-danger rounded-pill float-end"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="/admin/categories.php">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'tags.php' ? 'active' : ''; ?>" href="/admin/tags.php">
                    <i class="bi bi-tag"></i>
                    Tags
                </a>
            </li>
            
            <li class="nav-item">
                <?php 
                // Get count of pending comments for the badge
                $pendingComments = 0; // This would come from your database
                // $pendingComments = $commentManager->countPendingComments();
                ?>
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'comments.php' ? 'active' : ''; ?>" href="/admin/comments.php">
                    <i class="bi bi-chat-left-text"></i>
                    Comments
                    <?php if ($pendingComments > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill float-end"><?php echo $pendingComments; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'media.php' ? 'active' : ''; ?>" href="/admin/media.php">
                    <i class="bi bi-images"></i>
                    Media Library
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" href="/admin/users.php">
                    <i class="bi bi-people"></i>
                    Users
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="/admin/settings.php">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
                    <span>Reports</span>
                </h6>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="/admin/analytics.php">
                    <i class="bi bi-graph-up"></i>
                    Analytics
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link" href="/admin/logs.php">
                    <i class="bi bi-journal-text"></i>
                    System Logs
                </a>
            </li>
        </ul>
        
        <div class="p-3">
            <div class="card bg-light">
                <div class="card-body p-3">
                    <h6 class="card-title">Quick Stats</h6>
                    <p class="card-text small mb-1">
                        <i class="bi bi-file-text text-primary"></i> 
                        <strong><?php echo $stats['total_posts'] ?? '0'; ?></strong> Posts
                    </p>
                    <p class="card-text small mb-1">
                        <i class="bi bi-chat-left-text text-success"></i> 
                        <strong><?php echo $stats['total_comments'] ?? '0'; ?></strong> Comments
                    </p>
                    <p class="card-text small mb-1">
                        <i class="bi bi-people text-info"></i> 
                        <strong><?php echo $stats['total_users'] ?? '0'; ?></strong> Users
                    </p>
                </div>
            </div>
        </div>
    </div>
</nav>
