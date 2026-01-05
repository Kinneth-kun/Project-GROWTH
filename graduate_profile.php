<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

// PSGC API Endpoints
define('REGIONS_API', 'https://psgc.gitlab.io/api/regions/');
define('PROVINCES_API', 'https://psgc.gitlab.io/api/regions/{code}/provinces/');
define('CITIES_API', 'https://psgc.gitlab.io/api/provinces/{code}/cities-municipalities/');
define('BARANGAYS_API', 'https://psgc.gitlab.io/api/cities-municipalities/{code}/barangays/');

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Debug session data
    error_log("Session Data: " . print_r($_SESSION, true));
    
    // Check if user is logged in and is a graduate
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        error_log("User not logged in or session missing, redirecting to index.php");
        header("Location: index.php");
        exit();
    }
    
    // Check if user role is graduate
    if ($_SESSION['user_role'] !== 'graduate') {
        error_log("User role is not graduate, redirecting to index.php. Role: " . $_SESSION['user_role']);
        header("Location: index.php");
        exit();
    }
    
    // ============================================================================
    // PSGC API FUNCTIONS
    // ============================================================================
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
    
    // ============================================================================
    // ENHANCED NOTIFICATION GENERATION SYSTEM FOR GRADUATES
    // ============================================================================
    
    /**
     * Function to create notifications for graduate
     */
    function createGraduateNotification($conn, $graduate_id, $type, $message, $related_id = null) {
        try {
            $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                                   VALUES (?, ?, ?, NOW())");
            $stmt->execute([$graduate_id, $message, $type]);
            return true;
        } catch (PDOException $e) {
            error_log("Notification creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check for application status updates and generate notifications
     */
    function checkApplicationUpdates($conn, $graduate_id) {
        $notificationsGenerated = 0;
        
        // Check for recent application status changes
        $recentApps = $conn->prepare("
            SELECT a.app_id, j.job_title, a.app_status, a.app_updated_at
            FROM applications a
            JOIN jobs j ON a.app_job_id = j.job_id
            WHERE a.app_grad_usr_id = ? 
            AND a.app_updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY a.app_updated_at DESC
        ");
        $recentApps->execute([$graduate_id]);
        $applications = $recentApps->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($applications as $app) {
            // Check if notification already exists for this application update
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_message LIKE ? 
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $searchPattern = "%{$app['job_title']}%";
            $existingNotif->execute([$graduate_id, $searchPattern]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $statusText = ucfirst($app['app_status']);
                $message = "Your application for '{$app['job_title']}' has been updated to: {$statusText}";
                
                if (createGraduateNotification($conn, $graduate_id, 'application_update', $message, $app['app_id'])) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for new job recommendations and generate notifications
     */
    function checkJobRecommendations($conn, $graduate_id, $graduate) {
        $notificationsGenerated = 0;
        
        // Get job preferences (JSON array)
        $job_preferences = $graduate['grad_job_preference'] ?? '[]';
        $preferences_array = json_decode($job_preferences, true);
        
        if (empty($preferences_array) || !is_array($preferences_array)) {
            return 0;
        }
        
        // Check for new jobs matching any of the preferences
        foreach ($preferences_array as $preference) {
            $newJobs = $conn->prepare("
                SELECT j.job_id, j.job_title, e.emp_company_name
                FROM jobs j
                JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
                WHERE j.job_status = 'active'
                AND (j.job_domain LIKE CONCAT('%', ?, '%') OR j.job_title LIKE CONCAT('%', ?, '%'))
                AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY j.job_created_at DESC
                LIMIT 2
            ");
            $newJobs->execute([$preference, $preference]);
            $jobs = $newJobs->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($jobs as $job) {
                // Check if notification already exists
                $existingNotif = $conn->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE notif_usr_id = ? 
                    AND notif_message LIKE ? 
                    AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                ");
                $searchPattern = "%{$job['job_title']}%";
                $existingNotif->execute([$graduate_id, $searchPattern]);
                
                if ($existingNotif->fetchColumn() == 0) {
                    $message = "New job match: '{$job['job_title']}' at {$job['emp_company_name']}";
                    
                    if (createGraduateNotification($conn, $graduate_id, 'job_recommendation', $message, $job['job_id'])) {
                        $notificationsGenerated++;
                    }
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for portfolio completeness and generate notifications
     */
    function checkPortfolioCompleteness($conn, $graduate_id, $portfolio_stats) {
        $notificationsGenerated = 0;
        
        // Check for missing resume
        if (($portfolio_stats['has_resume'] ?? 0) == 0) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'portfolio_reminder'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Complete your portfolio: Upload your resume to increase job matches";
                
                if (createGraduateNotification($conn, $graduate_id, 'portfolio_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        // Check for low skill count
        if (($portfolio_stats['skill_count'] ?? 0) < 3) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'skill_reminder'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Add more skills to your portfolio to improve job recommendations";
                
                if (createGraduateNotification($conn, $graduate_id, 'skill_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for career development opportunities
     */
    function checkCareerDevelopment($conn, $graduate_id) {
        $notificationsGenerated = 0;
        
        // Check for unread shared resources
        $unreadResources = $conn->prepare("
            SELECT COUNT(*) as count FROM shared_resources 
            WHERE grad_usr_id = ? AND is_read = FALSE
        ");
        $unreadResources->execute([$graduate_id]);
        $resourceCount = $unreadResources->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($resourceCount > 0) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'career_resource'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "You have {$resourceCount} new career development resource(s) available";
                
                if (createGraduateNotification($conn, $graduate_id, 'career_resource', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for profile views and generate notifications
     */
    function checkProfileViews($conn, $graduate_id) {
        $notificationsGenerated = 0;
        
        // Check for recent employer profile views
        $recentViews = $conn->prepare("
            SELECT COUNT(*) as view_count, e.emp_company_name
            FROM employer_profile_views epv
            JOIN employers e ON epv.view_emp_usr_id = e.emp_usr_id
            WHERE epv.view_grad_usr_id = ?
            AND epv.view_viewed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY e.emp_company_name
            ORDER BY view_count DESC
            LIMIT 1
        ");
        $recentViews->execute([$graduate_id]);
        $views = $recentViews->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($views as $view) {
            if ($view['view_count'] > 0) {
                $existingNotif = $conn->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE notif_usr_id = ? 
                    AND notif_message LIKE ?
                    AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                ");
                $searchPattern = "%{$view['emp_company_name']}%";
                $existingNotif->execute([$graduate_id, $searchPattern]);
                
                if ($existingNotif->fetchColumn() == 0) {
                    $message = "Your profile was viewed by {$view['emp_company_name']}";
                    
                    if (createGraduateNotification($conn, $graduate_id, 'profile_view', $message)) {
                        $notificationsGenerated++;
                    }
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Enhanced system notifications generator for graduates
     */
    function generateGraduateNotifications($conn, $graduate_id, $graduate, $portfolio_stats) {
        $totalNotifications = 0;
        
        // 1. Check for application status updates
        $totalNotifications += checkApplicationUpdates($conn, $graduate_id);
        
        // 2. Check for new job recommendations
        $totalNotifications += checkJobRecommendations($conn, $graduate_id, $graduate);
        
        // 3. Check for portfolio completeness
        $totalNotifications += checkPortfolioCompleteness($conn, $graduate_id, $portfolio_stats);
        
        // 4. Check for career development opportunities
        $totalNotifications += checkCareerDevelopment($conn, $graduate_id);
        
        // 5. Check for profile views
        $totalNotifications += checkProfileViews($conn, $graduate_id);
        
        return $totalNotifications;
    }
    
    // Get graduate data
    $graduate_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT g.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, u.usr_gender, u.usr_birthdate, u.usr_address,
               u.usr_account_status, u.usr_is_approved
        FROM graduates g 
        JOIN users u ON g.grad_usr_id = u.usr_id 
        WHERE g.grad_usr_id = :graduate_id
    ");
    $stmt->bindParam(':graduate_id', $graduate_id);
    $stmt->execute();
    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$graduate) {
        // If no graduate record found, redirect to profile setup
        header("Location: graduate_form.php");
        exit();
    }
    
    // Check if user account is active and approved
    if ($graduate['usr_account_status'] !== 'active' || !$graduate['usr_is_approved']) {
        error_log("User account not active or approved. Status: " . $graduate['usr_account_status'] . ", Approved: " . $graduate['usr_is_approved']);
        header("Location: index.php?error=account_inactive");
        exit();
    }
    
    // Get portfolio status for notification generation
    $portfolio_stats = ['has_resume' => 0, 'skill_count' => 0, 'total_items' => 0];
    try {
        $portfolio_stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN port_item_type = 'resume' AND port_item_file IS NOT NULL THEN 1 ELSE 0 END) as has_resume,
                SUM(CASE WHEN port_item_type = 'skill' THEN 1 ELSE 0 END) as skill_count
            FROM portfolio_items
            WHERE port_usr_id = :graduate_id
        ");
        $portfolio_stmt->bindParam(':graduate_id', $graduate_id);
        $portfolio_stmt->execute();
        $portfolio_stats = $portfolio_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Portfolio items query error: " . $e->getMessage());
        $portfolio_stats = ['has_resume' => 0, 'skill_count' => 0, 'total_items' => 0];
    }
    
    // Generate notifications for graduate
    $notificationsGenerated = generateGraduateNotifications($conn, $graduate_id, $graduate, $portfolio_stats);
    
    // Get comprehensive notifications
    $notifications = [];
    $unread_notif_count = 0;
    try {
        $notif_stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE notif_usr_id = :graduate_id 
            ORDER BY notif_created_at DESC 
            LIMIT 15
        ");
        $notif_stmt->bindParam(':graduate_id', $graduate_id);
        $notif_stmt->execute();
        $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notifications as $notif) {
            if (!$notif['notif_is_read']) $unread_notif_count++;
        }
        
        // If no notifications found, create some default ones based on user activity
        if (empty($notifications)) {
            // Get application statistics for default notifications
            $apps_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_applications,
                    SUM(CASE WHEN app_status = 'pending' THEN 1 ELSE 0 END) as applied,
                    SUM(CASE WHEN app_status = 'reviewed' THEN 1 ELSE 0 END) as under_review,
                    SUM(CASE WHEN app_status = 'qualified' THEN 1 ELSE 0 END) as qualified,
                    SUM(CASE WHEN app_status = 'hired' THEN 1 ELSE 0 END) as hired,
                    SUM(CASE WHEN app_status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM applications
                WHERE app_grad_usr_id = :graduate_id
            ");
        $apps_stmt->bindParam(':graduate_id', $graduate_id);
        $apps_stmt->execute();
        $app_stats = $apps_stmt->fetch(PDO::FETCH_ASSOC);
            
        $notifications = generateDefaultNotifications($conn, $graduate_id, $graduate, $app_stats, $portfolio_stats);
        $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultNotifications($conn, $graduate_id, $graduate, ['total_applications' => 0], $portfolio_stats);
        $unread_notif_count = count($notifications);
    }
    
    // Calculate profile completion percentage
    $complete_fields = 0;
    $total_fields = 9; // Updated to include address field
    
    if (!empty($graduate['grad_degree'])) $complete_fields++;
    if (!empty($graduate['grad_year_graduated'])) $complete_fields++;
    if (!empty($graduate['grad_job_preference'])) $complete_fields++;
    if (!empty($graduate['grad_summary'])) $complete_fields++;
    if (!empty($graduate['usr_profile_photo'])) $complete_fields++;
    if (!empty($graduate['usr_phone'])) $complete_fields++;
    if (!empty($graduate['usr_gender'])) $complete_fields++;
    if (!empty($graduate['usr_birthdate'])) $complete_fields++;
    if (!empty($graduate['usr_address'])) $complete_fields++;
    
    $profile_complete_percentage = round(($complete_fields / $total_fields) * 100);
    
    // ============================================================================
    // FETCH COURSES AND JOB POSITIONS FROM DATABASE (Same as graduate_form.php)
    // ============================================================================
    $courses = [];
    $job_positions = [];
    $job_categories = [];
    $colleges = [];

    try {
        // Fetch all active courses grouped by college
        $courses_stmt = $conn->prepare("
            SELECT course_id, course_code, course_name, course_college 
            FROM courses 
            WHERE is_active = TRUE 
            ORDER BY course_college, course_name
        ");
        $courses_stmt->execute();
        $all_courses = $courses_stmt->fetchAll();
        
        // Organize courses by college
        foreach ($all_courses as $course) {
            $college = $course['course_college'];
            if (!isset($courses[$college])) {
                $courses[$college] = [];
                $colleges[] = $college;
            }
            $courses[$college][] = $course;
        }
        
        // Fetch all active job categories
        $categories_stmt = $conn->prepare("
            SELECT category_id, category_name 
            FROM job_categories 
            WHERE is_active = TRUE 
            ORDER BY category_name
        ");
        $categories_stmt->execute();
        $job_categories = $categories_stmt->fetchAll();
        
        // Fetch all active job positions
        $jobs_stmt = $conn->prepare("
            SELECT 
                jp.position_id,
                jp.position_name,
                jc.category_id,
                jc.category_name
            FROM job_positions jp
            INNER JOIN job_categories jc ON jp.category_id = jc.category_id
            WHERE jp.is_active = TRUE AND jc.is_active = TRUE
            ORDER BY jc.category_name, jp.position_name
        ");
        $jobs_stmt->execute();
        $all_jobs = $jobs_stmt->fetchAll();
        
        // Organize job positions by category
        foreach ($all_jobs as $job) {
            $category_id = $job['category_id'];
            if (!isset($job_positions[$category_id])) {
                $job_positions[$category_id] = [
                    'category_name' => $job['category_name'],
                    'positions' => []
                ];
            }
            $job_positions[$category_id]['positions'][] = [
                'position_id' => $job['position_id'],
                'position_name' => $job['position_name']
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching dropdown data - " . $e->getMessage());
        // Fallback to empty arrays if database query fails
        $courses = [];
        $job_positions = [];
        $job_categories = [];
        $colleges = [];
    }
    
    // ============================================================================
    // HANDLE FORM SUBMISSION
    // ============================================================================
    $error = '';
    $success = '';
    
    // Store form data in session for repopulation after submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $last_name = trim($_POST['last_name'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $alumni_number = trim($_POST['alumni_number'] ?? '');
        $degree = trim($_POST['degree'] ?? '');
        $year_graduated = trim($_POST['year_graduated'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $country_code = trim($_POST['country_code'] ?? '+63');
        $phone = trim($_POST['mobile_number'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $birthdate = trim($_POST['date_of_birth'] ?? '');
        
        // Address fields - IMPORTANT: Store the names, not just the codes
        $region = trim($_POST['region'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        
        // Get job preferences (can be multiple)
        $job_preferences = isset($_POST['job_preferences']) ? $_POST['job_preferences'] : [];
        $custom_job_preferences = isset($_POST['custom_job_preferences']) ? $_POST['custom_job_preferences'] : [];
        
        // Validate required fields
        $required_fields = [
            'last_name' => $last_name,
            'first_name' => $first_name,
            'email' => $graduate['usr_email'], // Email is from session, not editable
            'alumni_number' => $alumni_number,
            'degree' => $degree,
            'year_graduated' => $year_graduated,
            'region' => $region,
            'province' => $province,
            'city' => $city,
            'barangay' => $barangay
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field_name => $value) {
            if (empty($value)) {
                $missing_fields[] = $field_name;
            }
        }
        
        // Validate job preferences - only check if at least one is selected
        if (empty($job_preferences) && empty($custom_job_preferences)) {
            $missing_fields[] = 'job_preferences';
        }
        
        if (!empty($missing_fields)) {
            $error = "All required fields must be filled: " . implode(', ', $missing_fields);
        } elseif ($year_graduated < 1990 || $year_graduated > date('Y')) {
            $error = "Please enter a valid graduation year (1990-" . date('Y') . ").";
        } else {
            // Validate phone number
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 11)) {
                $error = "Phone number must be between 10 and 11 digits.";
            } else {
                $full_phone = $country_code . $phone;
                
                // Combine names
                $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : "") . "$last_name");
                
                // Combine address components
                $address_components = [];
                if (!empty($street_address)) $address_components[] = $street_address;
                if (!empty($barangay)) $address_components[] = $barangay;
                if (!empty($city)) $address_components[] = $city;
                if (!empty($province)) $address_components[] = $province;
                if (!empty($region)) $address_components[] = $region;
                if (!empty($zip_code)) $address_components[] = "ZIP: " . $zip_code;
                
                $permanent_address = implode(', ', $address_components);
                
                // Process job preferences
                $all_job_preferences = [];
                
                // Add selected job preferences
                foreach ($job_preferences as $job_pref) {
                    if (!empty($job_pref)) {
                        $all_job_preferences[] = htmlspecialchars($job_pref);
                    }
                }
                
                // Process custom job preferences
                foreach ($custom_job_preferences as $custom_pref) {
                    $custom_pref = trim($custom_pref);
                    if (!empty($custom_pref)) {
                        $all_job_preferences[] = $custom_pref;
                        
                        // Check if this custom preference already exists in job_positions table
                        $check_existing_stmt = $conn->prepare("
                            SELECT position_id FROM job_positions 
                            WHERE position_name = :pref_name 
                            AND is_active = TRUE
                        ");
                        $check_existing_stmt->bindParam(':pref_name', $custom_pref);
                        $check_existing_stmt->execute();
                        
                        if ($check_existing_stmt->rowCount() == 0) {
                            // Insert new custom job position into job_positions table
                            $category_stmt = $conn->prepare("
                                SELECT category_id FROM job_categories 
                                WHERE (category_name LIKE '%Other%' OR category_name LIKE '%Custom%') 
                                AND is_active = TRUE 
                                LIMIT 1
                            ");
                            $category_stmt->execute();
                            $category = $category_stmt->fetch();
                            
                            $category_id = $category ? $category['category_id'] : null;
                            
                            // If no "Other" category exists, create one
                            if (!$category_id) {
                                $insert_category_stmt = $conn->prepare("
                                    INSERT INTO job_categories (category_name, description, is_active, created_at) 
                                    VALUES ('Other/Custom', 'Custom job positions added by users', TRUE, NOW())
                                ");
                                $insert_category_stmt->execute();
                                $category_id = $conn->lastInsertId();
                            }
                            
                            // Insert the custom job position - REMOVED is_custom column
                            $insert_position_stmt = $conn->prepare("
                                INSERT INTO job_positions (
                                    category_id, 
                                    position_name, 
                                    is_active, 
                                    created_at
                                ) VALUES (
                                    :category_id, 
                                    :position_name, 
                                    TRUE, 
                                    NOW()
                                )
                            ");
                            $insert_position_stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
                            $insert_position_stmt->bindParam(':position_name', $custom_pref);
                            $insert_position_stmt->execute();
                        }
                    }
                }
                
                // Store job preferences as JSON array
                $job_preferences_json = json_encode($all_job_preferences, JSON_UNESCAPED_SLASHES);
                
                // Handle file uploads
                $profile_photo_path = $graduate['usr_profile_photo'] ?? '';
                
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
                            
                            $new_filename = 'profile_' . $graduate_id . '_' . time() . '.' . $file_ext;
                            $target_path = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($profile_photo['tmp_name'], $target_path)) {
                                $profile_photo_path = $target_path;
                                
                                // Delete old profile photo if exists
                                if (!empty($graduate['usr_profile_photo']) && file_exists($graduate['usr_profile_photo'])) {
                                    unlink($graduate['usr_profile_photo']);
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
                
                // Handle cropped image upload
                if (!empty($_POST['cropped_image_data'])) {
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
                        
                        $new_filename = 'profile_' . $graduate_id . '_' . time() . '.png';
                        $target_path = $upload_dir . $new_filename;
                        
                        if (file_put_contents($target_path, $cropped_image)) {
                            $profile_photo_path = $target_path;
                            
                            // Delete old profile photo if exists
                            if (!empty($graduate['usr_profile_photo']) && file_exists($graduate['usr_profile_photo'])) {
                                unlink($graduate['usr_profile_photo']);
                            }
                        } else {
                            $error = "Failed to save cropped profile photo. Please try again.";
                        }
                    } else {
                        $error = "Invalid cropped image data. Please try again.";
                    }
                }
                
                if (empty($error)) {
                    // Begin transaction
                    $conn->beginTransaction();
                    
                    try {
                        // Check for duplicate alumni number
                        $check_stmt = $conn->prepare("SELECT grad_id FROM graduates WHERE grad_school_id = :alumni_number AND grad_usr_id != :graduate_id");
                        $check_stmt->bindParam(':alumni_number', $alumni_number);
                        $check_stmt->bindParam(':graduate_id', $graduate_id, PDO::PARAM_INT);
                        $check_stmt->execute();
                        
                        if ($check_stmt->rowCount() > 0) {
                            throw new Exception("This alumni number is already registered by another user.");
                        }
                        
                        // Update graduate data
                        $update_stmt = $conn->prepare("
                            UPDATE graduates 
                            SET grad_degree = :degree, 
                                grad_year_graduated = :year_graduated, 
                                grad_job_preference = :job_preference,
                                grad_summary = :summary,
                                grad_school_id = :alumni_number,
                                grad_updated_at = NOW()
                            WHERE grad_usr_id = :graduate_id
                        ");
                        
                        $update_stmt->bindParam(':degree', $degree);
                        $update_stmt->bindParam(':year_graduated', $year_graduated);
                        $update_stmt->bindParam(':job_preference', $job_preferences_json);
                        $update_stmt->bindParam(':summary', $summary);
                        $update_stmt->bindParam(':alumni_number', $alumni_number);
                        $update_stmt->bindParam(':graduate_id', $graduate_id, PDO::PARAM_INT);
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to update graduate information.");
                        }
                        
                        // Update user data
                        $user_update_stmt = $conn->prepare("
                            UPDATE users 
                            SET usr_name = :full_name,
                                usr_phone = :phone, 
                                usr_profile_photo = :profile_photo,
                                usr_gender = :gender,
                                usr_birthdate = :birthdate,
                                usr_address = :address,
                                usr_updated_at = NOW()
                            WHERE usr_id = :graduate_id
                        ");
                        
                        $user_update_stmt->bindParam(':full_name', $full_name);
                        $user_update_stmt->bindParam(':phone', $full_phone);
                        $user_update_stmt->bindParam(':profile_photo', $profile_photo_path);
                        $user_update_stmt->bindParam(':gender', $gender);
                        $user_update_stmt->bindParam(':birthdate', $birthdate);
                        $user_update_stmt->bindParam(':address', $permanent_address);
                        $user_update_stmt->bindParam(':graduate_id', $graduate_id, PDO::PARAM_INT);
                        
                        if (!$user_update_stmt->execute()) {
                            throw new Exception("Failed to update user information.");
                        }
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $success = "Profile updated successfully!";
                        
                        // Create notification for profile update
                        createGraduateNotification($conn, $graduate_id, 'profile_update', 'Your profile has been successfully updated.');
                        
                        // Refresh graduate data
                        $stmt->execute();
                        $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        // Update session name
                        $_SESSION['user_name'] = $full_name;
                        
                        // Store the submitted address values in session for JavaScript to use
                        $_SESSION['last_address_data'] = [
                            'region' => $region,
                            'province' => $province,
                            'city' => $city,
                            'barangay' => $barangay,
                            'zip_code' => $zip_code,
                            'street_address' => $street_address
                        ];
                        
                        // Recalculate profile completion
                        $complete_fields = 0;
                        if (!empty($graduate['grad_degree'])) $complete_fields++;
                        if (!empty($graduate['grad_year_graduated'])) $complete_fields++;
                        if (!empty($graduate['grad_job_preference'])) $complete_fields++;
                        if (!empty($graduate['grad_summary'])) $complete_fields++;
                        if (!empty($graduate['usr_profile_photo'])) $complete_fields++;
                        if (!empty($graduate['usr_phone'])) $complete_fields++;
                        if (!empty($graduate['usr_gender'])) $complete_fields++;
                        if (!empty($graduate['usr_birthdate'])) $complete_fields++;
                        if (!empty($graduate['usr_address'])) $complete_fields++;
                        
                        $profile_complete_percentage = round(($complete_fields / $total_fields) * 100);
                        
                    } catch (Exception $e) {
                        // Rollback transaction on error
                        $conn->rollBack();
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
}

/**
 * Generate default notifications based on user data and activities
 */
function generateDefaultNotifications($conn, $graduate_id, $graduate, $app_stats, $portfolio_stats) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Profile Management! Complete your profile to get better job matches.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Profile completion notifications
    $complete_fields = 0;
    $total_fields = 9;
    
    if (!empty($graduate['grad_degree'])) $complete_fields++;
    if (!empty($graduate['grad_year_graduated'])) $complete_fields++;
    if (!empty($graduate['grad_job_preference'])) $complete_fields++;
    if (!empty($graduate['grad_summary'])) $complete_fields++;
    if (!empty($graduate['usr_profile_photo'])) $complete_fields++;
    if (!empty($graduate['usr_phone'])) $complete_fields++;
    if (!empty($graduate['usr_gender'])) $complete_fields++;
    if (!empty($graduate['usr_birthdate'])) $complete_fields++;
    if (!empty($graduate['usr_address'])) $complete_fields++;
    
    $completion_percentage = round(($complete_fields / $total_fields) * 100);
    
    if ($completion_percentage < 70) {
        $default_notifications[] = [
            'notif_message' => 'Complete your profile to increase your visibility to employers.',
            'notif_type' => 'profile_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    }
    
    // Portfolio completion notifications
    if (($portfolio_stats['has_resume'] ?? 0) == 0) {
        $default_notifications[] = [
            'notif_message' => 'Upload your resume to complete your portfolio and increase job matches.',
            'notif_type' => 'portfolio_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ];
    }
    
    return $default_notifications;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Handle mark as read for notifications
if (isset($_POST['mark_all_read']) && $_POST['mark_all_read'] == '1') {
    try {
        $update_stmt = $conn->prepare("
            UPDATE notifications 
            SET notif_is_read = 1 
            WHERE notif_usr_id = :graduate_id AND notif_is_read = 0
        ");
        $update_stmt->bindParam(':graduate_id', $graduate_id);
        $update_stmt->execute();
        
        // Refresh page to show updated notifications
        header("Location: graduate_profile.php");
        exit();
    } catch (PDOException $e) {
        error_log("Mark notifications as read error: " . $e->getMessage());
    }
}

// Handle mark single notification as read via AJAX
if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
    $notif_id = $_POST['notif_id'];
    try {
        $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :graduate_id");
        $mark_read_stmt->bindParam(':notif_id', $notif_id);
        $mark_read_stmt->bindParam(':graduate_id', $graduate_id);
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

/**
 * Get appropriate icon for notification type
 */
function getNotificationIcon($type) {
    switch ($type) {
        case 'application_update':
            return 'fas fa-file-alt';
        case 'job_recommendation':
            return 'fas fa-briefcase';
        case 'portfolio_reminder':
            return 'fas fa-file-contract';
        case 'skill_reminder':
            return 'fas fa-tools';
        case 'career_resource':
            return 'fas fa-graduation-cap';
        case 'profile_view':
            return 'fas fa-eye';
        case 'profile_update':
            return 'fas fa-user-edit';
        case 'profile_reminder':
            return 'fas fa-user-check';
        case 'system':
        default:
            return 'fas fa-info-circle';
    }
}

/**
 * Get notification priority based on type
 */
function getNotificationPriority($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'application_update') !== false || 
        strpos($type, 'hired') !== false ||
        strpos($type, 'qualified') !== false) {
        return 'high';
    } elseif (strpos($type, 'job_recommendation') !== false || 
              strpos($type, 'profile_view') !== false) {
        return 'medium';
    } else {
        return 'low';
    }
}

/**
 * Function to determine where a notification should link to based on its content
 */
function getNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'application') !== false || strpos($message, 'application') !== false) {
        return 'graduate_applications.php';
    } elseif (strpos($type, 'job') !== false || strpos($message, 'job') !== false) {
        return 'graduate_jobs.php';
    } elseif (strpos($type, 'portfolio') !== false || strpos($message, 'portfolio') !== false || strpos($message, 'resume') !== false) {
        return 'graduate_portfolio.php';
    } elseif (strpos($type, 'skill') !== false || strpos($message, 'skill') !== false) {
        return 'graduate_portfolio.php';
    } elseif (strpos($type, 'career') !== false || strpos($message, 'resource') !== false) {
        return 'graduate_tools.php';
    } elseif (strpos($type, 'profile') !== false || strpos($message, 'profile') !== false) {
        return 'graduate_profile.php';
    } else {
        return 'graduate_dashboard.php';
    }
}

// Parse full name into components for form display
$last_name = '';
$first_name = '';
$middle_name = '';

if (!empty($graduate['usr_name'])) {
    $name_parts = explode(' ', $graduate['usr_name']);
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

// Parse phone number to extract just the digits and country code
$phone_digits = '';
$phone_country_code = '+63';
if (!empty($graduate['usr_phone'])) {
    // Extract country code if present
    if (strpos($graduate['usr_phone'], '+63') === 0) {
        $phone_country_code = '+63';
        $phone_digits = substr($graduate['usr_phone'], 3); // Remove the +63
    } elseif (strpos($graduate['usr_phone'], '+1') === 0) {
        $phone_country_code = '+1';
        $phone_digits = substr($graduate['usr_phone'], 2); // Remove the +1
    } else {
        // Extract only digits from phone number
        $phone_digits = preg_replace('/[^0-9]/', '', $graduate['usr_phone']);
    }
}

// Parse address from database
$region = '';
$province = '';
$city = '';
$barangay = '';
$zip_code = '';
$street_address = '';

if (!empty($graduate['usr_address'])) {
    // The address is stored as: "Street, Barangay, City, Province, Region, ZIP: xxxx"
    $address_parts = explode(', ', $graduate['usr_address']);
    
    foreach ($address_parts as $part) {
        $part = trim($part);
        
        if (strpos($part, 'ZIP: ') === 0) {
            $zip_code = str_replace('ZIP: ', '', $part);
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
        if ($count > 0) $region = $address_parts_array[$count - 1];
        
        // The second to last is the province
        if ($count > 1) $province = $address_parts_array[$count - 2];
        
        // The third to last is the city
        if ($count > 2) $city = $address_parts_array[$count - 3];
        
        // The fourth to last is the barangay
        if ($count > 3) $barangay = $address_parts_array[$count - 4];
        
        // Anything before barangay is the street address
        if ($count > 4) {
            $street_address = implode(', ', array_slice($address_parts_array, 0, $count - 4));
        }
    }
}

// If we have session data from a recent form submission, use that instead
if (isset($_SESSION['last_address_data'])) {
    $region = $_SESSION['last_address_data']['region'] ?? $region;
    $province = $_SESSION['last_address_data']['province'] ?? $province;
    $city = $_SESSION['last_address_data']['city'] ?? $city;
    $barangay = $_SESSION['last_address_data']['barangay'] ?? $barangay;
    $zip_code = $_SESSION['last_address_data']['zip_code'] ?? $zip_code;
    $street_address = $_SESSION['last_address_data']['street_address'] ?? $street_address;
    
    // Clear session data after using it
    unset($_SESSION['last_address_data']);
}

// Parse job preferences (JSON array)
$selected_job_preferences = [];
$custom_job_preferences = [];
if (!empty($graduate['grad_job_preference'])) {
    $preferences_array = json_decode($graduate['grad_job_preference'], true);
    if (is_array($preferences_array)) {
        $selected_job_preferences = $preferences_array;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Alumni Profile</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Cropper.js CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
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
        
        .graduate-role {
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
        
        .notification, .profile {
            position: relative;
            margin-left: 20px;
            cursor: pointer;
        }
        
        .notification i, .profile i {
            font-size: 1.3rem;
            color: var(--primary-color);
            transition: all 0.3s;
        }
        
        .notification:hover i, .profile:hover i {
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
        
        .profile {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 30px;
            transition: all 0.3s;
            background: rgba(110, 3, 3, 0.05);
        }
        
        .profile:hover {
            background: rgba(110, 3, 3, 0.1);
        }
        
        .profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        .profile-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-right: 8px;
        }
        
        .profile i.fa-chevron-down {
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
        
        /* Profile Content Styles */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .profile-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 20px;
            border-top: 4px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
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
        
        .profile-completion {
            margin-bottom: 20px;
        }
        
        .completion-text {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .progress-bar {
            height: 10px;
            background: #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), #8a0404);
            border-radius: 5px;
            transition: width 0.5s ease;
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
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn-primary {
            background-color: var(--blue);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0033cc;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .file-upload {
            margin-bottom: 15px;
        }
        
        .file-upload-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }
        
        .file-input {
            display: none;
        }
        
        .file-custom {
            display: inline-block;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
            cursor: pointer;
            transition: background-color 0.3s;
            margin-bottom: 5px;
        }
        
        .file-custom:hover {
            background: #f0f0f0;
        }
        
        .file-name {
            margin-left: 10px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .file-requirements {
            font-size: 0.8rem;
            color: #999;
            margin-top: 5px;
        }
        
        .profile-photo-container {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-right: 20px;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        /* Loading Spinners for Address */
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
        
        /* ============================================
           ENHANCED JOB PREFERENCES STYLES - NO LIMIT
           ============================================ */
        .job-preferences-container {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #fafafa;
            margin-bottom: 20px;
        }
        
        .job-preferences-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .job-preferences-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .selection-info {
            font-size: 0.85rem;
            color: #666;
            background: #e8f4ff;
            padding: 5px 10px;
            border-radius: 4px;
        }
        
        .job-categories-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px;
            background: white;
            margin-bottom: 15px;
        }
        
        .job-category {
            margin-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .job-category:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .category-title {
            font-weight: 600;
            color: #444;
            margin-bottom: 8px;
            padding: 5px 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .job-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
            padding-left: 10px;
        }
        
        .job-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .job-option:hover {
            background: #f0f8ff;
        }
        
        .job-option.selected {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
        }
        
        .job-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .job-option label {
            cursor: pointer;
            flex: 1;
            font-size: 0.9rem;
        }
        
        .selected-jobs-container {
            margin-top: 20px;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
        }
        
        .selected-jobs-title {
            font-weight: 600;
            color: #444;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .selected-jobs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 40px;
            padding: 10px;
            border: 1px dashed #ddd;
            border-radius: 6px;
            background: white;
        }
        
        .job-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--primary-color);
            color: white;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .job-tag i {
            font-size: 0.75rem;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .job-tag i:hover {
            opacity: 1;
        }
        
        .custom-job-input-container {
            margin-top: 15px;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
        }
        
        .custom-job-title {
            font-weight: 600;
            color: #444;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .custom-job-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .custom-job-input {
            flex: 1;
        }
        
        .add-custom-job-btn {
            background: var(--green);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 10px 15px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .add-custom-job-btn:hover {
            background: #1a6d0e;
        }
        
        .add-custom-job-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .custom-job-hint {
            font-size: 0.8rem;
            color: #666;
            font-style: italic;
        }
        
        .no-selection-message {
            color: #999;
            font-style: italic;
            padding: 10px;
            text-align: center;
            width: 100%;
        }
        
        /* Cropper Modal Styles */
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
        }
        
        .modal-content {
            background-color: white;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: auto;
            padding: 20px;
            position: relative;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-title {
            font-size: 1.4rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
        }
        
        .close-modal:hover {
            color: var(--primary-color);
        }
        
        .cropper-container {
            width: 100%;
            height: 400px;
            margin-bottom: 20px;
            background-color: #f5f5f5;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .cropper-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 20px;
            border: 3px solid var(--primary-color);
        }
        
        .cropper-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .btn-success {
            background-color: var(--green);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #1a6b0d;
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
            
            .sidebar-header h3, .sidebar-menu span, .graduate-role, .menu-label {
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
                padding: 15px;
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
            
            .profile-photo-container {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-photo {
                margin-right: 0;
                margin-bottom: 15px;
            }
            
            .phone-container {
                flex-direction: column;
                gap: 8px;
            }
            
            .country-code {
                width: 100%;
            }
            
            .name-container {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .address-container {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .modal-content {
                width: 95%;
                padding: 15px;
            }
            
            .cropper-container {
                height: 300px;
            }
            
            .job-options {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .profile-card {
                padding: 20px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .cropper-container {
                height: 250px;
            }
            
            .cropper-actions {
                flex-direction: column;
                gap: 10px;
            }
            
            .cropper-actions .btn {
                width: 100%;
            }
            
            .custom-job-input-group {
                flex-direction: column;
            }
            
            .add-custom-job-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" alt="Profile">
            <div class="sidebar-header-text">
                <h3><?= htmlspecialchars($graduate['usr_name']) ?></h3>
                <div class="graduate-role">Alumni</div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <ul>
                <li>
                    <a href="graduate_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_jobs.php">
                        <i class="fas fa-briefcase"></i>
                        <span>Apply Jobs</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_employers.php">
                        <i class="fas fa-building"></i>
                        <span>Employers</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_portfolio.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Digital Portfolio</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_applications.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Applications</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_tools.php">
                        <i class="fas fa-tools"></i>
                        <span>Career Tools</span>
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
                                $notification_link = getNotificationLink($notif);
                                $notification_icon = getNotificationIcon($notif);
                                $priority_class = 'priority-' . getNotificationPriority($notif);
                            ?>
                            <a href="<?= $notification_link ?>" class="notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?>">
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
                <div class="profile" id="profile">
                    <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" alt="Profile">
                    <span class="profile-name"><?= htmlspecialchars($graduate['usr_name']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                    <div class="dropdown profile-dropdown" id="profileDropdown">
                        <a href="graduate_profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                        <a href="graduate_settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <div class="dropdown-item" style="border-top: 1px solid #eee; margin-top: 5px; padding-top: 15px;">
                            <i class="fas fa-user-graduate"></i> Logged in as: <strong><?= htmlspecialchars($graduate['usr_name']) ?></strong>
                        </div>
                        <a href="?logout=1" class="dropdown-item" style="color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?= htmlspecialchars($graduate['usr_name']) ?>!</h2>
            <p>Manage your profile information and increase your visibility to employers. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>
        
        <h1 class="page-title">My Profile</h1>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form action="graduate_profile.php" method="POST" enctype="multipart/form-data" id="profileForm">
            <div class="profile-container">
                <!-- Left Column -->
                <div>
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Personal Information</h3>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Full Name <span class="required">*</span></label>
                            <div class="name-container">
                                <div>
                                    <label class="form-label" for="last_name">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" class="form-input" 
                                           value="<?= htmlspecialchars($last_name) ?>" required placeholder="Last Name">
                                </div>
                                <div>
                                    <label class="form-label" for="first_name">First Name</label>
                                    <input type="text" id="first_name" name="first_name" class="form-input" 
                                           value="<?= htmlspecialchars($first_name) ?>" required placeholder="First Name">
                                </div>
                                <div>
                                    <label class="form-label" for="middle_name">Middle Name</label>
                                    <input type="text" id="middle_name" name="middle_name" class="form-input" 
                                           value="<?= htmlspecialchars($middle_name) ?>" placeholder="Middle Name">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="alumni_number">Alumni Number *</label>
                            <input type="text" id="alumni_number" name="alumni_number" class="form-input" 
                                value="<?= htmlspecialchars($graduate['grad_school_id'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="date_of_birth">Date of Birth *</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" class="form-input" 
                                value="<?= htmlspecialchars($graduate['usr_birthdate'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="gender">Gender *</label>
                            <select id="gender" name="gender" class="form-select" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?= ($graduate['usr_gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($graduate['usr_gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= ($graduate['usr_gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        
                        <!-- ENHANCED: Address Information Section with PSGC API -->
                        <div class="form-group">
                            <label class="form-label">Address Information <span class="required">*</span></label>
                            <div class="address-container">
                                <div>
                                    <label class="form-label" for="region">Region <span class="required">*</span></label>
                                    <select id="region" name="region" class="form-select" required>
                                        <option value="">Select Region</option>
                                        <!-- Regions will be populated by JavaScript -->
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
                                           value="<?= htmlspecialchars($zip_code) ?>" 
                                           placeholder="Zip Code" maxlength="4">
                                </div>
                                <div>
                                    <label class="form-label" for="street_address">Street Address</label>
                                    <input type="text" id="street_address" name="street_address" class="form-input" 
                                           value="<?= htmlspecialchars($street_address) ?>" 
                                           placeholder="House No., Street, Subdivision">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="mobile_number">Mobile Number *</label>
                            <div class="phone-container">
                                <select id="country_code" name="country_code" class="form-select country-code">
                                    <option value="+63" <?= $phone_country_code == '+63' ? 'selected' : '' ?>>+63 (PH)</option>
                                    <option value="+1" <?= $phone_country_code == '+1' ? 'selected' : '' ?>>+1 (US)</option>
                                    <option value="+44" <?= $phone_country_code == '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                                    <option value="+61" <?= $phone_country_code == '+61' ? 'selected' : '' ?>>+61 (AU)</option>
                                    <option value="+65" <?= $phone_country_code == '+65' ? 'selected' : '' ?>>+65 (SG)</option>
                                    <option value="+60" <?= $phone_country_code == '+60' ? 'selected' : '' ?>>+60 (MY)</option>
                                    <option value="+66" <?= $phone_country_code == '+66' ? 'selected' : '' ?>>+66 (TH)</option>
                                    <option value="+84" <?= $phone_country_code == '+84' ? 'selected' : '' ?>>+84 (VN)</option>
                                    <option value="+81" <?= $phone_country_code == '+81' ? 'selected' : '' ?>>+81 (JP)</option>
                                    <option value="+82" <?= $phone_country_code == '+82' ? 'selected' : '' ?>>+82 (KR)</option>
                                    <option value="+86" <?= $phone_country_code == '+86' ? 'selected' : '' ?>>+86 (CN)</option>
                                    <option value="+91" <?= $phone_country_code == '+91' ? 'selected' : '' ?>>+91 (IN)</option>
                                    <option value="+971" <?= $phone_country_code == '+971' ? 'selected' : '' ?>>+971 (AE)</option>
                                    <option value="+973" <?= $phone_country_code == '+973' ? 'selected' : '' ?>>+973 (BH)</option>
                                    <option value="+966" <?= $phone_country_code == '+966' ? 'selected' : '' ?>>+966 (SA)</option>
                                    <option value="+20" <?= $phone_country_code == '+20' ? 'selected' : '' ?>>+20 (EG)</option>
                                    <option value="+27" <?= $phone_country_code == '+27' ? 'selected' : '' ?>>+27 (ZA)</option>
                                </select>
                                <input type="tel" id="mobile_number" name="mobile_number" class="form-input phone-number" 
                                    value="<?= htmlspecialchars($phone_digits) ?>" 
                                    required placeholder="9234567890" maxlength="11" pattern="[0-9]{10,11}" 
                                    title="Please enter 10-11 digits only (numbers only)">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="email_address">Email Address *</label>
                            <input type="email" id="email_address" class="form-input" 
                                value="<?= htmlspecialchars($graduate['usr_email'] ?? '') ?>" disabled>
                            <small style="color: #999;">Email cannot be changed</small>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div>
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Profile Photo</h3>
                        </div>
                        
                        <div class="profile-photo-container">
                            <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" 
                                alt="Profile Photo" class="profile-photo" id="profilePhotoPreview">
                            
                            <div>
                                <div class="file-upload">
                                    <label class="file-upload-label">Upload New Photo</label>
                                    <label class="file-custom">
                                        Choose File
                                        <input type="file" class="file-input" name="profile_photo" id="profile_photo" accept=".jpg,.jpeg,.png,.gif">
                                    </label>
                                    <span class="file-name" id="profile_photo_name">No file chosen</span>
                                    <div class="file-requirements">Accepted formats: JPG, JPEG, PNG, GIF. Max size: 2MB</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-card">
                        <div class="profile-header">
                            <h3 class="profile-title">Academic & Career Information</h3>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="degree">Degree/Course *</label>
                            <select id="degree" name="degree" class="form-select" required>
                                <option value="">Select your course</option>
                                <?php if (!empty($courses) && !empty($colleges)): ?>
                                    <?php foreach ($colleges as $college): ?>
                                        <?php if (isset($courses[$college]) && !empty($courses[$college])): ?>
                                            <optgroup label="<?php echo htmlspecialchars($college); ?>">
                                                <?php foreach ($courses[$college] as $course): ?>
                                                    <option value="<?php echo htmlspecialchars($course['course_code']); ?>"
                                                        <?= ($graduate['grad_degree'] ?? '') == $course['course_code'] ? 'selected' : '' ?>>
                                                        <?php echo htmlspecialchars($course['course_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Fallback courses -->
                                    <optgroup label="College of Education (CoEd)">
                                        <option value="BEEd" <?= ($graduate['grad_degree'] ?? '') == 'BEEd' ? 'selected' : '' ?>>BEEd – Bachelor in Elementary Education</option>
                                        <option value="BSEd" <?= ($graduate['grad_degree'] ?? '') == 'BSEd' ? 'selected' : '' ?>>BSEd – Bachelor in Secondary Education</option>
                                    </optgroup>
                                    <optgroup label="College of Engineering (CoE)">
                                        <option value="BSCE" <?= ($graduate['grad_degree'] ?? '') == 'BSCE' ? 'selected' : '' ?>>BSCE – Bachelor of Science in Civil Engineering</option>
                                        <option value="BSECE" <?= ($graduate['grad_degree'] ?? '') == 'BSECE' ? 'selected' : '' ?>>BSECE – Bachelor of Science in Electronics Engineering</option>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="year_graduated">Year Graduated *</label>
                            <input type="number" id="year_graduated" name="year_graduated" class="form-input" 
                                value="<?= htmlspecialchars($graduate['grad_year_graduated'] ?? '') ?>" min="1990" max="2099" required>
                        </div>
                        
                        <!-- Enhanced Job Preferences Section - NO LIMIT -->
                        <div class="form-group">
                            <label class="form-label">Job Preference/Field <span class="required">*</span></label>
                            <div class="job-preferences-container">
                                <div class="job-preferences-header">
                                    <div class="job-preferences-title">Select your job preferences</div>
                                    <div class="selection-info">
                                        <i class="fas fa-info-circle"></i> Select as many as you want
                                    </div>
                                </div>
                                
                                <div class="job-categories-container" id="jobCategoriesContainer">
                                    <?php if (!empty($job_positions)): ?>
                                        <?php foreach ($job_positions as $category_id => $category_data): ?>
                                            <div class="job-category">
                                                <div class="category-title"><?php echo htmlspecialchars($category_data['category_name']); ?></div>
                                                <div class="job-options">
                                                    <?php foreach ($category_data['positions'] as $position): ?>
                                                        <div class="job-option">
                                                            <input type="checkbox" 
                                                                   id="job_pref_<?php echo $position['position_id']; ?>" 
                                                                   name="job_preferences[]" 
                                                                   value="<?php echo htmlspecialchars($position['position_name']); ?>"
                                                                   class="job-preference-checkbox"
                                                                   <?= in_array($position['position_name'], $selected_job_preferences) ? 'checked' : '' ?>>
                                                            <label for="job_pref_<?php echo $position['position_id']; ?>">
                                                                <?php echo htmlspecialchars($position['position_name']); ?>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="text-align: center; padding: 20px; color: #666;">
                                            <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                            <p>No job positions available. Please add custom preferences below.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="selected-jobs-container">
                                    <div class="selected-jobs-title">
                                        <i class="fas fa-check-circle"></i> Selected Job Preferences
                                    </div>
                                    <div class="selected-jobs-list" id="selectedJobsList">
                                        <?php if (!empty($selected_job_preferences)): ?>
                                            <?php foreach ($selected_job_preferences as $job): ?>
                                                <div class="job-tag">
                                                    <?php echo htmlspecialchars($job); ?>
                                                    <i class="fas fa-times" data-job="<?php echo htmlspecialchars($job); ?>" data-custom="false"></i>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="no-selection-message">No job preferences selected yet</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="custom-job-input-container">
                                    <div class="custom-job-title">
                                        <i class="fas fa-plus-circle"></i> Add Custom Job Preference
                                    </div>
                                    <div class="custom-job-input-group">
                                        <input type="text" 
                                               id="customJobInput" 
                                               class="form-input custom-job-input" 
                                               placeholder="Enter a job preference not listed above">
                                        <button type="button" id="addCustomJobBtn" class="add-custom-job-btn">
                                            <i class="fas fa-plus"></i> Add
                                        </button>
                                    </div>
                                    <div class="custom-job-hint">
                                        <i class="fas fa-lightbulb"></i> Your custom job preferences will be saved in the system for future users
                                    </div>
                                </div>
                            </div>
                            <div class="error-message" id="job_preferences_error"></div>
                            
                            <!-- Hidden inputs for custom job preferences -->
                            <div id="customJobPreferencesContainer"></div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="summary">Career Summary/Objective</label>
                            <textarea id="summary" name="summary" class="form-textarea" placeholder="Brief summary of your skills and career goals..."><?= htmlspecialchars($graduate['grad_summary'] ?? '') ?></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Update Profile</button>
                    </div>
                </div>
            </div>
            
            <!-- Hidden field for cropped image data -->
            <input type="hidden" name="cropped_image_data" id="cropped_image_data">
        </form>
    </div>

    <!-- Cropper Modal -->
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
                <button type="button" class="btn btn-secondary" id="cancelCrop">Cancel</button>
                <button type="button" class="btn btn-success" id="saveCrop">Save Cropped Image</button>
            </div>
        </div>
    </div>

    <!-- Cropper.js Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

    <script>
        // PSGC API Integration (Same as graduate_form.php)
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

        // Form validation and utilities
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
                const phoneRegex = /^[0-9]{10,11}$/;
                if (phone && !phoneRegex.test(phone)) {
                    this.errors.phone = 'Phone number must be 10-11 digits';
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

        // Job Preferences Manager - NO LIMIT
        class JobPreferencesManager {
            constructor() {
                this.selectedJobs = new Set();
                this.customJobs = new Set();
                this.container = document.getElementById('selectedJobsList');
                this.customContainer = document.getElementById('customJobPreferencesContainer');
                this.errorElement = document.getElementById('job_preferences_error');
                this.initialize();
            }
            
            initialize() {
                // Load previously selected jobs from PHP
                <?php foreach ($selected_job_preferences as $job): ?>
                    this.selectedJobs.add('<?php echo addslashes($job); ?>');
                <?php endforeach; ?>
                
                // Add event listeners to checkboxes
                document.querySelectorAll('.job-preference-checkbox').forEach(checkbox => {
                    checkbox.addEventListener('change', (e) => this.handleCheckboxChange(e));
                    
                    // Pre-check boxes based on PHP data
                    if (this.selectedJobs.has(checkbox.value)) {
                        checkbox.checked = true;
                    }
                });
                
                // Add event listener to custom job button
                document.getElementById('addCustomJobBtn').addEventListener('click', () => this.addCustomJob());
                
                // Allow Enter key to add custom job
                document.getElementById('customJobInput').addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.addCustomJob();
                    }
                });
                
                // Update UI
                this.updateUI();
            }
            
            handleCheckboxChange(event) {
                const checkbox = event.target;
                const jobName = checkbox.value;
                
                if (checkbox.checked) {
                    this.selectedJobs.add(jobName);
                } else {
                    this.selectedJobs.delete(jobName);
                }
                
                this.clearError();
                this.updateUI();
            }
            
            addCustomJob() {
                const input = document.getElementById('customJobInput');
                const jobName = input.value.trim();
                
                if (!jobName) {
                    this.showError('Please enter a job preference name.');
                    return;
                }
                
                // Check if already selected
                if (this.selectedJobs.has(jobName) || this.customJobs.has(jobName)) {
                    this.showError('This job preference is already selected.');
                    return;
                }
                
                this.customJobs.add(jobName);
                input.value = '';
                this.clearError();
                this.updateUI();
            }
            
            removeJob(jobName, isCustom = false) {
                if (isCustom) {
                    this.customJobs.delete(jobName);
                } else {
                    this.selectedJobs.delete(jobName);
                    // Also uncheck the checkbox
                    const checkbox = document.querySelector(`.job-preference-checkbox[value="${jobName}"]`);
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                }
                this.updateUI();
            }
            
            getTotalSelections() {
                return this.selectedJobs.size + this.customJobs.size;
            }
            
            updateUI() {
                // Update selected jobs display
                this.container.innerHTML = '';
                
                if (this.getTotalSelections() === 0) {
                    this.container.innerHTML = '<div class="no-selection-message">No job preferences selected yet</div>';
                } else {
                    // Add selected jobs from checkboxes
                    this.selectedJobs.forEach(jobName => {
                        this.addJobTag(jobName, false);
                    });
                    
                    // Add custom jobs
                    this.customJobs.forEach(jobName => {
                        this.addJobTag(jobName, true);
                    });
                }
                
                // Update hidden inputs for form submission
                this.updateHiddenInputs();
                
                // Update selection count
                this.updateSelectionCount();
            }
            
            addJobTag(jobName, isCustom) {
                const tag = document.createElement('div');
                tag.className = 'job-tag';
                tag.innerHTML = `
                    ${jobName}
                    <i class="fas fa-times" data-job="${jobName}" data-custom="${isCustom}"></i>
                `;
                
                tag.querySelector('i').addEventListener('click', (e) => {
                    e.stopPropagation();
                    const job = e.target.getAttribute('data-job');
                    const isCustom = e.target.getAttribute('data-custom') === 'true';
                    this.removeJob(job, isCustom);
                });
                
                this.container.appendChild(tag);
            }
            
            updateHiddenInputs() {
                // Clear existing custom job inputs
                this.customContainer.innerHTML = '';
                
                // Add hidden inputs for each custom job
                this.customJobs.forEach(jobName => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'custom_job_preferences[]';
                    input.value = jobName;
                    this.customContainer.appendChild(input);
                });
            }
            
            updateSelectionCount() {
                const count = this.getTotalSelections();
                const info = document.querySelector('.selection-info');
                if (info) {
                    info.innerHTML = `
                        <i class="fas fa-info-circle"></i> 
                        Selected: ${count} job preference${count !== 1 ? 's' : ''}
                    `;
                }
            }
            
            showError(message) {
                if (this.errorElement) {
                    this.errorElement.textContent = message;
                    this.errorElement.classList.add('show');
                }
            }
            
            clearError() {
                if (this.errorElement) {
                    this.errorElement.textContent = '';
                    this.errorElement.classList.remove('show');
                }
            }
            
            validate() {
                if (this.getTotalSelections() === 0) {
                    this.showError('Please select at least one job preference.');
                    return false;
                }
                
                this.clearError();
                return true;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize address API
            const addressAPI = new PSGCAddressAPI();
            
            // Initialize job preferences manager
            const jobManager = new JobPreferencesManager();
            
            // Initialize form validator
            const validator = new FormValidator();
            
            // DOM elements for address
            const regionSelect = document.getElementById('region');
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');
            const regionLoading = document.getElementById('regionLoading');
            const provinceLoading = document.getElementById('provinceLoading');
            const cityLoading = document.getElementById('cityLoading');
            const barangayLoading = document.getElementById('barangayLoading');
            
            // Address values from PHP
            const savedRegion = '<?= addslashes($region) ?>';
            const savedProvince = '<?= addslashes($province) ?>';
            const savedCity = '<?= addslashes($city) ?>';
            const savedBarangay = '<?= addslashes($barangay) ?>';
            
            // Common Dropdown Functionality
            const notification = document.getElementById('notification');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const profile = document.getElementById('profile');
            const profileDropdown = document.getElementById('profileDropdown');
            
            notification.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationDropdown.classList.toggle('active');
                profileDropdown.classList.remove('active');
            });
            
            profile.addEventListener('click', function(e) {
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
            
            // Load regions
            regionLoading.style.display = 'block';
            addressAPI.getRegions().then(regions => {
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
                        
                        // Select the saved region if it matches
                        if (region.name === savedRegion) {
                            option.selected = true;
                        }
                    });
                }
                
                regionLoading.style.display = 'none';
                provinceSelect.disabled = false;
                
                // If we have a saved region, trigger the change event to load provinces
                if (savedRegion) {
                    setTimeout(() => {
                        const regionOption = Array.from(regionSelect.options).find(opt => opt.value === savedRegion);
                        if (regionOption) {
                            regionSelect.value = savedRegion;
                            regionSelect.dispatchEvent(new Event('change'));
                        }
                    }, 100);
                }
                
                setupAddressListeners();
            }).catch(error => {
                console.error('Error loading regions:', error);
                regionLoading.style.display = 'none';
                showNotification('Error loading address data. Please refresh the page.', 'error');
            });
            
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
                                
                                // Select the saved province if it matches
                                if (province.name === savedProvince) {
                                    option.selected = true;
                                }
                            });
                            
                            provinceLoading.style.display = 'none';
                            provinceSelect.disabled = false;
                            
                            // If we have a saved province, trigger the change event to load cities
                            if (savedProvince) {
                                setTimeout(() => {
                                    provinceSelect.value = savedProvince;
                                    provinceSelect.dispatchEvent(new Event('change'));
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
                            cities.forEach(city => {
                                const option = document.createElement('option');
                                option.value = city.name;
                                option.setAttribute('data-code', city.code);
                                option.textContent = city.name;
                                citySelect.appendChild(option);
                                
                                // Select the saved city if it matches
                                if (city.name === savedCity) {
                                    option.selected = true;
                                }
                            });
                            
                            cityLoading.style.display = 'none';
                            citySelect.disabled = false;
                            
                            // If we have a saved city, trigger the change event to load barangays
                            if (savedCity) {
                                setTimeout(() => {
                                    citySelect.value = savedCity;
                                    citySelect.dispatchEvent(new Event('change'));
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
                            barangays.forEach(barangay => {
                                const option = document.createElement('option');
                                option.value = barangay.name;
                                option.textContent = barangay.name;
                                barangaySelect.appendChild(option);
                                
                                // Select the saved barangay if it matches
                                if (barangay.name === savedBarangay) {
                                    option.selected = true;
                                }
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
            
            // Cropper functionality
            let cropper;
            const cropperModal = document.getElementById('cropperModal');
            const imageToCrop = document.getElementById('imageToCrop');
            const closeCropperModal = document.getElementById('closeCropperModal');
            const cancelCrop = document.getElementById('cancelCrop');
            const saveCrop = document.getElementById('saveCrop');
            const croppedImageData = document.getElementById('cropped_image_data');
            const profilePhotoPreview = document.getElementById('profilePhotoPreview');
            
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
                    
                    // Update profile photo preview
                    profilePhotoPreview.src = croppedImageUrl;
                    
                    // Set cropped image data to hidden field
                    croppedImageData.value = croppedImageUrl;
                    
                    // Hide modal
                    hideCropperModal();
                    
                    // Clear the file input to allow re-uploading the same file
                    document.getElementById('profile_photo').value = '';
                    document.getElementById('profile_photo_name').textContent = 'No file chosen';
                }
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === cropperModal) {
                    hideCropperModal();
                }
            });
            
            // Enhanced Phone Number Validation
            const phoneInput = document.getElementById('mobile_number');
            
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
            
            // Form validation
            const profileForm = document.getElementById('profileForm');
            
            profileForm.addEventListener('submit', function(e) {
                // Clear previous errors
                validator.clearErrors();
                
                // Validate job preferences
                if (!jobManager.validate()) {
                    e.preventDefault();
                    showNotification('Please select at least one job preference.', 'error');
                    return;
                }
                
                // Validate required fields
                const requiredFields = [
                    'last_name', 'first_name', 'alumni_number', 'year_graduated', 
                    'degree', 'gender', 'region', 'province', 'city', 'barangay'
                ];
                
                let hasErrors = false;
                requiredFields.forEach(field => {
                    const element = document.getElementById(field) || document.querySelector(`[name="${field}"]`);
                    if (element && !validator.validateRequired(element.value, field)) {
                        hasErrors = true;
                    }
                });
                
                // Validate phone number
                const phone = phoneInput.value;
                if (phone && !validator.validatePhone(phone)) {
                    hasErrors = true;
                }
                
                if (hasErrors) {
                    e.preventDefault();
                    validator.showErrors();
                    showNotification('Please correct the errors in the form.', 'error');
                    return;
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
        
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i>
                ${message}
            `;
            
            // Insert at top of content
            const content = document.querySelector('.main-content');
            const pageTitle = document.querySelector('.page-title');
            if (pageTitle) {
                content.insertBefore(notification, pageTitle.nextElementSibling);
            } else {
                content.insertBefore(notification, content.firstChild);
            }
            
            // Remove after 5 seconds
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
    </script>
</body>
</html>