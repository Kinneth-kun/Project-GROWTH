<?php
session_start();
require 'vendor/autoload.php'; // Assuming PHPMailer is installed via Composer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("send_otp.php: Database Connection Failed: " . $e->getMessage());
    header('Location: index.php?page=reset&error=' . urlencode("Database error. Please try again."));
    exit;
}

// Check session variables
if (!isset($_SESSION['otp_email']) || !isset($_SESSION['signup_user_id']) || !isset($_SESSION['otp_purpose'])) {
    error_log("send_otp.php: Missing session variables");
    header('Location: index.php?page=reset&error=' . urlencode("Session expired. Please request a new OTP."));
    exit;
}

$email = $_SESSION['otp_email'];
$user_id = $_SESSION['signup_user_id'];
$purpose = $_SESSION['otp_purpose'];

// Generate OTP
$otp = sprintf("%06d", rand(100000, 999999));
$otp_hash = password_hash($otp, PASSWORD_BCRYPT);
$expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

try {
    // Store OTP in otp_tokens
    $stmt = $conn->prepare("INSERT INTO otp_tokens (user_id, otp_hash, expires_at, created_at, purpose) 
                           VALUES (:user_id, :otp_hash, :expires_at, NOW(), :purpose)");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':otp_hash', $otp_hash);
    $stmt->bindParam(':expires_at', $expires_at);
    $stmt->bindParam(':purpose', $purpose);
    $stmt->execute();
    
    // Send OTP via email
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'luckyariusdesabille36@gmail.com'; // Replace with your Gmail
    $mail->Password = 'dxsp fwev zrwl lpto'; // Replace with your Gmail App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    
    $mail->setFrom('luckyariusdesabille36@gmail.com', 'G.R.O.W.T.H.');
    $mail->addAddress($email);
    $mail->Subject = 'Your OTP Code';
    $mail->Body = "Your OTP code is: $otp\nIt is valid for 15 minutes.";
    
    $mail->send();
    error_log("send_otp.php: OTP sent to $email for user_id: $user_id, purpose: $purpose");
    
    // Redirect based on purpose
    $redirect_page = $purpose === 'reset' ? 'verify_reset_otp' : 'verify_otp';
    header("Location: index.php?page=$redirect_page&success=" . urlencode("OTP sent to your email."));
    exit;
} catch (Exception $e) {
    error_log("send_otp.php: Error sending OTP: " . $e->getMessage());
    header('Location: index.php?page=reset&error=' . urlencode("Failed to send OTP. Please try again."));
    exit;
}
?>