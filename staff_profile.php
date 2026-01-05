<?php
// staff_profile.php
session_start();

// Check if user is logged in and is a staff member
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'staff') {
    header("Location: index.php");
    exit();
}

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

$error = '';
$success = '';
$user = [];
$notifications = [];
$unread_notif_count = 0;

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get user data with staff information
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT u.*, s.staff_department, s.staff_position, s.staff_employee_id, s.staff_id_photo
        FROM users u 
        JOIN staff s ON u.usr_id = s.staff_usr_id
        WHERE u.usr_id = :user_id
    ");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // If no user record found, redirect to login
        header("Location: index.php");
        exit();
    }
    
    // Parse address information if available
    $address_components = [
        'region' => '',
        'province' => '',
        'city' => '',
        'barangay' => '',
        'zip_code' => '',
        'street_address' => ''
    ];
    
    if (!empty($user['usr_address'])) {
        $address_parts = explode(', ', $user['usr_address']);
        
        // Simple parsing logic - in a real application, you'd want more sophisticated parsing
        if (count($address_parts) >= 5) {
            $address_components['street_address'] = $address_parts[0] ?? '';
            $address_components['barangay'] = $address_parts[1] ?? '';
            $address_components['city'] = $address_parts[2] ?? '';
            $address_components['province'] = $address_parts[3] ?? '';
            $address_components['region'] = $address_parts[4] ?? '';
            
            // Extract zip code if present
            if (count($address_parts) > 5) {
                $address_components['zip_code'] = $address_parts[5] ?? '';
            }
        }
    }
    
    // ============================================================================
    // ENHANCED NOTIFICATION GENERATION SYSTEM FOR STAFF
    // ============================================================================
    
    /**
     * Function to create notifications for staff
     */
    function createStaffNotification($conn, $staff_id, $type, $message, $related_id = null) {
        try {
            $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                                   VALUES (?, ?, ?, NOW())");
            $stmt->execute([$staff_id, $message, $type]);
            return true;
        } catch (PDOException $e) {
            error_log("Staff notification creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check for pending job posts and generate notifications
     */
    function checkPendingJobs($conn, $staff_id) {
        $notificationsGenerated = 0;
        
        // Check for pending job posts
        $pendingJobs = $conn->prepare("
            SELECT COUNT(*) as pending_count 
            FROM jobs 
            WHERE job_status = 'pending'
        ");
        $pendingJobs->execute();
        $pending_count = $pendingJobs->fetch(PDO::FETCH_ASSOC)['pending_count'];
        
        if ($pending_count > 0) {
            // Check if notification already exists
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'pending_jobs'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
            ");
            $existingNotif->execute([$staff_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "You have {$pending_count} job posts pending validation";
                
                if (createStaffNotification($conn, $staff_id, 'pending_jobs', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for new employer registrations needing approval
     */
    function checkPendingEmployers($conn, $staff_id) {
        $notificationsGenerated = 0;
        
        // Check for unapproved employers
        $pendingEmployers = $conn->prepare("
            SELECT COUNT(*) as pending_count 
            FROM users 
            WHERE usr_role = 'employer' 
            AND usr_is_approved = FALSE
            AND usr_account_status = 'active'
        ");
        $pendingEmployers->execute();
        $pending_count = $pendingEmployers->fetch(PDO::FETCH_ASSOC)['pending_count'];
        
        if ($pending_count > 0) {
            // Check if notification already exists
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'pending_employers'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
            ");
            $existingNotif->execute([$staff_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "{$pending_count} employer accounts awaiting approval";
                
                if (createStaffNotification($conn, $staff_id, 'pending_employers', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for graduates needing assistance
     */
    function checkGraduatesNeedingHelp($conn, $staff_id) {
        $notificationsGenerated = 0;
        
        // Check for unmatched graduates
        $unmatchedGrads = $conn->prepare("
            SELECT COUNT(DISTINCT u.usr_id) as unmatched_count
            FROM users u
            JOIN graduates g ON u.usr_id = g.grad_usr_id
            LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
            WHERE a.app_id IS NULL 
            AND u.usr_role = 'graduate'
            AND u.usr_account_status = 'active'
        ");
        $unmatchedGrads->execute();
        $unmatched_count = $unmatchedGrads->fetch(PDO::FETCH_ASSOC)['unmatched_count'];
        
        if ($unmatched_count > 5) { // Only notify if significant number
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'unmatched_graduates'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
            ");
            $existingNotif->execute([$staff_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "{$unmatched_count} graduates have no job applications and may need assistance";
                
                if (createStaffNotification($conn, $staff_id, 'unmatched_graduates', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        // Check for graduates with incomplete portfolios
        $incompletePortfolios = $conn->prepare("
            SELECT COUNT(DISTINCT u.usr_id) as incomplete_count
            FROM users u
            JOIN graduates g ON u.usr_id = g.grad_usr_id
            LEFT JOIN portfolio_items p ON u.usr_id = p.port_usr_id
            WHERE p.port_id IS NULL 
            AND u.usr_role = 'graduate'
            AND u.usr_account_status = 'active'
        ");
        $incompletePortfolios->execute();
        $incomplete_count = $incompletePortfolios->fetch(PDO::FETCH_ASSOC)['incomplete_count'];
        
        if ($incomplete_count > 5) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'incomplete_portfolios'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
            ");
            $existingNotif->execute([$staff_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "{$incomplete_count} graduates have incomplete portfolios";
                
                if (createStaffNotification($conn, $staff_id, 'incomplete_portfolios', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for system alerts and reports
     */
    function checkSystemAlerts($conn, $staff_id) {
        $notificationsGenerated = 0;
        
        // Check for recent graduate activity drop
        $recentActivity = $conn->prepare("
            SELECT COUNT(*) as active_count 
            FROM users 
            WHERE usr_last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND usr_role = 'graduate'
            AND usr_account_status = 'active'
        ");
        $recentActivity->execute();
        $active_count = $recentActivity->fetch(PDO::FETCH_ASSOC)['active_count'];
        
        $totalGraduates = $conn->prepare("
            SELECT COUNT(*) as total_count 
            FROM users 
            WHERE usr_role = 'graduate'
            AND usr_account_status = 'active'
        ");
        $totalGraduates->execute();
        $total_count = $totalGraduates->fetch(PDO::FETCH_ASSOC)['total_count'];
        
        $activity_rate = $total_count > 0 ? ($active_count / $total_count) * 100 : 0;
        
        if ($activity_rate < 30) { // If less than 30% active in last week
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'low_activity'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $existingNotif->execute([$staff_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Low graduate activity: Only " . round($activity_rate) . "% of graduates active this week";
                
                if (createStaffNotification($conn, $staff_id, 'low_activity', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for successful matches and achievements
     */
    function checkSuccessStories($conn, $staff_id) {
        $notificationsGenerated = 0;
        
        // Check for recent hires
        $recentHires = $conn->prepare("
            SELECT COUNT(*) as hires_count 
            FROM applications 
            WHERE app_status = 'hired'
            AND app_updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $recentHires->execute();
        $hires_count = $recentHires->fetch(PDO::FETCH_ASSOC)['hires_count'];
        
        if ($hires_count > 0) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'recent_hires'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $existingNotif->execute([$staff_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Great news! {$hires_count} graduates were hired this week";
                
                if (createStaffNotification($conn, $staff_id, 'recent_hires', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Enhanced system notifications generator for staff
     */
    function generateStaffNotifications($conn, $staff_id) {
        $totalNotifications = 0;
        
        // 1. Check for pending job posts
        $totalNotifications += checkPendingJobs($conn, $staff_id);
        
        // 2. Check for pending employer approvals
        $totalNotifications += checkPendingEmployers($conn, $staff_id);
        
        // 3. Check for graduates needing assistance
        $totalNotifications += checkGraduatesNeedingHelp($conn, $staff_id);
        
        // 4. Check for system alerts
        $totalNotifications += checkSystemAlerts($conn, $staff_id);
        
        // 5. Check for success stories
        $totalNotifications += checkSuccessStories($conn, $staff_id);
        
        return $totalNotifications;
    }
    
    // Generate notifications for staff
    $notificationsGenerated = generateStaffNotifications($conn, $user_id);
    
    // Handle mark as read for notifications
    if (isset($_POST['mark_all_read']) && $_POST['mark_all_read'] == '1') {
        try {
            $update_stmt = $conn->prepare("
                UPDATE notifications 
                SET notif_is_read = 1 
                WHERE notif_usr_id = :user_id AND notif_is_read = 0
            ");
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();
            
            // Refresh page to show updated notifications
            header("Location: staff_profile.php");
            exit();
        } catch (PDOException $e) {
            error_log("Mark notifications as read error: " . $e->getMessage());
        }
    }
    
    // Handle mark single notification as read via AJAX
    if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
        $notif_id = $_POST['notif_id'];
        try {
            $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :user_id");
            $mark_read_stmt->bindParam(':notif_id', $notif_id);
            $mark_read_stmt->bindParam(':user_id', $user_id);
            $mark_read_stmt->execute();
            
            // Return success response
            echo json_encode(['success' => true]);
            exit();
        } catch (PDOException $e) {
            error_log("Mark single notification as read error: " . $e->getMessage());
            echo json_encode(['success' => false]);
            exit();
        }
    }
    
    // Parse full name into components for form
    $last_name = '';
    $first_name = '';
    $middle_name = '';

    if (!empty($user['usr_name'])) {
        $name_parts = explode(' ', $user['usr_name']);
        $name_count = count($name_parts);
        
        if ($name_count >= 1) {
            $last_name = $name_parts[$name_count - 1]; // Last part is last name
        }
        if ($name_count >= 2) {
            $first_name = $name_parts[0]; // First part is first name
        }
        if ($name_count >= 3) {
            // Everything in between is middle name
            $middle_name = implode(' ', array_slice($name_parts, 1, $name_count - 2));
        }
    }

    // Parse phone number for form display
    $country_code = '+63';
    $phone_number = '';

    if (!empty($user['usr_phone'])) {
        $phone_parts = explode(' ', $user['usr_phone'], 2);
        if (count($phone_parts) >= 2) {
            $country_code = $phone_parts[0];
            $phone_number = $phone_parts[1];
        } else {
            $phone_number = $user['usr_phone'];
        }
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_profile'])) {
            $last_name = trim($_POST['last_name'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $middle_name = trim($_POST['middle_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $country_code = trim($_POST['country_code'] ?? '+63');
            $phone = trim($_POST['phone'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $birthdate = trim($_POST['birthdate'] ?? '');
            
            // Enhanced phone number validation - CHANGED TO 11 DIGITS
            $phone = preg_replace('/[^0-9]/', '', $phone); // Remove any non-numeric characters
            
            // Validate phone number length - CHANGED TO 11 DIGITS
            if (!empty($phone) && (strlen($phone) < 11 || strlen($phone) > 11)) {
                $error = "Phone number must be exactly 11 digits.";
            }
            
            // Address fields
            $region = trim($_POST['region'] ?? '');
            $province = trim($_POST['province'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $barangay = trim($_POST['barangay'] ?? '');
            $zip_code = trim($_POST['zip_code'] ?? '');
            $street_address = trim($_POST['street_address'] ?? '');
            
            // Combine names into full name
            $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : "") . "$last_name");
            
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
            
            // Validate inputs
            if (empty($last_name) || empty($first_name) || empty($email)) {
                $error = "All required fields must be filled.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } elseif (!empty($phone) && !preg_match('/^[0-9]{11}$/', $phone)) {
                $error = "Please enter a valid phone number (exactly 11 digits).";
            } else {
                try {
                    // Check if email is already in use by another user
                    $email_check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_email = :email AND usr_id != :user_id");
                    $email_check_stmt->bindParam(':email', $email);
                    $email_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                    $email_check_stmt->execute();
                    
                    if ($email_check_stmt->rowCount() > 0) {
                        $error = "This email address is already registered with another account. Please use a different email address.";
                    } else {
                        // Check if phone number is already in use by another user
                        $phone_check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_phone = :phone AND usr_id != :user_id");
                        $phone_check_stmt->bindParam(':phone', $full_phone);
                        $phone_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                        $phone_check_stmt->execute();
                        
                        if ($phone_check_stmt->rowCount() > 0) {
                            $error = "This phone number is already registered with another account. Please use a different phone number.";
                        } else {
                            // Update user information with address
                            $update_stmt = $conn->prepare("
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
                            
                            $update_stmt->bindParam(':name', $full_name);
                            $update_stmt->bindParam(':email', $email);
                            $update_stmt->bindParam(':phone', $full_phone);
                            $update_stmt->bindParam(':gender', $gender);
                            $update_stmt->bindParam(':birthdate', $birthdate);
                            $update_stmt->bindParam(':address', $permanent_address);
                            $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                            
                            if ($update_stmt->execute()) {
                                $success = "Profile updated successfully!";
                                
                                // Refresh user data
                                $stmt->execute();
                                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                // Update session user name
                                $_SESSION['user_name'] = $full_name;
                                
                                // Update address components for display
                                $address_components['region'] = $region;
                                $address_components['province'] = $province;
                                $address_components['city'] = $city;
                                $address_components['barangay'] = $barangay;
                                $address_components['zip_code'] = $zip_code;
                                $address_components['street_address'] = $street_address;
                            } else {
                                $error = "Failed to update profile information.";
                            }
                        }
                    }
                } catch (PDOException $e) {
                    error_log("staff_profile.php: Database error - " . $e->getMessage());
                    $error = "Database error. Please try again.";
                }
            }
        }
        
        // Handle cropped image upload
        if (isset($_POST['cropped_image_data']) && !empty($_POST['cropped_image_data'])) {
            $cropped_image_data = $_POST['cropped_image_data'];
            
            // Remove the "data:image/png;base64," part
            $cropped_image_data = str_replace('data:image/png;base64,', '', $cropped_image_data);
            $cropped_image_data = str_replace(' ', '+', $cropped_image_data);
            
            // Decode the base64 data
            $cropped_image = base64_decode($cropped_image_data);
            
            if ($cropped_image !== false) {
                $upload_dir = 'uploads/profile_photos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'profile_' . $user_id . '_' . time() . '.png';
                $target_path = $upload_dir . $new_filename;
                
                if (file_put_contents($target_path, $cropped_image)) {
                    // Update user profile photo in database
                    $photo_update_stmt = $conn->prepare("
                        UPDATE users 
                        SET usr_profile_photo = :profile_photo,
                            usr_updated_at = NOW()
                        WHERE usr_id = :user_id
                    ");
                    
                    $photo_update_stmt->bindParam(':profile_photo', $target_path);
                    $photo_update_stmt->bindParam(':user_id', $user_id);
                    
                    if ($photo_update_stmt->execute()) {
                        $success = "Profile photo updated successfully!";
                        
                        // Delete old profile photo if exists
                        if (!empty($user['usr_profile_photo']) && file_exists($user['usr_profile_photo'])) {
                            unlink($user['usr_profile_photo']);
                        }
                        
                        // Refresh user data
                        $stmt->execute();
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $error = "Failed to update profile photo in database.";
                    }
                } else {
                    $error = "Failed to save cropped profile photo. Please try again.";
                }
            } else {
                $error = "Invalid cropped image data. Please try again.";
            }
        }
        
        // Handle profile photo upload (legacy method - kept for compatibility)
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $profile_photo = $_FILES['profile_photo'];
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            $file_ext = strtolower(pathinfo($profile_photo['name'], PATHINFO_EXTENSION));
            $file_size = $profile_photo['size'];
            
            if (in_array($file_ext, $allowed_types)) {
                if ($file_size <= $max_size) {
                    $upload_dir = 'uploads/profile_photos/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($profile_photo['tmp_name'], $target_path)) {
                        // Update user profile photo in database
                        $photo_update_stmt = $conn->prepare("
                            UPDATE users 
                            SET usr_profile_photo = :profile_photo,
                                usr_updated_at = NOW()
                            WHERE usr_id = :user_id
                        ");
                        
                        $photo_update_stmt->bindParam(':profile_photo', $target_path);
                        $photo_update_stmt->bindParam(':user_id', $user_id);
                        
                        if ($photo_update_stmt->execute()) {
                            $success = "Profile photo updated successfully!";
                            
                            // Delete old profile photo if exists
                            if (!empty($user['usr_profile_photo']) && file_exists($user['usr_profile_photo'])) {
                                unlink($user['usr_profile_photo']);
                            }
                            
                            // Refresh user data
                            $stmt->execute();
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        } else {
                            $error = "Failed to update profile photo in database.";
                        }
                    } else {
                        $error = "Failed to upload profile photo. Please try again.";
                    }
                } else {
                    $error = "Profile photo file is too large. Maximum size is 2MB.";
                }
            } else {
                $error = "Invalid file type for profile photo. Allowed types: JPG, JPEG, PNG, GIF.";
            }
        }
    }
    
    // Get comprehensive notifications for staff
    $notifications = [];
    $unread_notif_count = 0;
    try {
        $notif_stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE notif_usr_id = :user_id 
            ORDER BY notif_created_at DESC 
            LIMIT 15
        ");
        $notif_stmt->bindParam(':user_id', $user_id);
        $notif_stmt->execute();
        $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notifications as $notif) {
            if (!$notif['notif_is_read']) $unread_notif_count++;
        }
        
        // If no notifications found, create some default ones based on system status
        if (empty($notifications)) {
            $notifications = generateDefaultStaffNotifications($conn, $user_id);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Staff notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultStaffNotifications($conn, $user_id);
        $unread_notif_count = count($notifications);
    }
    
} catch (PDOException $e) {
    $error = "Database Connection Failed: " . $e->getMessage();
}

/**
 * Generate default notifications for staff based on system status
 */
function generateDefaultStaffNotifications($conn, $user_id) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Staff Profile! Keep your profile information up to date.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Profile reminder notification
    $default_notifications[] = [
        'notif_message' => 'Make sure your profile information is complete and up to date.',
        'notif_type' => 'profile_reminder',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
    ];
    
    return $default_notifications;
}

/**
 * Get appropriate icon for staff notification type
 */
function getStaffNotificationIcon($type) {
    switch ($type) {
        case 'pending_jobs':
            return 'fas fa-file-alt';
        case 'pending_employers':
            return 'fas fa-building';
        case 'unmatched_graduates':
            return 'fas fa-user-graduate';
        case 'incomplete_portfolios':
            return 'fas fa-file-contract';
        case 'low_activity':
            return 'fas fa-chart-line';
        case 'recent_hires':
            return 'fas fa-trophy';
        case 'profile_reminder':
            return 'fas fa-user-check';
        case 'system':
        default:
            return 'fas fa-info-circle';
    }
}

/**
 * Get notification priority based on type for staff
 */
function getStaffNotificationPriority($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'pending_jobs') !== false || 
        strpos($type, 'pending_employers') !== false) {
        return 'high';
    } elseif (strpos($type, 'unmatched_graduates') !== false || 
              strpos($type, 'incomplete_portfolios') !== false) {
        return 'medium';
    } else {
        return 'low';
    }
}

/**
 * Function to determine where a staff notification should link to based on its content
 */
function getStaffNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'job') !== false || strpos($message, 'job') !== false) {
        return 'staff_validate_jobs.php';
    } elseif (strpos($type, 'employer') !== false || strpos($message, 'employer') !== false) {
        return 'staff_manage_emp.php';
    } elseif (strpos($type, 'graduate') !== false || strpos($message, 'graduate') !== false) {
        return 'staff_assist_grads.php';
    } elseif (strpos($type, 'portfolio') !== false || strpos($message, 'portfolio') !== false) {
        return 'staff_assist_grads.php';
    } elseif (strpos($type, 'analytics') !== false || strpos($message, 'report') !== false) {
        return 'staff_analytics.php';
    } elseif (strpos($type, 'profile') !== false || strpos($message, 'profile') !== false) {
        return 'staff_profile.php';
    } else {
        return 'staff_dashboard.php';
    }
}

// Get profile photo path or use default
$profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($user['usr_name']) . "&background=6e0303&color=fff";
if (!empty($user['usr_profile_photo']) && file_exists($user['usr_profile_photo'])) {
    $profile_photo = $user['usr_profile_photo'];
}

// Get staff ID photos if available
$staff_id_photos = [];
if (!empty($user['staff_id_photo'])) {
    $staff_id_photos = json_decode($user['staff_id_photo'], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Staff Profile</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
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
      --sidebar-bg: var(--primary-color);
      --sidebar-hover: #8a0404;
      --sidebar-active: #5a0202;
    }
    
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    body {
      display: flex;
      background-color: #f5f6f8;
      min-height: 100vh;
      color: var(--text-color);
    }
    
    /* Enhanced Sidebar Styles */
    .sidebar {
      width: 280px;
      background: linear-gradient(180deg, var(--sidebar-bg) 0%, #4a0202 100%);
      color: white;
      height: 100vh;
      position: fixed;
      padding: 0;
      transition: all 0.3s;
      z-index: 100;
      box-shadow: 3px 0 15px rgba(0, 0, 0, 0.2);
      overflow-y: auto;
    }
    
    .sidebar-header {
      padding: 25px 25px 20px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.15);
      display: flex;
      align-items: center;
      background: rgba(0, 0, 0, 0.1);
    }
    
    .sidebar-header img {
      width: 50px;
      height: 50px;
      margin-right: 15px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(255, 255, 255, 0.3);
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
    }
    
    .sidebar-header-text {
      flex: 1;
    }
    
    .sidebar-header h3 {
      color: white;
      font-size: 1.2rem;
      font-weight: 600;
      margin-bottom: 4px;
    }
    
    .staff-role {
      font-size: 0.8rem;
      opacity: 0.8;
      background: rgba(255, 255, 255, 0.1);
      padding: 3px 8px;
      border-radius: 12px;
      display: inline-block;
    }
    
    .sidebar-menu {
      padding: 20px 0;
    }
    
    .sidebar-menu ul {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    
    .sidebar-menu li {
      margin-bottom: 5px;
    }
    
    .sidebar-menu a {
      display: flex;
      align-items: center;
      padding: 14px 25px;
      color: rgba(255, 255, 255, 0.85);
      text-decoration: none;
      transition: all 0.3s;
      position: relative;
      font-weight: 500;
    }
    
    .sidebar-menu a:hover {
      background-color: var(--sidebar-hover);
      color: white;
      padding-left: 30px;
    }
    
    .sidebar-menu a.active {
      background-color: var(--sidebar-active);
      color: white;
      border-left: 4px solid var(--secondary-color);
      box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.1);
    }
    
    .sidebar-menu a.active::before {
      content: '';
      position: absolute;
      right: 15px;
      width: 8px;
      height: 8px;
      background-color: var(--secondary-color);
      border-radius: 50%;
    }
    
    .sidebar-menu i {
      margin-right: 12px;
      font-size: 1.1rem;
      width: 20px;
      text-align: center;
    }
    
    .menu-label {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 15px 25px 10px;
      color: rgba(255, 255, 255, 0.6);
      font-weight: 600;
    }
    
    /* Main Content Styles */
    .main-content {
      margin-left: 280px;
      width: calc(100% - 280px);
      padding: 20px;
      transition: all 0.3s;
      background-color: var(--light-text);
    }
    
    /* Enhanced Top Navigation */
    .top-nav {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      padding: 15px 25px;
      background: white;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
      border-radius: 10px;
      margin-bottom: 25px;
    }
    
    .nav-right {
      display: flex;
      align-items: center;
    }
    
    .notification, .staff-profile {
      position: relative;
      margin-left: 20px;
      cursor: pointer;
    }
    
    .notification i, .staff-profile i {
      font-size: 1.3rem;
      color: var(--primary-color);
      transition: all 0.3s;
    }
    
    .notification:hover i, .staff-profile:hover i {
      transform: scale(1.1);
    }
    
    .notification-count {
      position: absolute;
      top: -5px;
      right: -5px;
      background: linear-gradient(135deg, var(--red), #e74c3c);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      font-weight: 600;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
    }
    
    .staff-profile {
      display: flex;
      align-items: center;
      padding: 8px 15px;
      border-radius: 30px;
      transition: all 0.3s;
      background: rgba(110, 3, 3, 0.05);
    }
    
    .staff-profile:hover {
      background: rgba(110, 3, 3, 0.1);
    }
    
    .staff-profile img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 12px;
      object-fit: cover;
      border: 2px solid var(--primary-color);
    }
    
    .staff-name {
      font-weight: 600;
      color: var(--primary-color);
      margin-right: 8px;
    }
    
    .staff-profile i.fa-chevron-down {
      font-size: 0.9rem;
      color: #777;
    }
    
    /* Enhanced Dropdown Styles */
    .dropdown {
      position: absolute;
      top: 50px;
      right: 0;
      background-color: white;
      box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
      border-radius: 10px;
      z-index: 100;
      display: none;
      overflow: hidden;
      border: 1px solid #eee;
    }
    
    .notification-dropdown {
      width: 450px;
      max-height: 500px;
      overflow-y: auto;
    }
    
    .profile-dropdown {
      width: 220px;
    }
    
    .dropdown.active {
      display: block;
      animation: fadeIn 0.2s ease;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .dropdown-header {
      padding: 18px 20px;
      border-bottom: 1px solid #eee;
      font-weight: 700;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
    }
    
    .mark-all-read {
      background: none;
      border: none;
      color: var(--secondary-color);
      cursor: pointer;
      font-size: 0.8rem;
      text-decoration: underline;
      font-weight: 500;
    }
    
    .dropdown-item {
      padding: 15px 20px;
      border-bottom: 1px solid #f0f0f0;
      cursor: pointer;
      display: block;
      color: #333;
      text-decoration: none;
      transition: background-color 0.2s;
    }
    
    .dropdown-item:hover {
      background-color: #fff5e6;
    }
    
    .dropdown-item:last-child {
      border-bottom: none;
    }
    
    .dropdown-item i {
      margin-right: 10px;
      color: var(--primary-color);
      width: 18px;
      text-align: center;
    }
    
    .notification-time {
      font-size: 0.8rem;
      color: #999;
      margin-top: 5px;
    }
    
    /* Enhanced Notification Items */
    .notification-link {
      padding: 0;
      border-bottom: 1px solid #f0f0f0;
      display: block;
      color: #333;
      text-decoration: none;
      transition: background-color 0.2s;
    }
    
    .notification-link:hover {
      background-color: #fff5e6;
    }
    
    .notification-link:last-child {
      border-bottom: none;
    }
    
    .notification-link.unread {
      background-color: #fff5e6;
      border-left: 3px solid var(--secondary-color);
    }
    
    .notification-item {
      display: flex;
      align-items: flex-start;
      padding: 15px 20px;
    }
    
    .notification-icon {
      margin-right: 12px;
      color: var(--primary-color);
      font-size: 1.2rem;
      min-width: 24px;
      margin-top: 2px;
    }
    
    .notification-content {
      flex: 1;
    }
    
    .notification-message {
      margin-bottom: 4px;
      line-height: 1.4;
      word-wrap: break-word;
      font-size: 0.9rem;
    }
    
    .notification-priority {
      display: inline-block;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      margin-right: 8px;
    }
    
    .priority-high {
      background-color: var(--red);
    }
    
    .priority-medium {
      background-color: var(--secondary-color);
    }
    
    .priority-low {
      background-color: var(--green);
    }
    
    .no-notifications {
      padding: 30px 20px;
      text-align: center;
      color: #999;
    }
    
    .no-notifications i {
      font-size: 2rem;
      margin-bottom: 10px;
      color: #ddd;
    }
    
    /* Enhanced Welcome Message Styles */
    .welcome-message {
      margin-bottom: 25px;
      padding: 20px 25px;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      position: relative;
      overflow: hidden;
    }
    
    .welcome-message::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 150px;
      height: 150px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 50%;
      transform: translate(40%, -40%);
    }
    
    .welcome-message h2 {
      margin-bottom: 8px;
      font-size: 1.6rem;
      font-weight: 600;
    }
    
    .welcome-message p {
      margin: 0;
      opacity: 0.9;
      font-size: 1rem;
    }
    
    /* Page Header */
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
    }
    
    .page-title {
      font-size: 1.9rem;
      color: var(--primary-color);
      font-weight: 700;
      position: relative;
      padding-bottom: 10px;
    }
    
    /* Enhanced Profile Container */
    .profile-container {
      display: grid;
      grid-template-columns: 1fr 1.5fr;
      gap: 25px;
      margin-bottom: 30px;
    }
    
    /* Enhanced Card Styles */
    .card {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
      padding: 25px;
      transition: all 0.3s;
      border-top: 4px solid var(--secondary-color);
      position: relative;
      overflow: hidden;
    }
    
    .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 18px;
    }
    
    .card-title {
      font-size: 1.1rem;
      color: var(--primary-color);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .card-icon {
      font-size: 1.5rem;
      color: var(--secondary-color);
      opacity: 0.8;
    }
    
    /* Profile Header Styles */
    .profile-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      margin-bottom: 25px;
      padding-bottom: 20px;
      border-bottom: 1px solid #f0f0f0;
    }
    
    .profile-avatar {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      object-fit: cover;
      border: 4px solid var(--primary-color);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
      margin-bottom: 15px;
      transition: all 0.3s;
    }
    
    .profile-avatar:hover {
      transform: scale(1.05);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
    }
    
    .profile-info h2 {
      margin-bottom: 8px;
      color: var(--primary-color);
      font-size: 1.4rem;
      font-weight: 600;
    }
    
    .profile-info p {
      color: #666;
      margin-bottom: 5px;
      font-size: 0.95rem;
    }
    
    .profile-role {
      display: inline-block;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      padding: 5px 15px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 500;
      margin-top: 8px;
      box-shadow: 0 2px 8px rgba(110, 3, 3, 0.2);
    }
    
    /* Enhanced Form Styles */
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--primary-color);
      font-size: 0.95rem;
    }
    
    .form-control {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      font-size: 1rem;
      transition: all 0.3s;
      background-color: #fafafa;
    }
    
    .form-control:focus {
      outline: none;
      border-color: var(--primary-color);
      background-color: white;
      box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
    }
    
    .btn {
      padding: 12px 25px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 1rem;
      font-weight: 600;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      box-shadow: 0 4px 15px rgba(110, 3, 3, 0.3);
    }
    
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(110, 3, 3, 0.4);
    }
    
    .btn-secondary {
      background: linear-gradient(135deg, #6c757d, #5a6268);
      color: white;
      box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
    }
    
    .btn-secondary:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }
    
    /* Enhanced Photo Upload Styles */
    .photo-upload {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 25px;
      padding: 20px;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      border-radius: 10px;
      border: 2px dashed #dee2e6;
    }
    
    .file-upload {
      margin-bottom: 15px;
      width: 100%;
      text-align: center;
    }
    
    .file-upload-label {
      display: block;
      margin-bottom: 10px;
      font-weight: 600;
      color: var(--primary-color);
      font-size: 1rem;
    }
    
    .file-input {
      display: none;
    }
    
    .file-custom {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 20px;
      border: 1px solid #ddd;
      border-radius: 8px;
      background: white;
      cursor: pointer;
      transition: all 0.3s;
      margin-bottom: 8px;
      font-weight: 500;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .file-custom:hover {
      background: #f8f9fa;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .file-name {
      margin-left: 10px;
      font-size: 0.9rem;
      color: #666;
      font-style: italic;
    }
    
    .file-requirements {
      font-size: 0.8rem;
      color: #999;
      margin-top: 8px;
      text-align: center;
    }
    
    /* Enhanced Name Container Styles */
    .name-container {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 15px;
    }
    
    /* Enhanced Phone Container Styles */
    .phone-container {
      display: flex;
      gap: 15px;
    }
    
    .country-code {
      width: 140px;
      flex-shrink: 0;
    }
    
    .phone-number {
      flex: 1;
    }
    
    .phone-requirements {
      font-size: 0.8rem;
      color: #666;
      margin-top: 8px;
      font-style: italic;
    }
    
    .required {
      color: var(--red);
    }
    
    /* Enhanced Alert Styles */
    .alert {
      padding: 15px 20px;
      border-radius: 8px;
      margin-bottom: 25px;
      border-left: 4px solid transparent;
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
    }
    
    .alert-success {
      background-color: #f0f9f4;
      color: var(--green);
      border-left-color: var(--green);
    }
    
    .alert-error {
      background-color: #fdf2f2;
      color: var(--red);
      border-left-color: var(--red);
    }
    
    /* Enhanced Profile Stats */
    .profile-stats {
      margin-top: 25px;
    }
    
    .profile-stats h3 {
      margin-bottom: 18px;
      color: var(--primary-color);
      font-size: 1.2rem;
      font-weight: 600;
      border-bottom: 2px solid #f0f0f0;
      padding-bottom: 10px;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 15px;
    }
    
    .stat-item {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      padding: 15px;
      border-radius: 8px;
      text-align: center;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      transition: all 0.3s;
      border-left: 3px solid var(--primary-color);
    }
    
    .stat-item:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .stat-value {
      font-weight: bold;
      color: var(--primary-color);
      font-size: 1.3rem;
      margin-bottom: 5px;
    }
    
    .stat-label {
      font-size: 0.85rem;
      color: #666;
      font-weight: 500;
    }
    
    /* Enhanced Address Container Styles */
    .address-container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
    }
    
    /* Enhanced Staff ID Photos Section */
    .staff-id-section {
      margin-top: 25px;
      padding-top: 20px;
      border-top: 1px solid #f0f0f0;
    }
    
    .staff-id-section h3 {
      margin-bottom: 18px;
      color: var(--primary-color);
      font-size: 1.2rem;
      font-weight: 600;
      border-bottom: 2px solid #f0f0f0;
      padding-bottom: 10px;
    }
    
    .id-photos-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-top: 15px;
    }
    
    .id-photo-card {
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 15px;
      background: #fafafa;
      text-align: center;
    }
    
    .id-photo-card h4 {
      margin-bottom: 10px;
      color: var(--primary-color);
      font-size: 1rem;
    }
    
    .id-photo-preview {
      max-width: 100%;
      max-height: 200px;
      border-radius: 4px;
      border: 1px solid #ddd;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .no-id-photo {
      padding: 30px;
      text-align: center;
      color: #999;
      font-style: italic;
    }
    
    /* Enhanced Cropper Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.8);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(5px);
    }
    
    .modal-content {
      background-color: white;
      border-radius: 15px;
      width: 90%;
      max-width: 800px;
      max-height: 90vh;
      overflow: auto;
      padding: 25px;
      position: relative;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }
    
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid #f0f0f0;
    }
    
    .modal-title {
      font-size: 1.5rem;
      color: var(--primary-color);
      font-weight: 700;
    }
    
    .close-modal {
      background: none;
      border: none;
      font-size: 1.8rem;
      cursor: pointer;
      color: #999;
      transition: all 0.3s;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .close-modal:hover {
      color: var(--primary-color);
      background-color: #f8f9fa;
    }
    
    .cropper-container {
      width: 100%;
      height: 400px;
      margin-bottom: 25px;
      background-color: #f5f5f5;
      border-radius: 10px;
      overflow: hidden;
      border: 2px solid #e0e0e0;
    }
    
    .cropper-preview {
      width: 150px;
      height: 150px;
      border-radius: 50%;
      overflow: hidden;
      margin: 0 auto 20px;
      border: 3px solid var(--primary-color);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }
    
    .cropper-actions {
      display: flex;
      justify-content: center;
      gap: 15px;
      margin-top: 25px;
    }
    
    /* Animation for new notifications */
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.1); }
      100% { transform: scale(1); }
    }
    
    .notification-pulse {
      animation: pulse 1s infinite;
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .profile-container {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 900px) {
      .name-container {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
      }
      
      .address-container {
        grid-template-columns: 1fr;
        gap: 12px;
      }
      
      .id-photos-grid {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 768px) {
      .sidebar {
        width: 70px;
        overflow: hidden;
      }
      
      .sidebar-header h3, .sidebar-menu span, .staff-role, .menu-label {
        display: none;
      }
      
      .sidebar-header {
        justify-content: center;
        padding: 10px 0;
      }
      
      .sidebar-header img {
        margin-right: 0;
      }
      
      .sidebar-menu a {
        justify-content: center;
        padding: 15px 0;
      }
      
      .sidebar-menu i {
        margin-right: 0;
        font-size: 1.3rem;
      }
      
      .main-content {
        margin-left: 70px;
        width: calc(100% - 70px);
      }
      
      .top-nav {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
      }
      
      .notification-dropdown {
        width: 350px;
        right: -100px;
      }
      
      .name-container {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .phone-container {
        flex-direction: column;
        gap: 10px;
      }
      
      .country-code {
        width: 100%;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .modal-content {
        width: 95%;
        padding: 20px;
      }
      
      .cropper-container {
        height: 300px;
      }
      
      .cropper-actions {
        flex-direction: column;
        gap: 10px;
      }
      
      .cropper-actions .btn {
        width: 100%;
      }
    }
    
    @media (max-width: 576px) {
      .main-content {
        padding: 15px;
      }
      
      .card {
        padding: 20px;
      }
      
      .notification-dropdown {
        width: 300px;
        right: -140px;
      }
      
      .profile-avatar {
        width: 100px;
        height: 100px;
      }
    }
  </style>
</head>
<body>
  <!-- Enhanced Sidebar -->
  <div class="sidebar">
    <div class="sidebar-header">
      <img src="<?= $profile_photo ?>" alt="Staff Avatar" id="sidebarAvatar">
      <div class="sidebar-header-text">
        <h3><?= htmlspecialchars($user['usr_name']) ?></h3>
        <div class="staff-role">Staff</div>
      </div>
    </div>
    <div class="sidebar-menu">
      <div class="menu-label">Main Navigation</div>
      <ul>
        <li>
          <a href="staff_dashboard.php">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
          </a>
        </li>
        <li>
          <a href="staff_assist_grads.php">
            <i class="fas fa-graduation-cap"></i>
            <span>Assist Alumni</span>
          </a>
        </li>
        <li>
          <a href="staff_track_stats.php">
            <i class="fas fa-location-dot"></i>
            <span>Track Alumni</span>
          </a>
        </li>
        <li>
          <a href="staff_validate_jobs.php">
            <i class="fas fa-check"></i>
            <span>Validate Jobs</span>
          </a>
        </li>
        <li>
          <a href="staff_manage_emp.php">
            <i class="fas fa-handshake"></i>
            <span>Manage Employers</span>
          </a>
        </li>
        <li>
          <a href="staff_analytics.php">
            <i class="fas fa-chart-bar"></i>
            <span>Analytics</span>
          </a>
        </li>
      </ul>
    </div>
  </div>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Enhanced Top Navigation -->
    <div class="top-nav">
      <div class="nav-right">
        <div class="notification" id="notification">
          <i class="fas fa-bell"></i>
          <?php if ($unread_notif_count > 0): ?>
          <span class="notification-count" id="notificationCount"><?= $unread_notif_count ?></span>
          <?php endif; ?>
          <div class="dropdown notification-dropdown" id="notificationDropdown">
            <div class="dropdown-header">
              Notifications (<?= count($notifications) ?>)
              <?php if ($unread_notif_count > 0): ?>
              <form method="POST" style="display: inline;">
                <input type="hidden" name="mark_all_read" value="1">
                <button type="submit" class="mark-all-read">Mark all as read</button>
              </form>
              <?php endif; ?>
            </div>
            <?php if (!empty($notifications)): ?>
              <?php foreach ($notifications as $notif): 
                $notification_link = getStaffNotificationLink($notif);
                $notification_icon = getStaffNotificationIcon($notif['notif_type']);
                $priority_class = 'priority-' . getStaffNotificationPriority($notif);
              ?>
              <a href="<?= $notification_link ?>" class="notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?? '' ?>">
                <div class="notification-item">
                  <div class="notification-icon">
                    <i class="<?= $notification_icon ?>"></i>
                  </div>
                  <div class="notification-content">
                    <div>
                      <span class="notification-priority <?= $priority_class ?>"></span>
                      <?= htmlspecialchars($notif['notif_message']) ?>
                    </div>
                    <div class="notification-time">
                      <?= date('M j, Y g:i A', strtotime($notif['notif_created_at'])) ?>
                    </div>
                  </div>
                </div>
              </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="no-notifications">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="staff-profile" id="staffProfile">
          <img src="<?= $profile_photo ?>" alt="profile" id="topNavAvatar">
          <span class="staff-name"><?= htmlspecialchars($user['usr_name']) ?></span>
          <i class="fas fa-chevron-down"></i>
          <div class="dropdown profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">Account Menu</div>
            <a href="staff_profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
            <a href="staff_changepass.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
            <div class="dropdown-item" style="border-top: 1px solid #eee; margin-top: 5px; padding-top: 15px;">
              <i class="fas fa-user-circle"></i> Logged in as: <strong><?= htmlspecialchars($user['usr_name']) ?></strong>
            </div>
            <a href="index.php" class="dropdown-item" style="color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Enhanced Welcome Message -->
    <div class="welcome-message">
      <h2>Welcome back, <?= htmlspecialchars($user['usr_name']) ?>!</h2>
      <p>Manage your profile information and settings. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Staff Profile</h1>
    </div>
    
    <?php if (!empty($success)): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>
    
    <form action="staff_profile.php" method="POST" enctype="multipart/form-data" id="profileForm">
      <div class="profile-container">
        <!-- Left Column: Profile Information -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Profile Information</h3>
            <i class="fas fa-user card-icon"></i>
          </div>
          
          <div class="profile-header">
            <img src="<?= $profile_photo ?>" alt="Staff Avatar" class="profile-avatar" id="profilePhotoPreview">
            <div class="profile-info">
              <h2><?= htmlspecialchars($user['usr_name']) ?></h2>
              <p><?= htmlspecialchars($user['usr_email']) ?></p>
              <p><i class="fas fa-id-badge"></i> <?= htmlspecialchars($user['staff_employee_id']) ?></p>
              <span class="profile-role"><?= htmlspecialchars($user['staff_position']) ?></span>
            </div>
          </div>
          
          <div class="photo-upload">
            <div class="file-upload">
              <label class="file-upload-label">Upload New Photo</label>
              <label class="file-custom">
                <i class="fas fa-camera"></i> Choose File
                <input type="file" class="file-input" name="profile_photo" id="profile_photo" accept=".jpg,.jpeg,.png,.gif">
              </label>
              <span class="file-name" id="profile_photo_name">No file chosen</span>
              <div class="file-requirements">Accepted formats: JPG, JPEG, PNG, GIF. Max size: 2MB</div>
            </div>
          </div>
          
          <div class="profile-stats">
            <h3>Account Details</h3>
            <div class="stats-grid">
              <div class="stat-item">
                <div class="stat-value"><?= ucfirst($user['usr_account_status']) ?></div>
                <div class="stat-label">Account Status</div>
              </div>
              <div class="stat-item">
                <div class="stat-value"><?= htmlspecialchars($user['staff_department']) ?></div>
                <div class="stat-label">Department</div>
              </div>
              <div class="stat-item">
                <div class="stat-value"><?= date('M j, Y', strtotime($user['usr_created_at'])) ?></div>
                <div class="stat-label">Member Since</div>
              </div>
              <div class="stat-item">
                <div class="stat-value">
                  <?= $user['usr_last_login'] ? date('M j, Y', strtotime($user['usr_last_login'])) : 'Never' ?>
                </div>
                <div class="stat-label">Last Login</div>
              </div>
            </div>
          </div>
          
          <!-- Staff ID Photos Section -->
          <?php if (!empty($staff_id_photos)): ?>
          <div class="staff-id-section">
            <h3>Staff ID Verification</h3>
            <div class="id-photos-grid">
              <?php if (!empty($staff_id_photos['front']) && file_exists($staff_id_photos['front'])): ?>
              <div class="id-photo-card">
                <h4>Front of Staff ID</h4>
                <img src="<?= $staff_id_photos['front'] ?>" alt="Front of Staff ID" class="id-photo-preview">
              </div>
              <?php else: ?>
              <div class="id-photo-card no-id-photo">
                <h4>Front of Staff ID</h4>
                <p>No photo available</p>
              </div>
              <?php endif; ?>
              
              <?php if (!empty($staff_id_photos['back']) && file_exists($staff_id_photos['back'])): ?>
              <div class="id-photo-card">
                <h4>Back of Staff ID</h4>
                <img src="<?= $staff_id_photos['back'] ?>" alt="Back of Staff ID" class="id-photo-preview">
              </div>
              <?php else: ?>
              <div class="id-photo-card no-id-photo">
                <h4>Back of Staff ID</h4>
                <p>No photo available</p>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        
        <!-- Right Column: Edit Profile Form -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Edit Profile Information</h3>
            <i class="fas fa-edit card-icon"></i>
          </div>
          
          <div class="form-group">
            <label class="form-label">Full Name <span class="required">*</span></label>
            <div class="name-container">
              <div>
                <label class="form-label" for="last_name">Last Name</label>
                <input type="text" id="last_name" name="last_name" class="form-control" 
                       value="<?= htmlspecialchars($last_name) ?>" 
                       required placeholder="Last Name">
              </div>
              <div>
                <label class="form-label" for="first_name">First Name</label>
                <input type="text" id="first_name" name="first_name" class="form-control" 
                       value="<?= htmlspecialchars($first_name) ?>" 
                       required placeholder="First Name">
              </div>
              <div>
                <label class="form-label" for="middle_name">Middle Name</label>
                <input type="text" id="middle_name" name="middle_name" class="form-control" 
                       value="<?= htmlspecialchars($middle_name) ?>" 
                       placeholder="Middle Name">
              </div>
            </div>
          </div>
          
          <div class="form-group">
            <label for="email" class="form-label">Email Address <span class="required">*</span></label>
            <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($user['usr_email']) ?>" required>
          </div>
          
          <div class="form-group">
            <label for="phone" class="form-label">Phone Number</label>
            <div class="phone-container">
              <div class="country-code">
                <label class="form-label" for="country_code">Country Code</label>
                <select id="country_code" name="country_code" class="form-control">
                  <option value="+63" <?= $country_code == '+63' ? 'selected' : '' ?>>+63 (PH)</option>
                  <option value="+1" <?= $country_code == '+1' ? 'selected' : '' ?>>+1 (US)</option>
                  <option value="+44" <?= $country_code == '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                  <option value="+61" <?= $country_code == '+61' ? 'selected' : '' ?>>+61 (AU)</option>
                  <option value="+65" <?= $country_code == '+65' ? 'selected' : '' ?>>+65 (SG)</option>
                  <option value="+60" <?= $country_code == '+60' ? 'selected' : '' ?>>+60 (MY)</option>
                  <option value="+66" <?= $country_code == '+66' ? 'selected' : '' ?>>+66 (TH)</option>
                  <option value="+84" <?= $country_code == '+84' ? 'selected' : '' ?>>+84 (VN)</option>
                  <option value="+81" <?= $country_code == '+81' ? 'selected' : '' ?>>+81 (JP)</option>
                  <option value="+82" <?= $country_code == '+82' ? 'selected' : '' ?>>+82 (KR)</option>
                  <option value="+86" <?= $country_code == '+86' ? 'selected' : '' ?>>+86 (CN)</option>
                  <option value="+91" <?= $country_code == '+91' ? 'selected' : '' ?>>+91 (IN)</option>
                  <option value="+971" <?= $country_code == '+971' ? 'selected' : '' ?>>+971 (AE)</option>
                  <option value="+973" <?= $country_code == '+973' ? 'selected' : '' ?>>+973 (BH)</option>
                  <option value="+966" <?= $country_code == '+966' ? 'selected' : '' ?>>+966 (SA)</option>
                  <option value="+20" <?= $country_code == '+20' ? 'selected' : '' ?>>+20 (EG)</option>
                  <option value="+27" <?= $country_code == '+27' ? 'selected' : '' ?>>+27 (ZA)</option>
                </select>
              </div>
              <div class="phone-number">
                <label class="form-label" for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="form-control" 
                    value="<?= htmlspecialchars($phone_number) ?>" 
                    placeholder="91234567890"
                    maxlength="11"
                    pattern="[0-9]{11}"
                    title="Please enter exactly 11 digits (numbers only)">
              </div>
            </div>
          </div>
          
          <div class="form-group">
            <label for="gender" class="form-label">Gender <span class="required">*</span></label>
            <select id="gender" name="gender" class="form-control" required>
              <option value="">Select Gender</option>
              <option value="Male" <?= $user['usr_gender'] == 'Male' ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= $user['usr_gender'] == 'Female' ? 'selected' : '' ?>>Female</option>
            </select>
          </div>
          
          <div class="form-group">
            <label for="birthdate" class="form-label">Birthdate <span class="required">*</span></label>
            <input type="date" id="birthdate" name="birthdate" class="form-control" value="<?= $user['usr_birthdate'] ?>" required>
          </div>
          
          <!-- Enhanced Address Information Section -->
          <div class="form-group">
            <label class="form-label">Address Information</label>
            <div class="address-container">
              <div>
                <label class="form-label" for="region">Region</label>
                <select id="region" name="region" class="form-control">
                  <option value="">Select Region</option>
                  <option value="NCR" <?= $address_components['region'] === 'NCR' ? 'selected' : '' ?>>National Capital Region (NCR)</option>
                  <option value="CAR" <?= $address_components['region'] === 'CAR' ? 'selected' : '' ?>>Cordillera Administrative Region (CAR)</option>
                  <option value="Region I" <?= $address_components['region'] === 'Region I' ? 'selected' : '' ?>>Region I - Ilocos Region</option>
                  <option value="Region II" <?= $address_components['region'] === 'Region II' ? 'selected' : '' ?>>Region II - Cagayan Valley</option>
                  <option value="Region III" <?= $address_components['region'] === 'Region III' ? 'selected' : '' ?>>Region III - Central Luzon</option>
                  <option value="Region IV-A" <?= $address_components['region'] === 'Region IV-A' ? 'selected' : '' ?>>Region IV-A - CALABARZON</option>
                  <option value="Region IV-B" <?= $address_components['region'] === 'Region IV-B' ? 'selected' : '' ?>>Region IV-B - MIMAROPA</option>
                  <option value="Region V" <?= $address_components['region'] === 'Region V' ? 'selected' : '' ?>>Region V - Bicol Region</option>
                  <option value="Region VI" <?= $address_components['region'] === 'Region VI' ? 'selected' : '' ?>>Region VI - Western Visayas</option>
                  <option value="Region VII" <?= $address_components['region'] === 'Region VII' ? 'selected' : '' ?>>Region VII - Central Visayas</option>
                  <option value="Region VIII" <?= $address_components['region'] === 'Region VIII' ? 'selected' : '' ?>>Region VIII - Eastern Visayas</option>
                  <option value="Region IX" <?= $address_components['region'] === 'Region IX' ? 'selected' : '' ?>>Region IX - Zamboanga Peninsula</option>
                  <option value="Region X" <?= $address_components['region'] === 'Region X' ? 'selected' : '' ?>>Region X - Northern Mindanao</option>
                  <option value="Region XI" <?= $address_components['region'] === 'Region XI' ? 'selected' : '' ?>>Region XI - Davao Region</option>
                  <option value="Region XII" <?= $address_components['region'] === 'Region XII' ? 'selected' : '' ?>>Region XII - SOCCSKSARGEN</option>
                  <option value="Region XIII" <?= $address_components['region'] === 'Region XIII' ? 'selected' : '' ?>>Region XIII - Caraga</option>
                  <option value="BARMM" <?= $address_components['region'] === 'BARMM' ? 'selected' : '' ?>>Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)</option>
                </select>
              </div>
              <div>
                <label class="form-label" for="province">Province</label>
                <select id="province" name="province" class="form-control">
                  <option value="">Select Province</option>
                </select>
              </div>
            </div>
          </div>
          
          <div class="form-group">
            <div class="address-container">
              <div>
                <label class="form-label" for="city">City/Municipality</label>
                <select id="city" name="city" class="form-control">
                  <option value="">Select City/Municipality</option>
                </select>
              </div>
              <div>
                <label class="form-label" for="barangay">Barangay</label>
                <select id="barangay" name="barangay" class="form-control">
                  <option value="">Select Barangay</option>
                </select>
              </div>
            </div>
          </div>
          
          <div class="form-group">
            <div class="address-container">
              <div>
                <label class="form-label" for="zip_code">Zip Code</label>
                <input type="text" id="zip_code" name="zip_code" class="form-control" 
                       value="<?= htmlspecialchars($address_components['zip_code']) ?>" 
                       placeholder="Zip Code">
              </div>
              <div>
                <label class="form-label" for="street_address">Street Address</label>
                <input type="text" id="street_address" name="street_address" class="form-control" 
                       value="<?= htmlspecialchars($address_components['street_address']) ?>" 
                       placeholder="House No., Street, Subdivision">
              </div>
            </div>
          </div>
          
          <button type="submit" name="update_profile" class="btn btn-primary">
            <i class="fas fa-save"></i> Update Profile
          </button>
        </div>
      </div>
      
      <!-- Hidden field for cropped image data -->
      <input type="hidden" name="cropped_image_data" id="cropped_image_data">
    </form>
  </div>

  <!-- Enhanced Cropper Modal -->
  <div class="modal" id="cropperModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title">Crop Profile Photo</h3>
        <button class="close-modal" id="closeCropperModal">&times;</button>
      </div>
      <div class="cropper-container">
        <img id="imageToCrop" src="" alt="Image to crop" style="max-width: 100%;">
      </div>
      <div class="cropper-preview"></div>
      <div class="cropper-actions">
        <button type="button" class="btn btn-secondary" id="cancelCrop">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="button" class="btn btn-primary" id="saveCrop">
          <i class="fas fa-crop"></i> Save Cropped Image
        </button>
      </div>
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

    // Common Dropdown Functionality
    const notification = document.getElementById('notification');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const staffProfile = document.getElementById('staffProfile');
    const profileDropdown = document.getElementById('profileDropdown');
    
    notification.addEventListener('click', function(e) {
      e.stopPropagation();
      notificationDropdown.classList.toggle('active');
      profileDropdown.classList.remove('active');
    });
    
    staffProfile.addEventListener('click', function(e) {
      e.stopPropagation();
      profileDropdown.classList.toggle('active');
      notificationDropdown.classList.remove('active');
    });
    
    document.addEventListener('click', function() {
      notificationDropdown.classList.remove('active');
      profileDropdown.classList.remove('active');
    });
    
    notificationDropdown.addEventListener('click', function(e) {
      e.stopPropagation();
    });
    
    profileDropdown.addEventListener('click', function(e) {
      e.stopPropagation();
    });
    
    // File input display functionality
    document.querySelectorAll('.file-input').forEach(input => {
      const fileNameElement = document.getElementById(input.id + '_name');
      
      input.addEventListener('change', function() {
        if (this.files.length > 0) {
          fileNameElement.textContent = this.files[0].name;
          
          // Preview profile photo
          if (this.id === 'profile_photo' && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
              // Show cropper modal instead of directly updating the preview
              showCropperModal(e.target.result);
            }
            reader.readAsDataURL(this.files[0]);
          }
        } else {
          fileNameElement.textContent = 'No file chosen';
        }
      });
    });
    
    // Cropper functionality
    let cropper;
    const cropperModal = document.getElementById('cropperModal');
    const imageToCrop = document.getElementById('imageToCrop');
    const closeCropperModal = document.getElementById('closeCropperModal');
    const cancelCrop = document.getElementById('cancelCrop');
    const saveCrop = document.getElementById('saveCrop');
    const croppedImageData = document.getElementById('cropped_image_data');
    const profilePhotoPreview = document.getElementById('profilePhotoPreview');
    const sidebarAvatar = document.getElementById('sidebarAvatar');
    const topNavAvatar = document.getElementById('topNavAvatar');
    
    function showCropperModal(imageSrc) {
      imageToCrop.src = imageSrc;
      cropperModal.style.display = 'flex';
      
      // Initialize cropper
      if (cropper) {
        cropper.destroy();
      }
      
      cropper = new Cropper(imageToCrop, {
        aspectRatio: 1,
        viewMode: 1,
        preview: '.cropper-preview',
        guides: true,
        background: false,
        movable: true,
        rotatable: true,
        scalable: true,
        zoomable: true,
        zoomOnTouch: true,
        zoomOnWheel: true,
        wheelZoomRatio: 0.1,
        cropBoxMovable: true,
        cropBoxResizable: true,
        toggleDragModeOnDblclick: true,
        minContainerWidth: 300,
        minContainerHeight: 300,
        responsive: true,
        restore: true,
        checkCrossOrigin: false,
        checkOrientation: false,
        modal: true,
        highlight: false,
        center: true,
        autoCropArea: 0.8
      });
    }
    
    function hideCropperModal() {
      cropperModal.style.display = 'none';
      if (cropper) {
        cropper.destroy();
        cropper = null;
      }
    }
    
    closeCropperModal.addEventListener('click', hideCropperModal);
    cancelCrop.addEventListener('click', hideCropperModal);
    
    saveCrop.addEventListener('click', function() {
      if (cropper) {
        // Get cropped canvas
        const canvas = cropper.getCroppedCanvas({
          width: 300,
          height: 300,
          fillColor: '#fff',
          imageSmoothingEnabled: true,
          imageSmoothingQuality: 'high'
        });
        
        // Convert canvas to base64 data URL
        const croppedImageUrl = canvas.toDataURL('image/png');
        
        // Update profile photo previews
        profilePhotoPreview.src = croppedImageUrl;
        sidebarAvatar.src = croppedImageUrl;
        topNavAvatar.src = croppedImageUrl;
        
        // Set cropped image data to hidden field
        croppedImageData.value = croppedImageUrl;
        
        // Hide modal
        hideCropperModal();
        
        // Clear the file input to allow re-uploading the same file
        document.getElementById('profile_photo').value = '';
        document.getElementById('profile_photo_name').textContent = 'No file chosen';
        
        // Submit the form to save the cropped image
        document.getElementById('profileForm').submit();
      }
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
      if (event.target === cropperModal) {
        hideCropperModal();
      }
    });
    
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
    
    // Enhanced notification functionality
    $(document).ready(function() {
      // Handle notification click and mark as read
      $('.notification-link').on('click', function(e) {
        const notifId = $(this).data('notif-id');
        const notificationItem = $(this);
        
        // Only mark as read if it's unread and has a valid ID
        if (notificationItem.hasClass('unread') && notifId) {
          // Send AJAX request to mark as read
          $.ajax({
            url: 'staff_profile.php',
            type: 'POST',
            data: {
              mark_as_read: true,
              notif_id: notifId
            },
            success: function(response) {
              const result = JSON.parse(response);
              if (result.success) {
                // Update UI
                notificationItem.removeClass('unread');
                
                // Update notification count
                const currentCount = parseInt($('#notificationCount').text());
                if (currentCount > 1) {
                  $('#notificationCount').text(currentCount - 1);
                } else {
                  $('#notificationCount').remove();
                }
              }
            },
            error: function() {
              console.log('Error marking notification as read');
            }
          });
        }
      });
      
      // Add hover effects to cards
      $('.card').hover(
        function() {
          $(this).css('transform', 'translateY(-5px)');
        },
        function() {
          $(this).css('transform', 'translateY(0)');
        }
      );
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        alert.style.display = 'none';
      });
    }, 5000);
  </script>
</body>
</html>