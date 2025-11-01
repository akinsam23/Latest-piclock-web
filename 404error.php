<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Page Not Found - Local Places</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .error-container {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            color: #e74c3c;
            line-height: 1;
        }
        .error-message {
            font-size: 24px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container error-container">
        <div>
            <div class="error-code">404</div>
            <h1 class="error-message">Page Not Found</h1>
            <p class="lead">The page you're looking for doesn't exist or has been moved.</p>
            <a href="/" class="btn btn-primary">Go to Homepage</a>
        </div>
    </div>
</body>
</html>