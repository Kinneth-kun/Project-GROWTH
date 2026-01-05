<?php
session_start();
ob_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("staff_form.php: Database Connection Failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Check if auto-approve is enabled in system settings
$auto_approve_users = 0;
try {
    $settings_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_users'");
    if ($settings_stmt) {
        $settings_stmt->execute();
        $auto_approve_setting = $settings_stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($auto_approve_setting !== false) {
            $auto_approve_users = intval($auto_approve_setting);
        }
    }
} catch (PDOException $e) {
    // If table doesn't exist yet or setting not found, use default value (0)
    $auto_approve_users = 0;
}

// Check if user is coming from role selection (has verified_user_id or user_id session)
if (!isset($_SESSION['verified_user_id']) && !isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login&error=' . urlencode("Please complete the registration process first."));
    exit;
}

// Determine user ID based on session
if (isset($_SESSION['verified_user_id'])) {
    $user_id = $_SESSION['verified_user_id'];
} else {
    $user_id = $_SESSION['user_id'];
}

// Get user data for display
try {
    $user_stmt = $conn->prepare("SELECT usr_name, usr_email, usr_phone FROM users WHERE usr_id = :user_id");
    $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $user_stmt->execute();
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        header('Location: index.php?page=login&error=' . urlencode("User not found."));
        exit;
    }
} catch (PDOException $e) {
    error_log("staff_form.php: Error fetching user data - " . $e->getMessage());
    $user_data = ['usr_name' => 'User', 'usr_email' => '', 'usr_phone' => ''];
}

$error = '';
$success = '';

// Check if staff profile already exists
try {
    $stmt = $conn->prepare("SELECT * FROM staff WHERE staff_usr_id = :user_id");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        // If profile exists, redirect to dashboard
        if (isset($_SESSION['verified_user_id'])) {
            // Only auto-approve if the setting is enabled
            if ($auto_approve_users) {
                $update_stmt = $conn->prepare("
                    UPDATE users 
                    SET usr_is_approved = TRUE, 
                        usr_account_status = 'active',
                        usr_updated_at = NOW()
                    WHERE usr_id = :user_id
                ");
            } else {
                // Keep account as pending approval
                $update_stmt = $conn->prepare("
                    UPDATE users 
                    SET usr_account_status = 'pending',
                        usr_updated_at = NOW()
                    WHERE usr_id = :user_id
                ");
            }
            $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $update_stmt->execute();
            
            // Set session variables for logged in user
            $_SESSION['user_id'] = $user_id;
            $_SESSION['logged_in'] = true;
            $_SESSION['is_approved'] = $auto_approve_users; // Set based on auto-approve setting
            $_SESSION['account_status'] = $auto_approve_users ? 'active' : 'pending';
            
            // Get user name and role
            $user_stmt = $conn->prepare("SELECT usr_name, usr_role FROM users WHERE usr_id = :user_id");
            $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $user_stmt->execute();
            $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user_data) {
                $_SESSION['user_name'] = $user_data['usr_name'];
                $_SESSION['user_role'] = $user_data['usr_role'];
            }
            
            unset($_SESSION['verified_user_id']);
        }
        $redirect_message = $auto_approve_users ? 
            "Staff profile already completed." : 
            "Staff profile completed. Waiting for admin approval.";
        header('Location: index.php?page=dashboard&message=' . urlencode($redirect_message));
        exit;
    }
} catch (PDOException $e) {
    error_log("staff_form.php: Database error checking existing profile - " . $e->getMessage());
    $error = "Database error. Please try again.";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = trim($_POST['last_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $country_code = trim($_POST['country_code'] ?? '+63');
    $phone = trim($_POST['phone'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $employee_id = trim($_POST['employee_id'] ?? '');
    
    // Address fields
    $region = trim($_POST['region'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');
    $street_address = trim($_POST['street_address'] ?? '');
    
    // Combine names into full name
    $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : "") . "$last_name");
    
    // Enhanced phone number validation
    $phone = preg_replace('/[^0-9]/', '', $phone); // Remove any non-numeric characters
    
    // Validate phone number length - CHANGED TO 11 DIGITS
    if (!empty($phone) && (strlen($phone) < 11 || strlen($phone) > 11)) {
        $error = "Phone number must be exactly 11 digits.";
    }
    
    // Combine country code and phone number
    $full_phone = $country_code . $phone;
    
    // Combine address components
    $permanent_address = "";
    if (!empty($street_address)) {
        $permanent_address .= $street_address . ", ";
    }
    if (!empty($barangay)) {
        $permanent_address .= $barangay . ", ";
    }
    if (!empty($city)) {
        $permanent_address .= $city . ", ";
    }
    if (!empty($province)) {
        $permanent_address .= $province . ", ";
    }
    if (!empty($region)) {
        $permanent_address .= $region;
    }
    if (!empty($zip_code)) {
        $permanent_address .= " " . $zip_code;
    }
    
    // Handle staff ID photo uploads (front and back)
    $staff_id_photo_front = null;
    $staff_id_photo_back = null;
    $upload_error = '';
    
    // Process front photo upload
    if (isset($_FILES['staff_id_photo_front']) && $_FILES['staff_id_photo_front']['error'] !== UPLOAD_ERR_NO_FILE) {
        $photo = $_FILES['staff_id_photo_front'];
        
        // Check for upload errors
        if ($photo['error'] !== UPLOAD_ERR_OK) {
            switch ($photo['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $upload_error = "File size too large. Maximum size is 2MB.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $upload_error = "File upload was incomplete.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $upload_error = "No file was uploaded.";
                    break;
                default:
                    $upload_error = "File upload error occurred.";
            }
        } else {
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $file_type = mime_content_type($photo['tmp_name']);
            
            if (!in_array($file_type, $allowed_types)) {
                $upload_error = "Only JPG, JPEG, PNG, and GIF files are allowed.";
            }
            
            // Validate file size (2MB max)
            $max_size = 2 * 1024 * 1024; // 2MB in bytes
            if ($photo['size'] > $max_size) {
                $upload_error = "File size must be less than 2MB.";
            }
            
            // If no errors, process the upload
            if (empty($upload_error)) {
                $upload_dir = 'uploads/staff_photos/';
                
                // Create upload directory if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename
                $file_extension = pathinfo($photo['name'], PATHINFO_EXTENSION);
                $filename = 'staff_front_' . $user_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($photo['tmp_name'], $upload_path)) {
                    $staff_id_photo_front = $upload_path;
                } else {
                    $upload_error = "Failed to upload front photo. Please try again.";
                }
            }
        }
    } else {
        $upload_error = "Staff ID front photo is required.";
    }
    
    // Process back photo upload if front was successful
    if (empty($upload_error)) {
        if (isset($_FILES['staff_id_photo_back']) && $_FILES['staff_id_photo_back']['error'] !== UPLOAD_ERR_NO_FILE) {
            $photo = $_FILES['staff_id_photo_back'];
            
            // Check for upload errors
            if ($photo['error'] !== UPLOAD_ERR_OK) {
                switch ($photo['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $upload_error = "File size too large. Maximum size is 2MB.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $upload_error = "File upload was incomplete.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $upload_error = "No file was uploaded.";
                        break;
                    default:
                        $upload_error = "File upload error occurred.";
                }
            } else {
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $file_type = mime_content_type($photo['tmp_name']);
                
                if (!in_array($file_type, $allowed_types)) {
                    $upload_error = "Only JPG, JPEG, PNG, and GIF files are allowed.";
                }
                
                // Validate file size (2MB max)
                $max_size = 2 * 1024 * 1024; // 2MB in bytes
                if ($photo['size'] > $max_size) {
                    $upload_error = "File size must be less than 2MB.";
                }
                
                // If no errors, process the upload
                if (empty($upload_error)) {
                    $upload_dir = 'uploads/staff_photos/';
                    
                    // Create upload directory if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $file_extension = pathinfo($photo['name'], PATHINFO_EXTENSION);
                    $filename = 'staff_back_' . $user_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($photo['tmp_name'], $upload_path)) {
                        $staff_id_photo_back = $upload_path;
                    } else {
                        $upload_error = "Failed to upload back photo. Please try again.";
                    }
                }
            }
        } else {
            $upload_error = "Staff ID back photo is required.";
        }
    }
    
    // Validate inputs
    if (empty($last_name) || empty($first_name) || empty($email) || empty($department) || empty($position) || empty($employee_id) || 
        empty($region) || empty($province) || empty($city) || empty($barangay)) {
        $error = "All required fields must be filled.";
    } elseif (!empty($upload_error)) {
        $error = $upload_error;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!empty($phone) && !preg_match('/^[0-9]{11}$/', $phone)) {
        $error = "Please enter a valid phone number (exactly 11 digits).";
    } else {
        try {
            // Check if employee ID already exists
            $check_stmt = $conn->prepare("SELECT staff_id FROM staff WHERE staff_employee_id = :employee_id");
            $check_stmt->bindParam(':employee_id', $employee_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error = "This employee ID is already registered.";
            } else {
                // Check if phone number is already in use by another user
                $phone_check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_phone = :phone AND usr_id != :user_id");
                $phone_check_stmt->bindParam(':phone', $full_phone);
                $phone_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $phone_check_stmt->execute();
                
                if ($phone_check_stmt->rowCount() > 0) {
                    $error = "This phone number is already registered with another account. Please use a different phone number.";
                } else {
                    // Check if email is already in use by another user
                    $email_check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_email = :email AND usr_id != :user_id");
                    $email_check_stmt->bindParam(':email', $email);
                    $email_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                    $email_check_stmt->execute();
                    
                    if ($email_check_stmt->rowCount() > 0) {
                        $error = "This email address is already registered with another account. Please use a different email address.";
                    } else {
                        // Update user information first with address
                        $update_user_stmt = $conn->prepare("
                            UPDATE users 
                            SET usr_name = :name, 
                                usr_email = :email, 
                                usr_phone = :phone,
                                usr_gender = :gender,
                                usr_birthdate = :birthdate,
                                usr_address = :address,
                                usr_updated_at = NOW()
                            WHERE usr_id = :user_id
                        ");
                        
                        $update_user_stmt->bindParam(':name', $full_name);
                        $update_user_stmt->bindParam(':email', $email);
                        $update_user_stmt->bindParam(':phone', $full_phone);
                        $update_user_stmt->bindParam(':gender', $gender);
                        $update_user_stmt->bindParam(':birthdate', $birthdate);
                        $update_user_stmt->bindParam(':address', $permanent_address);
                        $update_user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                        $update_user_stmt->execute();
                        
                        // Insert staff profile with ID photos
                        $insert_stmt = $conn->prepare("
                            INSERT INTO staff (staff_usr_id, staff_department, staff_position, staff_employee_id, staff_id_photo) 
                            VALUES (:user_id, :department, :position, :employee_id, :staff_id_photo)
                        ");
                        $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                        $insert_stmt->bindParam(':department', $department);
                        $insert_stmt->bindParam(':position', $position);
                        $insert_stmt->bindParam(':employee_id', $employee_id);
                        
                        // Store both photo paths as JSON in the database
                        $staff_photos = json_encode([
                            'front' => $staff_id_photo_front,
                            'back' => $staff_id_photo_back
                        ]);
                        $insert_stmt->bindParam(':staff_id_photo', $staff_photos);
                        $insert_stmt->execute();
                        
                        // Update user status based on auto-approve setting
                        if ($auto_approve_users) {
                            $update_status_stmt = $conn->prepare("
                                UPDATE users 
                                SET usr_is_approved = TRUE, 
                                    usr_account_status = 'active',
                                    usr_updated_at = NOW()
                                WHERE usr_id = :user_id
                            ");
                        } else {
                            $update_status_stmt = $conn->prepare("
                                UPDATE users 
                                SET usr_account_status = 'pending',
                                    usr_updated_at = NOW()
                                WHERE usr_id = :user_id
                            ");
                        }
                        $update_status_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                        $update_status_stmt->execute();
                        
                        // Set session variables for logged in user
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['logged_in'] = true;
                        $_SESSION['is_approved'] = $auto_approve_users; // Set based on auto-approve setting
                        $_SESSION['account_status'] = $auto_approve_users ? 'active' : 'pending';
                        $_SESSION['user_name'] = $full_name;
                        $_SESSION['user_role'] = 'staff';
                        
                        // Clear verification session if coming from registration
                        if (isset($_SESSION['verified_user_id'])) {
                            unset($_SESSION['verified_user_id']);
                        }
                        
                        if ($auto_approve_users) {
                            $success = "Staff profile completed successfully! Your account has been approved.";
                            // Redirect to dashboard after 2 seconds
                            header('Refresh: 2; URL=index.php?page=dashboard&success=' . urlencode($success));
                        } else {
                            $success = "Staff profile completed successfully! Your account is pending admin approval.";
                            // Redirect to pending page after 2 seconds
                            header('Refresh: 2; URL=index.php?page=pending_approval&message=' . urlencode($success));
                        }
                        $success .= " Redirecting...";
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("staff_form.php: Database error - " . $e->getMessage());
            $error = "Database error. Please try again.";
        }
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Complete Staff Profile</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root {
      --primary-color: #6e0303;
      --secondary-color: #f7a100;
      --accent-color: #ffa700;
      --text-color: #333;
      --light-text: #f8f9fa;
      --card-bg: #fff;
      --green: #1f7a11;
      --red: #d32f2f;
      --blue: #0044ff;
      --purple: #6a0dad;
    }
    
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    body {
      background-color: #f5f6f8;
      min-height: 100vh;
      color: var(--text-color);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    
    .container {
      width: 100%;
      max-width: 1200px;
      background: white;
      border-radius: 10px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      overflow: hidden;
    }
    
    .header {
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      color: white;
      padding: 30px;
      text-align: center;
    }
    
    .header h1 {
      font-size: 2.2rem;
      margin-bottom: 10px;
    }
    
    .header p {
      font-size: 1.1rem;
      opacity: 0.9;
    }
    
    .progress-container {
      background: #f8f9fa;
      padding: 15px 30px;
      border-bottom: 1px solid #e9ecef;
    }
    
    .progress-bar {
      height: 8px;
      background: #e0e0e0;
      border-radius: 4px;
      overflow: hidden;
      margin: 10px 0;
    }
    
    .progress {
      height: 100%;
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      border-radius: 4px;
      width: 66%;
      transition: width 0.5s ease;
    }
    
    .progress-text {
      display: flex;
      justify-content: space-between;
      font-size: 0.9rem;
      color: #666;
    }
    
    .content {
      padding: 30px;
    }
    
    .welcome-message {
      background: linear-gradient(to right, #e3f2fd, #bbdefb);
      padding: 20px;
      border-radius: 8px;
      margin-bottom: 25px;
      border-left: 4px solid var(--blue);
    }
    
    .welcome-message h2 {
      color: var(--primary-color);
      margin-bottom: 10px;
    }
    
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
    }
    
    .form-section {
      background: white;
      border-radius: 8px;
      padding: 25px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .personal-info {
      border-top: 4px solid var(--blue);
    }
    
    .staff-info {
      border-top: 4px solid var(--green);
    }
    
    .form-header {
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 1px solid #eee;
    }
    
    .form-title {
      font-size: 1.4rem;
      color: var(--primary-color);
      font-weight: 600;
    }
    
    .form-subtitle {
      color: #666;
      margin-top: 5px;
      font-size: 0.95rem;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--primary-color);
    }
    
    .form-input, .form-select, .form-file {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 5px;
      font-size: 1rem;
      transition: border-color 0.3s;
    }
    
    .form-input:focus, .form-select:focus, .form-file:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
    }
    
    .form-text {
      font-size: 0.85rem;
      color: #666;
      margin-top: 5px;
    }
    
    .required {
      color: var(--red);
    }
    
    .name-container {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 15px;
    }
    
    .address-container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
    }
    
    .btn {
      padding: 12px 25px;
      border: none;
      border-radius: 5px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    
    .btn-primary {
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      color: white;
    }
    
    .btn-primary:hover {
      background: linear-gradient(to right, #8a0404, #6e0303);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(110, 3, 3, 0.2);
    }
    
    .btn-outline {
      background: transparent;
      border: 1px solid #ddd;
      color: #666;
    }
    
    .btn-outline:hover {
      background: #f8f9fa;
      border-color: #ccc;
    }
    
    .alert {
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    
    .alert i {
      font-size: 1.2rem;
    }
    
    .action-buttons {
      display: flex;
      gap: 15px;
      margin-top: 30px;
      justify-content: center;
    }
    
    .phone-container {
      display: flex;
      gap: 10px;
    }
    
    .country-code {
      width: 100px;
      flex-shrink: 0;
    }
    
    .phone-number {
      flex: 1;
    }
    
    /* Enhanced File Upload Styles */
    .photo-upload-section {
      margin-top: 20px;
    }
    
    .photo-upload-title {
      font-size: 1.2rem;
      color: var(--primary-color);
      margin-bottom: 15px;
      padding-bottom: 10px;
      border-bottom: 1px solid #eee;
    }
    
    .photo-upload-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }
    
    .file-upload-card {
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 20px;
      background: #fafafa;
      transition: all 0.3s ease;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 300px;
      position: relative;
    }
    
    .file-upload-card:hover {
      border-color: var(--primary-color);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .file-upload-card.dragover {
      border-color: var(--primary-color);
      background: #f0f8ff;
    }
    
    .file-upload-header {
      display: flex;
      align-items: center;
      margin-bottom: 15px;
    }
    
    .file-upload-icon {
      font-size: 1.8rem;
      color: var(--primary-color);
      margin-right: 12px;
    }
    
    .file-upload-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--primary-color);
    }
    
    .file-upload-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      transition: all 0.3s ease;
    }
    
    .file-upload-body.has-preview {
      justify-content: flex-start;
    }
    
    .file-upload-text {
      margin-bottom: 15px;
      color: #666;
      font-size: 0.9rem;
      max-width: 90%;
    }
    
    .file-upload-btn {
      background: var(--primary-color);
      color: white;
      padding: 10px 16px;
      border-radius: 5px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background 0.3s;
      font-weight: 500;
      margin-bottom: 15px;
    }
    
    .file-upload-btn:hover {
      background: #8a0404;
    }
    
    .file-requirements {
      font-size: 0.8rem;
      color: #888;
      margin-top: 5px;
    }
    
    .file-preview-container {
      width: 100%;
      display: none;
      flex-direction: column;
      align-items: center;
    }
    
    .file-preview {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
    }
    
    .file-preview img {
      max-width: 100%;
      max-height: 200px;
      border-radius: 4px;
      border: 1px solid #ddd;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      margin-bottom: 10px;
    }
    
    .file-info {
      font-size: 0.85rem;
      color: #666;
      text-align: center;
      width: 100%;
      margin-bottom: 10px;
    }
    
    .file-info-item {
      margin-bottom: 3px;
    }
    
    .file-status {
      padding: 5px 10px;
      border-radius: 4px;
      font-size: 0.8rem;
      font-weight: 500;
      margin-top: 10px;
    }
    
    .file-status.success {
      background-color: #e8f5e9;
      color: #2e7d32;
    }
    
    .file-status.error {
      background-color: #ffebee;
      color: #c62828;
    }
    
    .file-input {
      display: none;
    }
    
    .preview-actions {
      display: flex;
      gap: 10px;
      margin-top: 10px;
    }
    
    .preview-action-btn {
      padding: 6px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: white;
      color: #666;
      cursor: pointer;
      font-size: 0.8rem;
      transition: all 0.3s;
    }
    
    .preview-action-btn:hover {
      background: #f5f5f5;
      border-color: #ccc;
    }
    
    .preview-action-btn.primary {
      background: var(--primary-color);
      color: white;
      border-color: var(--primary-color);
    }
    
    .preview-action-btn.primary:hover {
      background: #8a0404;
    }
    
    /* Responsive adjustments */
    @media (max-width: 968px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .name-container {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .address-container {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .photo-upload-grid {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 768px) {
      .container {
        margin: 10px;
        width: calc(100% - 20px);
      }
      
      .header {
        padding: 20px;
      }
      
      .header h1 {
        font-size: 1.8rem;
      }
      
      .content {
        padding: 20px;
      }
      
      .action-buttons {
        flex-direction: column;
      }
      
      .btn {
        width: 100%;
      }
      
      .phone-container {
        flex-direction: column;
        gap: 8px;
      }
      
      .country-code {
        width: 100%;
      }
      
      .file-upload-card {
        min-height: 250px;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>Complete Your Staff Profile</h1>
      <p>Welcome to CTU-PESO G.R.O.W.T.H. Platform</p>
    </div>
    
    <div class="progress-container">
      <div class="progress-text">
        <span>Registration Progress</span>
        <span>Step 2 of 2</span>
      </div>
      <div class="progress-bar">
        <div class="progress"></div>
      </div>
    </div>
    
    <div class="content">
      <div class="welcome-message">
        <h2>Welcome!</h2>
        <p>Please complete your personal and staff information to finalize your registration.</p>
        <?php if (!$auto_approve_users): ?>
          <p style="color: var(--red); font-weight: 500; margin-top: 10px;">
            <i class="fas fa-info-circle"></i> Your account will require admin approval after registration.
          </p>
        <?php endif; ?>
      </div>
      
      <?php if (!empty($success)): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i>
          <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>
      
      <?php if (!empty($error)): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>
      
      <form method="POST" action="" enctype="multipart/form-data">
        <div class="form-grid">
          <!-- Personal Information Section -->
          <div class="form-section personal-info">
            <div class="form-header">
              <h3 class="form-title">Personal Information</h3>
              <p class="form-subtitle">Your basic personal details</p>
            </div>
            
            <div class="form-group">
              <label class="form-label">Full Name <span class="required">*</span></label>
              <div class="name-container">
                <div>
                  <label class="form-label" for="last_name">Last Name</label>
                  <input type="text" id="last_name" name="last_name" class="form-input" 
                         value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" 
                         required placeholder="Last Name">
                </div>
                <div>
                  <label class="form-label" for="first_name">First Name</label>
                  <input type="text" id="first_name" name="first_name" class="form-input" 
                         value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" 
                         required placeholder="First Name">
                </div>
                <div>
                  <label class="form-label" for="middle_name">Middle Name</label>
                  <input type="text" id="middle_name" name="middle_name" class="form-input" 
                         value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>" 
                         placeholder="Middle Name">
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="email">Email Address <span class="required">*</span></label>
              <input type="email" id="email" name="email" class="form-input" 
                     value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : htmlspecialchars($user_data['usr_email']); ?>" 
                     required placeholder="Enter your email address">
            </div>
            
            <div class="form-group">
              <label class="form-label" for="phone">Phone Number</label>
              <div class="phone-container">
                <select id="country_code" name="country_code" class="form-select country-code">
                  <option value="+63" selected>+63 (PH)</option>
                  <option value="+1">+1 (US)</option>
                  <option value="+44">+44 (UK)</option>
                  <option value="+61">+61 (AU)</option>
                  <option value="+65">+65 (SG)</option>
                  <option value="+60">+60 (MY)</option>
                  <option value="+66">+66 (TH)</option>
                  <option value="+84">+84 (VN)</option>
                  <option value="+81">+81 (JP)</option>
                  <option value="+82">+82 (KR)</option>
                  <option value="+86">+86 (CN)</option>
                  <option value="+91">+91 (IN)</option>
                  <option value="+971">+971 (AE)</option>
                  <option value="+973">+973 (BH)</option>
                  <option value="+966">+966 (SA)</option>
                  <option value="+20">+20 (EG)</option>
                  <option value="+27">+27 (ZA)</option>
                </select>
                <input type="tel" id="phone" name="phone" class="form-input phone-number" 
                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" 
                       placeholder="91234567890" maxlength="11" pattern="[0-9]{11}" 
                       title="Please enter exactly 11 digits (numbers only)">
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="gender">Gender</label>
              <select id="gender" name="gender" class="form-select">
                <option value="">Select Gender</option>
                <option value="Male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="birthdate">Birthdate</label>
              <input type="date" id="birthdate" name="birthdate" class="form-input" 
                     value="<?php echo isset($_POST['birthdate']) ? htmlspecialchars($_POST['birthdate']) : ''; ?>">
            </div>
            
            <div class="form-group">
              <label class="form-label">Address Information <span class="required">*</span></label>
              <div class="address-container">
                <div>
                  <label class="form-label" for="region">Region <span class="required">*</span></label>
                  <select id="region" name="region" class="form-select" required>
                    <option value="">Select Region</option>
                    <option value="NCR" <?php echo (isset($_POST['region']) && $_POST['region'] === 'NCR') ? 'selected' : ''; ?>>National Capital Region (NCR)</option>
                    <option value="CAR" <?php echo (isset($_POST['region']) && $_POST['region'] === 'CAR') ? 'selected' : ''; ?>>Cordillera Administrative Region (CAR)</option>
                    <option value="Region I" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region I') ? 'selected' : ''; ?>>Region I - Ilocos Region</option>
                    <option value="Region II" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region II') ? 'selected' : ''; ?>>Region II - Cagayan Valley</option>
                    <option value="Region III" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region III') ? 'selected' : ''; ?>>Region III - Central Luzon</option>
                    <option value="Region IV-A" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region IV-A') ? 'selected' : ''; ?>>Region IV-A - CALABARZON</option>
                    <option value="Region IV-B" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region IV-B') ? 'selected' : ''; ?>>Region IV-B - MIMAROPA</option>
                    <option value="Region V" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region V') ? 'selected' : ''; ?>>Region V - Bicol Region</option>
                    <option value="Region VI" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region VI') ? 'selected' : ''; ?>>Region VI - Western Visayas</option>
                    <option value="Region VII" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region VII') ? 'selected' : ''; ?>>Region VII - Central Visayas</option>
                    <option value="Region VIII" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region VIII') ? 'selected' : ''; ?>>Region VIII - Eastern Visayas</option>
                    <option value="Region IX" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region IX') ? 'selected' : ''; ?>>Region IX - Zamboanga Peninsula</option>
                    <option value="Region X" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region X') ? 'selected' : ''; ?>>Region X - Northern Mindanao</option>
                    <option value="Region XI" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region XI') ? 'selected' : ''; ?>>Region XI - Davao Region</option>
                    <option value="Region XII" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region XII') ? 'selected' : ''; ?>>Region XII - SOCCSKSARGEN</option>
                    <option value="Region XIII" <?php echo (isset($_POST['region']) && $_POST['region'] === 'Region XIII') ? 'selected' : ''; ?>>Region XIII - Caraga</option>
                    <option value="BARMM" <?php echo (isset($_POST['region']) && $_POST['region'] === 'BARMM') ? 'selected' : ''; ?>>Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)</option>
                  </select>
                </div>
                <div>
                  <label class="form-label" for="province">Province <span class="required">*</span></label>
                  <select id="province" name="province" class="form-select" required>
                    <option value="">Select Province</option>
                  </select>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <div class="address-container">
                <div>
                  <label class="form-label" for="city">City/Municipality <span class="required">*</span></label>
                  <select id="city" name="city" class="form-select" required>
                    <option value="">Select City/Municipality</option>
                  </select>
                </div>
                <div>
                  <label class="form-label" for="barangay">Barangay <span class="required">*</span></label>
                  <select id="barangay" name="barangay" class="form-select" required>
                    <option value="">Select Barangay</option>
                  </select>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <div class="address-container">
                <div>
                  <label class="form-label" for="zip_code">Zip Code</label>
                  <input type="text" id="zip_code" name="zip_code" class="form-input" 
                         value="<?php echo isset($_POST['zip_code']) ? htmlspecialchars($_POST['zip_code']) : ''; ?>" 
                         placeholder="Zip Code">
                </div>
                <div>
                  <label class="form-label" for="street_address">Street Address</label>
                  <input type="text" id="street_address" name="street_address" class="form-input" 
                         value="<?php echo isset($_POST['street_address']) ? htmlspecialchars($_POST['street_address']) : ''; ?>" 
                         placeholder="House No., Street, Subdivision">
                </div>
              </div>
            </div>
          </div>
          
          <!-- Staff Information Section -->
          <div class="form-section staff-info">
            <div class="form-header">
              <h3 class="form-title">Staff Information</h3>
              <p class="form-subtitle">Your official staff details</p>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="department">Department <span class="required">*</span></label>
              <select id="department" name="department" class="form-select" required>
                <option value="">Select Department</option>
                <option value="Employment Services" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Employment Services') ? 'selected' : ''; ?>>Employment Services</option>
              </select>
              <p class="form-text">Select your primary department within PESO</p>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="position">Position <span class="required">*</span></label>
              <select id="position" name="position" class="form-select" required>
                <option value="">Select Position</option>
                <option value="Staff" <?php echo (isset($_POST['position']) && $_POST['position'] === 'Staff') ? 'selected' : ''; ?>>Staff</option>
              </select>
              <p class="form-text">Select your position within the PESO organization</p>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="employee_id">Employee ID <span class="required">*</span></label>
              <input type="text" id="employee_id" name="employee_id" class="form-input" 
                     value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>" 
                     required pattern="[A-Za-z0-9\-]+" 
                     title="Alphanumeric characters and hyphens only"
                     placeholder="Enter your employee ID number">
            </div>

            <!-- Enhanced Staff ID Photo Upload Section -->
            <div class="photo-upload-section">
              <h3 class="photo-upload-title">Staff ID Verification <span class="required">*</span></h3>
              <p class="form-text" style="margin-bottom: 15px;">Please upload clear photos of both sides of your Staff ID for verification purposes.</p>
              
              <div class="photo-upload-grid">
                <!-- Front Photo Upload -->
                <div class="file-upload-card" id="fileUploadCardFront">
                  <div class="file-upload-header">
                    <div class="file-upload-icon">
                      <i class="fas fa-id-card"></i>
                    </div>
                    <div class="file-upload-title">Front of Staff ID</div>
                  </div>
                  <div class="file-upload-body" id="uploadBodyFront">
                    <div class="file-upload-text">
                      Upload a clear photo of the front side of your Staff ID showing your photo and details.
                    </div>
                    <label for="staff_id_photo_front" class="file-upload-btn">
                      <i class="fas fa-upload"></i> Choose File
                    </label>
                    <div class="file-requirements">
                      <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: 2MB
                    </div>
                  </div>
                  <input type="file" id="staff_id_photo_front" name="staff_id_photo_front" class="file-input" 
                         accept="image/jpeg,image/jpg,image/png,image/gif" required>
                </div>
                
                <!-- Back Photo Upload -->
                <div class="file-upload-card" id="fileUploadCardBack">
                  <div class="file-upload-header">
                    <div class="file-upload-icon">
                      <i class="fas fa-id-card-alt"></i>
                    </div>
                    <div class="file-upload-title">Back of Staff ID</div>
                  </div>
                  <div class="file-upload-body" id="uploadBodyBack">
                    <div class="file-upload-text">
                      Upload a clear photo of the back side of your Staff ID showing additional details.
                    </div>
                    <label for="staff_id_photo_back" class="file-upload-btn">
                      <i class="fas fa-upload"></i> Choose File
                    </label>
                    <div class="file-requirements">
                      <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: 2MB
                    </div>
                  </div>
                  <input type="file" id="staff_id_photo_back" name="staff_id_photo_back" class="file-input" 
                         accept="image/jpeg,image/jpg,image/png,image/gif" required>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="action-buttons">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-check-circle"></i> Complete Registration
          </button>
          <a href="index.php?page=select_role" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back to Role Selection
          </a>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Comprehensive Philippine Address Data
    const regions = {
      "NCR": ["Metro Manila"],
      "CAR": ["Abra", "Apayao", "Benguet", "Ifugao", "Kalinga", "Mountain Province"],
      "Region I": ["Ilocos Norte", "Ilocos Sur", "La Union", "Pangasinan"],
      "Region II": ["Batanes", "Cagayan", "Isabela", "Nueva Vizcaya", "Quirino"],
      "Region III": ["Aurora", "Bataan", "Bulacan", "Nueva Ecija", "Pampanga", "Tarlac", "Zambales"],
      "Region IV-A": ["Batangas", "Cavite", "Laguna", "Quezon", "Rizal"],
      "Region IV-B": ["Marinduque", "Occidental Mindoro", "Oriental Mindoro", "Palawan", "Romblon"],
      "Region V": ["Albay", "Camarines Norte", "Camarines Sur", "Catanduanes", "Masbate", "Sorsogon"],
      "Region VI": ["Aklan", "Antique", "Capiz", "Guimaras", "Iloilo", "Negros Occidental"],
      "Region VII": ["Bohol", "Cebu", "Negros Oriental", "Siquijor"],
      "Region VIII": ["Biliran", "Eastern Samar", "Leyte", "Northern Samar", "Samar", "Southern Leyte"],
      "Region IX": ["Zamboanga del Norte", "Zamboanga del Sur", "Zamboanga Sibugay"],
      "Region X": ["Bukidnon", "Camiguin", "Lanao del Norte", "Misamis Occidental", "Misamis Oriental"],
      "Region XI": ["Davao de Oro", "Davao del Norte", "Davao del Sur", "Davao Occidental", "Davao Oriental"],
      "Region XII": ["Cotabato", "Sarangani", "South Cotabato", "Sultan Kudarat"],
      "Region XIII": ["Agusan del Norte", "Agusan del Sur", "Dinagat Islands", "Surigao del Norte", "Surigao del Sur"],
      "BARMM": ["Basilan", "Lanao del Sur", "Maguindanao", "Sulu", "Tawi-Tawi"]
    };

    const provinces = {
      "Metro Manila": ["Manila", "Quezon City", "Caloocan", "Las Piñas", "Makati", "Malabon", "Mandaluyong", "Marikina", "Muntinlupa", "Navotas", "Parañaque", "Pasay", "Pasig", "Pateros", "San Juan", "Taguig", "Valenzuela"],
      "Pampanga": ["Angeles City", "San Fernando", "Mabalacat", "Arayat", "Santa Ana", "Magalang", "Mexico", "Floridablanca", "Lubao", "Guagua", "Apalit", "Candaba", "Macabebe", "Masantol", "San Luis", "San Simon", "Santo Tomas", "Sasmuan"],
      "Cebu": ["Cebu City", "Mandaue City", "Lapu-Lapu City", "Talisay City", "Toledo City", "Danao City", "Naga City", "Carcar City", "Bogo City", "Cordova", "Compostela", "Liloan", "Minglanilla", "San Fernando", "San Remigio"],
      "Bohol": ["Tagbilaran City", "Carmen", "Dauis", "Panglao", "Corella", "Sikatuna", "Baclayon", "Alburquerque", "Loay", "Loboc", "Antequera", "Balilihan", "Calape", "Catigbian", "Clarin", "Cortes", "Dimiao", "Garcia Hernandez"],
      "Negros Oriental": ["Dumaguete City", "Bais City", "Bayawan City", "Tanjay City", "Guihulngan City", "Valencia", "Sibulan", "Bacong", "Dauin", "Zamboanguita", "Amlan", "Ayungon", "Bindoy", "Dumaguete", "Jimalalud", "La Libertad", "Mabinay", "Manjuyod"],
      "Ilocos Norte": ["Laoag City", "Batac City", "Paoay", "Vintar", "Bacarra", "Sarrat", "San Nicolas", "Badoc", "Pinili", "Currimao", "Banna", "Burgos", "Carasi", "Dingras", "Dumalneg", "Marcos", "Nueva Era", "Pagudpud"],
      "Ilocos Sur": ["Vigan City", "Candon City", "Narvacan", "Santa Maria", "Santa Catalina", "Santiago", "San Esteban", "Caoayan", "Santa", "Magsingal", "Banayoyo", "Bantay", "Burgos", "Cabugao", "Cervantes", "Galimuyod", "Gregorio del Pilar", "Lidlidda"],
      "La Union": ["San Fernando City", "Bauang", "Agoo", "Aringay", "Bacnotan", "Naguilian", "Tubao", "Rosario", "Santo Tomas", "Caba", "Bagulin", "Balaoan", "Bangar", "Burgos", "Luna", "Pugo", "San Gabriel", "Sudipen"],
      "Pangasinan": ["Dagupan City", "San Carlos City", "Urdaneta City", "Lingayen", "Calasiao", "Mangaldan", "Binmaley", "Manaoag", "Bayambang", "Alaminos City", "Anda", "Asingan", "Balungao", "Bani", "Basista", "Bautista", "Binalonan", "Bolinao"],
      "Bataan": ["Balanga City", "Mariveles", "Dinalupihan", "Orani", "Samal", "Abucay", "Pilar", "Bagac", "Morong", "Limay", "Hermosa", "Orion"],
      "Bulacan": ["Malolos", "Meycauayan", "San Jose del Monte", "Santa Maria", "Marilao", "Bocaue", "Guiguinto", "Plaridel", "Pulilan", "Calumpit", "Balagtas", "Baliwag", "Bustos", "Hagonoy", "Obando", "Pandi", "Paombong", "San Ildefonso"],
      "Nueva Ecija": ["Cabanatuan City", "Palayan City", "Gapan City", "San Jose City", "Science City of Muñoz", "Santa Rosa", "Peñaranda", "Lupao", "Talavera", "Rizal", "Aliaga", "Bongabon", "Cabiao", "Carranglan", "Cuyapo", "Gabaldon", "General Mamerto Natividad", "General Tinio"],
      "Tarlac": ["Tarlac City", "Concepcion", "Capas", "Bamban", "Paniqui", "Camiling", "Moncada", "Gerona", "Victoria", "San Manuel", "Anao", "La Paz", "Mayantoc", "Pura", "Ramos", "San Clemente", "Santa Ignacia"],
      "Zambales": ["Olongapo City", "Subic", "Iba", "Botolan", "Castillejos", "San Marcelino", "San Antonio", "San Felipe", "Cabangan", "Palauig", "Candelaria", "Masinloc", "Sta. Cruz"],
      "Batangas": ["Batangas City", "Lipa City", "Tanauan City", "Santo Tomas", "Bauan", "Nasugbu", "Calaca", "Balayan", "Lian", "Taal", "Alitagtag", "Balete", "Cuenca", "Ibaan", "Laurel", "Lemery", "Malvar", "Mataasnakahoy"],
      "Cavite": ["Dasmarinas", "Bacoor", "Imus", "Tagaytay City", "General Trias", "Trece Martires City", "Silang", "Kawit", "Noveleta", "Rosario", "Alfonso", "Amadeo", "Carmona", "Gen. Mariano Alvarez", "Indang", "Magallanes", "Maragondon", "Mendez"],
      "Laguna": ["Calamba City", "Santa Rosa City", "San Pablo City", "Biñan", "Cabuyao", "San Pedro", "Los Baños", "Sta. Cruz", "Nagcarlan", "Liliw", "Alaminos", "Bay", "Calauan", "Cavinti", "Famy", "Kalayaan", "Luisiana", "Lumban"],
      "Rizal": ["Antipolo City", "Taytay", "Cainta", "Binangonan", "Angono", "Rodriguez", "San Mateo", "Baras", "Tanay", "Pililla", "Cardona", "Jalajala", "Morong", "Teresa"],
      "Quezon": ["Lucena City", "Tayabas", "Candelaria", "Sariaya", "Gumaca", "Lopez", "Atimonan", "Mauban", "Infanta", "Real", "Agdangan", "Alabat", "Buenavista", "Burdeos", "Calauag", "Gen. Luna", "Gen. Nakar", "Guinayangan"],
      "Albay": ["Legazpi City", "Ligao City", "Tabaco City", "Daraga", "Guinobatan", "Camalig", "Polangui", "Oas", "Libon", "Malilipot", "Bacacay", "Malinao", "Sto. Domingo", "Rapu-Rapu", "Jovellar", "Pio Duran"],
      "Camarines Sur": ["Naga City", "Iriga City", "Pili", "Calabanga", "Libmanan", "Nabua", "Buhi", "Baao", "Bula", "Bato", "Bombon", "Cabusao", "Caramoan", "Del Gallego", "Gainza", "Garchitorena", "Lagonoy", "Magarao"]
    };

    const cities = {
      "Cebu City": ["Sambag I", "Sambag II", "Capitol Site", "Kamputhaw", "Luz", "Ermita", "Mabolo", "Talamban", "Tisa", "Labangon", "Banilad", "Basak Pardo", "Bulacao", "Busay", "Calamba", "Cogon Pardo", "Guadalupe"],
      "Mandaue City": ["Alang-alang", "Bakilid", "Pakna-an", "Basak", "Labogon", "Banilad", "Cabancalan", "Casili", "Casuntingan", "Centro", "Cubacub", "Guizo", "Ibabao-Estancia", "Jagobiao", "Looc", "Maguikay", "Opao"],
      "Lapu-Lapu City": ["Agus", "Babag", "Bankal", "Baring", "Basak", "Buaya", "Calawisan", "Canjulao", "Caw-oy", "Cawhagan", "Gun-ob", "Ibo", "Looc", "Mactan", "Maribago", "Marigondon", "Pajac", "Pajo", "Pangan-an", "Pusok", "Sabang", "Santa Rosa", "Subabasbas", "Tingo"],
      "Talisay City": ["Biasong", "Bulacao", "Cansojong", "Camp IV", "Dumlog", "Jaclupan", "Lagtang", "Lawaan I", "Lawaan II", "Lawaan III", "Linao", "Maghaway", "Manipis", "Mohon", "Poblacion", "Pooc", "San Isidro", "San Roque", "Tabunoc", "Tangke"],
      "Toledo City": ["Awihao", "Bagakay", "Bato", "Biga", "Bulongan", "Bunga", "Cantabaco", "Capitol", "Carmen", "Daanglungsod", "Don Andres Soriano", "Dumlog", "Ilihan", "Landahan", "Loay", "Luray II", "Matab-ang", "Media Once", "Pangamihan", "Poblacion", "Poog", "Putingbato", "Sagay", "Sam-ang", "Sangi", "Sto. Niño", "Subayon", "Talavera", "Tungkay"],
      "Danao City": ["Baliang", "Bayabas", "Binaliw", "Cabalawan", "Cagat-Lamac", "Cahumayan", "Cambanay", "Cambubho", "Cogon-Cruz", "Danasan", "Dungga", "Dunggoan", "Guinacot", "Guinsay", "Ibo", "Langosig", "Lawaan", "Licolico", "Looc", "Magtagobtob", "Malapoc", "Manlayag", "Mantija", "Mercado", "Oguis", "Pili", "Poblacion", "Quisol", "Sabang", "Sacsac", "Sandayong", "Santa Rosa", "Santican", "Sibacan", "Suba", "Taboc", "Taytay", "Togonon", "Tuburan Sur"],
      "Naga City": ["Alang-alang", "Balirong", "Bairan", "Bascaran", "Cabolawan", "Cantao-an", "Central Poblacion", "Cogon", "Colon", "East Poblacion", "Inoburan", "Inayagan", "Jaguimit", "Lanas", "Langtad", "Lutac", "Mainit", "Mayana", "Naalad", "North Poblacion", "Pangdan", "Patag", "South Poblacion", "Tagnocon", "Tangke", "Tinaan", "Uling", "West Poblacion"],
      "Carcar City": ["Bolinawan", "Buenavista", "Calidngan", "Can-asujan", "Guadalupe", "Liburon", "Napo", "Ocana", "Perrelos", "Poblacion I", "Poblacion II", "Poblacion III", "Tuyom", "Valladolid"],
      "Bogo City": ["Anonang Norte", "Anonang Sur", "Banban", "Binabag", "Campusong", "Cantagay", "Cayang", "Dakit", "Don Pedro Rodriguez", "Gairan", "Guadalupe", "La Paz", "La Purisima Concepcion", "Libertad", "Lourdes", "Malingin", "Marangog", "Nailon", "Odlot", "Pandan", "Polambato", "Sambag", "San Vicente", "Santa Cruz", "Sibongan", "Sulangan", "Taytayan", "Yati"],
      "Cordova": ["Alegria", "Bangbang", "Buagsong", "Catarman", "Cogon", "Dapitan", "Day-as", "Gabriela", "Gilutongan", "Ibabao", "Pilipog", "Poblacion", "San Miguel"],
      "Compostela": ["Bagalnga", "Basak", "Buluang", "Cabadiangan", "Cambayog", "Canamucan", "Cogon", "Dapdap", "Estaca", "Lagundi", "Mulao", "Panangban", "Poblacion", "Tag-ube", "Tamiao"],
      "Liloan": ["Cabadiangan", "Calero", "Catarman", "Cotcot", "Jubay", "Lataban", "Mulao", "Poblacion", "San Roque", "San Vicente", "Santa Cruz", "Tabla", "Tayud", "Yati"],
      "Minglanilla": ["Cadulawan", "Calajo-an", "Camp 7", "Camp 8", "Cuanos", "Guindaruhan", "Linao", "Mangoto", "Pakigne", "Poblacion Ward I", "Poblacion Ward II", "Poblacion Ward III", "Tubod", "Tulay", "Tungkop", "Tungkil", "Tungkop", "Vito"],
      "San Fernando": ["Balud", "Balungag", "Basak", "Bugho", "Cabatbatan", "Greenhills", "Ilaya", "Lantawan", "Liburon", "Magsico", "Panadtaran", "Poblacion North", "Poblacion South", "San Isidro", "Sangat", "Tabionan", "Tananas", "Tinaan", "Tonggo"],
      "San Remigio": ["Anapog", "Argawanon", "Bagtic", "Bancasan", "Batad", "Busogon", "Calambua", "Canagahan", "Dapdap", "Gawaygaway", "Hagnaya", "Kayam", "Kinawahan", "Lamintak", "Lawis", "Luyang", "Mancilang", "Poblacion", "Punao", "Sab-a", "San Miguel", "Tambongon", "To-ong", "Victoria"]
    };

    const barangays = {
      "Alang-alang": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Bakilid": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Pakna-an": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Basak": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Labogon": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Banilad": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Cabancalan": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Casili": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Casuntingan": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Centro": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Cubacub": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Guizo": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Ibabao-Estancia": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Jagobiao": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Looc": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Maguikay": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Opao": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Agus": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Babag": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Bankal": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Baring": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Basak": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Buaya": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Calawisan": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Canjulao": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Caw-oy": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Cawhagan": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Gun-ob": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Ibo": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Looc": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Mactan": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Maribago": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Marigondon": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Pajac": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Pajo": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Pangan-an": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Pusok": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Sabang": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Santa Rosa": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Subabasbas": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Tingo": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Biasong": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Bulacao": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Cansojong": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Camp IV": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Dumlog": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Jaclupan": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Lagtang": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Lawaan I": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Lawaan II": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Lawaan III": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Linao": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Maghaway": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Manipis": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Mohon": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Poblacion": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Pooc": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "San Isidro": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "San Roque": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Tabunoc": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"],
      "Tangke": ["Zone 1", "Zone 2", "Zone 3", "Zone 4", "Zone 5", "Zone 6", "Zone 7", "Zone 8"]
    };

    // Enhanced Phone Number Validation - CHANGED TO 11 DIGITS
    document.addEventListener('DOMContentLoaded', function() {
      const phoneInput = document.getElementById('phone');
      
      // Only allow numbers and limit to 11 digits
      phoneInput.addEventListener('input', function(e) {
        // Remove any non-numeric characters
        let value = e.target.value.replace(/[^0-9]/g, '');
        
        // Limit to 11 digits
        if (value.length > 11) {
          value = value.substring(0, 11);
        }
        
        e.target.value = value;
      });
      
      // Prevent non-numeric input on keydown
      phoneInput.addEventListener('keydown', function(e) {
        // Allow: backspace, delete, tab, escape, enter, and decimal point
        if ([46, 8, 9, 27, 13, 110, 190].indexOf(e.keyCode) !== -1 ||
             // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
             (e.keyCode === 65 && e.ctrlKey === true) || 
             (e.keyCode === 67 && e.ctrlKey === true) ||
             (e.keyCode === 86 && e.ctrlKey === true) ||
             (e.keyCode === 88 && e.ctrlKey === true) ||
             // Allow: home, end, left, right
             (e.keyCode >= 35 && e.keyCode <= 39)) {
          return;
        }
        
        // Ensure that it is a number and stop the keypress if not
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
          e.preventDefault();
        }
      });
      
      // Validate on paste
      phoneInput.addEventListener('paste', function(e) {
        const pastedData = e.clipboardData.getData('text');
        if (!/^\d+$/.test(pastedData)) {
          e.preventDefault();
        }
      });
    });

    // Enhanced File upload functionality for both front and back photos
    document.addEventListener('DOMContentLoaded', function() {
      // Front photo upload
      const fileInputFront = document.getElementById('staff_id_photo_front');
      const uploadBodyFront = document.getElementById('uploadBodyFront');
      const fileUploadCardFront = document.getElementById('fileUploadCardFront');

      fileInputFront.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
          // Validate file type
          const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
          if (!allowedTypes.includes(file.type)) {
            alert('Please select a valid image file (JPG, JPEG, PNG, GIF).');
            fileInputFront.value = '';
            return;
          }

          // Validate file size (2MB)
          const maxSize = 2 * 1024 * 1024; // 2MB in bytes
          if (file.size > maxSize) {
            alert('File size must be less than 2MB.');
            fileInputFront.value = '';
            return;
          }

          // Show preview - Replace the upload body content
          const reader = new FileReader();
          reader.onload = function(e) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            
            uploadBodyFront.innerHTML = `
              <div class="file-preview">
                <img src="${e.target.result}" alt="Front Preview">
                <div class="file-info">
                  <div class="file-info-item"><strong>File Name:</strong> ${file.name}</div>
                  <div class="file-info-item"><strong>File Size:</strong> ${fileSize} MB</div>
                  <div class="file-info-item"><strong>File Type:</strong> ${file.type}</div>
                </div>
                <div class="file-status success">
                  <i class="fas fa-check-circle"></i> File successfully uploaded
                </div>
                <div class="preview-actions">
                  <button type="button" class="preview-action-btn primary" onclick="document.getElementById('staff_id_photo_front').click()">
                    <i class="fas fa-sync-alt"></i> Replace
                  </button>
                  <button type="button" class="preview-action-btn" onclick="removeImage('front')">
                    <i class="fas fa-trash"></i> Remove
                  </button>
                </div>
              </div>
            `;
            uploadBodyFront.classList.add('has-preview');
          };
          reader.readAsDataURL(file);
        }
      });

      // Back photo upload
      const fileInputBack = document.getElementById('staff_id_photo_back');
      const uploadBodyBack = document.getElementById('uploadBodyBack');
      const fileUploadCardBack = document.getElementById('fileUploadCardBack');

      fileInputBack.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
          // Validate file type
          const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
          if (!allowedTypes.includes(file.type)) {
            alert('Please select a valid image file (JPG, JPEG, PNG, GIF).');
            fileInputBack.value = '';
            return;
          }

          // Validate file size (2MB)
          const maxSize = 2 * 1024 * 1024; // 2MB in bytes
          if (file.size > maxSize) {
            alert('File size must be less than 2MB.');
            fileInputBack.value = '';
            return;
          }

          // Show preview - Replace the upload body content
          const reader = new FileReader();
          reader.onload = function(e) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            
            uploadBodyBack.innerHTML = `
              <div class="file-preview">
                <img src="${e.target.result}" alt="Back Preview">
                <div class="file-info">
                  <div class="file-info-item"><strong>File Name:</strong> ${file.name}</div>
                  <div class="file-info-item"><strong>File Size:</strong> ${fileSize} MB</div>
                  <div class="file-info-item"><strong>File Type:</strong> ${file.type}</div>
                </div>
                <div class="file-status success">
                  <i class="fas fa-check-circle"></i> File successfully uploaded
                </div>
                <div class="preview-actions">
                  <button type="button" class="preview-action-btn primary" onclick="document.getElementById('staff_id_photo_back').click()">
                    <i class="fas fa-sync-alt"></i> Replace
                  </button>
                  <button type="button" class="preview-action-btn" onclick="removeImage('back')">
                    <i class="fas fa-trash"></i> Remove
                  </button>
                </div>
              </div>
            `;
            uploadBodyBack.classList.add('has-preview');
          };
          reader.readAsDataURL(file);
        }
      });

      // Drag and drop functionality for front photo
      fileUploadCardFront.addEventListener('dragover', function(e) {
        e.preventDefault();
        fileUploadCardFront.classList.add('dragover');
      });

      fileUploadCardFront.addEventListener('dragleave', function(e) {
        e.preventDefault();
        fileUploadCardFront.classList.remove('dragover');
      });

      fileUploadCardFront.addEventListener('drop', function(e) {
        e.preventDefault();
        fileUploadCardFront.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
          fileInputFront.files = files;
          fileInputFront.dispatchEvent(new Event('change'));
        }
      });

      // Drag and drop functionality for back photo
      fileUploadCardBack.addEventListener('dragover', function(e) {
        e.preventDefault();
        fileUploadCardBack.classList.add('dragover');
      });

      fileUploadCardBack.addEventListener('dragleave', function(e) {
        e.preventDefault();
        fileUploadCardBack.classList.remove('dragover');
      });

      fileUploadCardBack.addEventListener('drop', function(e) {
        e.preventDefault();
        fileUploadCardBack.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
          fileInputBack.files = files;
          fileInputBack.dispatchEvent(new Event('change'));
        }
      });
    });

    // Function to remove uploaded image and restore original state
    function removeImage(side) {
      if (side === 'front') {
        document.getElementById('staff_id_photo_front').value = '';
        document.getElementById('uploadBodyFront').innerHTML = `
          <div class="file-upload-text">
            Upload a clear photo of the front side of your Staff ID showing your photo and details.
          </div>
          <label for="staff_id_photo_front" class="file-upload-btn">
            <i class="fas fa-upload"></i> Choose File
          </label>
          <div class="file-requirements">
            <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: 2MB
          </div>
        `;
        document.getElementById('uploadBodyFront').classList.remove('has-preview');
      } else if (side === 'back') {
        document.getElementById('staff_id_photo_back').value = '';
        document.getElementById('uploadBodyBack').innerHTML = `
          <div class="file-upload-text">
            Upload a clear photo of the back side of your Staff ID showing additional details.
          </div>
          <label for="staff_id_photo_back" class="file-upload-btn">
            <i class="fas fa-upload"></i> Choose File
          </label>
          <div class="file-requirements">
            <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: 2MB
          </div>
        `;
        document.getElementById('uploadBodyBack').classList.remove('has-preview');
      }
    }

    // Address dropdown functionality
    document.addEventListener('DOMContentLoaded', function() {
      const regionSelect = document.getElementById('region');
      const provinceSelect = document.getElementById('province');
      const citySelect = document.getElementById('city');
      const barangaySelect = document.getElementById('barangay');

      regionSelect.addEventListener('change', function() {
        const selectedRegion = regionSelect.value;
        provinceSelect.innerHTML = '<option value="">Select Province</option>';
        if (selectedRegion && regions[selectedRegion]) {
          regions[selectedRegion].forEach(province => {
            const option = document.createElement('option');
            option.value = province;
            option.textContent = province;
            provinceSelect.appendChild(option);
          });
        }
        // Clear dependent fields
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
      });

      provinceSelect.addEventListener('change', function() {
        const selectedProvince = provinceSelect.value;
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        if (selectedProvince && provinces[selectedProvince]) {
          provinces[selectedProvince].forEach(city => {
            const option = document.createElement('option');
            option.value = city;
            option.textContent = city;
            citySelect.appendChild(option);
          });
        }
        // Clear dependent field
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
      });

      citySelect.addEventListener('change', function() {
        const selectedCity = citySelect.value;
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
        if (selectedCity && cities[selectedCity]) {
          cities[selectedCity].forEach(barangay => {
            const option = document.createElement('option');
            option.value = barangay;
            option.textContent = barangay;
            barangaySelect.appendChild(option);
          });
        }
      });
    });

    // Client-side validation - CHANGED TO 11 DIGITS
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('form');
      form.addEventListener('submit', function(e) {
        const department = document.getElementById('department').value;
        const position = document.getElementById('position').value;
        const employeeId = document.getElementById('employee_id').value;
        const phone = document.getElementById('phone').value;
        const lastName = document.getElementById('last_name').value;
        const firstName = document.getElementById('first_name').value;
        const region = document.getElementById('region').value;
        const province = document.getElementById('province').value;
        const city = document.getElementById('city').value;
        const barangay = document.getElementById('barangay').value;
        const staffIdPhotoFront = document.getElementById('staff_id_photo_front').files[0];
        const staffIdPhotoBack = document.getElementById('staff_id_photo_back').files[0];
        
        const regexId = /^[A-Za-z0-9\-]+$/;
        const regexPhone = /^[0-9]{11}$/;
        
        if (!lastName || !firstName) {
          e.preventDefault();
          alert('Please fill in both Last Name and First Name fields.');
          return false;
        }
        
        if (!department) {
          e.preventDefault();
          alert('Please select a department.');
          return false;
        }
        
        if (!position) {
          e.preventDefault();
          alert('Please select a position.');
          return false;
        }
        
        if (!regexId.test(employeeId)) {
          e.preventDefault();
          alert('Employee ID contains invalid characters. Only letters, numbers, and hyphens are allowed.');
          return false;
        }
        
        if (phone && !regexPhone.test(phone)) {
          e.preventDefault();
          alert('Please enter a valid phone number (exactly 11 digits, numbers only).');
          return false;
        }
        
        if (!region || !province || !city || !barangay) {
          e.preventDefault();
          alert('Please complete all address fields (Region, Province, City/Municipality, and Barangay).');
          return false;
        }
        
        if (!staffIdPhotoFront) {
          e.preventDefault();
          alert('Please upload the front photo of your Staff ID for verification.');
          return false;
        }
        
        if (!staffIdPhotoBack) {
          e.preventDefault();
          alert('Please upload the back photo of your Staff ID for verification.');
          return false;
        }
      });
    });
  </script>
</body>
</html>