<?php
// submit_success.php
require_once 'config/database.php';
require_once 'includes/auth.php';
requireLogin();

$pageTitle = 'Submission Successful';

include 'includes/header.php';
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center">
            <div class="card shadow">
                <div class="card-body p-5">
                    <div class="mb-4">
                        <div class="success-checkmark">
                            <div class="check-icon">
                                <span class="icon-line line-tip"></span>
                                <span class="icon-line line-long"></span>
                                <div class="icon-circle"></div>
                                <div class="icon-fix"></div>
                            </div>
                        </div>
                    </div>
                    
                    <h1 class="mb-3">Thank You for Your Submission!</h1>
                    
                    <p class="lead mb-4">
                        Your news submission has been received and is currently under review by our team.
                        You'll be notified once it's been approved and published.
                    </p>
                    
                    <div class="d-flex justify-content-center gap-3">
                        <a href="submit.php" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i>Submit Another
                        </a>
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-home me-2"></i>Back to Home
                        </a>
                    </div>
                    
                    <div class="mt-5 pt-4 border-top">
                        <h5 class="mb-3">What happens next?</h5>
                        <div class="row text-start">
                            <div class="col-md-4 mb-3">
                                <div class="d-flex">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-search fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6>Review Process</h6>
                                        <p class="text-muted small mb-0">Our team will review your submission to ensure it meets our content guidelines.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-flex">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-bell fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6>Notification</h6>
                                        <p class="text-muted small mb-0">You'll receive an email once your submission is approved or if we need more information.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="d-flex">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-globe fa-2x"></i>
                                    </div>
                                    <div>
                                        <h6>Publication</h6>
                                        <p class="text-muted small mb-0">Once approved, your news will be visible to our community of readers.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.success-checkmark {
    width: 80px;
    height: 115px;
    margin: 0 auto;
    position: relative;
}

.check-icon {
    width: 80px;
    height: 80px;
    position: relative;
    border-radius: 50%;
    box-sizing: content-box;
    border: 4px solid #4CAF50;
}

.check-icon::before {
    top: 3px;
    left: -2px;
    width: 30px;
    transform-origin: 100% 50%;
    border-radius: 100px 0 0 100px;
}

.check-icon::after {
    top: 0;
    left: 30px;
    width: 60px;
    transform-origin: 0 50%;
    border-radius: 0 100px 100px 0;
    animation: rotate-circle 4.25s ease-in;
}

.check-icon::before, .check-icon::after {
    content: '';
    height: 100px;
    position: absolute;
    background: #FFFFFF;
    transform: rotate(-45deg);
}

.icon-line {
    height: 5px;
    background-color: #4CAF50;
    display: block;
    border-radius: 2px;
    position: absolute;
    z-index: 10;
}

.icon-line.line-tip {
    top: 46px;
    left: 14px;
    width: 25px;
    transform: rotate(45deg);
    animation: icon-line-tip 0.75s;
}

.icon-line.line-long {
    top: 38px;
    right: 8px;
    width: 47px;
    transform: rotate(-45deg);
    animation: icon-line-long 0.75s;
}

@keyframes icon-line-tip {
    0% { width: 0; left: 1px; top: 19px; }
    54% { width: 0; left: 1px; top: 19px; }
    70% { width: 50px; left: -8px; top: 37px; }
    84% { width: 17px; left: 21px; top: 48px; }
    100% { width: 25px; left: 14px; top: 45px; }
}

@keyframes icon-line-long {
    0% { width: 0; right: 46px; top: 54px; }
    65% { width: 0; right: 46px; top: 54px; }
    84% { width: 55px; right: 0px; top: 35px; }
    100% { width: 47px; right: 8px; top: 38px; }
}

@keyframes rotate-circle {
    0% { transform: rotate(-45deg); }
    5% { transform: rotate(-45deg); }
    12% { transform: rotate(-405deg); }
    100% { transform: rotate(-405deg); }
}
</style>

<?php include 'includes/footer.php'; ?>
