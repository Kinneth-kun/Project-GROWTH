<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();
session_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("verify_otp.php: Database Connection Failed: " . $e->getMessage());
    header('Location: index.php?page=verify_otp&error=' . urlencode("Database error. Please try again."));
    exit;
}

// Check if we should bypass OTP verification (for testing/demo purposes)
$bypass_otp = true; // Set to false to require proper OTP verification

// Log session state and POST data for debugging
error_log("verify_otp.php: Session state - otp_email: " . (isset($_SESSION['otp_email']) ? $_SESSION['otp_email'] : 'not set') . ", signup_user_id: " . (isset($_SESSION['signup_user_id']) ? $_SESSION['signup_user_id'] : 'not set') . ", POST otp: " . (isset($_POST['otp']) ? $_POST['otp'] : 'not set'));

// Check request method and session variables
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("verify_otp.php: Invalid request method: {$_SERVER['REQUEST_METHOD']}");
    header('Location: index.php?page=verify_otp&error=' . urlencode("Invalid request method. Please submit the OTP form."));
    exit;
}

if (!isset($_SESSION['otp_email'])) {
    error_log("verify_otp.php: Missing otp_email session variable");
    header('Location: index.php?page=signup&error=' . urlencode("Session expired. Please start the signup process again."));
    exit;
}

if (!isset($_SESSION['signup_user_id'])) {
    error_log("verify_otp.php: Missing signup_user_id session variable");
    header('Location: index.php?page=signup&error=' . urlencode("Session expired. Please start the signup process again."));
    exit;
}

$email = $_SESSION['otp_email'];
$user_id = $_SESSION['signup_user_id'];

// If bypass is enabled, skip OTP verification and proceed directly
if ($bypass_otp) {
    try {
        // Get user info
        $stmt = $conn->prepare("SELECT usr_id, usr_email, usr_name, usr_role, usr_account_status 
                               FROM users 
                               WHERE usr_id = :user_id AND usr_email = :email");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user_data) {
            error_log("verify_otp.php: User not found during bypass - user_id: $user_id, email: $email");
            header('Location: index.php?page=signup&error=' . urlencode("User not found. Please start the signup process again."));
            exit;
        }
        
        // Check account status
        if ($user_data['usr_account_status'] === 'suspended') {
            error_log("verify_otp.php: Account suspended for user_id: $user_id");
            header('Location: index.php?page=verify_otp&error=' . urlencode("Your account is suspended. Please contact the administrator."));
            exit;
        } elseif ($user_data['usr_account_status'] === 'inactive') {
            error_log("verify_otp.php: Account inactive for user_id: $user_id");
            header('Location: index.php?page=verify_otp&error=' . urlencode("Your account is inactive. Please contact administrator to reactivate."));
            exit;
        }
        
        // Success: set session for role selection
        $_SESSION['verified_user_id'] = $user_data['usr_id'];
        $_SESSION['verified_email'] = $user_data['usr_email'];
        
        // Clear OTP-related session variables
        unset($_SESSION['otp_email']);
        unset($_SESSION['signup_user_id']);
        
        error_log("verify_otp.php: OTP bypassed successfully for user_id: $user_id");
        header('Location: index.php?page=select_role&success=' . urlencode("Proceeding to account type selection."));
        exit;
        
    } catch (PDOException $e) {
        error_log("verify_otp.php: Database error during bypass: " . $e->getMessage());
        header('Location: index.php?page=verify_otp&error=' . urlencode("Database error. Please try again."));
        exit;
    }
}

// Original OTP verification code (for when bypass is disabled)
if (!isset($_POST['otp']) || empty(trim($_POST['otp']))) {
    error_log("verify_otp.php: Missing or empty OTP in POST data");
    header('Location: index.php?page=verify_otp&error=' . urlencode("Please enter the OTP code."));
    exit;
}

$otp = trim($_POST['otp']);

// Validate OTP format
if (!preg_match('/^[0-9]{6}$/', $otp)) {
    error_log("verify_otp.php: Invalid OTP format: $otp, user_id: $user_id");
    header('Location: index.php?page=verify_otp&error=' . urlencode("Invalid OTP format. Please enter a 6-digit code."));
    exit;
}

// Find the latest valid OTP for the user
try {
    $stmt = $conn->prepare("SELECT ot.id, ot.user_id, ot.otp_hash, ot.expires_at, ot.attempts, u.usr_email, u.usr_name, u.usr_role, u.usr_account_status 
                           FROM otp_tokens ot 
                           JOIN users u ON ot.user_id = u.usr_id 
                           WHERE u.usr_email = :email AND ot.user_id = :user_id 
                           AND ot.expires_at > NOW() 
                           ORDER BY ot.created_at DESC 
                           LIMIT 1");
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $otp_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("verify_otp.php: OTP query result - rows: " . ($otp_data ? '1' : '0') . ", email: $email, user_id: $user_id, expires_at: " . ($otp_data['expires_at'] ?? 'none'));

    if (!$otp_data) {
        // Check if any OTP record exists for debugging
        $debug_stmt = $conn->prepare("SELECT ot.id, ot.user_id, ot.expires_at, ot.created_at FROM otp_tokens ot WHERE ot.user_id = :user_id");
        $debug_stmt->bindParam(':user_id', $user_id);
        $debug_stmt->execute();
        $debug_otp = $debug_stmt->fetch(PDO::FETCH_ASSOC);
        error_log("verify_otp.php: Debug OTP check - exists: " . ($debug_otp ? 'yes' : 'no') . ", expires_at: " . ($debug_otp['expires_at'] ?? 'none') . ", created_at: " . ($debug_otp['created_at'] ?? 'none'));
        
        header('Location: index.php?page=reset&error=' . urlencode("No valid OTP found. Please request a new OTP."));
        exit;
    }
    
    // Check account status
    if ($otp_data['usr_account_status'] === 'suspended') {
        error_log("verify_otp.php: Account suspended for user_id: {$otp_data['user_id']}");
        header('Location: index.php?page=verify_otp&error=' . urlencode("Your account is suspended. Please contact the administrator."));
        exit;
    } elseif ($otp_data['usr_account_status'] === 'inactive') {
        error_log("verify_otp.php: Account inactive for user_id: {$otp_data['user_id']}");
        header('Location: index.php?page=verify_otp&error=' . urlencode("Your account is inactive. Please contact administrator to reactivate."));
        exit;
    }
    
    // Verify OTP
    if (password_verify($otp, $otp_data['otp_hash'])) {
        // Success: set session for role selection
        $_SESSION['verified_user_id'] = $otp_data['user_id'];
        $_SESSION['verified_email'] = $otp_data['usr_email'];
        
        // Clear OTP record
        $stmt = $conn->prepare("DELETE FROM otp_tokens WHERE id = :id");
        $stmt->bindParam(':id', $otp_data['id']);
        $stmt->execute();
        
        // Clear OTP-related session variables
        unset($_SESSION['otp_email']);
        unset($_SESSION['signup_user_id']);
        
        error_log("verify_otp.php: OTP verified successfully for user_id: {$otp_data['user_id']}");
        header('Location: index.php?page=select_role&success=' . urlencode("OTP verified successfully! Please select your account type."));
        exit;
    } else {
        // Increment attempts
        $new_attempts = $otp_data['attempts'] + 1;
        $stmt = $conn->prepare("UPDATE otp_tokens SET attempts = :attempts WHERE id = :id");
        $stmt->bindParam(':attempts', $new_attempts);
        $stmt->bindParam(':id', $otp_data['id']);
        $stmt->execute();
        
        if ($new_attempts >= 5) {
            $stmt = $conn->prepare("DELETE FROM otp_tokens WHERE id = :id");
            $stmt->bindParam(':id', $otp_data['id']);
            $stmt->execute();
            error_log("verify_otp.php: Too many attempts for user_id: {$otp_data['user_id']}");
            header('Location: index.php?page=reset&error=' . urlencode("Too many attempts. Please request a new OTP."));
            exit;
        }
        
        error_log("verify_otp.php: Incorrect OTP for user_id: {$otp_data['user_id']}, attempts: $new_attempts");
        header('Location: index.php?page=verify_otp&error=' . urlencode("Incorrect OTP. Attempts left: " . (5 - $new_attempts)));
        exit;
    }
} catch (PDOException $e) {
    error_log("verify_otp.php: Database error: " . $e->getMessage());
    header('Location: index.php?page=verify_otp&error=' . urlencode("Database error. Please try again."));
    exit;
}

ob_end_flush();
?>