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

// PSGC API Endpoints
define('REGIONS_API', 'https://psgc.gitlab.io/api/regions/');
define('PROVINCES_API', 'https://psgc.gitlab.io/api/regions/{code}/provinces/');
define('CITIES_API', 'https://psgc.gitlab.io/api/provinces/{code}/cities-municipalities/');
define('BARANGAYS_API', 'https://psgc.gitlab.io/api/cities-municipalities/{code}/barangays/');

function fetchFromAPI($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("PSGC API Error: HTTP $httpCode for URL: $url");
        return null;
    }
    
    return json_decode($response, true);
}

function sortByName($a, $b) {
    return strcmp($a['name'], $b['name']);
}

// Handle PSGC API requests
if (isset($_GET['getRegions'])) {
    header('Content-Type: application/json');
    $regions = fetchFromAPI(REGIONS_API);
    if ($regions && is_array($regions)) {
        usort($regions, 'sortByName');
        echo json_encode($regions);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['getProvinces']) && isset($_GET['regionCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['regionCode'], PROVINCES_API);
    $provinces = fetchFromAPI($url);
    if ($provinces && is_array($provinces)) {
        usort($provinces, 'sortByName');
        echo json_encode($provinces);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['getCities']) && isset($_GET['provinceCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['provinceCode'], CITIES_API);
    $cities = fetchFromAPI($url);
    if ($cities && is_array($cities)) {
        usort($cities, 'sortByName');
        echo json_encode($cities);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['getBarangays']) && isset($_GET['cityCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['cityCode'], BARANGAYS_API);
    $barangays = fetchFromAPI($url);
    if ($barangays && is_array($barangays)) {
        usort($barangays, 'sortByName');
        echo json_encode($barangays);
    } else {
        echo json_encode([]);
    }
    exit;
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
    
    // Address fields - IMPORTANT: Store PSGC names
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
    
    // Combine address components properly
    $address_components = [];
    if (!empty($street_address)) $address_components[] = $street_address;
    if (!empty($barangay)) $address_components[] = $barangay;
    if (!empty($city)) $address_components[] = $city;
    if (!empty($province)) $address_components[] = $province;
    if (!empty($region)) $address_components[] = $region;
    if (!empty($zip_code)) $address_components[] = "ZIP: " . $zip_code;
    
    $permanent_address = implode(', ', $address_components);
    
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
    
    /* Address Loading Spinner */
    .loading-spinner {
      display: none;
      color: var(--primary-color);
      text-align: center;
      padding: 10px;
    }
    
    .loading-spinner i {
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* Form validation styles */
    .form-input.error, .form-select.error, .form-textarea.error {
      border-color: var(--red);
      background-color: #fff8f8;
    }
    
    .error-message {
      color: var(--red);
      font-size: 0.85rem;
      margin-top: 5px;
      display: none;
    }
    
    .error-message.show {
      display: block;
    }
    
    .form-group.has-error .form-label {
      color: var(--red);
    }
    
    /* Loading overlay */
    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.8);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      display: none;
    }
    
    .loading-spinner-large {
      font-size: 3rem;
      color: var(--primary-color);
      margin-bottom: 20px;
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
  <!-- Loading Overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner-large">
      <i class="fas fa-spinner fa-spin"></i>
    </div>
    <p>Processing your request...</p>
  </div>
  
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
      
      <form method="POST" action="" enctype="multipart/form-data" id="staffForm">
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
                  <div class="error-message" id="last_name_error"></div>
                </div>
                <div>
                  <label class="form-label" for="first_name">First Name</label>
                  <input type="text" id="first_name" name="first_name" class="form-input" 
                         value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" 
                         required placeholder="First Name">
                  <div class="error-message" id="first_name_error"></div>
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
              <div class="error-message" id="email_error"></div>
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
              <div class="error-message" id="phone_error"></div>
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
                  </select>
                  <div class="error-message" id="region_error"></div>
                  <div class="loading-spinner" id="regionLoading">
                    <i class="fas fa-spinner"></i> Loading regions...
                  </div>
                </div>
                <div>
                  <label class="form-label" for="province">Province <span class="required">*</span></label>
                  <select id="province" name="province" class="form-select" required disabled>
                    <option value="">Select Province</option>
                  </select>
                  <div class="error-message" id="province_error"></div>
                  <div class="loading-spinner" id="provinceLoading" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading provinces...
                  </div>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <div class="address-container">
                <div>
                  <label class="form-label" for="city">City/Municipality <span class="required">*</span></label>
                  <select id="city" name="city" class="form-select" required disabled>
                    <option value="">Select City/Municipality</option>
                  </select>
                  <div class="error-message" id="city_error"></div>
                  <div class="loading-spinner" id="cityLoading" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading cities...
                  </div>
                </div>
                <div>
                  <label class="form-label" for="barangay">Barangay <span class="required">*</span></label>
                  <select id="barangay" name="barangay" class="form-select" required disabled>
                    <option value="">Select Barangay</option>
                  </select>
                  <div class="error-message" id="barangay_error"></div>
                  <div class="loading-spinner" id="barangayLoading" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading barangays...
                  </div>
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
              <div class="error-message" id="employee_id_error"></div>
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
                  <div class="error-message" id="staff_id_photo_front_error"></div>
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
                  <div class="error-message" id="staff_id_photo_back_error"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <div class="action-buttons">
          <button type="submit" class="btn btn-primary" id="submitBtn">
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
    // PSGC API Integration (Removed hardcoded data)
    class PSGCAddressAPI {
      constructor() {
        this.baseURL = window.location.origin + window.location.pathname;
        this.cache = {
          regions: null,
          provinces: {},
          cities: {},
          barangays: {}
        };
      }
      
      async fetchData(endpoint, params = {}) {
        const url = new URL(this.baseURL);
        Object.keys(params).forEach(key => {
          url.searchParams.append(key, params[key]);
        });
        url.searchParams.append(endpoint, '1');
        
        try {
          const response = await fetch(url.toString(), {
            method: 'GET',
            headers: {
              'Accept': 'application/json'
            }
          });
          
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }
          
          const data = await response.json();
          return Array.isArray(data) ? data : [];
        } catch (error) {
          console.warn('API fetch failed:', error);
          return [];
        }
      }
      
      async getRegions() {
        if (this.cache.regions) {
          return this.cache.regions;
        }
        
        const regions = await this.fetchData('getRegions');
        this.cache.regions = regions;
        return regions;
      }
      
      async getProvinces(regionCode) {
        if (this.cache.provinces[regionCode]) {
          return this.cache.provinces[regionCode];
        }
        
        const provinces = await this.fetchData('getProvinces', { regionCode: regionCode });
        this.cache.provinces[regionCode] = provinces;
        return provinces;
      }
      
      async getCities(provinceCode) {
        if (this.cache.cities[provinceCode]) {
          return this.cache.cities[provinceCode];
        }
        
        const cities = await this.fetchData('getCities', { provinceCode: provinceCode });
        this.cache.cities[provinceCode] = cities;
        return cities;
      }
      
      async getBarangays(cityCode) {
        if (this.cache.barangays[cityCode]) {
          return this.cache.barangays[cityCode];
        }
        
        const barangays = await this.fetchData('getBarangays', { cityCode: cityCode });
        this.cache.barangays[cityCode] = barangays;
        return barangays;
      }
    }

    // Form validation
    class FormValidator {
      constructor() {
        this.errors = {};
      }
      
      validateRequired(value, fieldName) {
        if (!value || value.trim() === '') {
          this.errors[fieldName] = `${fieldName.replace('_', ' ')} is required`;
          return false;
        }
        return true;
      }
      
      validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailRegex.test(email)) {
          this.errors.email = 'Please enter a valid email address';
          return false;
        }
        return true;
      }
      
      validatePhone(phone) {
        const phoneRegex = /^[0-9]{11}$/;
        if (phone && !phoneRegex.test(phone)) {
          this.errors.phone = 'Phone number must be exactly 11 digits';
          return false;
        }
        return true;
      }
      
      validateFile(file, fieldName) {
        if (!file || file.size === 0) {
          this.errors[fieldName] = `${fieldName} is required`;
          return false;
        }
        
        const maxSize = 2 * 1024 * 1024; // 2MB
        if (file.size > maxSize) {
          this.errors[fieldName] = `File size must be less than 2MB`;
          return false;
        }
        
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
          this.errors[fieldName] = 'Only JPG, JPEG, PNG, and GIF files are allowed';
          return false;
        }
        
        return true;
      }
      
      clearErrors() {
        this.errors = {};
        document.querySelectorAll('.error-message').forEach(el => {
          el.classList.remove('show');
          el.textContent = '';
        });
        document.querySelectorAll('.form-input, .form-select').forEach(el => {
          el.classList.remove('error');
        });
        document.querySelectorAll('.form-group').forEach(el => {
          el.classList.remove('has-error');
        });
      }
      
      showErrors() {
        for (const [field, message] of Object.entries(this.errors)) {
          const errorElement = document.getElementById(`${field}_error`);
          const inputElement = document.getElementById(field) || document.querySelector(`[name="${field}"]`);
          const formGroup = inputElement ? inputElement.closest('.form-group') : null;
          
          if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.add('show');
          }
          
          if (inputElement) {
            inputElement.classList.add('error');
          }
          
          if (formGroup) {
            formGroup.classList.add('has-error');
          }
        }
      }
      
      isValid() {
        return Object.keys(this.errors).length === 0;
      }
    }

    // Main application
    document.addEventListener('DOMContentLoaded', function() {
      const addressAPI = new PSGCAddressAPI();
      const validator = new FormValidator();
      const form = document.getElementById('staffForm');
      const submitBtn = document.getElementById('submitBtn');
      const loadingOverlay = document.getElementById('loadingOverlay');
      
      // DOM elements
      const regionSelect = document.getElementById('region');
      const provinceSelect = document.getElementById('province');
      const citySelect = document.getElementById('city');
      const barangaySelect = document.getElementById('barangay');
      const regionLoading = document.getElementById('regionLoading');
      const provinceLoading = document.getElementById('provinceLoading');
      const cityLoading = document.getElementById('cityLoading');
      const barangayLoading = document.getElementById('barangayLoading');
      
      // File upload elements
      const fileInputFront = document.getElementById('staff_id_photo_front');
      const uploadBodyFront = document.getElementById('uploadBodyFront');
      const fileUploadCardFront = document.getElementById('fileUploadCardFront');
      const fileInputBack = document.getElementById('staff_id_photo_back');
      const uploadBodyBack = document.getElementById('uploadBodyBack');
      const fileUploadCardBack = document.getElementById('fileUploadCardBack');
      
      // Load regions on page load
      loadRegions();
      
      async function loadRegions() {
        regionLoading.style.display = 'block';
        try {
          const regions = await addressAPI.getRegions();
          regionSelect.innerHTML = '<option value="">Select Region</option>';
          
          if (regions.length === 0) {
            showNotification('Unable to load regions. Please refresh the page.', 'error');
          } else {
            regions.forEach(region => {
              const option = document.createElement('option');
              option.value = region.name; // Store the name
              option.setAttribute('data-code', region.code);
              option.textContent = region.name;
              regionSelect.appendChild(option);
            });
          }
          
          regionLoading.style.display = 'none';
          provinceSelect.disabled = false;
          setupAddressListeners();
        } catch (error) {
          console.error('Error loading regions:', error);
          regionLoading.style.display = 'none';
          showNotification('Error loading address data. Please refresh the page.', 'error');
        }
      }
      
      function setupAddressListeners() {
        regionSelect.addEventListener('change', async function() {
          const regionName = this.value;
          const regionCode = this.options[this.selectedIndex].getAttribute('data-code');
          
          if (!regionName || !regionCode) {
            resetDropdown(provinceSelect, 'Select Province');
            resetDropdown(citySelect, 'Select City/Municipality');
            resetDropdown(barangaySelect, 'Select Barangay');
            return;
          }
          
          provinceLoading.style.display = 'block';
          provinceSelect.disabled = true;
          provinceSelect.innerHTML = '<option value="">Select Province</option>';
          
          resetDropdown(citySelect, 'Select City/Municipality');
          resetDropdown(barangaySelect, 'Select Barangay');
          
          try {
            const provinces = await addressAPI.getProvinces(regionCode);
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            
            if (provinces.length > 0) {
              provinces.forEach(province => {
                const option = document.createElement('option');
                option.value = province.name;
                option.setAttribute('data-code', province.code);
                option.textContent = province.name;
                provinceSelect.appendChild(option);
              });
              
              provinceLoading.style.display = 'none';
              provinceSelect.disabled = false;
              
              if (provinces.length === 1) {
                provinceSelect.value = provinces[0].name;
                setTimeout(() => provinceSelect.dispatchEvent(new Event('change')), 100);
              }
            } else {
              provinceLoading.style.display = 'none';
              provinceSelect.disabled = false;
              showNotification('No provinces found for this region.', 'warning');
            }
          } catch (error) {
            console.error('Error loading provinces:', error);
            provinceLoading.style.display = 'none';
            provinceSelect.disabled = false;
            showNotification('Error loading provinces', 'error');
          }
        });
        
        provinceSelect.addEventListener('change', async function() {
          const provinceName = this.value;
          const provinceCode = this.options[this.selectedIndex].getAttribute('data-code');
          
          if (!provinceName || !provinceCode) {
            resetDropdown(citySelect, 'Select City/Municipality');
            resetDropdown(barangaySelect, 'Select Barangay');
            return;
          }
          
          cityLoading.style.display = 'block';
          citySelect.disabled = true;
          citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
          resetDropdown(barangaySelect, 'Select Barangay');
          
          try {
            const cities = await addressAPI.getCities(provinceCode);
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            
            if (cities.length > 0) {
              cities.forEach(city => {
                const option = document.createElement('option');
                option.value = city.name;
                option.setAttribute('data-code', city.code);
                option.textContent = city.name;
                citySelect.appendChild(option);
              });
              
              cityLoading.style.display = 'none';
              citySelect.disabled = false;
              
              if (cities.length === 1) {
                citySelect.value = cities[0].name;
                setTimeout(() => citySelect.dispatchEvent(new Event('change')), 100);
              }
            } else {
              cityLoading.style.display = 'none';
              citySelect.disabled = false;
              showNotification('No cities/municipalities found for this province.', 'warning');
            }
          } catch (error) {
            console.error('Error loading cities:', error);
            cityLoading.style.display = 'none';
            citySelect.disabled = false;
            showNotification('Error loading cities', 'error');
          }
        });
        
        citySelect.addEventListener('change', async function() {
          const cityName = this.value;
          const cityCode = this.options[this.selectedIndex].getAttribute('data-code');
          
          if (!cityName || !cityCode) {
            resetDropdown(barangaySelect, 'Select Barangay');
            return;
          }
          
          barangayLoading.style.display = 'block';
          barangaySelect.disabled = true;
          barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
          
          try {
            const barangays = await addressAPI.getBarangays(cityCode);
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            
            if (barangays.length > 0) {
              barangays.forEach(barangay => {
                const option = document.createElement('option');
                option.value = barangay.name;
                option.textContent = barangay.name;
                barangaySelect.appendChild(option);
              });
              
              barangayLoading.style.display = 'none';
              barangaySelect.disabled = false;
              
              if (barangays.length === 1) {
                barangaySelect.value = barangays[0].name;
              }
            } else {
              barangayLoading.style.display = 'none';
              barangaySelect.disabled = false;
              showNotification('No barangays found for this city/municipality.', 'warning');
            }
          } catch (error) {
            console.error('Error loading barangays:', error);
            barangayLoading.style.display = 'none';
            barangaySelect.disabled = false;
            showNotification('Error loading barangays', 'error');
          }
        });
      }
      
      function resetDropdown(selectElement, placeholder) {
        selectElement.innerHTML = `<option value="">${placeholder}</option>`;
        selectElement.disabled = true;
        selectElement.value = '';
      }
      
      // File upload handlers
      fileInputFront.addEventListener('change', function(e) {
        handleFileUpload(e, uploadBodyFront, 'staff_id_photo_front', 'front');
      });
      
      fileInputBack.addEventListener('change', function(e) {
        handleFileUpload(e, uploadBodyBack, 'staff_id_photo_back', 'back');
      });
      
      function handleFileUpload(event, uploadBody, inputId, side) {
        const file = event.target.files[0];
        if (file) {
          const maxSize = 2 * 1024 * 1024; // 2MB
          const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
          
          if (!allowedTypes.includes(file.type)) {
            showNotification('Please select a valid image file (JPG, JPEG, PNG, GIF).', 'error');
            document.getElementById(inputId).value = '';
            return;
          }
          
          if (file.size > maxSize) {
            showNotification('File size must be less than 2MB.', 'error');
            document.getElementById(inputId).value = '';
            return;
          }
          
          const reader = new FileReader();
          reader.onload = function(e) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            const fileName = file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name;
            
            uploadBody.innerHTML = `
              <div class="file-preview">
                <img src="${e.target.result}" alt="${side} Preview">
                <div class="file-info">
                  <div class="file-info-item"><strong>File:</strong> ${fileName}</div>
                  <div class="file-info-item"><strong>Size:</strong> ${fileSize} MB</div>
                  <div class="file-info-item"><strong>Type:</strong> ${file.type.split('/')[1].toUpperCase()}</div>
                </div>
                <div class="file-status success">
                  <i class="fas fa-check-circle"></i> File uploaded
                </div>
                <div class="preview-actions">
                  <button type="button" class="preview-action-btn primary" onclick="document.getElementById('${inputId}').click()">
                    <i class="fas fa-sync-alt"></i> Replace
                  </button>
                  <button type="button" class="preview-action-btn" onclick="removeUploadedImage('${inputId}', '${side}')">
                    <i class="fas fa-trash"></i> Remove
                  </button>
                </div>
              </div>
            `;
            uploadBody.classList.add('has-preview');
          };
          reader.readAsDataURL(file);
        }
      }
      
      // Drag and drop
      [fileUploadCardFront, fileUploadCardBack].forEach((card, index) => {
        const side = index === 0 ? 'front' : 'back';
        const inputId = index === 0 ? 'staff_id_photo_front' : 'staff_id_photo_back';
        
        card.addEventListener('dragover', function(e) {
          e.preventDefault();
          card.classList.add('dragover');
        });
        
        card.addEventListener('dragleave', function(e) {
          e.preventDefault();
          card.classList.remove('dragover');
        });
        
        card.addEventListener('drop', function(e) {
          e.preventDefault();
          card.classList.remove('dragover');
          
          const files = e.dataTransfer.files;
          if (files.length > 0) {
            const fileInput = document.getElementById(inputId);
            fileInput.files = files;
            fileInput.dispatchEvent(new Event('change'));
          }
        });
      });
      
      // Phone number validation
      const phoneInput = document.getElementById('phone');
      phoneInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        if (value.length > 11) {
          value = value.substring(0, 11);
        }
        e.target.value = value;
      });
      
      // Form validation
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        validator.clearErrors();
        
        // Validate required fields
        const requiredFields = [
          'last_name', 'first_name', 'email', 'employee_id', 
          'department', 'position', 'region', 'province', 'city', 'barangay'
        ];
        
        requiredFields.forEach(field => {
          const element = document.getElementById(field);
          if (element) {
            validator.validateRequired(element.value, field);
          }
        });
        
        // Validate email
        const email = document.getElementById('email').value;
        validator.validateEmail(email);
        
        // Validate phone
        const phone = phoneInput.value;
        if (phone) {
          validator.validatePhone(phone);
        }
        
        // Validate files
        const frontFile = fileInputFront.files[0];
        const backFile = fileInputBack.files[0];
        
        if (frontFile) {
          validator.validateFile(frontFile, 'staff_id_photo_front');
        } else {
          validator.errors.staff_id_photo_front = 'Front photo is required';
        }
        
        if (backFile) {
          validator.validateFile(backFile, 'staff_id_photo_back');
        } else {
          validator.errors.staff_id_photo_back = 'Back photo is required';
        }
        
        // Validate address selections
        if (regionSelect.value === '') {
          validator.errors.region = 'Region is required';
        }
        if (provinceSelect.value === '') {
          validator.errors.province = 'Province is required';
        }
        if (citySelect.value === '') {
          validator.errors.city = 'City/Municipality is required';
        }
        if (barangaySelect.value === '') {
          validator.errors.barangay = 'Barangay is required';
        }
        
        // Validate employee ID format
        const employeeId = document.getElementById('employee_id').value;
        const regexId = /^[A-Za-z0-9\-]+$/;
        if (employeeId && !regexId.test(employeeId)) {
          validator.errors.employee_id = 'Employee ID contains invalid characters. Only letters, numbers, and hyphens are allowed.';
        }
        
        if (!validator.isValid()) {
          validator.showErrors();
          showNotification('Please correct the errors in the form.', 'error');
          return;
        }
        
        // Show loading overlay
        showLoading(true);
        submitBtn.disabled = true;
        
        // Submit form
        try {
          // Allow the form to submit normally
          form.submit();
        } catch (error) {
          console.error('Form submission error:', error);
          showNotification('Error submitting form. Please try again.', 'error');
          showLoading(false);
          submitBtn.disabled = false;
        }
      });
      
      // Helper functions
      function showLoading(show) {
        if (show) {
          loadingOverlay.style.display = 'flex';
        } else {
          loadingOverlay.style.display = 'none';
        }
      }
      
      function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
        notification.innerHTML = `
          <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'check-circle'}"></i>
          ${message}
        `;
        
        const content = document.querySelector('.content');
        content.insertBefore(notification, content.firstChild);
        
        setTimeout(() => {
          notification.remove();
        }, 5000);
      }
    });
    
    // Global function to remove uploaded image
    function removeUploadedImage(inputId, side) {
      const fileInput = document.getElementById(inputId);
      const uploadBody = document.getElementById(`uploadBody${side.charAt(0).toUpperCase() + side.slice(1)}`);
      
      fileInput.value = '';
      uploadBody.innerHTML = `
        <div class="file-upload-text">
          Upload a clear photo of the ${side} side of your Staff ID showing your photo and details.
        </div>
        <label for="${inputId}" class="file-upload-btn">
          <i class="fas fa-upload"></i> Choose File
        </label>
        <div class="file-requirements">
          <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: 2MB
        </div>
      `;
      uploadBody.classList.remove('has-preview');
    }
  </script>
</body>
</html>