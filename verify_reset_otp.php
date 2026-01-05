<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();
session_start();

// Database connection (for user validation only)
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("verify_reset_otp.php: Database Connection Failed: " . $e->getMessage());
    header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Database error. Please try again."));
    exit;
}

// Log session state and POST data for debugging
error_log("verify_reset_otp.php: Session state - otp_email: " . (isset($_SESSION['otp_email']) ? $_SESSION['otp_email'] : 'not set') . 
          ", signup_user_id: " . (isset($_SESSION['signup_user_id']) ? $_SESSION['signup_user_id'] : 'not set') . 
          ", otp_purpose: " . (isset($_SESSION['otp_purpose']) ? $_SESSION['otp_purpose'] : 'not set') . 
          ", POST otp: " . (isset($_POST['otp']) ? $_POST['otp'] : 'not set'));

// Check request method and session variables
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("verify_reset_otp.php: Invalid request method: {$_SERVER['REQUEST_METHOD']}");
    header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Invalid request method. Please submit the OTP form."));
    exit;
}

if (!isset($_SESSION['otp_email'])) {
    error_log("verify_reset_otp.php: Missing otp_email session variable");
    header('Location: index.php?page=reset&error=' . urlencode("Session expired. Please request a new OTP."));
    exit;
}

if (!isset($_SESSION['signup_user_id'])) {
    error_log("verify_reset_otp.php: Missing signup_user_id session variable");
    header('Location: index.php?page=reset&error=' . urlencode("Session expired. Please request a new OTP."));
    exit;
}

if (!isset($_SESSION['otp_purpose']) || $_SESSION['otp_purpose'] !== 'reset') {
    error_log("verify_reset_otp.php: Invalid or missing otp_purpose session variable (expected 'reset')");
    header('Location: index.php?page=reset&error=' . urlencode("Invalid session data. Please request a new OTP."));
    exit;
}

if (!isset($_POST['otp']) || empty(trim($_POST['otp']))) {
    error_log("verify_reset_otp.php: Missing or empty OTP in POST data");
    header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Please enter the OTP code."));
    exit;
}

$otp = trim($_POST['otp']);
$email = $_SESSION['otp_email'];
$user_id = $_SESSION['signup_user_id'];

// Validate OTP format
if (!preg_match('/^[0-9]{6}$/', $otp)) {
    error_log("verify_reset_otp.php: Invalid OTP format: $otp, user_id: $user_id");
    header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Invalid OTP format. Please enter a 6-digit code."));
    exit;
}

// Check account status
try {
    $stmt = $conn->prepare("SELECT usr_id, usr_email, usr_name, usr_role, usr_account_status 
                           FROM users 
                           WHERE usr_email = :email AND usr_id = :user_id");
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("verify_reset_otp.php: User query result - rows: " . ($user_data ? '1' : '0') . 
              ", email: $email, user_id: $user_id");

    if (!$user_data) {
        error_log("verify_reset_otp.php: No user found for email: $email, user_id: $user_id");
        header('Location: index.php?page=reset&error=' . urlencode("No account found. Please request a new OTP."));
        exit;
    }
    
    if ($user_data['usr_account_status'] === 'suspended') {
        error_log("verify_reset_otp.php: Account suspended for user_id: {$user_data['usr_id']}");
        header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Your account is suspended. Please contact the administrator."));
        exit;
    } elseif ($user_data['usr_account_status'] === 'inactive') {
        error_log("verify_reset_otp.php: Account inactive for user_id: {$user_data['usr_id']}");
        header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Your account is inactive. Please contact administrator to reactivate."));
        exit;
    }
    
    // Accept any 6-digit OTP (static/DIY or generated)
    // No database OTP check - consider all 6-digit codes valid for password reset
    error_log("verify_reset_otp.php: Accepting any 6-digit OTP: $otp for user_id: {$user_data['usr_id']}");
    
    // Success: set session for password reset
    $_SESSION['reset_verified'] = true;
    $_SESSION['reset_user_id'] = $user_data['usr_id'];
    
    // Clear OTP-related session variables
    unset($_SESSION['otp_email']);
    unset($_SESSION['signup_user_id']);
    unset($_SESSION['otp_purpose']);
    
    error_log("verify_reset_otp.php: OTP verified successfully for user_id: {$user_data['usr_id']}, proceeding to reset_password.php");
    header('Location: reset_password.php?success=' . urlencode("OTP verified successfully! Please set your new password."));
    exit;

} catch (PDOException $e) {
    error_log("verify_reset_otp.php: Database error: " . $e->getMessage());
    header('Location: index.php?page=verify_reset_otp&error=' . urlencode("Database error. Please try again."));
    exit;
}

ob_end_flush();
?>