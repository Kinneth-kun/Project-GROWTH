<?php
session_start();

// Check if user is verified for password reset
if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true || !isset($_SESSION['reset_user_id'])) {
    header('Location: index.php?page=reset&error=' . urlencode("Unauthorized access. Please request a new OTP."));
    exit;
}

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("reset_password.php: Database Connection Failed: " . $e->getMessage());
    header('Location: index.php?page=reset&error=' . urlencode("Database error. Please try again."));
    exit;
}

$error = isset($_GET['error']) ? urldecode($_GET['error']) : '';
$success = isset($_GET['success']) ? urldecode($_GET['success']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET usr_password = :password, usr_failed_login_attempts = 0 WHERE usr_id = :user_id");
            $stmt->bindParam(':password', $password_hash);
            $stmt->bindParam(':user_id', $_SESSION['reset_user_id']);
            $stmt->execute();
            
            // Clear reset session
            unset($_SESSION['reset_verified']);
            unset($_SESSION['reset_user_id']);
            
            $success = "Password reset successfully! You can now log in with your new password.";
            header("refresh:5;url=index.php?page=login");
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | G.R.O.W.T.H.</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6e0303;
            --primary-light: #8a0404;
            --secondary: #ffa700;
            --secondary-light: #f7a100;
            --success: #1f7a11;
            --info: #0044ff;
            --warning: #ff9800;
            --danger: #d32f2f;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border-radius: 12px;
            --box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            --transition: all 0.3s ease;
            --mobile-header-height: 70px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            width: 100%;
            overflow-x: hidden;
        }

        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            display: flex;
            flex: 1;
            width: 100%;
            min-height: 100vh;
        }

        .brand-section {
            flex: 1.2;
            background: linear-gradient(rgba(110, 3, 3, 0.85), rgba(138, 4, 4, 0.9)), url('images/bg.jpeg');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
        }

        .brand-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZGVmcz48cGF0dGVybiBpZD0icGF0dGVybiIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiBwYXR0ZXJuVW5pdHM9InVzZXJTcGFjZU9uVXNlIiBwYXR0ZXJuVHJhbnNmb3JtPSJyb3RhdGUoNDUpIj48cmVjdCB3aWR0aD0iMSIgaGVpZ2h0PSIxIiBmaWxsPSJyZ2JhKDI1NSwgMjU1LCAyNTUsIDAuMDUpIi8+PC9wYXR0ZXJuPjwvZGVmcz48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSJ1cmwoI3BhdHRlcm4pIiAvPjwvc3ZnPg==');
            opacity: 0.3;
        }

        .brand-header {
            position: relative;
            z-index: 1;
            margin-top: 1rem;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 2rem;
        }

        .brand-logo img {
            width: 90px;
            height: 90px;
            border-radius: 10px;
            object-fit: contain;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            background: transparent;
            padding: 5px;
        }

        .brand-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .brand-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .brand-acronym {
            font-size: 3.5rem;
            font-weight: 800;
            margin: 2rem 0;
            line-height: 1.1;
            letter-spacing: 1px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .brand-description {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.95;
            line-height: 1.6;
            font-weight: 500;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
            background: rgba(255, 255, 255, 0.1);
            padding: 1.5rem;
            border-radius: 10px;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .mission-statement {
            font-size: 1.1rem;
            margin-top: 1.5rem;
            opacity: 0.9;
            line-height: 1.6;
            font-style: italic;
        }

        .brand-footer {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2rem;
        }

        .partner-logos {
            display: flex;
            gap: 15px;
        }

        .partner-logos img {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: contain;
            background: transparent;
            padding: 5px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .copyright {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .form-section {
            flex: 1;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: white;
            overflow-y: auto;
            min-height: 100vh;
        }

        .form-container {
            max-width: 450px;
            width: 100%;
            margin: 0 auto;
            padding: 1rem;
        }

        .form-header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .form-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .form-subtitle {
            color: var(--gray);
            font-size: 1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .alert-error {
            background-color: #ffebee;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .alert-success {
            background-color: #e8f5e9;
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-warning {
            background-color: #fff3e0;
            color: #e65100;
            border-left: 4px solid var(--warning);
        }

        .alert-info {
            background-color: #e3f2fd;
            color: var(--info);
            border-left: 4px solid var(--info);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            font-size: 1rem;
            transition: var(--transition);
            background-color: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }

        .form-control::placeholder {
            color: #adb5bd;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(110, 3, 3, 0.3);
        }

        .btn-secondary {
            background-color: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background-color: var(--secondary-light);
            transform: translateY(-2px);
        }

        .form-footer {
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .password-strength {
            margin-top: 0.5rem;
            height: 6px;
            border-radius: 3px;
            background-color: var(--gray-light);
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: var(--transition);
        }

        .strength-weak {
            background-color: var(--danger);
            width: 33%;
        }

        .strength-medium {
            background-color: var(--warning);
            width: 66%;
        }

        .strength-strong {
            background-color: var(--success);
            width: 100%;
        }

        .password-requirements {
            margin-top: 0.8rem;
            font-size: 0.8rem;
            color: var(--gray);
        }

        .password-requirements ul {
            padding-left: 1.2rem;
            margin-top: 0.3rem;
        }

        .password-requirements li {
            margin-bottom: 0.2rem;
        }

        .requirement-met {
            color: var(--success) !important;
        }

        .requirement-not-met {
            color: var(--danger) !important;
        }

        .btn:disabled {
            background-color: var(--gray-light);
            color: var(--gray);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn:disabled:hover {
            background-color: var(--gray-light);
            transform: none;
            box-shadow: none;
        }

        /* Mobile Navigation */
        .mobile-nav {
            display: none;
            background: var(--primary);
            color: white;
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: var(--mobile-header-height);
        }

        .mobile-nav-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
        }

        .mobile-logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .mobile-logo img {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: contain;
            background: transparent;
        }

        .mobile-menu-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Mobile brand section for small screens */
        .mobile-brand {
            display: none;
            background: linear-gradient(rgba(110, 3, 3, 0.9), rgba(138, 4, 4, 0.95));
            color: white;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* Mobile-specific enhancements */
        .mobile-form-container {
            width: 100%;
            max-width: 100%;
            padding: 0;
        }

        .mobile-form-content {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-top: 1rem;
        }

        .mobile-form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
            text-align: center;
        }

        .mobile-form-subtitle {
            color: var(--gray);
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .mobile-form-footer {
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
            color: var(--gray);
            padding: 0 1rem;
        }

        .mobile-form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        /* Enhanced mobile form controls */
        .mobile-form-control {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--gray-light);
            border-radius: 10px;
            font-size: 16px; /* Prevent zoom on iOS */
            transition: var(--transition);
            background-color: white;
            min-height: 52px;
        }

        .mobile-form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }

        .mobile-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
            text-decoration: none;
            min-height: 52px;
        }

        .mobile-btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .mobile-btn-primary:active {
            background-color: var(--primary-light);
            transform: scale(0.98);
        }

        /* Responsive Design - Mobile First Approach */
        @media (max-width: 1200px) {
            .brand-acronym {
                font-size: 3rem;
            }
            
            .brand-description {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 992px) {
            .container {
                flex-direction: column;
                min-height: 100vh;
            }
            
            .brand-section {
                display: none;
            }
            
            .mobile-nav {
                display: block;
            }
            
            .form-section {
                padding: 1rem;
                min-height: calc(100vh - var(--mobile-header-height));
                justify-content: flex-start;
                padding-top: 0.5rem;
            }
            
            .form-container {
                max-width: 100%;
                padding: 0;
            }
            
            .mobile-brand {
                display: block;
            }
            
            .brand-acronym {
                font-size: 2.5rem;
            }
            
            .brand-description {
                font-size: 1rem;
            }
            
            /* Use mobile-specific styles on mobile */
            .form-title {
                font-size: 1.5rem;
            }
            
            .form-subtitle {
                font-size: 0.9rem;
            }
            
            .form-control, .btn {
                min-height: 52px;
            }
        }

        @media (max-width: 768px) {
            .form-section {
                padding: 0.8rem;
            }
            
            .mobile-form-content {
                padding: 1.2rem;
            }
            
            .form-title {
                font-size: 1.4rem;
            }
            
            .btn {
                padding: 0.9rem 1.2rem;
                font-size: 0.95rem;
            }
            
            .alert {
                padding: 0.8rem;
                font-size: 0.85rem;
            }
            
            .form-control {
                padding: 0.9rem;
            }
            
            .mobile-brand {
                padding: 1.2rem;
            }
            
            .brand-logo img {
                width: 60px;
                height: 60px;
            }
            
            .brand-title {
                font-size: 1.3rem;
            }
            
            .brand-subtitle {
                font-size: 0.9rem;
            }
            
            .partner-logos img {
                width: 50px;
                height: 50px;
            }
        }

        @media (max-width: 576px) {
            .form-section {
                padding: 0.5rem;
            }
            
            .mobile-form-content {
                padding: 1rem;
                border-radius: 12px;
            }
            
            .form-title {
                font-size: 1.3rem;
            }
            
            .form-subtitle {
                font-size: 0.85rem;
            }
            
            .btn {
                padding: 0.9rem 1rem;
                font-size: 0.95rem;
            }
            
            .form-control {
                padding: 0.9rem;
            }
            
            .form-group {
                margin-bottom: 1.2rem;
            }
            
            .mobile-brand {
                padding: 1rem;
                margin-bottom: 0.8rem;
            }
            
            .partner-logos {
                gap: 10px;
            }
            
            .partner-logos img {
                width: 45px;
                height: 45px;
            }
            
            .copyright {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 400px) {
            .btn {
                padding: 0.85rem 0.9rem;
                font-size: 0.9rem;
            }
            
            .form-control {
                padding: 0.85rem;
            }
            
            .form-group {
                margin-bottom: 1rem;
            }
            
            .mobile-brand {
                padding: 0.8rem;
            }
        }

        @media (max-width: 350px) {
            .form-section {
                padding: 0.3rem;
            }
            
            .mobile-form-content {
                padding: 0.8rem;
            }
            
            .mobile-brand {
                padding: 0.7rem;
            }
            
            .brand-logo {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .brand-logo img {
                width: 50px;
                height: 50px;
            }
        }

        /* Extra small devices */
        @media (max-width: 320px) {
            .form-title {
                font-size: 1.2rem;
            }
            
            .btn {
                padding: 0.8rem 0.8rem;
            }
        }

        /* Ensure form elements are always properly sized */
        input, button, select, textarea {
            max-width: 100%;
        }

        /* Prevent horizontal scrolling */
        body, .container, .form-section {
            overflow-x: hidden;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Improved scrolling for mobile */
        .form-section {
            -webkit-overflow-scrolling: touch;
        }
        
        /* Better touch targets for mobile */
        .btn, .form-control {
            min-height: 44px;
        }
        
        /* Prevent zoom on input focus for mobile */
        @media (max-width: 768px) {
            input, select, textarea {
                font-size: 16px; /* Prevents zoom on iOS */
            }
        }
        
        /* Mobile-specific utility classes */
        .mobile-only {
            display: none;
        }
        
        @media (max-width: 992px) {
            .mobile-only {
                display: block;
            }
            
            .desktop-only {
                display: none;
            }
        }
        
        /* Success message styling */
        .success-message {
            color: var(--success);
            font-size: 16px;
            margin: 20px 0;
            padding: 15px;
            background-color: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #c8e6c9;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .redirect-notice {
            font-size: 14px;
            margin-top: 10px;
            color: #555;
        }
    </style>
</head>
<body>
    <!-- Mobile Navigation -->
    <div class="mobile-nav">
        <div class="mobile-nav-content">
            <div class="mobile-logo">
                <img src="images/ctu.png" alt="CTU Logo">
                <span style="font-weight: 600;">G.R.O.W.T.H.</span>
            </div>
            <button class="mobile-menu-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
    
    <div class="container fade-in">
        <div class="brand-section desktop-only">
            <div class="brand-header">
                <div class="brand-logo">
                    <img src="images/ctu.png" alt="CTU Logo">
                    <div>
                        <div class="brand-title">G.R.O.W.T.H.</div>
                        <div class="brand-subtitle">Graduate Resource Opportunity Workforce Tracking Hub</div>
                    </div>
                </div>
                
                <div class="brand-acronym">
                    G.R.O.W.T.H.
                </div>
                
                <div class="brand-description">
                    Connecting talented alumni with leading employers. 
                    Our platform bridges the gap between education and employment, 
                    providing resources, opportunities, and career growth for CTU graduates.
                </div>
                
                <div class="mission-statement">
                    <i class="fas fa-quote-left"></i> 
                    Empowering CTU alumni to achieve their career aspirations through innovative technology and meaningful connections with industry partners.
                    <i class="fas fa-quote-right"></i>
                </div>
            </div>
            
            <div class="brand-footer">
                <div class="partner-logos">
                    <img src="images/mkla.png" alt="CTU Logo">
                    <img src="images/peso.jpeg" alt="PESO Logo">
                </div>
                <div class="copyright">
                    &copy; <?php echo date('Y'); ?> CTU Graduate Hub
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <div class="form-container mobile-form-container">
                <!-- Mobile Brand Section -->
                <div class="mobile-brand mobile-only">
                    <div class="brand-logo" style="justify-content: center; margin-bottom: 1rem;">
                        <img src="images/ctu.png" alt="CTU Logo">
                        <div>
                            <div class="brand-title">G.R.O.W.T.H.</div>
                            <div class="brand-subtitle">Graduate Resource Opportunity Workforce Tracking Hub</div>
                        </div>
                    </div>
                    <p style="font-size: 0.9rem; opacity: 0.9;">
                        Connecting talented alumni with leading employers
                    </p>
                </div>
                
                <div class="mobile-form-content">
                    <div class="form-header">
                        <h2 class="form-title mobile-form-title">Reset Password</h2>
                        <p class="form-subtitle mobile-form-subtitle">Create a new password for your account</p>
                    </div>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="success-message">
                            <i class="fas fa-check-circle"></i>
                            <?= htmlspecialchars($success) ?>
                            <div class="redirect-notice">You will be redirected to the login page in <span id="countdown">5</span> seconds...</div>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="resetForm">
                        <div class="form-group">
                            <label class="form-label" for="password">New Password</label>
                            <input class="form-control mobile-form-control" name="password" id="password" type="password" placeholder="Enter new password" required minlength="8">
                            <div class="password-strength">
                                <div class="password-strength-bar" id="password-strength-bar"></div>
                            </div>
                            <div class="password-requirements">
                                <div>Password must contain:</div>
                                <ul>
                                    <li id="length-req">At least 8 characters</li>
                                    <li id="lowercase-req">One lowercase letter</li>
                                    <li id="uppercase-req">One uppercase letter</li>
                                    <li id="number-req">One number</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <input class="form-control mobile-form-control" name="confirm_password" id="confirm_password" type="password" placeholder="Confirm new password" required minlength="8">
                            <div id="password-match" style="margin-top: 5px; font-size: 14px;"></div>
                        </div>
                        
                        <button class="btn btn-primary mobile-btn mobile-btn-primary" type="submit" name="reset_password" id="resetSubmit">
                            <i class="fas fa-key"></i> Reset Password
                        </button>
                    </form>
                    
                    <div class="form-footer mobile-form-footer">
                        Remember your password? <a href="index.php?page=login">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($success)): ?>
    <script>
        let seconds = 5;
        const countdownElement = document.getElementById('countdown');
        const countdownInterval = setInterval(function() {
            seconds--;
            if (countdownElement) countdownElement.textContent = seconds;
            if (seconds <= 0) clearInterval(countdownInterval);
        }, 1000);
    </script>
    <?php endif; ?>

    <script>
        // Password strength indicator and validation
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm_password');
            const resetSubmit = document.getElementById('resetSubmit');
            
            if (passwordInput && resetSubmit) {
                passwordInput.addEventListener('input', function() {
                    checkPasswordStrength();
                    validateForm();
                });
            }
            
            // Password confirmation check
            if (confirmInput) {
                confirmInput.addEventListener('input', function() {
                    checkPasswordMatch();
                    validateForm();
                });
            }
            
            // Mobile menu button functionality
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    // This could be expanded to show a mobile menu if needed
                    alert('Mobile menu would open here');
                });
            }
            
            // Add touch feedback for mobile buttons
            const mobileButtons = document.querySelectorAll('.mobile-btn');
            mobileButtons.forEach(button => {
                button.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                
                button.addEventListener('touchend', function() {
                    this.style.transform = '';
                });
            });
        });
        
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('password-strength-bar');
            const lengthReq = document.getElementById('length-req');
            const lowercaseReq = document.getElementById('lowercase-req');
            const uppercaseReq = document.getElementById('uppercase-req');
            const numberReq = document.getElementById('number-req');
            
            // Reset classes
            strengthBar.className = 'password-strength-bar';
            
            let strength = 0;
            let requirementsMet = 0;
            const totalRequirements = 4;
            
            // Check length
            if (password.length >= 8) {
                lengthReq.className = 'requirement-met';
                strength += 25;
                requirementsMet++;
            } else {
                lengthReq.className = 'requirement-not-met';
            }
            
            // Check lowercase
            if (/[a-z]/.test(password)) {
                lowercaseReq.className = 'requirement-met';
                strength += 25;
                requirementsMet++;
            } else {
                lowercaseReq.className = 'requirement-not-met';
            }
            
            // Check uppercase
            if (/[A-Z]/.test(password)) {
                uppercaseReq.className = 'requirement-met';
                strength += 25;
                requirementsMet++;
            } else {
                uppercaseReq.className = 'requirement-not-met';
            }
            
            // Check numbers
            if (/[0-9]/.test(password)) {
                numberReq.className = 'requirement-met';
                strength += 25;
                requirementsMet++;
            } else {
                numberReq.className = 'requirement-not-met';
            }
            
            // Update strength bar
            if (strength > 0) {
                strengthBar.style.width = strength + '%';
                
                if (strength <= 33) {
                    strengthBar.className = 'password-strength-bar strength-weak';
                } else if (strength <= 66) {
                    strengthBar.className = 'password-strength-bar strength-medium';
                } else {
                    strengthBar.className = 'password-strength-bar strength-strong';
                }
            } else {
                strengthBar.style.width = '0%';
            }
            
            return requirementsMet === totalRequirements;
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchIndicator = document.getElementById('password-match');
            
            if (confirm.length === 0) {
                matchIndicator.textContent = '';
                matchIndicator.style.color = '';
                return false;
            } else if (password === confirm) {
                matchIndicator.textContent = '✓ Passwords match';
                matchIndicator.style.color = 'var(--success)';
                return true;
            } else {
                matchIndicator.textContent = '✗ Passwords do not match';
                matchIndicator.style.color = 'var(--danger)';
                return false;
            }
        }
        
        function validateForm() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const resetSubmit = document.getElementById('resetSubmit');
            
            // Check if all password requirements are met
            const passwordValid = checkPasswordStrength();
            
            // Check if passwords match
            const passwordsMatch = checkPasswordMatch();
            
            // Enable submit button only if all validations pass
            if (passwordValid && passwordsMatch && password.length >= 8 && confirm.length >= 8) {
                resetSubmit.disabled = false;
            } else {
                resetSubmit.disabled = true;
            }
        }
        
        // Form validation for reset
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Final validation before submission
            const passwordErrors = validatePasswordRequirements(password);
            if (passwordErrors.length > 0) {
                e.preventDefault();
                alert('Please fix the following password requirements:\n' + passwordErrors.join('\n'));
                return;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match. Please check and try again.');
                return;
            }
        });
        
        // Client-side password validation function
        function validatePasswordRequirements(password) {
            const errors = [];
            
            // Check minimum length
            if (password.length < 8) {
                errors.push("Password must be at least 8 characters long");
            }
            
            // Check for at least one lowercase letter
            if (!/[a-z]/.test(password)) {
                errors.push("Password must contain at least one lowercase letter");
            }
            
            // Check for at least one uppercase letter
            if (!/[A-Z]/.test(password)) {
                errors.push("Password must contain at least one uppercase letter");
            }
            
            // Check for at least one number
            if (!/[0-9]/.test(password)) {
                errors.push("Password must contain at least one number");
            }
            
            return errors;
        }
    </script>
</body>
</html>