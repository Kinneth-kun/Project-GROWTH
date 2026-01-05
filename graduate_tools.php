<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

// Initialize variables to prevent undefined errors
$graduate = [];
$skills = [];
$portfolio_items = [];
$shared_resources = [];
$notifications = [];
$resume_data = [];
$success_message = '';
$error_message = '';
$unread_notif_count = 0;
$unread_resources_count = 0;
$notificationsGenerated = 0;
$show_resume_preview = false; // NEW: Flag to control resume preview display

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Debug session data
    error_log("Session Data: " . print_r($_SESSION, true));
    
    // Check if user is logged in and is a graduate - FIXED VERSION
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
    
    // Handle resource read status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_resource_read'])) {
        $resource_id = $_POST['resource_id'];
        $graduate_id = $_SESSION['user_id'];
        
        // Update resource as read
        $stmt = $conn->prepare("UPDATE shared_resources SET is_read = 1 WHERE resource_id = :resource_id AND grad_usr_id = :graduate_id");
        $stmt->bindParam(':resource_id', $resource_id);
        $stmt->bindParam(':graduate_id', $graduate_id);
        $stmt->execute();
        
        // Return JSON response for AJAX requests
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        }
        
        // Redirect back for regular form submissions
        header("Location: graduate_tools.php");
        exit();
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
        
        // Get job preference
        $job_preference = $graduate['grad_job_preference'] ?? 'General';
        
        // Check for new jobs matching preferences
        $newJobs = $conn->prepare("
            SELECT j.job_id, j.job_title, e.emp_company_name
            FROM jobs j
            JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
            WHERE j.job_status = 'active'
            AND (j.job_domain LIKE CONCAT('%', ?, '%') OR j.job_title LIKE CONCAT('%', ?, '%'))
            AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY j.job_created_at DESC
            LIMIT 3
        ");
        $newJobs->execute([$job_preference, $job_preference]);
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
        SELECT g.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, 
               u.usr_gender, u.usr_birthdate, u.usr_address, u.usr_created_at,
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
        header("Location: graduate_profile.php?setup=1");
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
    
    // Get skills for resume builder
    $skills_stmt = $conn->prepare("
        SELECT s.skill_name, s.skill_category, gs.skill_level 
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        WHERE gs.grad_usr_id = :graduate_id
        ORDER BY gs.skill_level DESC, s.skill_name
    ");
    $skills_stmt->bindParam(':graduate_id', $graduate_id);
    $skills_stmt->execute();
    $skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get portfolio items for resume builder
    $portfolio_stmt = $conn->prepare("
        SELECT * FROM portfolio_items 
        WHERE port_usr_id = :graduate_id 
        ORDER BY port_item_type, port_created_at DESC
    ");
    $portfolio_stmt->bindParam(':graduate_id', $graduate_id);
    $portfolio_stmt->execute();
    $portfolio_items = $portfolio_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get shared resources from staff
    $resources_stmt = $conn->prepare("
        SELECT sr.*, u.usr_name as staff_name 
        FROM shared_resources sr 
        JOIN users u ON sr.staff_usr_id = u.usr_id 
        WHERE sr.grad_usr_id = :graduate_id 
        ORDER BY sr.shared_at DESC
    ");
    $resources_stmt->bindParam(':graduate_id', $graduate_id);
    $resources_stmt->execute();
    $shared_resources = $resources_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread resources
    $unread_resources_stmt = $conn->prepare("
        SELECT COUNT(*) as count FROM shared_resources 
        WHERE grad_usr_id = :graduate_id AND is_read = FALSE
    ");
    $unread_resources_stmt->bindParam(':graduate_id', $graduate_id);
    $unread_resources_stmt->execute();
    $unread_resources_count = $unread_resources_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
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
    
    // Handle resume generation
    $resume_data = [];
    $success_message = '';
    $error_message = '';
    
    // NEW: Check if we're coming from a resume generation request
    $show_resume_preview = false;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_resume'])) {
        // Collect all form data
        $resume_data = [
            'personal' => [
                'full_name' => $_POST['full_name'] ?? $graduate['usr_name'],
                'address' => $_POST['address'] ?? '',
                'contact_number' => $_POST['contact_number'] ?? $graduate['usr_phone'],
                'email' => $_POST['email'] ?? $graduate['usr_email'],
                'age' => $_POST['age'] ?? '',
                'birthdate' => $_POST['birthdate'] ?? '',
                'birthplace' => $_POST['birthplace'] ?? '',
                'sex' => $_POST['sex'] ?? $graduate['usr_gender'],
                'status' => $_POST['status'] ?? '',
                'nationality' => $_POST['nationality'] ?? 'Filipino',
                'religion' => $_POST['religion'] ?? '',
                'mother_name' => $_POST['mother_name'] ?? '',
                'mother_occupation' => $_POST['mother_occupation'] ?? '',
                'father_name' => $_POST['father_name'] ?? '',
                'father_occupation' => $_POST['father_occupation'] ?? '',
                'siblings' => $_POST['siblings'] ?? '',
                'profile_photo' => $graduate['usr_profile_photo'] ?? ''
            ],
            'job_objective' => $_POST['job_objective'] ?? '',
            'education' => [],
            'skills' => $skills,
            'experience' => [],
            'character_references' => []
        ];
        
        // Process education entries
        if (isset($_POST['education_level'])) {
            foreach ($_POST['education_level'] as $index => $level) {
                if (!empty($level)) {
                    $resume_data['education'][] = [
                        'level' => $level,
                        'school' => $_POST['education_school'][$index] ?? '',
                        'details' => $_POST['education_details'][$index] ?? '',
                        'year' => $_POST['education_year'][$index] ?? ''
                    ];
                }
            }
        }
        
        // Process experience entries
        if (isset($_POST['experience_description'])) {
            foreach ($_POST['experience_description'] as $index => $description) {
                if (!empty($description)) {
                    $resume_data['experience'][] = [
                        'description' => $description
                    ];
                }
            }
        }
        
        // Process character references
        if (isset($_POST['reference_name'])) {
            foreach ($_POST['reference_name'] as $index => $name) {
                if (!empty($name)) {
                    $resume_data['character_references'][] = [
                        'name' => $name,
                        'position' => $_POST['reference_position'][$index] ?? '',
                        'company' => $_POST['reference_company'][$index] ?? ''
                    ];
                }
            }
        }
        
        // Process additional skills
        if (isset($_POST['additional_skills'])) {
            foreach ($_POST['additional_skills'] as $skill) {
                if (!empty($skill)) {
                    $resume_data['skills'][] = [
                        'skill_name' => $skill,
                        'skill_category' => 'Additional',
                        'skill_level' => 'intermediate'
                    ];
                }
            }
        }
        
        // Generate HTML resume preview
        $success_message = "Resume data prepared successfully! You can now preview or download your resume.";
        
        // NEW: Set flag to show resume preview immediately
        $show_resume_preview = true;
        
        // Create notification for resume generation
        createGraduateNotification($conn, $graduate_id, 'portfolio_reminder', 'You have successfully generated a new resume using the Resume Builder tool.');
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
        'notif_message' => 'Welcome to CTU-PESO Career Tools! Build your resume and explore career resources.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Portfolio completion notifications
    if (($portfolio_stats['has_resume'] ?? 0) == 0) {
        $default_notifications[] = [
            'notif_message' => 'Use our Resume Builder to create a professional resume for your job applications.',
            'notif_type' => 'portfolio_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    }
    
    if (($portfolio_stats['skill_count'] ?? 0) < 3) {
        $default_notifications[] = [
            'notif_message' => 'Add more skills to your portfolio to improve job recommendations.',
            'notif_type' => 'skill_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ];
    }
    
    // Career development notifications
    $default_notifications[] = [
        'notif_message' => 'Check out career development resources in the Career Hub section.',
        'notif_type' => 'career_resource',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))
    ];
    
    return $default_notifications;
}

// Handle file downloads
if (isset($_GET['download'])) {
    $file = $_GET['download'];
    $file_path = '';
    
    switch ($file) {
        case 'resume_template':
            $file_path = 'downloads/Resume_Template_Basic_Format.docx';
            $file_name = 'Resume_Template_Basic_Format.docx';
            break;
        case 'interview_checklist':
            $file_path = 'downloads/Interview_Preparation_Checklist.pdf';
            $file_name = 'Interview_Preparation_Checklist.pdf';
            break;
        case 'cover_letter':
            $file_path = 'downloads/Sample_Cover_Letter.docx';
            $file_name = 'Sample_Cover_Letter.docx';
            break;
        default:
            exit('Invalid file request');
    }
    
    // Check if file exists
    if (file_exists($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        // If file doesn't exist, create a placeholder (in a real application, you would have actual files)
        $error_message = "File not found. Please contact administrator.";
    }
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
        header("Location: graduate_tools.php");
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
        return 'graduate_tools.php';
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

// YouTube video URLs for the video guides
$video_urls = [
    'interview_preparation' => 'https://youtu.be/LCWr-TJrc0k?si=SBthisxykdE1QCBH',
    'resume_writing' => 'https://youtu.be/R3abknwWX7k?si=K4hYwo8m9SbVJ0kG',
    'job_fairs' => 'https://youtu.be/LI_WmATfgFg?si=PKrSBlQ4O-pk2por'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Career Tools</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        :root {
            --frame-orange: #ffa700;
            --panel-orange: #f7a100;
            --maroon: #6e0303;
            --black: #000;
            --green: #1f7a11;
            --blue: #0044ff;
            --purple: #6a0dad;
            --red: #d32f2f;
            --light-color: #f9f9f9;
            --dark-color: #333;
            --sidebar-bg: var(--maroon);
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
            background-color: #f5f5f5;
            min-height: 100vh;
            color: var(--black);
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
            border-left: 4px solid var(--panel-orange);
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-menu a.active::before {
            content: '';
            position: absolute;
            right: 15px;
            width: 8px;
            height: 8px;
            background-color: var(--panel-orange);
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
            background-color: var(--light-color);
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
            color: var(--maroon);
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
            border: 2px solid var(--maroon);
        }
        
        .profile-name {
            font-weight: 600;
            color: var(--maroon);
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
            width: 420px;
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
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
        }
        
        .mark-all-read {
            background: none;
            border: none;
            color: var(--panel-orange);
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
            color: var(--maroon);
            width: 18px;
            text-align: center;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #999;
            margin-top: 5px;
        }
        
        .notification-item {
            display: flex;
            align-items: flex-start;
        }
        
        .notification-icon {
            margin-right: 12px;
            color: var(--maroon);
            font-size: 1.2rem;
            min-width: 24px;
            margin-top: 2px;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .unread {
            background-color: #fff5e6;
            border-left: 3px solid var(--panel-orange);
        }
        
        .no-notifications {
            padding: 30px 20px;
            text-align: center;
            color: #999;
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
            background-color: var(--panel-orange);
        }
        
        .priority-low {
            background-color: var(--green);
        }
        
        /* Enhanced Welcome Message */
        .welcome-message {
            margin-bottom: 25px;
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--maroon), #8a0404);
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
            color: var(--maroon);
            font-weight: 700;
            position: relative;
            padding-bottom: 10px;
        }
    
        
        /* Choice Cards */
        .choice-container {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .choice-card {
            flex: 1;
            min-width: 300px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
            border-top: 4px solid var(--panel-orange);
            position: relative;
            overflow: hidden;
        }
        
        .choice-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
            border-color: var(--maroon);
        }
        
        .choice-card.active {
            border-color: var(--maroon);
            background-color: #fff5f5;
        }
        
        .choice-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #fff5e6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--frame-orange);
            font-size: 1.8rem;
        }
        
        .choice-title {
            font-size: 1.4rem;
            color: var(--maroon);
            margin-bottom: 10px;
        }
        
        .choice-description {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .choice-button {
            padding: 12px 25px;
            background-color: var(--blue);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .choice-button:hover {
            background-color: #0033cc;
        }
        
        /* Content Frame Styles */
        .content-frame {
            display: none;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 30px;
            min-height: 500px;
            border-top: 4px solid var(--panel-orange);
            position: relative;
        }
        
        .content-frame.active {
            display: block;
        }
        
        .frame-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .frame-header h2 {
            color: var(--maroon);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .frame-header p {
            color: #666;
            margin-top: 5px;
        }
        
        .back-button {
            background-color: var(--maroon);
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 20px;
            margin-left: auto;
            transition: background-color 0.3s;
        }
        
        .back-button:hover {
            background-color: #8a0404;
        }
        
        /* Shared Resources Styles */
        .shared-resources-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-top: 4px solid var(--panel-orange);
        }
        
        .shared-resources-section h3 {
            color: var(--maroon);
            margin-bottom: 15px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .shared-resources-section h3 i {
            color: var(--panel-orange);
        }
        
        .resource-item {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--maroon);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        
        .resource-item.unread {
            background: #f0f7ff;
            border-left: 4px solid var(--blue);
        }
        
        .resource-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .resource-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .resource-header h4 {
            color: var(--maroon);
            margin: 0;
            flex: 1;
        }
        
        .resource-type {
            background: var(--maroon);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-left: 10px;
        }
        
        .resource-description {
            color: #666;
            margin-bottom: 10px;
            line-height: 1.5;
        }
        
        .resource-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #888;
            margin-bottom: 15px;
        }
        
        .no-resources {
            text-align: center;
            padding: 40px;
            color: #666;
            background: #f9f9f9;
            border-radius: 8px;
        }
        
        .access-btn {
            display: inline-block;
            background: var(--blue);
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .access-btn:hover {
            background: #0033cc;
            transform: translateY(-2px);
        }
        
        /* Form Styles */
        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .form-group {
            flex: 1 0 calc(50% - 20px);
            margin: 0 10px 20px;
            min-width: 250px;
            position: relative;
        }
        
        .form-group-full {
            flex: 1 0 calc(100% - 20px);
            margin: 0 10px 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--maroon);
        }
        
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            border-color: var(--maroon);
            outline: none;
            box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .skills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .skill-tag {
            background-color: #fff5e6;
            color: var(--frame-orange);
            padding: 5px 12px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            margin-bottom: 5px;
            animation: fadeIn 0.3s ease;
        }
        
        .skill-tag i {
            margin-left: 5px;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .skill-tag i:hover {
            color: var(--red);
        }
        
        .add-skill {
            display: flex;
            margin-top: 10px;
        }
        
        .add-skill input {
            flex: 1;
            margin-right: 10px;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
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
        
        .btn-secondary {
            background-color: #e0e0e0;
            color: #333;
        }
        
        .btn-secondary:hover {
            background-color: #d0d0d0;
        }
        
        .btn-add {
            background-color: var(--green);
            color: white;
            padding: 8px 15px;
        }
        
        .btn-add:hover {
            background-color: #1a6b0a;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        /* Modern Resume Preview Styles */
        .resume-preview {
            background: white;
            border: 1px solid #ddd;
            padding: 40px 50px;
            margin-top: 20px;
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            font-size: 14px;
            width: 210mm; /* A4 width */
            min-height: 297mm; /* A4 height */
            margin: 20px auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: relative;
        }

        /* Modern Header with Two Columns */
        .resume-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #2c3e50;
        }

        .resume-header-left {
            flex: 1;
        }

        .resume-name {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        .resume-title {
            font-size: 18px;
            color: #7f8c8d;
            font-weight: 500;
            margin-bottom: 15px;
        }

        .resume-contact {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #34495e;
            font-size: 14px;
        }

        .contact-item i {
            color: #3498db;
            width: 16px;
            text-align: center;
        }

        .resume-profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            object-fit: cover;
            border: 3px solid #3498db;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        /* Two Column Layout */
        .resume-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }

        .resume-main {
            padding-right: 20px;
            border-right: 2px solid #ecf0f1;
        }

        .resume-sidebar {
            padding-left: 10px;
        }

        /* Section Styles */
        .resume-section {
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #3498db;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 50px;
            height: 2px;
            background: #e74c3c;
        }

        /* Experience and Education Items */
        .experience-item, .education-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ecf0f1;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .item-title {
            font-size: 16px;
            font-weight: bold;
            color: #2c3e50;
        }

        .item-date {
            color: #7f8c8d;
            font-size: 14px;
            font-weight: 500;
        }

        .item-subtitle {
            color: #3498db;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .item-description {
            color: #555;
            line-height: 1.5;
        }

        /* Skills Section */
        .skills-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .skill-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .skill-name {
            color: #2c3e50;
            font-weight: 500;
        }

        .skill-level {
            color: #7f8c8d;
            font-size: 12px;
        }

        /* References Section */
        .reference-item {
            margin-bottom: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #3498db;
        }

        .reference-name {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .reference-position {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 3px;
        }

        .reference-company {
            color: #555;
            font-size: 13px;
        }

        /* Personal Info in Sidebar */
        .personal-info-item {
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-weight: bold;
            color: #2c3e50;
            font-size: 13px;
            margin-bottom: 3px;
        }

        .info-value {
            color: #555;
            font-size: 14px;
        }

        /* Career Hub Styles */
        .resources-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .resource-card {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--maroon);
        }
        
        .resource-title {
            font-size: 1.2rem;
            color: var(--maroon);
            margin-bottom: 15px;
        }
        
        .resource-list {
            list-style: none;
        }
        
        .resource-list li {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .resource-list li:last-child {
            border-bottom: none;
        }
        
        .resource-list i {
            color: var(--maroon);
        }
        
        .download-link {
            color: var(--maroon);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .download-link:hover {
            color: #8a0404;
            text-decoration: underline;
        }
        
        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .video-card {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            transition: transform 0.3s;
        }
        
        .video-card:hover {
            transform: translateY(-5px);
        }
        
        .video-card i {
            font-size: 2rem;
            color: var(--maroon);
            margin-bottom: 10px;
        }
        
        .video-card h4 {
            margin-bottom: 10px;
            color: var(--maroon);
        }
        
        .watch-btn {
            background: var(--maroon);
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-block;
            margin-top: 10px;
            transition: background-color 0.3s;
        }
        
        .watch-btn:hover {
            background: #8a0404;
        }
        
        .personalized-tips {
            background: linear-gradient(45deg, var(--maroon), #8a0404);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 25px;
        }
        
        .tips-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
        }
        
        .tips-list {
            list-style: none;
        }
        
        .tips-list li {
            padding: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .tips-list i {
            color: var(--panel-orange);
        }
        
        /* Alert Styles */
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
        
        /* Back Button Container */
        .back-button-container {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        /* Download Button Container */
        .download-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .download-container.hidden {
            display: none;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .choice-container {
                flex-direction: column;
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
            
            .choice-container {
                flex-direction: column;
            }
            
            .form-group {
                flex: 1 0 calc(100% - 20px);
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 10px;
            }
            
            .resume-preview {
                width: 100%;
                padding: 20px;
            }
            
            .resume-header {
                flex-direction: column;
                text-align: center;
            }
            
            .resume-profile-photo {
                margin: 15px auto 0;
            }
            
            .resume-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .resume-main {
                padding-right: 0;
                border-right: none;
                border-bottom: 2px solid #ecf0f1;
                padding-bottom: 20px;
            }
            
            .resume-sidebar {
                padding-left: 0;
            }
            
            .back-button-container {
                justify-content: center;
            }
            
            .back-button {
                width: 100%;
                justify-content: center;
            }
            
            .resource-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .resource-type {
                margin-left: 0;
                margin-top: 5px;
            }
            
            .resource-meta {
                flex-direction: column;
                gap: 5px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .choice-card, .content-frame {
                padding: 20px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
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
                    <a href="graduate_tools.php" class="active">
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
                            Notifications
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
                            <a href="<?= $notification_link ?>" class="dropdown-item notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?? '' ?>">
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
                            <div class="no-notifications">No notifications</div>
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
            <p>Kickstart Your Career: Build Your Resume or Explore Job Prep Resources! <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>
        
        <h1 class="page-title">Graduate Career Tools</h1>
        
        <!-- Alert Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success" id="successAlert"><?= $success_message ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error" id="errorAlert"><?= $error_message ?></div>
        <?php endif; ?>
        
        <!-- Choice Cards - NEW: Hide when resume preview should be shown -->
        <div class="choice-container" id="choiceContainer" style="<?= $show_resume_preview ? 'display: none;' : 'display: flex;' ?>">
            <div class="choice-card active" id="resumeBuilderCard">
                <div class="choice-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <h3 class="choice-title">Resume Builder</h3>
                <p class="choice-description">Create a professional resume in minutes with our easy-to-use builder. Customize your resume for different job applications.</p>
                <button class="choice-button" id="resumeBuilderBtn">Build Resume</button>
            </div>
            
            <div class="choice-card" id="careerHubCard">
                <div class="choice-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h3 class="choice-title">Career Hub</h3>
                <p class="choice-description">Explore job preparation resources, interview guides, and skills-building tools to enhance your career prospects.</p>
                <button class="choice-button" id="careerHubBtn">Explore Resources</button>
            </div>
        </div>
        
        <!-- Resume Builder Frame - NEW: Show immediately when resume preview should be shown -->
        <div class="content-frame" id="resumeBuilderFrame" style="<?= $show_resume_preview ? 'display: block;' : 'display: none;' ?>">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-file-alt"></i> Resume Builder</h2>
                    <p>Create a professional resume following the traditional CV format.</p>
                </div>
            </div>
            
            <form id="resumeForm" method="POST" action="">
                <input type="hidden" name="generate_resume" value="1">
                
                <!-- Personal Information Section -->
                <div class="form-group form-group-full">
                    <h3 class="form-label" style="color: var(--maroon); border-bottom: 2px solid var(--maroon); padding-bottom: 5px;">Personal Information</h3>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name" class="form-label">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" class="form-input" 
                               value="<?= htmlspecialchars($graduate['usr_name']) ?>" 
                               placeholder="Enter your full name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="address" class="form-label">Permanent Address</label>
                        <input type="text" id="address" name="address" class="form-input" 
                               value="<?= htmlspecialchars($graduate['usr_address'] ?? '') ?>" 
                               placeholder="Enter your permanent address">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_number" class="form-label">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" class="form-input" 
                               value="<?= htmlspecialchars($graduate['usr_phone'] ?? '') ?>" 
                               placeholder="Enter your contact number">
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" id="email" name="email" class="form-input" 
                               value="<?= htmlspecialchars($graduate['usr_email']) ?>" 
                               placeholder="Enter your email" required>
                    </div>
                </div>
                
                <!-- Personal Background -->
                <div class="form-group form-group-full">
                    <h3 class="form-label" style="color: var(--maroon); border-bottom: 2px solid var(--maroon); padding-bottom: 5px;">Personal Background</h3>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="age" class="form-label">Age</label>
                        <input type="number" id="age" name="age" class="form-input" 
                               placeholder="Enter your age" min="16" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label for="birthdate" class="form-label">Birthdate</label>
                        <input type="date" id="birthdate" name="birthdate" class="form-input" 
                               value="<?= htmlspecialchars($graduate['usr_birthdate'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="birthplace" class="form-label">Birthplace</label>
                        <input type="text" id="birthplace" name="birthplace" class="form-input" 
                               placeholder="Enter your birthplace">
                    </div>
                    
                    <div class="form-group">
                        <label for="sex" class="form-label">Sex</label>
                        <select id="sex" name="sex" class="form-select">
                            <option value="">Select Sex</option>
                            <option value="Male" <?= ($graduate['usr_gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($graduate['usr_gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                            <option value="Other" <?= ($graduate['usr_gender'] == 'Other') ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="status" class="form-label">Civil Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Select Status</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Widowed">Widowed</option>
                            <option value="Separated">Separated</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="nationality" class="form-label">Nationality</label>
                        <input type="text" id="nationality" name="nationality" class="form-input" 
                               value="Filipino" placeholder="Enter your nationality">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="religion" class="form-label">Religion</label>
                        <input type="text" id="religion" name="religion" class="form-input" 
                               placeholder="Enter your religion">
                    </div>
                </div>
                
                <!-- Family Background -->
                <div class="form-group form-group-full">
                    <h3 class="form-label" style="color: var(--maroon); border-bottom: 2px solid var(--maroon); padding-bottom: 5px;">Family Background</h3>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="mother_name" class="form-label">Mother's Name</label>
                        <input type="text" id="mother_name" name="mother_name" class="form-input" 
                               placeholder="Enter mother's name">
                    </div>
                    
                    <div class="form-group">
                        <label for="mother_occupation" class="form-label">Mother's Occupation</label>
                        <input type="text" id="mother_occupation" name="mother_occupation" class="form-input" 
                               placeholder="Enter mother's occupation">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="father_name" class="form-label">Father's Name</label>
                        <input type="text" id="father_name" name="father_name" class="form-input" 
                               placeholder="Enter father's name">
                    </div>
                    
                    <div class="form-group">
                        <label for="father_occupation" class="form-label">Father's Occupation</label>
                        <input type="text" id="father_occupation" name="father_occupation" class="form-input" 
                               placeholder="Enter father's occupation">
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label for="siblings" class="form-label">Siblings (Names, separated by commas)</label>
                    <textarea id="siblings" name="siblings" class="form-textarea" 
                              placeholder="Enter siblings' names separated by commas"></textarea>
                </div>
                
                <!-- Job Objective -->
                <div class="form-group form-group-full">
                    <label for="job_objective" class="form-label">Job Objective</label>
                    <textarea id="job_objective" name="job_objective" class="form-textarea" 
                              placeholder="Example: To leverage my skills and experience as a web developer to design, develop, and maintain high-quality websites and web applications..."></textarea>
                </div>
                
                <!-- Educational Background -->
                <div class="form-group form-group-full">
                    <h3 class="form-label" style="color: var(--maroon); border-bottom: 2px solid var(--maroon); padding-bottom: 5px;">Educational Background</h3>
                    <div id="education-container">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Level (e.g., College, Senior High School)</label>
                                <input type="text" name="education_level[]" class="form-input" placeholder="Enter education level">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">School Name</label>
                                <input type="text" name="education_school[]" class="form-input" placeholder="Enter school name">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Details (e.g., Course, Honors)</label>
                                <input type="text" name="education_details[]" class="form-input" placeholder="Enter details">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Year Graduated</label>
                                <input type="number" name="education_year[]" class="form-input" placeholder="Enter year" min="1900" max="2099">
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-add" id="addEducationBtn">
                        <i class="fas fa-plus"></i> Add Education
                    </button>
                </div>
                
                <!-- Skills -->
                <div class="form-group form-group-full">
                    <label class="form-label">Skills</label>
                    <?php if (!empty($skills)): ?>
                    <div style="padding: 10px; background: #f9f9f9; border-radius: 6px; margin-bottom: 15px;">
                        <p style="font-weight: 500; margin-bottom: 10px;">Skills from your profile:</p>
                        <div class="skills-container">
                            <?php foreach ($skills as $skill): ?>
                            <span class="skill-tag">
                                <?= htmlspecialchars($skill['skill_name']) ?> (<?= ucfirst($skill['skill_level']) ?>)
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="skills-container" id="skillsContainer">
                        <!-- Additional skills will be added here dynamically -->
                    </div>
                    <div class="add-skill">
                        <input type="text" id="newSkill" class="form-input" placeholder="Add a skill">
                        <button type="button" class="btn btn-add" id="addSkillBtn">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
                
                <!-- Experience -->
                <div class="form-group form-group-full">
                    <h3 class="form-label" style="color: var(--maroon); border-bottom: 2px solid var(--maroon); padding-bottom: 5px;">Experience</h3>
                    <div id="experience-container">
                        <div class="form-group form-group-full">
                            <label class="form-label">Experience Description</label>
                            <textarea name="experience_description[]" class="form-textarea" placeholder="Describe your experience"></textarea>
                        </div>
                    </div>
                    <button type="button" class="btn btn-add" id="addExperienceBtn">
                        <i class="fas fa-plus"></i> Add Experience
                    </button>
                </div>
                
                <!-- Character References -->
                <div class="form-group form-group-full">
                    <h3 class="form-label" style="color: var(--maroon); border-bottom: 2px solid var(--maroon); padding-bottom: 5px;">Character References</h3>
                    <div id="references-container">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Reference Name</label>
                                <input type="text" name="reference_name[]" class="form-input" placeholder="Enter reference name">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Position</label>
                                <input type="text" name="reference_position[]" class="form-input" placeholder="Enter position">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Company</label>
                                <input type="text" name="reference_company[]" class="form-input" placeholder="Enter company">
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-add" id="addReferenceBtn">
                        <i class="fas fa-plus"></i> Add Reference
                    </button>
                </div>
                
                <div class="form-actions">
                    <button type="reset" class="btn btn-secondary">Reset Form</button>
                    <button type="submit" class="btn btn-primary">Generate Resume</button>
                </div>
            </form>
            
            <!-- Modern Resume Preview -->
            <?php if (!empty($resume_data)): ?>
            <div class="resume-preview" id="resumePreview">
                <!-- Header Section -->
                <div class="resume-header">
                    <div class="resume-header-left">
                        <div class="resume-name"><?= htmlspecialchars($resume_data['personal']['full_name']) ?></div>
                        <div class="resume-title">Information Systems Graduate</div>
                        <div class="resume-contact">
                            <div class="contact-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($resume_data['personal']['address'] ?? 'Address not provided') ?></span>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span><?= htmlspecialchars($resume_data['personal']['contact_number'] ?? 'Contact not provided') ?></span>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span><?= htmlspecialchars($resume_data['personal']['email']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($resume_data['personal']['profile_photo'])): ?>
                    <img src="<?= htmlspecialchars($resume_data['personal']['profile_photo']) ?>" alt="Profile Photo" class="resume-profile-photo">
                    <?php else: ?>
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($resume_data['personal']['full_name']) ?>&background=3498db&color=fff&size=120" alt="Profile Photo" class="resume-profile-photo">
                    <?php endif; ?>
                </div>

                <!-- Two Column Content -->
                <div class="resume-content">
                    <!-- Main Content Column -->
                    <div class="resume-main">
                        <!-- Job Objective -->
                        <?php if (!empty($resume_data['job_objective'])): ?>
                        <div class="resume-section">
                            <div class="section-title">Career Objective</div>
                            <p><?= nl2br(htmlspecialchars($resume_data['job_objective'])) ?></p>
                        </div>
                        <?php endif; ?>

                        <!-- Education -->
                        <?php if (!empty($resume_data['education'])): ?>
                        <div class="resume-section">
                            <div class="section-title">Education</div>
                            <?php foreach ($resume_data['education'] as $education): ?>
                            <div class="education-item">
                                <div class="item-header">
                                    <div class="item-title"><?= htmlspecialchars($education['level']) ?></div>
                                    <?php if (!empty($education['year'])): ?>
                                    <div class="item-date"><?= htmlspecialchars($education['year']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="item-subtitle"><?= htmlspecialchars($education['school']) ?></div>
                                <?php if (!empty($education['details'])): ?>
                                <div class="item-description"><?= htmlspecialchars($education['details']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Experience -->
                        <?php if (!empty($resume_data['experience'])): ?>
                        <div class="resume-section">
                            <div class="section-title">Experience</div>
                            <?php foreach ($resume_data['experience'] as $experience): ?>
                            <div class="experience-item">
                                <div class="item-description"><?= htmlspecialchars($experience['description']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Character References -->
                        <?php if (!empty($resume_data['character_references'])): ?>
                        <div class="resume-section">
                            <div class="section-title">Character References</div>
                            <?php foreach ($resume_data['character_references'] as $reference): ?>
                            <div class="reference-item">
                                <div class="reference-name"><?= htmlspecialchars($reference['name']) ?></div>
                                <?php if (!empty($reference['position'])): ?>
                                <div class="reference-position"><?= htmlspecialchars($reference['position']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($reference['company'])): ?>
                                <div class="reference-company"><?= htmlspecialchars($reference['company']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar Column -->
                    <div class="resume-sidebar">
                        <!-- Personal Information -->
                        <div class="resume-section">
                            <div class="section-title">Personal Info</div>
                            <?php if (!empty($resume_data['personal']['age'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Age</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['age']) ?> years old</div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['birthdate'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Birthdate</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['birthdate']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['birthplace'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Birthplace</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['birthplace']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['sex'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['sex']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['status'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Civil Status</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['status']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['nationality'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Nationality</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['nationality']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['religion'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Religion</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['religion']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Skills -->
                        <?php if (!empty($resume_data['skills'])): ?>
                        <div class="resume-section">
                            <div class="section-title">Skills</div>
                            <div class="skills-list">
                                <?php foreach ($resume_data['skills'] as $skill): ?>
                                <div class="skill-item">
                                    <div class="skill-name"><?= htmlspecialchars($skill['skill_name']) ?></div>
                                    <div class="skill-level"><?= ucfirst($skill['skill_level']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Family Background -->
                        <div class="resume-section">
                            <div class="section-title">Family Background</div>
                            <?php if (!empty($resume_data['personal']['mother_name'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Mother</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['mother_name']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['mother_occupation'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Mother's Occupation</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['mother_occupation']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['father_name'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Father</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['father_name']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['father_occupation'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Father's Occupation</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['father_occupation']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($resume_data['personal']['siblings'])): ?>
                            <div class="personal-info-item">
                                <div class="info-label">Siblings</div>
                                <div class="info-value"><?= htmlspecialchars($resume_data['personal']['siblings']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Download Button -->
            <div class="download-container" id="downloadContainer">
                <button type="button" class="btn btn-primary" id="downloadPdfBtn">
                    <i class="fas fa-download"></i> Download as PDF
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Back Button Container -->
            <div class="back-button-container">
                <button class="back-button" id="backFromResumeBtn">
                    <i class="fas fa-arrow-left"></i> Back to Options
                </button>
            </div>
        </div>
        
        <!-- Career Hub Frame (initially hidden) -->
        <div class="content-frame" id="careerHubFrame">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-graduation-cap"></i> Career Hub</h2>
                    <p>Explore job preparation resources, interview guides, and skills-building tools.</p>
                </div>
            </div>
            
            <!-- Shared Resources from Staff -->
            <div class="shared-resources-section">
                <h3><i class="fas fa-share-square"></i> Resources Shared by Staff</h3>
                <div class="resources-list" id="sharedResources">
                    <?php if (!empty($shared_resources)): ?>
                        <?php foreach ($shared_resources as $resource): ?>
                            <div class="resource-item <?= $resource['is_read'] ? 'read' : 'unread' ?>" id="resource-<?= $resource['resource_id'] ?>">
                                <div class="resource-header">
                                    <h4><?= htmlspecialchars($resource['resource_title']) ?></h4>
                                    <span class="resource-type"><?= ucfirst(str_replace('_', ' ', $resource['resource_type'])) ?></span>
                                </div>
                                <?php if (!empty($resource['resource_description'])): ?>
                                    <p class="resource-description"><?= htmlspecialchars($resource['resource_description']) ?></p>
                                <?php endif; ?>
                                <div class="resource-meta">
                                    <span class="shared-by">Shared by: <?= htmlspecialchars($resource['staff_name']) ?></span>
                                    <span class="shared-date"><?= date('M j, Y g:i A', strtotime($resource['shared_at'])) ?></span>
                                </div>
                                <a href="<?= htmlspecialchars($resource['resource_url']) ?>" target="_blank" class="access-btn" onclick="markResourceAsRead(<?= $resource['resource_id'] ?>, this)">
                                    <i class="fas fa-external-link-alt"></i> Access Resource
                                </a>
                                <form method="POST" class="mark-resource-form" id="form-<?= $resource['resource_id'] ?>" style="display: none;">
                                    <input type="hidden" name="mark_resource_read" value="1">
                                    <input type="hidden" name="resource_id" value="<?= $resource['resource_id'] ?>">
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-resources">
                            <p>No resources have been shared with you yet. Staff members will share helpful resources based on your needs.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="resources-grid">
                <!-- Interview Tips -->
                <div class="resource-card">
                    <h3 class="resource-title">Interview Tips</h3>
                    <ul class="resource-list">
                        <li><i class="fas fa-check-circle"></i> Dress professionally and research the company</li>
                        <li><i class="fas fa-check-circle"></i> Practice common interview questions</li>
                        <li><i class="fas fa-check-circle"></i> Show confidence and ask thoughtful questions</li>
                        <li><i class="fas fa-check-circle"></i> Be honest about your skills but show willingness to grow</li>
                    </ul>
                </div>
                
                <!-- Sample Questions -->
                <div class="resource-card">
                    <h3 class="resource-title">Sample Interview Questions</h3>
                    <ul class="resource-list">
                        <li><i class="fas fa-question-circle"></i> Tell me about yourself</li>
                        <li><i class="fas fa-question-circle"></i> Why should we hire you?</li>
                        <li><i class="fas fa-question-circle"></i> Describe a challenge you faced and how you overcame it</li>
                        <li><i class="fas fa-question-circle"></i> Where do you see yourself in 5 years?</li>
                    </ul>
                </div>
                
                <!-- Downloadable Resources -->
                <div class="resource-card">
                    <h3 class="resource-title">Downloadable Resources</h3>
                    <ul class="resource-list">
                        <li>
                            <i class="fas fa-file-word"></i> Resume Template - Basic Format
                            <a href="?download=resume_template" class="download-link">
                                <i class="fas fa-download"></i> Download (.docx)
                            </a>
                        </li>
                        <li>
                            <i class="fas fa-file-pdf"></i> Interview Preparation Checklist
                            <a href="?download=interview_checklist" class="download-link">
                                <i class="fas fa-download"></i> Download (.pdf)
                            </a>
                        </li>
                        <li>
                            <i class="fas fa-file-word"></i> Sample Cover Letter
                            <a href="?download=cover_letter" class="download-link">
                                <i class="fas fa-download"></i> Download (.docx)
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Video Guides -->
            <h3 style="margin-top: 30px; color: var(--maroon);">Video Guides</h3>
            <div class="video-grid">
                <div class="video-card">
                    <i class="fas fa-video"></i>
                    <h4>How to Prepare for Interviews</h4>
                    <a href="https://youtu.be/LCWr-TJrc0k?si=SBthisxykdE1QCBH" target="_blank" class="watch-btn">Watch Now</a>
                </div>
                <div class="video-card">
                    <i class="fas fa-video"></i>
                    <h4>Resume Writing Tips for Graduates</h4>
                    <a href="https://youtu.be/R3abknwWX7k?si=K4hYwo8m9SbVJ0kG" target="_blank" class="watch-btn">Watch Now</a>
                </div>
                <div class="video-card">
                    <i class="fas fa-video"></i>
                    <h4>Mastering Job Fairs and Online Applications</h4>
                    <a href="https://youtu.be/LI_WmATfgFg?si=PKrSBlQ4O-pk2por" target="_blank" class="watch-btn">Watch Now</a>
                </div>
            </div>
            
            <!-- Back Button Container -->
            <div class="back-button-container">
                <button class="back-button" id="backFromCareerBtn">
                    <i class="fas fa-arrow-left"></i> Back to Options
                </button>
            </div>
        </div>
    </div>

    <script>
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
        
        // Enhanced notification functionality
        $(document).ready(function() {
            // Handle notification click and mark as read
            $('.notification-link').on('click', function(e) {
                const notifId = $(this).data('notif-id');
                const notificationItem = $(this);
                
                // Only mark as read if it's unread and has an ID
                if (notificationItem.hasClass('unread') && notifId) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'graduate_tools.php',
                        type: 'POST',
                        data: {
                            mark_as_read: true,
                            notif_id: notifId
                        },
                        success: function(response) {
                            try {
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
                            } catch (e) {
                                console.log('Error parsing response');
                            }
                        },
                        error: function() {
                            console.log('Error marking notification as read');
                        }
                    });
                }
            });
            
            // Add hover effects to cards
            $('.choice-card').hover(
                function() {
                    $(this).css('transform', 'translateY(-5px)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                }
            );
        });
        
        // Frame Selection Functionality
        const choiceContainer = document.getElementById('choiceContainer');
        const resumeBuilderBtn = document.getElementById('resumeBuilderBtn');
        const careerHubBtn = document.getElementById('careerHubBtn');
        const resumeBuilderFrame = document.getElementById('resumeBuilderFrame');
        const careerHubFrame = document.getElementById('careerHubFrame');
        const backFromResumeBtn = document.getElementById('backFromResumeBtn');
        const backFromCareerBtn = document.getElementById('backFromCareerBtn');
        
        resumeBuilderBtn.addEventListener('click', function() {
            choiceContainer.style.display = 'none';
            resumeBuilderFrame.style.display = 'block';
            careerHubFrame.style.display = 'none';
        });
        
        careerHubBtn.addEventListener('click', function() {
            choiceContainer.style.display = 'none';
            careerHubFrame.style.display = 'block';
            resumeBuilderFrame.style.display = 'none';
        });
        
        backFromResumeBtn.addEventListener('click', function() {
            resumeBuilderFrame.style.display = 'none';
            choiceContainer.style.display = 'flex';
        });
        
        backFromCareerBtn.addEventListener('click', function() {
            careerHubFrame.style.display = 'none';
            choiceContainer.style.display = 'flex';
        });
        
        // Skills Management
        const skillsContainer = document.getElementById('skillsContainer');
        const newSkillInput = document.getElementById('newSkill');
        const addSkillBtn = document.getElementById('addSkillBtn');
        let additionalSkills = [];
        
        addSkillBtn.addEventListener('click', function() {
            const skill = newSkillInput.value.trim();
            if (skill && !additionalSkills.includes(skill)) {
                addSkill(skill);
                additionalSkills.push(skill);
                newSkillInput.value = '';
            }
        });
        
        function addSkill(skill) {
            const skillTag = document.createElement('div');
            skillTag.className = 'skill-tag';
            skillTag.innerHTML = `
                ${skill}
                <i class="fas fa-times" onclick="removeSkill('${skill}')"></i>
            `;
            skillsContainer.appendChild(skillTag);
        }
        
        function removeSkill(skill) {
            additionalSkills = additionalSkills.filter(s => s !== skill);
            const skillTags = skillsContainer.querySelectorAll('.skill-tag');
            skillTags.forEach(tag => {
                if (tag.textContent.includes(skill)) {
                    tag.remove();
                }
            });
        }
        
        // Dynamic Form Elements
        // Add Education
        document.getElementById('addEducationBtn').addEventListener('click', function() {
            const container = document.getElementById('education-container');
            const newElement = document.createElement('div');
            newElement.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Level (e.g., College, Senior High School)</label>
                        <input type="text" name="education_level[]" class="form-input" placeholder="Enter education level">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">School Name</label>
                        <input type="text" name="education_school[]" class="form-input" placeholder="Enter school name">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Details (e.g., Course, Honors)</label>
                        <input type="text" name="education_details[]" class="form-input" placeholder="Enter details">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Year Graduated</label>
                        <input type="number" name="education_year[]" class="form-input" placeholder="Enter year" min="1900" max="2099">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" class="btn btn-secondary" onclick="this.parentElement.parentElement.parentElement.remove()">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                </div>
                <hr style="margin: 15px 0; border: 1px solid #eee;">
            `;
            container.appendChild(newElement);
        });
        
        // Add Experience
        document.getElementById('addExperienceBtn').addEventListener('click', function() {
            const container = document.getElementById('experience-container');
            const newElement = document.createElement('div');
            newElement.innerHTML = `
                <div class="form-group form-group-full">
                    <label class="form-label">Experience Description</label>
                    <textarea name="experience_description[]" class="form-textarea" placeholder="Describe your experience"></textarea>
                    <button type="button" class="btn btn-secondary" style="margin-top: 10px;" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i> Remove
                    </button>
                </div>
                <hr style="margin: 15px 0; border: 1px solid #eee;">
            `;
            container.appendChild(newElement);
        });
        
        // Add Character Reference
        document.getElementById('addReferenceBtn').addEventListener('click', function() {
            const container = document.getElementById('references-container');
            const newElement = document.createElement('div');
            newElement.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Reference Name</label>
                        <input type="text" name="reference_name[]" class="form-input" placeholder="Enter reference name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Position</label>
                        <input type="text" name="reference_position[]" class="form-input" placeholder="Enter position">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Company</label>
                        <input type="text" name="reference_company[]" class="form-input" placeholder="Enter company">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" class="btn btn-secondary" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </div>
                </div>
                <hr style="margin: 15px 0; border: 1px solid #eee;">
            `;
            container.appendChild(newElement);
        });
        
        // Form Submission
        document.getElementById('resumeForm').addEventListener('submit', function(e) {
            // Add additional skills to form data
            additionalSkills.forEach(skill => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'additional_skills[]';
                input.value = skill;
                this.appendChild(input);
            });
            
            // Validation is handled by HTML5 required attributes
            // The form will submit to the same page and PHP will process it
        });
        
        // PDF Generation Functionality
        document.getElementById('downloadPdfBtn')?.addEventListener('click', function() {
            generatePDF();
        });
        
        function generatePDF() {
            const { jsPDF } = window.jspdf;
            if (!jsPDF) {
                alert('PDF generation library not loaded. Please try again.');
                return;
            }
            
            const doc = new jsPDF('p', 'mm', 'a4');
            
            // Get resume preview element
            const resumePreview = document.getElementById('resumePreview');
            
            if (!resumePreview) {
                alert('Resume preview not found. Please generate a resume first.');
                return;
            }
            
            // Use html2canvas to capture the resume preview as an image
            html2canvas(resumePreview, {
                scale: 2, // Higher scale for better quality
                useCORS: true,
                logging: false
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/jpeg', 1.0);
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 295; // A4 height in mm
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;
                
                // Add first page
                doc.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                // Add additional pages if needed
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    doc.addPage();
                    doc.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                // Save the PDF
                const fullName = resumePreview.querySelector('.resume-name').textContent;
                const fileName = `${fullName.replace(/\s+/g, '_')}_Resume.pdf`;
                doc.save(fileName);
            }).catch(error => {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF. Please try again.');
            });
        }
        
        // Resource Marking Functionality
        function markResourceAsRead(resourceId, element) {
            // Submit the form via AJAX
            const form = document.getElementById(`form-${resourceId}`);
            if (!form) return true;
            
            const formData = new FormData(form);
            formData.append('ajax', 'true');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const resourceItem = document.getElementById(`resource-${resourceId}`);
                    if (resourceItem) {
                        resourceItem.classList.remove('unread');
                        resourceItem.classList.add('read');
                    }
                    
                    // Update the unread resources count in the UI if needed
                    updateUnreadResourcesCount();
                }
            })
            .catch(error => console.error('Error:', error));
            
            // Allow the link to open normally
            return true;
        }
        
        function updateUnreadResourcesCount() {
            // This function can be expanded to update a counter in the UI
            const unreadResources = document.querySelectorAll('.resource-item.unread');
            console.log(`Unread resources: ${unreadResources.length}`);
            // You could update a badge counter here if desired
        }
        
        // Initialize with some skills
        window.onload = function() {
            // Show alerts if they exist
            <?php if (!empty($success_message)): ?>
                document.getElementById('successAlert').style.display = 'block';
                setTimeout(function() {
                    const alert = document.getElementById('successAlert');
                    if (alert) alert.style.display = 'none';
                }, 5000);
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                document.getElementById('errorAlert').style.display = 'block';
                setTimeout(function() {
                    const alert = document.getElementById('errorAlert');
                    if (alert) alert.style.display = 'none';
                }, 5000);
            <?php endif; ?>
            
            // Auto-fill some fields based on user data
            <?php if (!empty($graduate['usr_birthdate'])): ?>
                document.getElementById('age').value = calculateAge('<?= $graduate['usr_birthdate'] ?>');
            <?php endif; ?>
            
            // Initialize unread resources count
            updateUnreadResourcesCount();
        };
        
        // Helper function to calculate age from birthdate
        function calculateAge(birthdate) {
            const today = new Date();
            const birthDate = new Date(birthdate);
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            return age;
        }
    </script>
</body>
</html>