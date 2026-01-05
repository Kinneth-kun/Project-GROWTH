<?php
session_start();

// Check if user is logged in and is an employer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'employer') {
    header("Location: index.php");
    exit();
}

// PSGC API Endpoints
define('REGIONS_API', 'https://psgc.gitlab.io/api/regions/');
define('PROVINCES_API', 'https://psgc.gitlab.io/api/regions/{code}/provinces/');
define('CITIES_API', 'https://psgc.gitlab.io/api/provinces/{code}/cities-municipalities/');
define('BARANGAYS_API', 'https://psgc.gitlab.io/api/cities-municipalities/{code}/barangays/');

// ============================================
// PSGC API FUNCTIONS
// ============================================
function fetchFromAPI($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

$error = '';
$success = '';
$employer = [];
$profile_complete_percentage = 0;
$notifications = [];
$unread_notif_count = 0;

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Handle marking notifications as read via AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        $employer_id = $_SESSION['user_id'];
        
        if ($_POST['ajax_action'] === 'mark_notifications_read') {
            if (isset($_POST['mark_all']) && $_POST['mark_all'] === 'true') {
                // Mark all notifications as read for this user
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET notif_is_read = 1 
                    WHERE notif_usr_id = :employer_id 
                    AND notif_is_read = 0
                ");
                $stmt->bindParam(':employer_id', $employer_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'action' => 'mark_all']);
                exit();
            } elseif (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
                // Mark specific notifications as read
                $placeholders = str_repeat('?,', count($_POST['notification_ids']) - 1) . '?';
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET notif_is_read = 1 
                    WHERE notif_id IN ($placeholders) 
                    AND notif_usr_id = ?
                ");
                
                $params = array_merge($_POST['notification_ids'], [$employer_id]);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'action' => 'mark_specific']);
                exit();
            }
        }
    }
    
    // Get employer data
    $employer_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT e.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, 
               u.usr_address, u.usr_birthdate, u.usr_gender
        FROM employers e 
        JOIN users u ON e.emp_usr_id = u.usr_id 
        WHERE e.emp_usr_id = :employer_id
    ");
    $stmt->bindParam(':employer_id', $employer_id);
    $stmt->execute();
    $employer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ========== FETCH INDUSTRIES FROM DATABASE ==========
    $grouped_industries = [];
    try {
        $industry_stmt = $conn->prepare("
            SELECT industry_id, industry_name, is_custom 
            FROM industry_categories 
            WHERE is_active = TRUE 
            ORDER BY 
                is_custom ASC, 
                CASE 
                    WHEN industry_name LIKE 'Software%' THEN 1
                    WHEN industry_name LIKE 'IT%' THEN 2
                    WHEN industry_name LIKE 'Telecommunications%' THEN 3
                    WHEN industry_name LIKE 'E-commerce%' THEN 4
                    WHEN industry_name LIKE 'Digital Marketing%' THEN 5
                    WHEN industry_name LIKE 'Cyber%' THEN 6
                    ELSE 99
                END,
                industry_name ASC
        ");
        $industry_stmt->execute();
        $industries = $industry_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group industries by category for better display
        foreach ($industries as $industry) {
            $category = '';
            $industry_name = $industry['industry_name'];
            
            // Categorize industries
            if (strpos($industry_name, 'Software') === 0 || 
                strpos($industry_name, 'IT') === 0 ||
                strpos($industry_name, 'Digital') === 0 ||
                strpos($industry_name, 'Data') === 0 ||
                strpos($industry_name, 'Cyber') === 0 ||
                $industry_name === 'E-commerce' ||
                $industry_name === 'Artificial Intelligence') {
                $category = 'Technology & IT';
            } elseif (strpos($industry_name, 'Engineering') !== false || 
                     strpos($industry_name, 'Manufacturing') !== false ||
                     $industry_name === 'Construction' ||
                     $industry_name === 'Automotive' ||
                     $industry_name === 'Aerospace') {
                $category = 'Engineering & Manufacturing';
            } elseif (strpos($industry_name, 'Health') !== false || 
                     strpos($industry_name, 'Medical') !== false ||
                     strpos($industry_name, 'Pharma') !== false ||
                     $industry_name === 'Biotechnology') {
                $category = 'Healthcare & Pharmaceuticals';
            } elseif (strpos($industry_name, 'Finance') !== false || 
                     strpos($industry_name, 'Bank') !== false ||
                     $industry_name === 'Insurance' ||
                     $industry_name === 'Investment' ||
                     $industry_name === 'Accounting' ||
                     $industry_name === 'Fintech') {
                $category = 'Finance & Banking';
            } elseif (strpos($industry_name, 'Education') !== false || 
                     strpos($industry_name, 'Training') !== false ||
                     $industry_name === 'Research & Development') {
                $category = 'Education & Training';
            } elseif (strpos($industry_name, 'Hospitality') !== false || 
                     strpos($industry_name, 'Hotel') !== false ||
                     strpos($industry_name, 'Restaurant') !== false ||
                     strpos($industry_name, 'Travel') !== false ||
                     strpos($industry_name, 'Event') !== false ||
                     strpos($industry_name, 'Food') !== false) {
                $category = 'Hospitality & Tourism';
            } elseif (strpos($industry_name, 'Retail') !== false || 
                     strpos($industry_name, 'Consumer') !== false ||
                     $industry_name === 'Fashion' ||
                     $industry_name === 'FMCG') {
                $category = 'Retail & Consumer Goods';
            } elseif (strpos($industry_name, 'Government') !== false || 
                     strpos($industry_name, 'Public') !== false ||
                     $industry_name === 'Defense') {
                $category = 'Government & Public Sector';
            } else {
                $category = 'Other Industries';
            }
            
            // Separate custom industries
            if ($industry['is_custom']) {
                $category = 'Custom Industries';
            }
            
            if (!isset($grouped_industries[$category])) {
                $grouped_industries[$category] = [];
            }
            
            $grouped_industries[$category][] = $industry;
        }
    } catch (PDOException $e) {
        // Fallback to empty array
        $grouped_industries = [];
    }
    
    // ========== ENHANCED ADDRESS PARSING ==========
    $parsed_address = [
        'street_address' => '',
        'barangay' => '',
        'city' => '',
        'province' => '',
        'region' => '',
        'zip_code' => ''
    ];
    
    if (!empty($employer['usr_address'])) {
        // The address is stored as: "Street, Barangay, City, Province, Region, ZIP: xxxx"
        $address_parts = explode(', ', $employer['usr_address']);
        
        foreach ($address_parts as $part) {
            $part = trim($part);
            
            if (strpos($part, 'ZIP: ') === 0) {
                $parsed_address['zip_code'] = str_replace('ZIP: ', '', $part);
            } elseif (preg_match('/^[0-9]{4}$/', $part)) {
                $zip_code = $part;
            } else {
                // Store address parts in an array for later processing
                $address_parts_array[] = $part;
            }
        }
        
        // If we have address parts, assign them in reverse order (Region, Province, City, Barangay, Street)
        if (!empty($address_parts_array)) {
            $count = count($address_parts_array);
            
            // The last part is the region
            if ($count > 0) $parsed_address['region'] = $address_parts_array[$count - 1];
            
            // The second to last is the province
            if ($count > 1) $parsed_address['province'] = $address_parts_array[$count - 2];
            
            // The third to last is the city
            if ($count > 2) $parsed_address['city'] = $address_parts_array[$count - 3];
            
            // The fourth to last is the barangay
            if ($count > 3) $parsed_address['barangay'] = $address_parts_array[$count - 4];
            
            // Anything before barangay is the street address
            if ($count > 4) {
                $parsed_address['street_address'] = implode(', ', array_slice($address_parts_array, 0, $count - 4));
            }
        }
    }
    
    // If we have session data from a recent form submission, use that instead
    if (isset($_SESSION['last_address_data'])) {
        $parsed_address['region'] = $_SESSION['last_address_data']['region'] ?? $parsed_address['region'];
        $parsed_address['province'] = $_SESSION['last_address_data']['province'] ?? $parsed_address['province'];
        $parsed_address['city'] = $_SESSION['last_address_data']['city'] ?? $parsed_address['city'];
        $parsed_address['barangay'] = $_SESSION['last_address_data']['barangay'] ?? $parsed_address['barangay'];
        $parsed_address['zip_code'] = $_SESSION['last_address_data']['zip_code'] ?? $parsed_address['zip_code'];
        $parsed_address['street_address'] = $_SESSION['last_address_data']['street_address'] ?? $parsed_address['street_address'];
        
        // Clear session data after using it
        unset($_SESSION['last_address_data']);
    }
    
    // ========== ENHANCED NOTIFICATIONS SYSTEM ==========
    
    // 1. Create application notifications for recent applications
    $recent_apps_stmt = $conn->prepare("
        SELECT 
            a.app_id,
            a.app_applied_at,
            u.usr_name,
            j.job_title,
            j.job_id,
            e.emp_company_name
        FROM applications a
        JOIN jobs j ON a.app_job_id = j.job_id
        JOIN users u ON a.app_grad_usr_id = u.usr_id
        JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
        WHERE j.job_emp_usr_id = :employer_id
        AND a.app_applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY a.app_applied_at DESC
    ");
    $recent_apps_stmt->bindParam(':employer_id', $employer_id);
    $recent_apps_stmt->execute();
    $recent_applications = $recent_apps_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create notifications for recent applications that don't have notifications yet
    foreach ($recent_applications as $app) {
        $notification_message = 'New application received for "' . $app['job_title'] . '" from ' . $app['usr_name'];
        
        // Check if notification already exists
        $check_notif_stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE notif_usr_id = :employer_id 
            AND notif_message = :message
            AND notif_type = 'application'
        ");
        $check_notif_stmt->bindParam(':employer_id', $employer_id);
        $check_notif_stmt->bindParam(':message', $notification_message);
        $check_notif_stmt->execute();
        
        if ($check_notif_stmt->fetchColumn() == 0) {
            // Insert new notification
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'application', 0, :created_at)
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->bindParam(':created_at', $app['app_applied_at']);
            $insert_notif_stmt->execute();
        }
    }
    
    // 2. Create job status notifications for recently reviewed jobs
    $recent_jobs_stmt = $conn->prepare("
        SELECT 
            j.job_id,
            j.job_title,
            j.job_status,
            j.job_reviewed_at,
            u.usr_name as reviewer_name
        FROM jobs j
        LEFT JOIN users u ON j.job_reviewed_by = u.usr_id
        WHERE j.job_emp_usr_id = :employer_id
        AND j.job_reviewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY j.job_reviewed_at DESC
    ");
    $recent_jobs_stmt->bindParam(':employer_id', $employer_id);
    $recent_jobs_stmt->execute();
    $recent_jobs = $recent_jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($recent_jobs as $job) {
        if ($job['job_status'] === 'active') {
            $notification_message = 'Your job posting "' . $job['job_title'] . '" has been approved and is now active';
        } elseif ($job['job_status'] === 'rejected') {
            $notification_message = 'Your job posting "' . $job['job_title'] . '" has been rejected by admin';
        } else {
            continue;
        }
        
        // Check if notification already exists
        $check_notif_stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE notif_usr_id = :employer_id 
            AND notif_message = :message
            AND notif_type = 'job_status'
        ");
        $check_notif_stmt->bindParam(':employer_id', $employer_id);
        $check_notif_stmt->bindParam(':message', $notification_message);
        $check_notif_stmt->execute();
        
        if ($check_notif_stmt->fetchColumn() == 0) {
            // Insert new notification
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'job_status', 0, :created_at)
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->bindParam(':created_at', $job['job_reviewed_at']);
            $insert_notif_stmt->execute();
        }
    }
    
    // 3. Create profile view notifications for recent profile views - FIXED QUERY
    $recent_views_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as view_count,
            DATE(view_viewed_at) as view_date
        FROM employer_profile_views 
        WHERE view_emp_usr_id = :employer_id
        AND view_viewed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        GROUP BY DATE(view_viewed_at)
        ORDER BY view_date DESC
        LIMIT 1
    ");
    $recent_views_stmt->bindParam(':employer_id', $employer_id);
    $recent_views_stmt->execute();
    $recent_views = $recent_views_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recent_views && $recent_views['view_count'] >= 3) {
        $notification_message = 'Your company profile was viewed by ' . $recent_views['view_count'] . ' graduates today';
        
        // Check if notification already exists
        $check_notif_stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE notif_usr_id = :employer_id 
            AND notif_message LIKE 'Your company profile was viewed by%'
            AND notif_type = 'profile_views'
            AND DATE(notif_created_at) = CURDATE()
        ");
        $check_notif_stmt->bindParam(':employer_id', $employer_id);
        $check_notif_stmt->execute();
        
        if ($check_notif_stmt->fetchColumn() == 0) {
            // Insert new notification
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'profile_views', 0, NOW())
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->execute();
        }
    }
    
    // 4. Create system notifications (example: weekly summary)
    $last_system_notif_stmt = $conn->prepare("
        SELECT COUNT(*) FROM notifications 
        WHERE notif_usr_id = :employer_id 
        AND notif_type = 'system'
        AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $last_system_notif_stmt->bindParam(':employer_id', $employer_id);
    $last_system_notif_stmt->execute();
    
    if ($last_system_notif_stmt->fetchColumn() == 0) {
        // Get job statistics for weekly summary
        $jobs_stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN j.job_status = 'active' THEN 1 ELSE 0 END) as active_jobs,
                SUM(CASE WHEN j.job_status = 'pending' THEN 1 ELSE 0 END) as pending_jobs
            FROM jobs j
            WHERE j.job_emp_usr_id = :employer_id
        ");
        $jobs_stmt->bindParam(':employer_id', $employer_id);
        $jobs_stmt->execute();
        $job_stats = $jobs_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get application statistics for weekly summary
        $apps_stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_applications,
                SUM(CASE WHEN a.app_status = 'pending' THEN 1 ELSE 0 END) as pending_applications
            FROM applications a
            JOIN jobs j ON a.app_job_id = j.job_id
            WHERE j.job_emp_usr_id = :employer_id
        ");
        $apps_stmt->bindParam(':employer_id', $employer_id);
        $apps_stmt->execute();
        $app_stats = $apps_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create weekly summary notification
        $total_active_jobs = $job_stats['active_jobs'] ?? 0;
        $total_pending_apps = $app_stats['pending_applications'] ?? 0;
        
        $system_message = "Weekly Summary: You have {$total_active_jobs} active jobs and {$total_pending_apps} pending applications";
        
        $insert_system_notif_stmt = $conn->prepare("
            INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
            VALUES (:employer_id, :message, 'system', 0, NOW())
        ");
        $insert_system_notif_stmt->bindParam(':employer_id', $employer_id);
        $insert_system_notif_stmt->bindParam(':message', $system_message);
        $insert_system_notif_stmt->execute();
    }
    
    // 5. Get ALL notifications (removed LIMIT 8)
    $notif_stmt = $conn->prepare("
        SELECT 
            n.notif_id,
            n.notif_usr_id,
            n.notif_message,
            n.notif_type,
            n.notif_is_read,
            n.notif_created_at,
            CASE 
                WHEN n.notif_type = 'application' THEN 'fas fa-file-alt'
                WHEN n.notif_type = 'job_status' THEN 'fas fa-briefcase'
                WHEN n.notif_type = 'profile_views' THEN 'fas fa-eye'
                WHEN n.notif_type = 'system' THEN 'fas fa-cog'
                WHEN n.notif_type = 'application_update' THEN 'fas fa-sync-alt'
                ELSE 'fas fa-bell'
            END as notif_icon,
            CASE 
                WHEN n.notif_type = 'application' THEN '#0044ff'
                WHEN n.notif_type = 'job_status' THEN '#1f7a11'
                WHEN n.notif_type = 'profile_views' THEN '#6a0dad'
                WHEN n.notif_type = 'system' THEN '#ffa700'
                WHEN n.notif_type = 'application_update' THEN '#d32f2f'
                ELSE '#6e0303'
            END as notif_color
        FROM notifications n
        WHERE n.notif_usr_id = :employer_id 
        ORDER BY n.notif_created_at DESC
    ");
    $notif_stmt->bindParam(':employer_id', $employer_id);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread notifications
    $unread_notif_count = 0;
    foreach ($notifications as $notif) {
        if (!$notif['notif_is_read']) $unread_notif_count++;
    }
    
    // Calculate profile completion percentage
    $complete_fields = 0;
    $total_fields = 9; // Added address fields
    
    if (!empty($employer['emp_company_name'])) $complete_fields++;
    if (!empty($employer['emp_industry'])) $complete_fields++;
    if (!empty($employer['emp_contact_person'])) $complete_fields++;
    if (!empty($employer['emp_company_description'])) $complete_fields++;
    if (!empty($employer['emp_business_permit'])) $complete_fields++;
    if (!empty($employer['emp_dti_sec'])) $complete_fields++;
    if (!empty($employer['usr_profile_photo'])) $complete_fields++;
    if (!empty($employer['usr_phone'])) $complete_fields++;
    if (!empty($employer['usr_address'])) $complete_fields++;
    
    $profile_complete_percentage = round(($complete_fields / $total_fields) * 100);
    
    // ========== ENHANCED FORM SUBMISSION HANDLING ==========
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
        $company_name = trim($_POST['company_name'] ?? '');
        $industry_id = isset($_POST['industry']) ? intval($_POST['industry']) : 0;
        $custom_industry = trim($_POST['custom_industry'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $company_description = trim($_POST['company_description'] ?? '');
        $country_code = trim($_POST['country_code'] ?? '+63');
        $phone = trim($_POST['phone'] ?? '');
        
        // Enhanced: Address fields - Store the names, not just codes
        $region = trim($_POST['region'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        
        // Personal info
        $gender = trim($_POST['gender'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        
        // Handle file uploads
        $profile_photo_path = $employer['usr_profile_photo'] ?? '';
        $business_permit_path = $employer['emp_business_permit'] ?? '';
        $dti_sec_path = $employer['emp_dti_sec'] ?? '';
        
        // Process profile photo upload
        if (!empty($_FILES['profile_photo']['name'])) {
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
                    
                    $new_filename = 'profile_' . $employer_id . '_' . time() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($profile_photo['tmp_name'], $target_path)) {
                        $profile_photo_path = $target_path;
                        
                        // Delete old profile photo if exists
                        if (!empty($employer['usr_profile_photo']) && file_exists($employer['usr_profile_photo'])) {
                            unlink($employer['usr_profile_photo']);
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
        
        // Process cropped image upload if provided
        if (!empty($_POST['cropped_image_data'])) {
            $cropped_image_data = $_POST['cropped_image_data'];
            
            // Remove the data:image/png;base64, part
            $cropped_image_data = str_replace('data:image/png;base64,', '', $cropped_image_data);
            $cropped_image_data = str_replace(' ', '+', $cropped_image_data);
            
            // Decode the base64 data
            $cropped_image = base64_decode($cropped_image_data);
            
            // Generate filename and path
            $upload_dir = 'uploads/profile_photos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = 'profile_' . $employer_id . '_cropped_' . time() . '.png';
            $target_path = $upload_dir . $new_filename;
            
            // Save the image
            if (file_put_contents($target_path, $cropped_image)) {
                $profile_photo_path = $target_path;
                
                // Delete old profile photo if exists
                if (!empty($employer['usr_profile_photo']) && file_exists($employer['usr_profile_photo'])) {
                    unlink($employer['usr_profile_photo']);
                }
            } else {
                $error = "Failed to save cropped image. Please try again.";
            }
        }
        
        // Process Business Permit upload if no error from previous upload
        if (empty($error) && !empty($_FILES['business_permit']['name'])) {
            $business_permit = $_FILES['business_permit'];
            $allowed_types = ['pdf', 'jpg', 'jpeg', 'png'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            $file_ext = strtolower(pathinfo($business_permit['name'], PATHINFO_EXTENSION));
            $file_size = $business_permit['size'];
            
            if (in_array($file_ext, $allowed_types)) {
                if ($file_size <= $max_size) {
                    $upload_dir = 'uploads/employer_docs/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'business_permit_' . $employer_id . '_' . time() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($business_permit['tmp_name'], $target_path)) {
                        $business_permit_path = $target_path;
                        
                        // Delete old business permit if exists
                        if (!empty($employer['emp_business_permit']) && file_exists($employer['emp_business_permit'])) {
                            unlink($employer['emp_business_permit']);
                        }
                    } else {
                        $error = "Failed to upload Business Permit. Please try again.";
                    }
                } else {
                    $error = "Business Permit file is too large. Maximum size is 5MB.";
                }
            } else {
                $error = "Invalid file type for Business Permit. Allowed types: PDF, JPG, JPEG, PNG.";
            }
        }
        
        // Process DTI/SEC Certificate upload if no error from previous upload
        if (empty($error) && !empty($_FILES['dti_sec']['name'])) {
            $dti_sec = $_FILES['dti_sec'];
            $allowed_types = ['pdf', 'jpg', 'jpeg', 'png'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            $file_ext = strtolower(pathinfo($dti_sec['name'], PATHINFO_EXTENSION));
            $file_size = $dti_sec['size'];
            
            if (in_array($file_ext, $allowed_types)) {
                if ($file_size <= $max_size) {
                    $upload_dir = 'uploads/employer_docs/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'dti_sec_' . $employer_id . '_' . time() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($dti_sec['tmp_name'], $target_path)) {
                        $dti_sec_path = $target_path;
                        
                        // Delete old DTI/SEC certificate if exists
                        if (!empty($employer['emp_dti_sec']) && file_exists($employer['emp_dti_sec'])) {
                            unlink($employer['emp_dti_sec']);
                        }
                    } else {
                        $error = "Failed to upload DTI/SEC Certificate. Please try again.";
                    }
                } else {
                    $error = "DTI/SEC Certificate file is too large. Maximum size is 5MB.";
                }
            } else {
                $error = "Invalid file type for DTI/SEC Certificate. Allowed types: PDF, JPG, JPEG, PNG.";
            }
        }
        
        // Enhanced phone number validation
        if (empty($error)) {
            $phone = preg_replace('/[^0-9]/', '', $phone); // Remove any non-numeric characters
            
            // Validate phone number length - CHANGED TO 11 DIGITS
            if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 11)) {
                $error = "Phone number must be between 10 and 11 digits.";
            }
        }
        
        // Enhanced: Validate required address fields
        if (empty($error)) {
            $required_address_fields = ['region', 'province', 'city', 'barangay'];
            foreach ($required_address_fields as $field) {
                if (empty($$field)) {
                    $error = ucfirst($field) . " is required in the address.";
                    break;
                }
            }
        }
        
        if (empty($error)) {
            // Get industry name based on selection
            $industry_name = '';
            
            if ($industry_id > 0 && $industry_id !== 9999) {
                // Get industry name from database
                try {
                    $industry_stmt = $conn->prepare("SELECT industry_name FROM industry_categories WHERE industry_id = :industry_id");
                    $industry_stmt->bindParam(':industry_id', $industry_id, PDO::PARAM_INT);
                    $industry_stmt->execute();
                    $industry_data = $industry_stmt->fetch();
                    
                    if ($industry_data) {
                        $industry_name = $industry_data['industry_name'];
                    } else {
                        $error = "Selected industry is not valid.";
                    }
                } catch (PDOException $e) {
                    $error = "Error validating industry selection.";
                }
            } elseif ($industry_id === 9999 && !empty($custom_industry)) {
                // Handle custom industry
                $custom_industry = ucfirst(strtolower(trim($custom_industry)));
                $industry_name = $custom_industry;
                
                // Check if custom industry already exists (case-insensitive)
                try {
                    $custom_check_stmt = $conn->prepare("
                        SELECT industry_id FROM industry_categories 
                        WHERE LOWER(industry_name) = LOWER(:industry_name) AND is_active = TRUE
                    ");
                    $custom_check_stmt->bindParam(':industry_name', $custom_industry);
                    $custom_check_stmt->execute();
                    
                    if ($custom_check_stmt->rowCount() === 0) {
                        // Insert new custom industry
                        $insert_industry_stmt = $conn->prepare("
                            INSERT INTO industry_categories (industry_name, is_custom, added_by_user_id, created_at) 
                            VALUES (:industry_name, TRUE, :user_id, NOW())
                        ");
                        $insert_industry_stmt->bindParam(':industry_name', $custom_industry);
                        $insert_industry_stmt->bindParam(':user_id', $employer_id, PDO::PARAM_INT);
                        $insert_industry_stmt->execute();
                    }
                } catch (PDOException $e) {
                    // If there's an error, still use the custom industry name
                    // This allows the form to proceed even if industry insertion fails
                }
            } else {
                $error = "Please select an industry or enter a custom industry.";
            }
            
            if (empty($error) && !empty($industry_name)) {
                // Combine country code and phone number
                $full_phone = $country_code . $phone;
                
                // ENHANCED: Combine address components properly - using the actual names from form
                $address_components = [];
                if (!empty($street_address)) $address_components[] = $street_address;
                if (!empty($barangay)) $address_components[] = $barangay;
                if (!empty($city)) $address_components[] = $city;
                if (!empty($province)) $address_components[] = $province;
                if (!empty($region)) $address_components[] = $region;
                if (!empty($zip_code)) $address_components[] = "ZIP: " . $zip_code;
                
                $permanent_address = implode(', ', $address_components);
                
                // Begin transaction
                $conn->beginTransaction();
                
                try {
                    // Update employer data
                    $update_stmt = $conn->prepare("
                        UPDATE employers 
                        SET emp_company_name = :company_name, 
                            emp_industry = :industry, 
                            emp_contact_person = :contact_person, 
                            emp_company_description = :company_description,
                            emp_business_permit = :business_permit,
                            emp_dti_sec = :dti_sec,
                            emp_updated_at = NOW()
                        WHERE emp_usr_id = :employer_id
                    ");
                    
                    $update_stmt->bindParam(':company_name', $company_name);
                    $update_stmt->bindParam(':industry', $industry_name);
                    $update_stmt->bindParam(':contact_person', $contact_person);
                    $update_stmt->bindParam(':company_description', $company_description);
                    $update_stmt->bindParam(':business_permit', $business_permit_path);
                    $update_stmt->bindParam(':dti_sec', $dti_sec_path);
                    $update_stmt->bindParam(':employer_id', $employer_id);
                    
                    if ($update_stmt->execute()) {
                        // Update user data
                        $user_update_stmt = $conn->prepare("
                            UPDATE users 
                            SET usr_phone = :phone, 
                                usr_profile_photo = :profile_photo,
                                usr_gender = :gender,
                                usr_birthdate = :birthdate,
                                usr_address = :address,
                                usr_updated_at = NOW()
                            WHERE usr_id = :employer_id
                        ");
                        
                        $user_update_stmt->bindParam(':phone', $full_phone);
                        $user_update_stmt->bindParam(':profile_photo', $profile_photo_path);
                        $user_update_stmt->bindParam(':gender', $gender);
                        $user_update_stmt->bindParam(':birthdate', $birthdate);
                        $user_update_stmt->bindParam(':address', $permanent_address);
                        $user_update_stmt->bindParam(':employer_id', $employer_id);
                        
                        if ($user_update_stmt->execute()) {
                            // Commit transaction
                            $conn->commit();
                            
                            $success = "Profile updated successfully!";
                            
                            // Refresh employer data
                            $stmt->execute();
                            $employer = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Recalculate profile completion
                            $complete_fields = 0;
                            if (!empty($employer['emp_company_name'])) $complete_fields++;
                            if (!empty($employer['emp_industry'])) $complete_fields++;
                            if (!empty($employer['emp_contact_person'])) $complete_fields++;
                            if (!empty($employer['emp_company_description'])) $complete_fields++;
                            if (!empty($employer['emp_business_permit'])) $complete_fields++;
                            if (!empty($employer['emp_dti_sec'])) $complete_fields++;
                            if (!empty($employer['usr_profile_photo'])) $complete_fields++;
                            if (!empty($employer['usr_phone'])) $complete_fields++;
                            if (!empty($employer['usr_address'])) $complete_fields++;
                            
                            $profile_complete_percentage = round(($complete_fields / $total_fields) * 100);
                            
                            // Re-parse address with ENHANCED parsing
                            $parsed_address = [
                                'street_address' => '',
                                'barangay' => '',
                                'city' => '',
                                'province' => '',
                                'region' => '',
                                'zip_code' => ''
                            ];
                            
                            if (!empty($employer['usr_address'])) {
                                $address_parts = explode(', ', $employer['usr_address']);
                                
                                foreach ($address_parts as $part) {
                                    $part = trim($part);
                                    
                                    if (strpos($part, 'ZIP: ') === 0) {
                                        $parsed_address['zip_code'] = str_replace('ZIP: ', '', $part);
                                    } elseif (preg_match('/^[0-9]{4}$/', $part)) {
                                        $parsed_address['zip_code'] = $part;
                                    } else {
                                        $address_parts_array[] = $part;
                                    }
                                }
                                
                                if (!empty($address_parts_array)) {
                                    $count = count($address_parts_array);
                                    
                                    if ($count > 0) $parsed_address['region'] = $address_parts_array[$count - 1];
                                    if ($count > 1) $parsed_address['province'] = $address_parts_array[$count - 2];
                                    if ($count > 2) $parsed_address['city'] = $address_parts_array[$count - 3];
                                    if ($count > 3) $parsed_address['barangay'] = $address_parts_array[$count - 4];
                                    if ($count > 4) {
                                        $parsed_address['street_address'] = implode(', ', array_slice($address_parts_array, 0, $count - 4));
                                    }
                                }
                            }
                            
                            // Store the submitted address values in session for JavaScript to use
                            $_SESSION['last_address_data'] = [
                                'region' => $region,
                                'province' => $province,
                                'city' => $city,
                                'barangay' => $barangay,
                                'zip_code' => $zip_code,
                                'street_address' => $street_address
                            ];
                            
                            // Create notification for profile update
                            $profile_update_message = "Your company profile has been updated successfully";
                            $insert_profile_notif_stmt = $conn->prepare("
                                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                                VALUES (:employer_id, :message, 'system', 0, NOW())
                            ");
                            $insert_profile_notif_stmt->bindParam(':employer_id', $employer_id);
                            $insert_profile_notif_stmt->bindParam(':message', $profile_update_message);
                            $insert_profile_notif_stmt->execute();
                            
                        } else {
                            throw new Exception("Failed to update user information.");
                        }
                    } else {
                        throw new Exception("Failed to update employer information.");
                    }
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollBack();
                    $error = $e->getMessage();
                }
            }
        }
    }
    
} catch (PDOException $e) {
    $error = "Database Connection Failed: " . $e->getMessage();
}

// Extract country code and phone number from stored phone
$stored_phone = $employer['usr_phone'] ?? '';
$country_code = '+63';
$phone_number = '';

if (!empty($stored_phone)) {
    // Extract country code (assuming format like +639123456789)
    if (strpos($stored_phone, '+') === 0) {
        // Find where the country code ends (after +63)
        $country_code = substr($stored_phone, 0, 3); // +63
        $phone_number = substr($stored_phone, 3); // rest of the number
    } else {
        $phone_number = $stored_phone;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Employer Profile</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
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
        
        .company-name {
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
        
        .notification, .admin-profile {
            position: relative;
            margin-left: 20px;
            cursor: pointer;
        }
        
        .notification i, .admin-profile i {
            font-size: 1.3rem;
            color: var(--primary-color);
            transition: all 0.3s;
        }
        
        .notification:hover i, .admin-profile:hover i {
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
        
        .admin-profile {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 30px;
            transition: all 0.3s;
            background: rgba(110, 3, 3, 0.05);
        }
        
        .admin-profile:hover {
            background: rgba(110, 3, 3, 0.1);
        }
        
        .admin-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        .admin-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-right: 8px;
        }
        
        .admin-profile i.fa-chevron-down {
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
        
        /* Enhanced Profile Cards */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .profile-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 20px;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .profile-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        /* Enhanced Profile Completion */
        .profile-completion {
            margin-bottom: 25px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            border-left: 4px solid var(--secondary-color);
        }
        
        .completion-text {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .progress-bar {
            height: 12px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), #8a0404);
            border-radius: 10px;
            transition: width 0.5s ease;
            position: relative;
        }
        
        .progress::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background-image: linear-gradient(
                -45deg,
                rgba(255, 255, 255, 0.2) 25%,
                transparent 25%,
                transparent 50%,
                rgba(255, 255, 255, 0.2) 50%,
                rgba(255, 255, 255, 0.2) 75%,
                transparent 75%,
                transparent
            );
            background-size: 20px 20px;
            animation: move 2s linear infinite;
        }
        
        @keyframes move {
            0% { background-position: 0 0; }
            100% { background-position: 20px 0; }
        }
        
        /* Enhanced Form Styles */
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.95rem;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #fff;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
            background: #fff;
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }
        
        /* Address Container Styles */
        .address-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .address-group {
            margin-bottom: 15px;
        }
        
        /* Phone Container Styles */
        .phone-container {
            display: flex;
            gap: 10px;
        }
        
        .country-code {
            width: 120px;
            flex-shrink: 0;
        }
        
        .phone-number {
            flex: 1;
        }
        
        /* Industry Selection Styles */
        .industry-selection-container {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            background: #fafafa;
        }
        
        .industry-options-container {
            margin-bottom: 15px;
        }
        
        .custom-industry-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
        }
        
        .custom-industry-input {
            flex: 1;
        }
        
        /* Loading Spinner Styles */
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
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
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
        
        /* Enhanced File Upload */
        .file-upload {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
            transition: all 0.3s;
        }
        
        .file-upload:hover {
            border-color: var(--primary-color);
            background: #fff5f5;
        }
        
        .file-upload-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.95rem;
        }
        
        .file-input {
            display: none;
        }
        
        .file-custom {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: 2px solid var(--primary-color);
            border-radius: 6px;
            background: white;
            color: var(--primary-color);
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .file-custom:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }
        
        .file-name {
            margin-left: 12px;
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }
        
        .file-requirements {
            font-size: 0.8rem;
            color: #999;
            margin-top: 8px;
            line-height: 1.4;
        }
        
        .document-preview {
            margin-top: 12px;
            padding: 12px;
            background: #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 4px solid var(--green);
        }
        
        .document-preview a {
            color: var(--blue);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .document-preview a:hover {
            text-decoration: underline;
        }
        
        /* Enhanced Profile Photo */
        .profile-photo-container {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
        }
        
        .profile-photo {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-right: 25px;
        }
        
        /* Enhanced Alert Styles */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        /* Image Cropper Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border-radius: 12px 12px 0 0;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .cropper-container {
            width: 100%;
            max-height: 400px;
            margin-bottom: 20px;
            overflow: hidden;
            border-radius: 8px;
        }
        
        .cropper-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 20px;
            border: 4px solid var(--primary-color);
        }
        
        .cropper-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }
            
            .sidebar-header h3, .sidebar-menu span, .company-name, .menu-label {
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
            
            .profile-photo-container {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-photo {
                margin-right: 0;
                margin-bottom: 20px;
            }
            
            .dropdown {
                width: 90%;
                right: 5%;
            }
            
            .notification-dropdown {
                width: 350px;
                right: -100px;
            }
            
            .cropper-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .cropper-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .phone-container {
                flex-direction: column;
                gap: 8px;
            }
            
            .country-code {
                width: 100%;
            }
            
            .address-container {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .custom-industry-input-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .custom-industry-input-group .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .notification-dropdown {
                width: 300px;
                right: -140px;
            }
            
            .profile-card {
                padding: 20px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= !empty($employer['usr_profile_photo']) ? htmlspecialchars($employer['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($employer['emp_company_name']) . '&background=random' ?>" alt="Company Logo">
            <div class="sidebar-header-text">
                <h3><?= htmlspecialchars($employer['usr_name']) ?></h3>
                <div class="company-name"><?= htmlspecialchars($employer['emp_company_name']) ?></div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <ul>
                <li>
                    <a href="employer_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="employer_jobs.php">
                        <i class="fas fa-briefcase"></i>
                        <span>Manage Jobs</span>
                    </a>
                </li>
                <li>
                    <a href="employer_portfolio.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Alumni Portfolios</span>
                    </a>
                </li>
                <li>
                    <a href="employer_candidates.php">
                        <i class="fas fa-list-alt"></i>
                        <span>Shortlist Candidates</span>
                    </a>
                </li>
                <li>
                    <a href="employer_analytics.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Data Analytics</span>
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
                            <button class="mark-all-read" id="markAllRead">Mark all as read</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notif): 
                                // Determine priority and icon based on notification type
                                $priority_class = 'priority-medium';
                                $notification_icon = $notif['notif_icon'];
                                
                                if ($notif['notif_type'] === 'application') {
                                    $priority_class = 'priority-high';
                                } elseif ($notif['notif_type'] === 'job_status') {
                                    $priority_class = 'priority-medium';
                                } elseif ($notif['notif_type'] === 'profile_views') {
                                    $priority_class = 'priority-low';
                                } elseif ($notif['notif_type'] === 'system') {
                                    $priority_class = 'priority-medium';
                                } elseif ($notif['notif_type'] === 'application_update') {
                                    $priority_class = 'priority-high';
                                }
                                
                                // Determine the URL for the notification
                                $notification_url = 'employer_jobs.php?section=manage';
                                if (strpos($notif['notif_type'], 'application') !== false) {
                                    $notification_url = 'employer_jobs.php?section=manage';
                                } elseif (strpos($notif['notif_type'], 'job') !== false) {
                                    $notification_url = 'employer_jobs.php?section=manage';
                                } elseif (strpos($notif['notif_type'], 'profile') !== false) {
                                    $notification_url = 'employer_analytics.php';
                                } else {
                                    $notification_url = 'employer_dashboard.php';
                                }
                            ?>
                            <a href="<?= $notification_url ?>" class="notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?>">
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="<?= $notification_icon ?>" style="color: <?= $notif['notif_color'] ?>"></i>
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
                <div class="admin-profile" id="adminProfile">
                    <img src="<?= !empty($employer['usr_profile_photo']) ? htmlspecialchars($employer['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($employer['usr_name']) . '&background=random' ?>" alt="Employer">
                    <span class="admin-name"><?= htmlspecialchars($employer['usr_name']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                    <div class="dropdown profile-dropdown" id="profileDropdown">
                        <a href="employer_profile.php" class="dropdown-item"><i class="fas fa-building"></i> Company Profile</a>
                        <a href="employer_settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <div class="dropdown-item" style="border-top: 1px solid #eee; margin-top: 5px; padding-top: 15px;">
                            <i class="fas fa-user-circle"></i> Logged in as: <strong><?= htmlspecialchars($employer['usr_name']) ?></strong>
                        </div>
                        <a href="index.php" class="dropdown-item" style="color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?= htmlspecialchars($employer['usr_name']) ?>!</h2>
            <p>Manage your company profile and information here.</p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Company Profile</h1>
        </div>
        
        <!-- Alert Messages -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Enhanced Profile Completion -->
        <div class="profile-completion">
            <div class="completion-text">
                <span>Profile Completion</span>
                <span><?= $profile_complete_percentage ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="progress" style="width: <?= $profile_complete_percentage ?>%;"></div>
            </div>
        </div>
        
        <form action="employer_profile.php" method="POST" enctype="multipart/form-data" id="profileForm">
            <div class="profile-container">
                <!-- Left Column -->
                <div>
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Company Information</h3>
                            <i class="fas fa-building card-icon"></i>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="company_name">Company Name *</label>
                            <input type="text" id="company_name" name="company_name" class="form-input" 
                                value="<?= htmlspecialchars($employer['emp_company_name'] ?? '') ?>" required>
                        </div>
                        
                        <!-- Enhanced Industry Selection -->
                        <div class="form-group">
                            <label class="form-label" for="industry">Industry *</label>
                            <div class="industry-selection-container">
                                <div class="industry-options-container">
                                    <select id="industry" name="industry" class="form-select" required>
                                        <option value="">Select Industry</option>
                                        <?php if (!empty($grouped_industries)): ?>
                                            <?php foreach ($grouped_industries as $category => $category_industries): ?>
                                                <?php if ($category !== 'Custom Industries'): ?>
                                                    <optgroup label="<?= htmlspecialchars($category); ?>">
                                                        <?php foreach ($category_industries as $industry): ?>
                                                            <option value="<?= $industry['industry_id']; ?>"
                                                                <?php echo (isset($employer['emp_industry']) && $employer['emp_industry'] == $industry['industry_name']) ? 'selected' : ''; ?>>
                                                                <?= htmlspecialchars($industry['industry_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            
                                            <!-- Custom Industries Group -->
                                            <?php if (isset($grouped_industries['Custom Industries']) && !empty($grouped_industries['Custom Industries'])): ?>
                                                <optgroup label="Custom Industries">
                                                    <?php foreach ($grouped_industries['Custom Industries'] as $industry): ?>
                                                        <option value="<?= $industry['industry_id']; ?>"
                                                            <?php echo (isset($employer['emp_industry']) && $employer['emp_industry'] == $industry['industry_name']) ? 'selected' : ''; ?>>
                                                            <?= htmlspecialchars($industry['industry_name']); ?> (Custom)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                            
                                            <!-- Option to add new custom industry -->
                                            <option value="9999">+ Add New Industry</option>
                                        <?php else: ?>
                                            <!-- Fallback if database query fails -->
                                            <optgroup label="Technology & IT">
                                                <option value="1">Software Development</option>
                                                <option value="2">IT Services</option>
                                            </optgroup>
                                            <option value="9999">+ Add New Industry</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <!-- Custom Industry Input (hidden by default) -->
                                <div id="customIndustryContainer" style="display: none; margin-top: 10px;">
                                    <label class="form-label" for="custom_industry">Enter Custom Industry</label>
                                    <div class="custom-industry-input-group">
                                        <input type="text" id="custom_industry" name="custom_industry" 
                                               class="form-input custom-industry-input" 
                                               placeholder="Enter your industry (e.g., Renewable Energy, Gaming, etc.)"
                                               value="<?php echo isset($form_data['custom_industry']) ? htmlspecialchars($form_data['custom_industry']) : ''; ?>">
                                    </div>
                                    <div class="form-text" style="margin-top: 5px;">
                                        <i class="fas fa-info-circle"></i> Your custom industry will be saved for future use.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="contact_person">Contact Person *</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-input" 
                                value="<?= htmlspecialchars($employer['emp_contact_person'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="company_description">Company Description</label>
                            <textarea id="company_description" name="company_description" class="form-textarea" placeholder="Describe your company, mission, and values..."><?= htmlspecialchars($employer['emp_company_description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Contact Information</h3>
                            <i class="fas fa-address-card card-icon"></i>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" id="email" class="form-input" 
                                value="<?= htmlspecialchars($employer['usr_email'] ?? '') ?>" disabled>
                            <small style="color: #999; font-size: 0.85rem;">Email cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="phone">Phone Number *</label>
                            <div class="phone-container">
                                <select id="country_code" name="country_code" class="form-select country-code">
                                    <option value="+63" <?= $country_code === '+63' ? 'selected' : '' ?>>+63 (PH)</option>
                                    <option value="+1" <?= $country_code === '+1' ? 'selected' : '' ?>>+1 (US)</option>
                                    <option value="+44" <?= $country_code === '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                                    <option value="+61" <?= $country_code === '+61' ? 'selected' : '' ?>>+61 (AU)</option>
                                    <option value="+65" <?= $country_code === '+65' ? 'selected' : '' ?>>+65 (SG)</option>
                                    <option value="+60" <?= $country_code === '+60' ? 'selected' : '' ?>>+60 (MY)</option>
                                    <option value="+66" <?= $country_code === '+66' ? 'selected' : '' ?>>+66 (TH)</option>
                                    <option value="+84" <?= $country_code === '+84' ? 'selected' : '' ?>>+84 (VN)</option>
                                    <option value="+81" <?= $country_code === '+81' ? 'selected' : '' ?>>+81 (JP)</option>
                                    <option value="+82" <?= $country_code === '+82' ? 'selected' : '' ?>>+82 (KR)</option>
                                    <option value="+86" <?= $country_code === '+86' ? 'selected' : '' ?>>+86 (CN)</option>
                                    <option value="+91" <?= $country_code === '+91' ? 'selected' : '' ?>>+91 (IN)</option>
                                    <option value="+971" <?= $country_code === '+971' ? 'selected' : '' ?>>+971 (AE)</option>
                                    <option value="+973" <?= $country_code === '+973' ? 'selected' : '' ?>>+973 (BH)</option>
                                    <option value="+966" <?= $country_code === '+966' ? 'selected' : '' ?>>+966 (SA)</option>
                                    <option value="+20" <?= $country_code === '+20' ? 'selected' : '' ?>>+20 (EG)</option>
                                    <option value="+27" <?= $country_code === '+27' ? 'selected' : '' ?>>+27 (ZA)</option>
                                </select>
                                <input type="tel" id="phone" name="phone" class="form-input phone-number" 
                                    value="<?= htmlspecialchars($phone_number) ?>" 
                                    placeholder="9234567890" maxlength="11" pattern="[0-9]{10,11}" 
                                    title="Please enter 10-11 digits only (numbers only)" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-select">
                                <option value="">Select Gender</option>
                                <option value="Male" <?= (isset($employer['usr_gender']) && $employer['usr_gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= (isset($employer['usr_gender']) && $employer['usr_gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="birthdate">Birthdate</label>
                            <input type="date" id="birthdate" name="birthdate" class="form-input" 
                                value="<?= htmlspecialchars($employer['usr_birthdate'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div>
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Profile Photo</h3>
                            <i class="fas fa-camera card-icon"></i>
                        </div>
                        
                        <div class="profile-photo-container">
                            <img src="<?= !empty($employer['usr_profile_photo']) ? htmlspecialchars($employer['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($employer['emp_company_name']) . '&background=random' ?>" 
                                alt="Profile Photo" class="profile-photo" id="profilePhotoPreview">
                            
                            <div>
                                <div class="file-upload">
                                    <label class="file-upload-label">Upload New Photo</label>
                                    <label class="file-custom">
                                        <i class="fas fa-upload"></i> Choose File
                                        <input type="file" class="file-input" name="profile_photo" id="profile_photo" accept=".jpg,.jpeg,.png,.gif">
                                    </label>
                                    <span class="file-name" id="profile_photo_name">No file chosen</span>
                                    <div class="file-requirements">Accepted formats: JPG, JPEG, PNG, GIF. Max size: 2MB</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ENHANCED: Address Section with improved saving mechanism -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Company Address</h3>
                            <i class="fas fa-map-marker-alt card-icon"></i>
                        </div>
                        
                        <div class="form-group">
                            <div class="address-container">
                                <div class="address-group">
                                    <label class="form-label" for="region">Region *</label>
                                    <select id="region" name="region" class="form-select" required>
                                        <option value="">Select Region</option>
                                    </select>
                                    <div class="loading-spinner" id="regionLoading">
                                        <i class="fas fa-spinner"></i> Loading regions...
                                    </div>
                                </div>
                                <div class="address-group">
                                    <label class="form-label" for="province">Province *</label>
                                    <select id="province" name="province" class="form-select" required disabled>
                                        <option value="">Select Province</option>
                                    </select>
                                    <div class="loading-spinner" id="provinceLoading" style="display: none;">
                                        <i class="fas fa-spinner"></i> Loading provinces...
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="address-container">
                                <div class="address-group">
                                    <label class="form-label" for="city">City/Municipality *</label>
                                    <select id="city" name="city" class="form-select" required disabled>
                                        <option value="">Select City/Municipality</option>
                                    </select>
                                    <div class="loading-spinner" id="cityLoading" style="display: none;">
                                        <i class="fas fa-spinner"></i> Loading cities...
                                    </div>
                                </div>
                                <div class="address-group">
                                    <label class="form-label" for="barangay">Barangay *</label>
                                    <select id="barangay" name="barangay" class="form-select" required disabled>
                                        <option value="">Select Barangay</option>
                                    </select>
                                    <div class="loading-spinner" id="barangayLoading" style="display: none;">
                                        <i class="fas fa-spinner"></i> Loading barangays...
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="address-container">
                                <div class="address-group">
                                    <label class="form-label" for="zip_code">Zip Code</label>
                                    <input type="text" id="zip_code" name="zip_code" class="form-input" 
                                        value="<?= htmlspecialchars($parsed_address['zip_code'] ?? '') ?>" 
                                        placeholder="Zip Code" maxlength="4">
                                </div>
                                <div class="address-group">
                                    <label class="form-label" for="street_address">Street Address</label>
                                    <input type="text" id="street_address" name="street_address" class="form-input" 
                                        value="<?= htmlspecialchars($parsed_address['street_address'] ?? '') ?>" 
                                        placeholder="House No., Street, Subdivision">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Company Documents</h3>
                            <i class="fas fa-file-alt card-icon"></i>
                        </div>
                        
                        <div class="file-upload">
                            <label class="file-upload-label">Business Permit *</label>
                            <?php if (!empty($employer['emp_business_permit'])): ?>
                                <div class="document-preview">
                                    <i class="fas fa-check-circle"></i> Current file: <a href="<?= htmlspecialchars($employer['emp_business_permit']) ?>" target="_blank">View Document</a>
                                </div>
                            <?php endif; ?>
                            <label class="file-custom">
                                <i class="fas fa-upload"></i> Choose File
                                <input type="file" class="file-input" name="business_permit" id="business_permit" accept=".pdf,.jpg,.jpeg,.png">
                            </label>
                            <span class="file-name" id="business_permit_name">No file chosen</span>
                            <div class="file-requirements">Accepted formats: PDF, JPG, JPEG, PNG. Max size: 5MB</div>
                        </div>
                        
                        <div class="file-upload">
                            <label class="file-upload-label">DTI/SEC Certificate *</label>
                            <?php if (!empty($employer['emp_dti_sec'])): ?>
                                <div class="document-preview">
                                    <i class="fas fa-check-circle"></i> Current file: <a href="<?= htmlspecialchars($employer['emp_dti_sec']) ?>" target="_blank">View Document</a>
                                </div>
                            <?php endif; ?>
                            <label class="file-custom">
                                <i class="fas fa-upload"></i> Choose File
                                <input type="file" class="file-input" name="dti_sec" id="dti_sec" accept=".pdf,.jpg,.jpeg,.png">
                            </label>
                            <span class="file-name" id="dti_sec_name">No file chosen</span>
                            <div class="file-requirements">Accepted formats: PDF, JPG, JPEG, PNG. Max size: 5MB</div>
                        </div>
                    </div>
                    
                    <div class="profile-card">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Hidden field for cropped image data -->
            <input type="hidden" name="cropped_image_data" id="croppedImageData">
        </form>
    </div>

    <!-- Image Cropper Modal -->
    <div class="modal-overlay" id="cropperModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Crop Profile Photo</h3>
                <button class="modal-close" id="closeCropper">&times;</button>
            </div>
            <div class="modal-body">
                <div class="cropper-container">
                    <img id="imageToCrop" src="" alt="Image to crop">
                </div>
                <div class="cropper-preview"></div>
                <div class="cropper-actions">
                    <button class="btn btn-secondary" id="cancelCrop">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="btn btn-primary" id="applyCrop">
                        <i class="fas fa-check"></i> Apply Crop
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // PSGC API Integration
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
                    console.warn('PSGC API fetch failed:', error);
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

        // Enhanced Dropdown Functionality
        const notification = document.getElementById('notification');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const adminProfile = document.getElementById('adminProfile');
        const profileDropdown = document.getElementById('profileDropdown');
        
        notification.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('active');
            profileDropdown.classList.remove('active');
        });
        
        adminProfile.addEventListener('click', function(e) {
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
        
        // Enhanced File input display functionality
        document.querySelectorAll('.file-input').forEach(input => {
            const fileNameElement = document.getElementById(input.id + '_name');
            
            input.addEventListener('change', function() {
                if (this.files.length > 0) {
                    fileNameElement.textContent = this.files[0].name;
                    fileNameElement.style.color = 'var(--green)';
                    fileNameElement.style.fontWeight = '600';
                    
                    // Preview profile photo and open cropper if it's a profile photo
                    if (this.id === 'profile_photo' && this.files[0]) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // Open the cropper modal with the selected image
                            openImageCropper(e.target.result);
                        }
                        reader.readAsDataURL(this.files[0]);
                    }
                } else {
                    fileNameElement.textContent = 'No file chosen';
                    fileNameElement.style.color = '#666';
                    fileNameElement.style.fontWeight = '500';
                }
            });
        });
        
        // Image Cropper Functionality
        let cropper;
        const cropperModal = document.getElementById('cropperModal');
        const imageToCrop = document.getElementById('imageToCrop');
        const closeCropper = document.getElementById('closeCropper');
        const cancelCrop = document.getElementById('cancelCrop');
        const applyCrop = document.getElementById('applyCrop');
        const croppedImageData = document.getElementById('croppedImageData');
        const profilePhotoPreview = document.getElementById('profilePhotoPreview');
        
        function openImageCropper(imageSrc) {
            // Set the image source
            imageToCrop.src = imageSrc;
            
            // Show the modal
            cropperModal.style.display = 'flex';
            
            // Initialize cropper if not already initialized
            if (cropper) {
                cropper.destroy();
            }
            
            cropper = new Cropper(imageToCrop, {
                aspectRatio: 1,
                viewMode: 1,
                guides: true,
                background: false,
                autoCropArea: 0.8,
                responsive: true,
                restore: false,
                checkCrossOrigin: false,
                crop: function(event) {
                    // Update preview if needed
                }
            });
        }
        
        function closeImageCropper() {
            cropperModal.style.display = 'none';
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        }
        
        function applyImageCrop() {
            if (cropper) {
                // Get cropped canvas
                const canvas = cropper.getCroppedCanvas({
                    width: 300,
                    height: 300,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });
                
                // Convert canvas to base64 data URL
                const croppedDataURL = canvas.toDataURL('image/png');
                
                // Set the cropped image data to hidden input
                croppedImageData.value = croppedDataURL;
                
                // Update the profile photo preview
                profilePhotoPreview.src = croppedDataURL;
                
                // Clear the file input since we're using the cropped image
                document.getElementById('profile_photo').value = '';
                document.getElementById('profile_photo_name').textContent = 'No file chosen';
                document.getElementById('profile_photo_name').style.color = '#666';
                document.getElementById('profile_photo_name').style.fontWeight = '500';
                
                // Close the cropper
                closeImageCropper();
            }
        }
        
        // Event listeners for cropper modal
        closeCropper.addEventListener('click', closeImageCropper);
        cancelCrop.addEventListener('click', closeImageCropper);
        applyCrop.addEventListener('click', applyImageCrop);
        
        // Close modal when clicking outside
        cropperModal.addEventListener('click', function(e) {
            if (e.target === cropperModal) {
                closeImageCropper();
            }
        });
        
        // Mark notifications as read functionality
        document.addEventListener('DOMContentLoaded', function() {
            const markAllReadBtn = document.getElementById('markAllRead');
            const notificationItems = document.querySelectorAll('.notification-link.unread');
            
            // Mark all as read
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Get all unread notification IDs
                    const unreadIds = Array.from(notificationItems).map(item => 
                        item.getAttribute('data-notif-id')
                    );
                    
                    if (unreadIds.length > 0) {
                        // Send AJAX request to mark all as read
                        const formData = new FormData();
                        formData.append('ajax_action', 'mark_notifications_read');
                        formData.append('mark_all', 'true');
                        
                        fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Update UI
                                notificationItems.forEach(item => {
                                    item.classList.remove('unread');
                                });
                                
                                // Update notification count
                                const notificationCount = document.querySelector('.notification-count');
                                if (notificationCount) {
                                    notificationCount.remove();
                                }
                                
                                // Remove mark all button
                                markAllReadBtn.remove();
                            }
                        })
                        .catch(error => {
                            console.error('Error marking notifications as read:', error);
                        });
                    }
                });
            }
            
            // Mark single notification as read when clicked
            notificationItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (!this.classList.contains('unread')) return;
                    
                    const notifId = this.getAttribute('data-notif-id');
                    
                    // Send AJAX request to mark as read
                    const formData = new FormData();
                    formData.append('ajax_action', 'mark_notifications_read');
                    formData.append('notification_ids[]', notifId);
                    
                    fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI
                            this.classList.remove('unread');
                            
                            // Update notification count
                            const notificationCount = document.querySelector('.notification-count');
                            if (notificationCount) {
                                const newCount = parseInt(notificationCount.textContent) - 1;
                                if (newCount > 0) {
                                    notificationCount.textContent = newCount;
                                } else {
                                    notificationCount.remove();
                                    const markAllBtn = document.getElementById('markAllRead');
                                    if (markAllBtn) markAllBtn.remove();
                                }
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notification as read:', error);
                    });
                });
            });
        });
        
        // Industry selection handling
        document.addEventListener('DOMContentLoaded', function() {
            const industrySelect = document.getElementById('industry');
            const customIndustryContainer = document.getElementById('customIndustryContainer');
            const customIndustryInput = document.getElementById('custom_industry');
            
            if (industrySelect) {
                industrySelect.addEventListener('change', function() {
                    if (this.value === '9999') {
                        customIndustryContainer.style.display = 'block';
                        customIndustryInput.required = true;
                    } else {
                        customIndustryContainer.style.display = 'none';
                        customIndustryInput.required = false;
                        customIndustryInput.value = '';
                    }
                });
                
                // Initialize industry selection
                if (industrySelect.value === '9999') {
                    customIndustryContainer.style.display = 'block';
                    customIndustryInput.required = true;
                }
            }
            
            // ENHANCED: Initialize PSGC Address API with improved address handling
            const addressAPI = new PSGCAddressAPI();
            
            // DOM elements for address
            const regionSelect = document.getElementById('region');
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');
            const regionLoading = document.getElementById('regionLoading');
            const provinceLoading = document.getElementById('provinceLoading');
            const cityLoading = document.getElementById('cityLoading');
            const barangayLoading = document.getElementById('barangayLoading');
            
            // Pre-filled address values from PHP
            const preFilledRegion = "<?= htmlspecialchars($parsed_address['region'] ?? '') ?>";
            const preFilledProvince = "<?= htmlspecialchars($parsed_address['province'] ?? '') ?>";
            const preFilledCity = "<?= htmlspecialchars($parsed_address['city'] ?? '') ?>";
            const preFilledBarangay = "<?= htmlspecialchars($parsed_address['barangay'] ?? '') ?>";
            
            // Load regions on page load
            loadRegions();
            
            async function loadRegions() {
                if (!regionSelect) return;
                
                regionLoading.style.display = 'block';
                try {
                    const regions = await addressAPI.getRegions();
                    regionSelect.innerHTML = '<option value="">Select Region</option>';
                    
                    if (regions.length === 0) {
                        showNotification('Unable to load regions. Please refresh the page.', 'error');
                    } else {
                        // ENHANCED: Store region names (not codes) for proper saving
                        regions.forEach(region => {
                            const option = document.createElement('option');
                            option.value = region.name; // Store the name
                            option.setAttribute('data-code', region.code);
                            option.textContent = region.name;
                            
                            // Select pre-filled region if exists
                            if (region.name === preFilledRegion) {
                                option.selected = true;
                            }
                            
                            regionSelect.appendChild(option);
                        });
                        
                        // If a region is pre-filled, trigger loading of its provinces
                        if (preFilledRegion) {
                            setTimeout(() => {
                                const regionOption = Array.from(regionSelect.options).find(opt => opt.value === preFilledRegion);
                                if (regionOption) {
                                    regionSelect.value = preFilledRegion;
                                    regionSelect.dispatchEvent(new Event('change'));
                                }
                            }, 100);
                        }
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
                            // ENHANCED: Store province names (not codes) for proper saving
                            provinces.forEach(province => {
                                const option = document.createElement('option');
                                option.value = province.name; // Store the name
                                option.setAttribute('data-code', province.code);
                                option.textContent = province.name;
                                
                                // Select pre-filled province if exists
                                if (province.name === preFilledProvince) {
                                    option.selected = true;
                                }
                                
                                provinceSelect.appendChild(option);
                            });
                            
                            provinceLoading.style.display = 'none';
                            provinceSelect.disabled = false;
                            
                            // If a province is pre-filled, trigger loading of its cities
                            if (preFilledProvince) {
                                setTimeout(() => {
                                    const provinceOption = Array.from(provinceSelect.options).find(opt => opt.value === preFilledProvince);
                                    if (provinceOption) {
                                        provinceSelect.value = preFilledProvince;
                                        provinceSelect.dispatchEvent(new Event('change'));
                                    }
                                }, 100);
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
                            // ENHANCED: Store city names (not codes) for proper saving
                            cities.forEach(city => {
                                const option = document.createElement('option');
                                option.value = city.name; // Store the name
                                option.setAttribute('data-code', city.code);
                                option.textContent = city.name;
                                
                                // Select pre-filled city if exists
                                if (city.name === preFilledCity) {
                                    option.selected = true;
                                }
                                
                                citySelect.appendChild(option);
                            });
                            
                            cityLoading.style.display = 'none';
                            citySelect.disabled = false;
                            
                            // If a city is pre-filled, trigger loading of its barangays
                            if (preFilledCity) {
                                setTimeout(() => {
                                    const cityOption = Array.from(citySelect.options).find(opt => opt.value === preFilledCity);
                                    if (cityOption) {
                                        citySelect.value = preFilledCity;
                                        citySelect.dispatchEvent(new Event('change'));
                                    }
                                }, 100);
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
                            // ENHANCED: Store barangay names for proper saving
                            barangays.forEach(barangay => {
                                const option = document.createElement('option');
                                option.value = barangay.name;
                                option.textContent = barangay.name;
                                
                                // Select pre-filled barangay if exists
                                if (barangay.name === preFilledBarangay) {
                                    option.selected = true;
                                }
                                
                                barangaySelect.appendChild(option);
                            });
                            
                            barangayLoading.style.display = 'none';
                            barangaySelect.disabled = false;
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
            
            function showNotification(message, type = 'info') {
                const notification = document.createElement('div');
                notification.className = `alert alert-${type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success'}`;
                notification.innerHTML = `
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'check-circle'}"></i>
                    ${message}
                `;
                
                const content = document.querySelector('.main-content');
                const welcomeMessage = document.querySelector('.welcome-message');
                if (welcomeMessage) {
                    content.insertBefore(notification, welcomeMessage.nextElementSibling);
                } else {
                    content.insertBefore(notification, content.firstChild);
                }
                
                setTimeout(() => {
                    notification.remove();
                }, 5000);
            }
            
            // Form validation
            const profileForm = document.getElementById('profileForm');
            
            profileForm.addEventListener('submit', function(e) {
                // Validate required address fields
                const requiredAddressFields = ['region', 'province', 'city', 'barangay'];
                let hasAddressErrors = false;
                
                requiredAddressFields.forEach(field => {
                    const element = document.getElementById(field);
                    if (element && !element.value) {
                        hasAddressErrors = true;
                        element.classList.add('error');
                    } else if (element) {
                        element.classList.remove('error');
                    }
                });
                
                if (hasAddressErrors) {
                    e.preventDefault();
                    showNotification('Please complete all required address fields.', 'error');
                    return;
                }
                
                // Validate phone number length
                const phoneInput = document.getElementById('phone');
                if (phoneInput && phoneInput.value) {
                    const phoneValue = phoneInput.value.replace(/[^0-9]/g, '');
                    if (phoneValue.length < 10 || phoneValue.length > 11) {
                        e.preventDefault();
                        phoneInput.classList.add('error');
                        showNotification('Phone number must be between 10 and 11 digits.', 'error');
                        return;
                    } else {
                        phoneInput.classList.remove('error');
                    }
                }
                
                // Validate industry selection
                const industrySelect = document.getElementById('industry');
                const customIndustryInput = document.getElementById('custom_industry');
                
                if (industrySelect && industrySelect.value === '9999') {
                    // Custom industry selected
                    if (!customIndustryInput || !customIndustryInput.value.trim()) {
                        e.preventDefault();
                        customIndustryInput.classList.add('error');
                        showNotification('Please enter a custom industry name.', 'error');
                        return;
                    } else {
                        customIndustryInput.classList.remove('error');
                    }
                } else if (industrySelect && !industrySelect.value) {
                    // No industry selected
                    e.preventDefault();
                    industrySelect.classList.add('error');
                    showNotification('Please select an industry.', 'error');
                    return;
                } else {
                    if (industrySelect) industrySelect.classList.remove('error');
                    if (customIndustryInput) customIndustryInput.classList.remove('error');
                }
                
                // All validations passed
                showNotification('Updating profile...', 'info');
            });
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.display = 'none';
                });
            }, 5000);
        });
    </script>
</body>
</html>