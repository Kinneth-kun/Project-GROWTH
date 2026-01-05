<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if admin exists, if not create one
    $adminCheck = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'admin'")->fetchColumn();
    if ($adminCheck == 0) {
        $adminPassword = password_hash('Admin123!', PASSWORD_BCRYPT); // Strong password for admin
        $conn->exec("INSERT INTO users (usr_name, usr_email, usr_password, usr_role, usr_gender, usr_birthdate, usr_address, usr_is_approved, usr_account_status) 
                    VALUES ('Admin User', 'admin@ctu.edu.ph', '$adminPassword', 'admin', 'Male', '1980-01-01', 'Cebu City', TRUE, 'active')");
    }
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Check system settings (staff accounts, auto-approve users, and maintenance mode)
$enable_staff_accounts_setting = 0;
$auto_approve_users_setting = 0;
$maintenance_mode_setting = 0;

try {
    // Check if system_settings table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'system_settings'")->fetchColumn();
    
    if ($tableCheck) {
        $settings_stmt = $conn->prepare("SELECT setting_name, setting_value FROM system_settings WHERE setting_name IN ('enable_staff_accounts', 'auto_approve_users', 'maintenance_mode')");
        $settings_stmt->execute();
        $system_settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (isset($system_settings['enable_staff_accounts'])) {
            $enable_staff_accounts_setting = intval($system_settings['enable_staff_accounts']);
        }
        if (isset($system_settings['auto_approve_users'])) {
            $auto_approve_users_setting = intval($system_settings['auto_approve_users']);
        }
        if (isset($system_settings['maintenance_mode'])) {
            $maintenance_mode_setting = intval($system_settings['maintenance_mode']);
        }
    }
} catch (PDOException $e) {
    // If table doesn't exist yet or setting not found, use default values
    $enable_staff_accounts_setting = 0;
    $auto_approve_users_setting = 0;
    $maintenance_mode_setting = 0;
}

// Handle all form submissions
$error = isset($_GET['error']) ? urldecode($_GET['error']) : '';
$success = isset($_GET['success']) ? urldecode($_GET['success']) : '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE usr_email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Check if maintenance mode is enabled and user is not admin
            if ($maintenance_mode_setting && $user['usr_role'] !== 'admin') {
                $error = "System is currently under maintenance. Please try again later.";
            } elseif ($user['usr_account_status'] === 'suspended') {
                $error = "Your account has been suspended. Please contact administrator.";
            } elseif ($user['usr_account_status'] === 'inactive') {
                $error = "Your account is inactive. Please contact administrator to reactivate.";
            } elseif ($user['usr_role'] === 'admin' || $user['usr_is_approved']) {
                if (password_verify($password, $user['usr_password'])) {
                    $updateStmt = $conn->prepare("UPDATE users SET usr_last_login = NOW(), usr_failed_login_attempts = 0 WHERE usr_id = :user_id");
                    $updateStmt->bindParam(':user_id', $user['usr_id']);
                    $updateStmt->execute();
                    
                    $_SESSION['user_id'] = $user['usr_id'];
                    $_SESSION['user_name'] = $user['usr_name'];
                    $_SESSION['user_role'] = $user['usr_role'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['account_status'] = $user['usr_account_status'];
                    $_SESSION['expire_time'] = time() + 3600;
                    
                    $dashboard = [
                        'admin' => 'admin_dashboard.php',
                        'graduate' => 'graduate_dashboard.php',
                        'employer' => 'employer_dashboard.php',
                        'staff' => 'staff_dashboard.php'
                    ];
                    
                    header('Location: ' . ($dashboard[$user['usr_role']] ?? 'index.php'));
                    exit();
                } else {
                    $failedAttempts = ($user['usr_failed_login_attempts'] ?? 0) + 1;
                    $updateStmt = $conn->prepare("UPDATE users SET usr_failed_login_attempts = :attempts WHERE usr_id = :user_id");
                    $updateStmt->bindParam(':attempts', $failedAttempts);
                    $updateStmt->bindParam(':user_id', $user['usr_id']);
                    $updateStmt->execute();
                    
                    if ($failedAttempts >= 5) {
                        $lockStmt = $conn->prepare("UPDATE users SET usr_account_status = 'suspended' WHERE usr_id = :user_id");
                        $lockStmt->bindParam(':user_id', $user['usr_id']);
                        $lockStmt->execute();
                        $error = "Too many failed login attempts. Your account has been locked.";
                    } else {
                        $error = "Invalid email or password. " . (5 - $failedAttempts) . " attempts remaining.";
                    }
                }
            } else {
                $error = "Your account is pending approval. Please wait for admin approval.";
            }
        } else {
            $error = "Invalid email or password.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Password validation function
function validatePassword($password) {
    $errors = [];
    
    // Check minimum length
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    // Check for at least one lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    // Check for at least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    // Check for at least one number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    // Check if maintenance mode is enabled
    if ($maintenance_mode_setting) {
        $error = "System is currently under maintenance. New registrations are temporarily disabled. Please try again later.";
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm'] ?? '';
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } else {
            // Validate password requirements
            $passwordErrors = validatePassword($password);
            if (!empty($passwordErrors)) {
                $error = implode(", ", $passwordErrors);
            } elseif ($password !== $confirm_password) {
                $error = "Passwords do not match";
            } else {
                try {
                    // Check for existing user
                    $check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_email = :email");
                    $check_stmt->bindParam(":email", $email);
                    $check_stmt->execute();
                    
                    if ($check_stmt->rowCount() > 0) {
                        $error = "Email already registered";
                    } else {
                        // Always require OTP verification regardless of auto-approve setting
                        $is_approved = FALSE; // Will be set to TRUE after profile completion if auto-approve is enabled
                        $account_status = 'pending'; // Will be updated after profile completion
                        
                        // Insert user with pending status (will be updated after profile completion)
                        $password_hash = password_hash($password, PASSWORD_BCRYPT);
                        $temp_name = 'Pending User';
                        $registration_date = date('Y-m-d H:i:s');
                        
                        $stmt = $conn->prepare("INSERT INTO users 
                                              (usr_name, usr_email, usr_password, usr_role, usr_created_at, usr_is_approved, usr_account_status) 
                                              VALUES (:name, :email, :password, NULL, :regdate, :is_approved, :account_status)");
                        $stmt->bindParam(":name", $temp_name);
                        $stmt->bindParam(":email", $email);
                        $stmt->bindParam(":password", $password_hash);
                        $stmt->bindParam(":regdate", $registration_date);
                        $stmt->bindParam(":is_approved", $is_approved, PDO::PARAM_BOOL);
                        $stmt->bindParam(":account_status", $account_status);
                        
                        if ($stmt->execute()) {
                            $_SESSION['signup_user_id'] = $conn->lastInsertId();
                            $_SESSION['otp_email'] = $email;
                            $_SESSION['otp_purpose'] = 'signup';
                            $_SESSION['auto_approve_enabled'] = $auto_approve_users_setting; // Store auto-approve setting for later use
                            
                            // Always go to OTP verification first
                            header('Location: send_otp.php');
                            exit();
                        } else {
                            $error = "Registration failed. Please try again.";
                        }
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle OTP request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_otp'])) {
    // Check if maintenance mode is enabled
    if ($maintenance_mode_setting) {
        $error = "System is currently under maintenance. Password reset is temporarily unavailable. Please try again later.";
    } else {
        $email = trim($_POST['email'] ?? '');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address";
        } else {
            try {
                $stmt = $conn->prepare("SELECT usr_id, usr_name, usr_account_status FROM users WHERE usr_email = :email");
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    if ($user['usr_account_status'] === 'suspended') {
                        $error = "Your account is suspended. Please contact the administrator.";
                    } elseif ($user['usr_account_status'] === 'inactive') {
                        $error = "Your account is inactive. Please contact administrator to reactivate.";
                    } else {
                        $_SESSION['otp_email'] = $email;
                        $_SESSION['signup_user_id'] = $user['usr_id'];
                        $_SESSION['otp_purpose'] = 'reset';
                        header('Location: send_otp.php');
                        exit();
                    }
                } else {
                    $error = "No account found with that email address.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Handle role selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_role'])) {
    // Check if maintenance mode is enabled
    if ($maintenance_mode_setting) {
        $error = "System is currently under maintenance. Please try again later.";
    } else {
        $role = trim($_POST['role'] ?? '');
        $allowed_roles = ['graduate', 'employer'];
        
        // Add staff to allowed roles only if enabled
        if ($enable_staff_accounts_setting) {
            $allowed_roles[] = 'staff';
        }
        
        if (!in_array($role, $allowed_roles)) {
            $error = "Invalid role selected.";
        } else {
            try {
                // Update user role only - approval status will be set after profile completion
                $stmt = $conn->prepare("UPDATE users SET usr_role = :role WHERE usr_id = :user_id");
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':user_id', $_SESSION['verified_user_id']);
                $stmt->execute();
                
                // Set user_id in session for the form
                $_SESSION['user_id'] = $_SESSION['verified_user_id'];
                $_SESSION['user_role'] = $role;
                
                // Store auto-approve setting for use after profile completion
                $_SESSION['auto_approve_enabled'] = $auto_approve_users_setting;
                
                // Always redirect to the appropriate form to fill out information
                $form_pages = [
                    'graduate' => 'graduate_form.php',
                    'employer' => 'employer_form.php',
                    'staff' => 'staff_form.php'
                ];
                
                $success = "Role selected successfully! Please complete your profile.";
                header('Location: ' . $form_pages[$role] . '?success=' . urlencode($success));
                exit();
                
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Determine which page to show
$show_login = true;
$show_signup = false;
$show_reset = false;
$show_otp_form = false;
$show_select_role = false;

if (isset($_GET['page'])) {
    if ($_GET['page'] === 'signup') {
        $show_signup = true;
        $show_login = false;
    } elseif ($_GET['page'] === 'reset') {
        $show_reset = true;
        $show_login = false;
    } elseif ($_GET['page'] === 'verify_otp' || $_GET['page'] === 'verify_reset_otp') {
        $show_otp_form = true;
        $show_login = false;
        $show_reset = false;
    } elseif ($_GET['page'] === 'select_role') {
        $show_select_role = true;
        $show_login = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>G.R.O.W.T.H.</title>
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

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 10px;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .forgot-link:hover {
            text-decoration: underline;
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

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: #b71c1c;
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

        .user-type-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .user-type-card {
            background: white;
            border: 2px solid var(--gray-light);
            border-radius: 10px;
            padding: 1.5rem;
            cursor: pointer;
            transition: var(--transition);
            text-align: left;
        }

        .user-type-card:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        }

        .user-type-card.selected {
            border-color: var(--primary);
            background-color: rgba(110, 3, 3, 0.05);
        }

        .user-type-icon {
            font-size: 1.8rem;
            margin-bottom: 0.8rem;
            color: var(--primary);
        }

        .user-type-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .user-type-description {
            font-size: 0.9rem;
            color: var(--gray);
            line-height: 1.5;
        }

        .otp-inputs {
            display: flex;
            gap: 0.8rem;
            justify-content: center;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }

        .otp-input {
            width: 3.5rem;
            height: 4rem;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            transition: var(--transition);
        }

        .otp-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
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

        .password-requirements .valid {
            color: var(--success);
        }

        .password-requirements .invalid {
            color: var(--danger);
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

        .mobile-user-type-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .mobile-user-type-card {
            background: white;
            border: 2px solid var(--gray-light);
            border-radius: 12px;
            padding: 1.2rem;
            cursor: pointer;
            transition: var(--transition);
            text-align: left;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .mobile-user-type-card:active {
            transform: scale(0.98);
        }

        .mobile-user-type-card.selected {
            border-color: var(--primary);
            background-color: rgba(110, 3, 3, 0.05);
        }

        .mobile-user-type-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .mobile-user-type-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
            color: var(--dark);
        }

        .mobile-user-type-description {
            font-size: 0.85rem;
            color: var(--gray);
            line-height: 1.4;
        }

        .mobile-otp-inputs {
            display: flex;
            gap: 0.8rem;
            justify-content: center;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }

        .mobile-otp-input {
            width: 3rem;
            height: 3.5rem;
            text-align: center;
            font-size: 1.3rem;
            font-weight: 600;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            transition: var(--transition);
            min-height: 52px;
        }

        .mobile-otp-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
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
            
            .user-type-card {
                min-height: 120px;
                padding: 1.2rem;
            }
            
            .user-type-icon {
                font-size: 1.5rem;
            }
            
            .user-type-title {
                font-size: 1.1rem;
            }
            
            .user-type-description {
                font-size: 0.85rem;
            }
            
            .otp-input {
                width: 3rem;
                height: 3.5rem;
                font-size: 1.3rem;
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
            
            .otp-input {
                width: 2.8rem;
                height: 3.2rem;
                font-size: 1.2rem;
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
            
            .user-type-card {
                padding: 1rem;
            }
            
            .user-type-icon {
                font-size: 1.3rem;
            }
            
            .user-type-title {
                font-size: 1rem;
            }
            
            .user-type-description {
                font-size: 0.8rem;
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
            
            .otp-inputs {
                gap: 0.5rem;
            }
            
            .otp-input {
                width: 2.5rem;
                height: 3rem;
                font-size: 1.1rem;
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
            
            .user-type-card {
                padding: 0.9rem;
                min-height: 110px;
            }
            
            .user-type-icon {
                font-size: 1.2rem;
            }
            
            .user-type-title {
                font-size: 1rem;
            }
            
            .user-type-description {
                font-size: 0.8rem;
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
            .otp-inputs {
                gap: 0.4rem;
            }
            
            .otp-input {
                width: 2.3rem;
                height: 2.8rem;
                font-size: 1rem;
            }
            
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
            
            .user-type-card {
                padding: 0.8rem;
                min-height: 100px;
            }
            
            .user-type-icon {
                font-size: 1.1rem;
            }
            
            .user-type-title {
                font-size: 0.95rem;
            }
            
            .user-type-description {
                font-size: 0.75rem;
            }
            
            .mobile-brand {
                padding: 0.8rem;
            }
        }

        @media (max-width: 350px) {
            .otp-inputs {
                gap: 0.3rem;
            }
            
            .otp-input {
                width: 2rem;
                height: 2.5rem;
                font-size: 0.9rem;
            }
            
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
            .otp-input {
                width: 1.8rem;
                height: 2.3rem;
                font-size: 0.85rem;
            }
            
            .form-title {
                font-size: 1.2rem;
            }
            
            .btn {
                padding: 0.8rem 0.8rem;
            }
            
            .user-type-card {
                padding: 0.7rem;
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
        .btn, .form-control, .user-type-card {
            min-height: 44px;
        }
        
        .otp-input {
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
                    <?php if ($maintenance_mode_setting && $show_login): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-tools"></i>
                            <div>
                                <strong>System Under Maintenance</strong>
                                <p>The system is currently undergoing maintenance. Regular users cannot log in at this time.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($show_otp_form): ?>
                        <div class="form-header">
                            <h2 class="form-title mobile-form-title">Verify Your Identity</h2>
                            <p class="form-subtitle mobile-form-subtitle">Enter the 6-digit code sent to your email</p>
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="<?= isset($_SESSION['otp_purpose']) && $_SESSION['otp_purpose'] === 'reset' ? 'verify_reset_otp.php' : 'verify_otp.php' ?>">
                            <div class="otp-inputs mobile-otp-inputs">
                                <input class="otp-input mobile-otp-input" name="otp1" type="text" maxlength="1" pattern="[0-9]" required>
                                <input class="otp-input mobile-otp-input" name="otp2" type="text" maxlength="1" pattern="[0-9]" required>
                                <input class="otp-input mobile-otp-input" name="otp3" type="text" maxlength="1" pattern="[0-9]" required>
                                <input class="otp-input mobile-otp-input" name="otp4" type="text" maxlength="1" pattern="[0-9]" required>
                                <input class="otp-input mobile-otp-input" name="otp5" type="text" maxlength="1" pattern="[0-9]" required>
                                <input class="otp-input mobile-otp-input" name="otp6" type="text" maxlength="1" pattern="[0-9]" required>
                            </div>
                            <input type="hidden" name="otp" id="full-otp">
                            <button class="btn btn-primary mobile-btn mobile-btn-primary" type="submit" name="verify_otp">
                                <i class="fas fa-check"></i> Verify OTP
                            </button>
                        </form>
                        
                        <div class="form-footer mobile-form-footer">
                            Didn't receive OTP? <a href="?page=reset">Request another</a><br>
                            Back to <a href="?page=login">Log in</a>
                        </div>
                        
                    <?php elseif ($show_reset): ?>
                        <div class="form-header">
                            <h2 class="form-title mobile-form-title">Reset Your Password</h2>
                            <p class="form-subtitle mobile-form-subtitle">Enter your email to receive a verification code</p>
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address</label>
                                <input class="form-control mobile-form-control" name="email" id="email" type="email" placeholder="Enter your email address" required>
                            </div>
                            <button class="btn btn-primary mobile-btn mobile-btn-primary" type="submit" name="request_otp">
                                <i class="fas fa-paper-plane"></i> Send Verification Code
                            </button>
                        </form>
                        
                        <div class="form-footer mobile-form-footer">
                            Remember your password? <a href="?page=login">Log in here</a>
                        </div>
                        
                    <?php elseif ($show_select_role): ?>
                        <div class="form-header">
                            <h2 class="form-title mobile-form-title">Select Account Type</h2>
                            <p class="form-subtitle mobile-form-subtitle">Choose the account type that best describes you</p>
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="roleForm">
                            <div class="user-type-container mobile-user-type-container">
                                <div class="user-type-card mobile-user-type-card" data-role="graduate" onclick="selectRole('graduate')">
                                    <div class="user-type-icon mobile-user-type-icon">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div class="user-type-title mobile-user-type-title">Alumni</div>
                                    <div class="user-type-description mobile-user-type-description">
                                        I am a CTU Alumni looking for job opportunities and career resources.
                                    </div>
                                </div>
                                
                                <div class="user-type-card mobile-user-type-card" data-role="employer" onclick="selectRole('employer')">
                                    <div class="user-type-icon mobile-user-type-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="user-type-title mobile-user-type-title">Employer</div>
                                    <div class="user-type-description mobile-user-type-description">
                                        I represent a company looking to hire talented CTU graduates.
                                    </div>
                                </div>
                                
                                <?php if ($enable_staff_accounts_setting): ?>
                                    <div class="user-type-card mobile-user-type-card" data-role="staff" onclick="selectRole('staff')">
                                        <div class="user-type-icon mobile-user-type-icon">
                                            <i class="fas fa-user-tie"></i>
                                        </div>
                                        <div class="user-type-title mobile-user-type-title">Staff</div>
                                        <div class="user-type-description mobile-user-type-description">
                                            I am CTU-PESO staff member with administrative access to the platform.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="role" id="selected_role">
                            <button class="btn btn-primary mobile-btn mobile-btn-primary" type="submit" name="select_role" id="roleSubmit" style="display: none;">
                                Continue
                            </button>
                        </form>
                        
                        <div class="form-footer mobile-form-footer">
                            Already have an account? <a href="?page=login">Log in here</a>
                        </div>
                        
                    <?php elseif ($show_signup): ?>
                        <div class="form-header">
                            <h2 class="form-title mobile-form-title">Create Account</h2>
                            <p class="form-subtitle mobile-form-subtitle">Join our platform to unlock opportunities</p>
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="signupForm">
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address</label>
                                <input class="form-control mobile-form-control" name="email" id="email" type="email" placeholder="Enter your email" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="password">Password</label>
                                <input class="form-control mobile-form-control" name="password" id="password" type="password" placeholder="Create a password" required minlength="8">
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
                                <label class="form-label" for="confirm">Confirm Password</label>
                                <input class="form-control mobile-form-control" name="confirm" id="confirm" type="password" placeholder="Confirm your password" required minlength="8">
                                <div id="password-match" style="margin-top: 5px; font-size: 14px;"></div>
                            </div>
                            
                            <button class="btn btn-primary mobile-btn mobile-btn-primary" type="submit" name="signup" id="signupSubmit" disabled>
                                <i class="fas fa-user-plus"></i> Create Account
                            </button>
                        </form>
                        
                        <div class="form-footer mobile-form-footer">
                            Already have an account? <a href="?page=login">Log in here</a>
                        </div>
                        
                    <?php else: ?>
                        <div class="form-header">
                            <h2 class="form-title mobile-form-title">Welcome Back</h2>
                            <p class="form-subtitle mobile-form-subtitle">Sign in to your account to continue</p>
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address</label>
                                <input class="form-control mobile-form-control" name="email" id="email" type="email" placeholder="Enter your email" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="password">Password</label>
                                <input class="form-control mobile-form-control" name="password" id="password" type="password" placeholder="Enter your password" required>
                            </div>
                            
                            <div class="form-options">
                                <div class="checkbox-container">
                                    <input type="checkbox" id="remember" name="remember" checked>
                                    <label for="remember">Remember me</label>
                                </div>
                                <a href="?page=reset" class="forgot-link">Forgot Password?</a>
                            </div>
                            
                            <button class="btn btn-primary mobile-btn mobile-btn-primary" type="submit" name="login">
                                <i class="fas fa-sign-in-alt"></i> Sign In
                            </button>
                        </form>
                        
                        <div class="form-footer mobile-form-footer">
                            Don't have an account? <a href="?page=signup">Sign up here</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // OTP Input Handling
        document.addEventListener('DOMContentLoaded', function() {
            const otpInputs = document.querySelectorAll('.otp-input');
            
            if (otpInputs.length > 0) {
                otpInputs.forEach((input, index) => {
                    input.addEventListener('input', function() {
                        if (this.value.length === 1 && index < otpInputs.length - 1) {
                            otpInputs[index + 1].focus();
                        }
                        updateFullOTP();
                    });
                    
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
                            otpInputs[index - 1].focus();
                        }
                    });
                });
                
                function updateFullOTP() {
                    let fullOTP = '';
                    otpInputs.forEach(input => {
                        fullOTP += input.value;
                    });
                    document.getElementById('full-otp').value = fullOTP;
                }
            }
            
            // Password strength indicator and validation
            const passwordInput = document.getElementById('password');
            const signupSubmit = document.getElementById('signupSubmit');
            
            if (passwordInput && signupSubmit) {
                passwordInput.addEventListener('input', function() {
                    checkPasswordStrength();
                    validateForm();
                });
            }
            
            // Password confirmation check
            const confirmInput = document.getElementById('confirm');
            if (confirmInput) {
                confirmInput.addEventListener('input', function() {
                    checkPasswordMatch();
                    validateForm();
                });
            }
            
            // Email input validation
            const emailInput = document.getElementById('email');
            if (emailInput && signupSubmit) {
                emailInput.addEventListener('input', validateForm);
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
            
            // Add touch feedback for user type cards on mobile
            const userTypeCards = document.querySelectorAll('.user-type-card');
            userTypeCards.forEach(card => {
                card.addEventListener('touchstart', function() {
                    this.style.transform = 'scale(0.98)';
                });
                
                card.addEventListener('touchend', function() {
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
            const confirm = document.getElementById('confirm').value;
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
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm').value;
            const signupSubmit = document.getElementById('signupSubmit');
            
            // Basic email validation
            const emailValid = email.includes('@') && email.includes('.');
            
            // Check if all password requirements are met
            const passwordValid = checkPasswordStrength();
            
            // Check if passwords match
            const passwordsMatch = checkPasswordMatch();
            
            // Enable submit button only if all validations pass
            if (emailValid && passwordValid && passwordsMatch && password.length >= 8 && confirm.length >= 8) {
                signupSubmit.disabled = false;
            } else {
                signupSubmit.disabled = true;
            }
        }
        
        function selectRole(role) {
            // Update UI
            document.querySelectorAll('.user-type-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.user-type-card[data-role="${role}"]`).classList.add('selected');
            
            // Set hidden input value
            document.getElementById('selected_role').value = role;
            
            // Show and focus the submit button
            const submitBtn = document.getElementById('roleSubmit');
            submitBtn.style.display = 'block';
            submitBtn.focus();
        }
        
        // Form validation for signup
        document.getElementById('signupForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm').value;
            
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