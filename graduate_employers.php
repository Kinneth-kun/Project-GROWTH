<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

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
 * Check for new employers and generate notifications
 */
function checkNewEmployers($conn, $graduate_id) {
    $notificationsGenerated = 0;
    
    try {
        // Check for employers joined in the last 24 hours
        $newEmployers = $conn->prepare("
            SELECT e.emp_company_name, e.emp_industry
            FROM employers e
            JOIN users u ON e.emp_usr_id = u.usr_id
            WHERE u.usr_account_status = 'active'
            AND u.usr_is_approved = 1
            AND u.usr_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY u.usr_created_at DESC
            LIMIT 3
        ");
        $newEmployers->execute();
        $employers = $newEmployers->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($employers as $employer) {
            // Check if notification already exists for this employer
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_message LIKE ? 
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
            ");
            $searchPattern = "%{$employer['emp_company_name']}%";
            $existingNotif->execute([$graduate_id, $searchPattern]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "New employer joined: '{$employer['emp_company_name']}' in {$employer['emp_industry']}";
                
                if (createGraduateNotification($conn, $graduate_id, 'new_employer', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error checking new employers: " . $e->getMessage());
    }
    
    return $notificationsGenerated;
}

/**
 * Check for job opportunities from viewed employers
 */
function checkEmployerJobOpportunities($conn, $graduate_id, $graduate) {
    $notificationsGenerated = 0;
    
    try {
        // Get job preference
        $job_preference = $graduate['grad_job_preference'] ?? 'General';
        
        // Check for jobs from recently viewed employers that match preferences
        $jobOpportunities = $conn->prepare("
            SELECT j.job_title, e.emp_company_name
            FROM jobs j
            JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
            JOIN employer_profile_views epv ON e.emp_usr_id = epv.view_emp_usr_id
            WHERE j.job_status = 'active'
            AND epv.view_grad_usr_id = ?
            AND (j.job_domain LIKE CONCAT('%', ?, '%') OR j.job_title LIKE CONCAT('%', ?, '%'))
            AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND epv.view_viewed_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ORDER BY j.job_created_at DESC
            LIMIT 3
        ");
        $jobOpportunities->execute([$graduate_id, $job_preference, $job_preference]);
        $jobs = $jobOpportunities->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as $job) {
            // Check if notification already exists
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_message LIKE ? 
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $searchPattern = "%{$job['job_title']}%";
            $existingNotif->execute([$graduate_id, $searchPattern]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Job match at viewed employer: '{$job['job_title']}' at {$job['emp_company_name']}";
                
                if (createGraduateNotification($conn, $graduate_id, 'job_opportunity', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error checking employer job opportunities: " . $e->getMessage());
    }
    
    return $notificationsGenerated;
}

/**
 * Enhanced employers notifications generator
 */
function generateEmployerNotifications($conn, $graduate_id, $graduate) {
    $totalNotifications = 0;
    
    try {
        // 1. Check for new employers
        $totalNotifications += checkNewEmployers($conn, $graduate_id);
        
        // 2. Check for job opportunities from viewed employers
        $totalNotifications += checkEmployerJobOpportunities($conn, $graduate_id, $graduate);
    } catch (Exception $e) {
        error_log("Error generating employer notifications: " . $e->getMessage());
    }
    
    return $totalNotifications;
}

/**
 * Generate default notifications based on user data and activities
 */
function generateDefaultNotifications($conn, $graduate_id, $all_employers) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to the Employers section! Discover companies hiring in your field.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // New employers notification
    if (count($all_employers) > 0) {
        $default_notifications[] = [
            'notif_message' => 'There are ' . count($all_employers) . ' active employers looking for talent.',
            'notif_type' => 'employer_update',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    }
    
    // Employer viewing tips
    $default_notifications[] = [
        'notif_message' => 'Tip: Research employers before applying to understand their company culture.',
        'notif_type' => 'employer_tip',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
    ];
    
    return $default_notifications;
}

/**
 * Get appropriate icon for notification type
 */
function getNotificationIcon($type) {
    switch ($type) {
        case 'new_employer':
            return 'fas fa-building';
        case 'job_opportunity':
            return 'fas fa-briefcase';
        case 'employer_view':
            return 'fas fa-eye';
        case 'employer_update':
            return 'fas fa-info-circle';
        case 'employer_tip':
            return 'fas fa-lightbulb';
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
    
    if (strpos($type, 'job_opportunity') !== false) {
        return 'high';
    } elseif (strpos($type, 'new_employer') !== false) {
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
    
    if (strpos($type, 'employer') !== false || strpos($message, 'employer') !== false) {
        return 'graduate_employers.php';
    } elseif (strpos($type, 'job') !== false || strpos($message, 'job') !== false) {
        return 'graduate_jobs.php';
    } else {
        return 'graduate_employers.php';
    }
}

/**
 * Get employer documents
 */
function getEmployerDocuments($conn, $employer_id) {
    try {
        $stmt = $conn->prepare("
            SELECT emp_business_permit, emp_dti_sec 
            FROM employers 
            WHERE emp_usr_id = ?
        ");
        $stmt->execute([$employer_id]);
        $documents = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $document_list = [];
        if (!empty($documents['emp_business_permit'])) {
            $document_list[] = [
                'type' => 'Business Permit',
                'file_path' => $documents['emp_business_permit'],
                'icon' => 'fas fa-file-contract',
                'file_type' => pathinfo($documents['emp_business_permit'], PATHINFO_EXTENSION)
            ];
        }
        
        if (!empty($documents['emp_dti_sec'])) {
            $document_list[] = [
                'type' => 'DTI/SEC Registration',
                'file_path' => $documents['emp_dti_sec'],
                'icon' => 'fas fa-building',
                'file_type' => pathinfo($documents['emp_dti_sec'], PATHINFO_EXTENSION)
            ];
        }
        
        return $document_list;
    } catch (PDOException $e) {
        error_log("Error fetching employer documents: " . $e->getMessage());
        return [];
    }
}

/**
 * Format date for display
 */
function formatDate($date) {
    $now = new DateTime();
    $dateObj = new DateTime($date);
    $interval = $now->diff($dateObj);
    
    if ($interval->days == 0) {
        return 'Today';
    } elseif ($interval->days == 1) {
        return 'Yesterday';
    } elseif ($interval->days < 7) {
        return $interval->days . ' days ago';
    } else {
        return $dateObj->format('M j, Y');
    }
}

/**
 * Get file icon based on file type
 */
function getFileIcon($file_type) {
    $file_type = strtolower($file_type);
    switch ($file_type) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            return 'fas fa-file-image';
        default:
            return 'fas fa-file';
    }
}

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
    
    // Get graduate data
    $graduate_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT g.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, 
               u.usr_gender, u.usr_birthdate, u.usr_account_status, u.usr_is_approved
        FROM graduates g 
        JOIN users u ON g.grad_usr_id = u.usr_id 
        WHERE g.grad_usr_id = :graduate_id
    ");
    $stmt->bindParam(':graduate_id', $graduate_id);
    $stmt->execute();
    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$graduate) {
        error_log("No graduate record found for user ID: $graduate_id, redirecting to profile setup");
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
    
    // ===== ADDED: Track employer view when clicking from list =====
    if (isset($_GET['employer_id']) && !isset($_GET['view_tracked'])) {
        $employer_id = $_GET['employer_id'];
        
        // Check if this view already exists today (prevent duplicate counts)
        $check_view_stmt = $conn->prepare("
            SELECT view_id FROM employer_profile_views 
            WHERE view_emp_usr_id = :employer_id 
            AND view_grad_usr_id = :graduate_id 
            AND DATE(view_viewed_at) = CURDATE()
            LIMIT 1
        ");
        $check_view_stmt->bindParam(':employer_id', $employer_id);
        $check_view_stmt->bindParam(':graduate_id', $graduate_id);
        $check_view_stmt->execute();
        
        if ($check_view_stmt->rowCount() === 0) {
            // Insert new view record
            $insert_view_stmt = $conn->prepare("
                INSERT INTO employer_profile_views (view_emp_usr_id, view_grad_usr_id, view_viewed_at) 
                VALUES (:employer_id, :graduate_id, NOW())
            ");
            $insert_view_stmt->bindParam(':employer_id', $employer_id);
            $insert_view_stmt->bindParam(':graduate_id', $graduate_id);
            $insert_view_stmt->execute();
            
            // Create notification for employer profile view
            $employer_name_stmt = $conn->prepare("SELECT emp_company_name FROM employers WHERE emp_usr_id = :employer_id");
            $employer_name_stmt->bindParam(':employer_id', $employer_id);
            $employer_name_stmt->execute();
            $employer_name = $employer_name_stmt->fetch(PDO::FETCH_ASSOC)['emp_company_name'] ?? 'Unknown Employer';
            
            createGraduateNotification($conn, $graduate_id, 'employer_view', "You viewed {$employer_name}'s profile");
        }
        
        // Redirect to avoid duplicate tracking on page refresh
        header("Location: graduate_employers.php?employer_id=" . $employer_id . "&view_tracked=1");
        exit();
    }
    // ===== END ADDED CODE =====
    
    // Generate employer-specific notifications
    $notificationsGenerated = generateEmployerNotifications($conn, $graduate_id, $graduate);
    
    // Get active employers count
    $employers_stmt = $conn->prepare("
        SELECT COUNT(*) as active_employers 
        FROM employers e 
        JOIN users u ON e.emp_usr_id = u.usr_id 
        WHERE u.usr_account_status = 'active' 
        AND u.usr_is_approved = 1
    ");
    $employers_stmt->execute();
    $employers_count = $employers_stmt->fetch(PDO::FETCH_ASSOC)['active_employers'];
    
    // Get recently joined employers (Last 2 weeks) - MODIFIED
    $recent_employers_stmt = $conn->prepare("
        SELECT e.emp_company_name, e.emp_industry, u.usr_created_at 
        FROM employers e 
        JOIN users u ON e.emp_usr_id = u.usr_id 
        WHERE u.usr_account_status = 'active' 
        AND u.usr_is_approved = 1
        AND u.usr_created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)  -- MODIFIED: Last 2 weeks (14 days)
        ORDER BY u.usr_created_at DESC 
        LIMIT 5
    ");
    $recent_employers_stmt->execute();
    $recent_employers = $recent_employers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get count of recently joined employers for the stat card (Last 2 weeks) - MODIFIED
    $recent_employers_count_stmt = $conn->prepare("
        SELECT COUNT(*) as recent_count 
        FROM employers e 
        JOIN users u ON e.emp_usr_id = u.usr_id 
        WHERE u.usr_account_status = 'active' 
        AND u.usr_is_approved = 1
        AND u.usr_created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)  -- MODIFIED: Last 2 weeks (14 days)
    ");
    $recent_employers_count_stmt->execute();
    $recent_employers_count = $recent_employers_count_stmt->fetch(PDO::FETCH_ASSOC)['recent_count'];
    
    // Get all active employers with details
    $all_employers_stmt = $conn->prepare("
        SELECT e.*, u.usr_created_at, u.usr_email,
               (SELECT COUNT(*) FROM jobs j WHERE j.job_emp_usr_id = e.emp_usr_id AND j.job_status = 'active') as job_count
        FROM employers e 
        JOIN users u ON e.emp_usr_id = u.usr_id 
        WHERE u.usr_account_status = 'active' 
        AND u.usr_is_approved = 1
        ORDER BY e.emp_company_name
    ");
    $all_employers_stmt->execute();
    $all_employers = $all_employers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
            $notifications = generateDefaultNotifications($conn, $graduate_id, $all_employers);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultNotifications($conn, $graduate_id, $all_employers);
        $unread_notif_count = count($notifications);
    }
    
    // Check if a specific employer is selected
    $selected_employer = null;
    $employer_jobs = [];
    $employer_documents = [];
    
    if (isset($_GET['employer_id'])) {
        $employer_id = $_GET['employer_id'];
        
        // Get employer details
        $employer_stmt = $conn->prepare("
            SELECT e.*, u.usr_created_at, u.usr_email
            FROM employers e 
            JOIN users u ON e.emp_usr_id = u.usr_id 
            WHERE e.emp_usr_id = :employer_id 
            AND u.usr_account_status = 'active' 
            AND u.usr_is_approved = 1
        ");
        $employer_stmt->bindParam(':employer_id', $employer_id);
        $employer_stmt->execute();
        $selected_employer = $employer_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_employer) {
            // Get employer documents
            $employer_documents = getEmployerDocuments($conn, $employer_id);
            
            // Get jobs for this employer with application status
            $jobs_stmt = $conn->prepare("
                SELECT j.*, 
                       EXISTS(SELECT 1 FROM applications a2 WHERE a2.app_job_id = j.job_id AND a2.app_grad_usr_id = :graduate_id) as has_applied
                FROM jobs j 
                WHERE j.job_emp_usr_id = :employer_id 
                AND j.job_status = 'active'
                ORDER BY j.job_created_at DESC
            ");
            $jobs_stmt->bindParam(':employer_id', $employer_id);
            $jobs_stmt->bindParam(':graduate_id', $graduate_id);
            $jobs_stmt->execute();
            $employer_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
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
        header("Location: graduate_employers.php");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Active Employers</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        
        .notification-item {
            display: flex;
            align-items: flex-start;
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
        
        .unread {
            background-color: #fff5e6;
            border-left: 3px solid var(--secondary-color);
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
            background-color: var(--secondary-color);
        }
        
        .priority-low {
            background-color: var(--green);
        }
        
        /* Enhanced Welcome Message */
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
        
        /* Page Title */
        .page-title {
            margin-bottom: 20px;
            color: var(--primary-color);
            font-size: 1.9rem;
            font-weight: 700;
            position: relative;
            padding-bottom: 10px;
        }

        
        /* MODIFIED: Stats Grid - Made smaller and more compact */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            border-top: 4px solid var(--secondary-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 1.4rem;
            color: white;
        }
        
        .stat-icon.employers {
            background: linear-gradient(135deg, var(--blue), #0033cc);
        }
        
        .stat-icon.recent {
            background: linear-gradient(135deg, var(--purple), #4b0082);
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        /* Content Sections */
        .content-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 25px;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }
        
        .content-section:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-title {
            font-size: 1.4rem;
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fff5e6, #ffedd5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
            font-size: 1.3rem;
        }
        
        /* Employers List */
        .employers-list {
            list-style: none;
        }
        
        .employer-item {
            padding: 20px;
            border-bottom: 1px solid #eee;
            transition: all 0.3s;
            cursor: pointer;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .employer-item:hover {
            background-color: #fff5e6;
            transform: translateX(5px);
        }
        
        .employer-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .employer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .employer-name {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.2rem;
            margin-right: 10px;
        }
        
        .employer-jobs {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .employer-details {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 0.9rem;
            color: #666;
            flex-wrap: wrap;
        }
        
        .employer-industry {
            background-color: #f0f0f0;
            padding: 5px 12px;
            border-radius: 20px;
            margin-right: 10px;
            font-weight: 500;
        }
        
        .employer-date {
            color: #999;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Employer Detail View */
        .employer-detail {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-bottom: 25px;
            border-top: 4px solid var(--secondary-color);
        }
        
        .employer-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .employer-detail-name {
            font-size: 2rem;
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .employer-detail-industry {
            font-size: 1.2rem;
            color: #666;
            margin-bottom: 10px;
        }
        
        .back-button {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            transition: all 0.3s;
            margin-left: auto;
            font-weight: 600;
        }
        
        .back-button:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-2px);
        }
        
        .employer-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .info-item {
            margin-bottom: 20px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 8px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-label i {
            color: var(--secondary-color);
        }
        
        .info-value {
            color: #333;
            font-size: 1rem;
        }
        
        /* Documents Section */
        .documents-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .document-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }
        
        .document-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .document-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--blue), #0033cc);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }
        
        .document-info {
            flex: 1;
        }
        
        .document-type {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .document-view {
            background: var(--blue);
            color: white;
            padding: 6px 12px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .document-view:hover {
            background: #0033cc;
            transform: translateY(-1px);
        }
        
        /* Jobs List */
        .jobs-list {
            list-style: none;
            margin-top: 25px;
        }
        
        .job-item {
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s;
            background: white;
        }
        
        .job-item:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .job-title {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.3rem;
            margin-bottom: 10px;
        }
        
        .job-details {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .job-detail {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .job-detail i {
            color: var(--secondary-color);
            width: 16px;
        }
        
        .job-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 15px;
        }
        
        .view-job-btn {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .view-job-btn:hover {
            background: linear-gradient(135deg, #0033cc, var(--blue));
            transform: translateY(-2px);
        }
        
        .applied-btn {
            background: linear-gradient(135deg, var(--green), #2e7d32);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: not-allowed;
            text-decoration: none;
            display: inline-block;
            font-weight: 600;
        }
        
        /* Enhanced Job Details Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 20px;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .job-detail-modal, .document-modal {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .job-detail-modal,
        .modal-overlay.active .document-modal {
            transform: translateY(0);
        }
        
        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            position: relative;
        }
        
        .modal-title {
            font-size: 1.8rem;
            color: white;
            font-weight: 600;
        }
        
        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        
        .modal-content {
            padding: 30px;
        }
        
        .document-preview {
            width: 100%;
            height: 600px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f9f9f9;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .document-iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 8px;
        }
        
        .document-placeholder {
            text-align: center;
            color: #666;
        }
        
        .document-placeholder i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--blue);
        }
        
        .document-info-preview {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .document-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        
        .document-info-label {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .document-info-value {
            color: #333;
        }
        
        .job-detail-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .job-info-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .job-main-info {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
            position: relative;
        }
        
        .job-main-title {
            font-size: 26px;
            margin-bottom: 10px;
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .job-main-company {
            color: var(--dark-gray);
            margin-bottom: 10px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .job-detail-list {
            margin-top: 20px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-label i {
            width: 20px;
            text-align: center;
            color: var(--secondary-color);
        }
        
        .detail-value {
            color: #555;
            font-weight: 500;
        }
        
        .job-description-section, .job-requirements-section, .job-skills-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .section-title {
            font-size: 1.3rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--secondary-color);
        }
        
        .skills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .skill {
            background: rgba(110, 3, 3, 0.1);
            color: var(--primary-color);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            border: 1px solid rgba(110, 3, 3, 0.2);
            font-weight: 500;
        }
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .btn-view {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #333;
        }
        
        .btn-view:hover {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            transform: translateY(-2px);
        }
        
        .btn-apply {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .btn-apply:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-2px);
        }
        
        /* Tips Box */
        .tips-box {
            background: linear-gradient(135deg, #fff8e1, #ffecb3);
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            border-left: 4px solid #ffc107;
        }
        
        .tips-title {
            font-weight: 700;
            margin-bottom: 15px;
            color: #7d6608;
            font-size: 1.1rem;
        }
        
        .tips-list {
            padding-left: 20px;
            color: #666;
        }
        
        .tips-list li {
            margin-bottom: 10px;
            line-height: 1.5;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .job-detail-content {
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .employer-details {
                flex-direction: column;
                gap: 5px;
            }
            
            .employer-info-grid {
                grid-template-columns: 1fr;
            }
            
            .employer-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .employer-detail-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .back-button {
                margin-left: 0;
                width: 100%;
            }
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
            
            .job-details {
                flex-direction: column;
                gap: 10px;
            }
            
            .document-preview {
                height: 400px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .employer-detail {
                padding: 20px;
            }
            
            .modal-content {
                padding: 20px;
            }
            
            .job-detail-content {
                gap: 15px;
            }
            
            .document-preview {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Job Details Modal -->
    <div class="modal-overlay" id="jobDetailModal">
        <div class="job-detail-modal">
            <div class="modal-header">
                <h2 class="modal-title">Job Details</h2>
                <button class="modal-close" onclick="closeJobDetailModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <div class="job-detail-content">
                    <div class="job-info-section">
                        <div class="job-main-info">
                            <h2 class="job-main-title" id="detail-job-title"></h2>
                            <p class="job-main-company" id="detail-job-company"></p>
                        </div>
                        
                        <div class="job-detail-list">
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-building"></i>
                                    Company
                                </span>
                                <span class="detail-value" id="detail-company-name"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Location
                                </span>
                                <span class="detail-value" id="detail-job-location"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-clock"></i>
                                    Job Type
                                </span>
                                <span class="detail-value" id="detail-job-type"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-money-bill-wave"></i>
                                    Salary
                                </span>
                                <span class="detail-value" id="detail-job-salary"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-tag"></i>
                                    Domain
                                </span>
                                <span class="detail-value" id="detail-job-domain"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-calendar"></i>
                                    Posted
                                </span>
                                <span class="detail-value" id="detail-job-posted"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="job-description-section">
                            <h3 class="section-title"><i class="fas fa-file-alt"></i> Job Description</h3>
                            <div id="detail-job-description"></div>
                        </div>
                        
                        <div class="job-requirements-section">
                            <h3 class="section-title"><i class="fas fa-tasks"></i> Job Requirements</h3>
                            <div id="detail-job-requirements"></div>
                        </div>
                        
                        <div class="job-skills-section">
                            <h3 class="section-title"><i class="fas fa-tools"></i> Required Skills</h3>
                            <div class="skills" id="detail-job-skills"></div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button class="btn btn-view" onclick="closeJobDetailModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button class="btn btn-apply" id="detail-apply-btn">
                        <i class="fas fa-paper-plane"></i> Apply Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Document View Modal -->
    <div class="modal-overlay" id="documentModal">
        <div class="document-modal">
            <div class="modal-header">
                <h2 class="modal-title" id="document-modal-title">View Document</h2>
                <button class="modal-close" onclick="closeDocumentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <div class="document-preview" id="document-preview">
                    <div class="document-placeholder">
                        <i class="fas fa-file"></i>
                        <p>Loading document preview...</p>
                    </div>
                </div>
                <div class="document-info-preview">
                    <div class="document-info-item">
                        <span class="document-info-label">Document Type:</span>
                        <span class="document-info-value" id="document-type-preview"></span>
                    </div>
                    <div class="document-info-item">
                        <span class="document-info-label">File Type:</span>
                        <span class="document-info-value" id="document-file-type"></span>
                    </div>
                    <div class="document-info-item">
                        <span class="document-info-label">Uploaded By:</span>
                        <span class="document-info-value"><?= htmlspecialchars($selected_employer['emp_company_name'] ?? 'Employer') ?></span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="btn btn-view" onclick="closeDocumentModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <a href="#" class="btn btn-apply" id="document-download-btn" download>
                        <i class="fas fa-download"></i> Download
                    </a>
                </div>
            </div>
        </div>
    </div>

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
                    <a href="graduate_employers.php" class="active">
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
                            <a href="<?= $notification_link ?>" class="dropdown-item notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?>">
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
            <p>Discover active employers and find your dream job opportunities. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new employer notifications." : "" ?></p>
        </div>

        <!-- Page Content -->
        <h1 class="page-title">Active Employers</h1>
        
        <!-- MODIFIED: Stats Overview - Made smaller and more compact -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon employers">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-number"><?= $employers_count ?></div>
                <div class="stat-label">Total Employers</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon recent">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-number"><?= $recent_employers_count ?></div>
                <div class="stat-label">Recently Joined</div>  <!-- MODIFIED: Updated label -->
            </div>
        </div>
        
        <?php if ($selected_employer): ?>
        <!-- Employer Detail View -->
        <div class="employer-detail">
            <div class="employer-detail-header">
                <div>
                    <h2 class="employer-detail-name"><?= htmlspecialchars($selected_employer['emp_company_name']) ?></h2>
                    <div class="employer-detail-industry"><?= htmlspecialchars($selected_employer['emp_industry']) ?></div>
                </div>
            </div>
            
            <div class="employer-info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-user-tie"></i> Contact Person</div>
                    <div class="info-value"><?= htmlspecialchars($selected_employer['emp_contact_person']) ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="info-value"><?= htmlspecialchars($selected_employer['usr_email']) ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                    <div class="info-value"><?= !empty($selected_employer['usr_phone']) ? htmlspecialchars($selected_employer['usr_phone']) : 'Not provided' ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-calendar-alt"></i> Member Since</div>
                    <div class="info-value"><?= date('M j, Y', strtotime($selected_employer['usr_created_at'])) ?></div>
                </div>
            </div>
            
            <?php if (!empty($selected_employer['emp_company_description'])): ?>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-info-circle"></i> Company Description</div>
                <div class="info-value"><?= nl2br(htmlspecialchars($selected_employer['emp_company_description'])) ?></div>
            </div>
            <?php endif; ?>
            
            <!-- Employer Documents Section -->
            <?php if (!empty($employer_documents)): ?>
            <div class="documents-section">
                <h3 class="section-title"><i class="fas fa-file-contract"></i> Company Documents</h3>
                <p>Verified documents submitted by the employer. Click "View" to preview documents within the system.</p>
                <div class="documents-grid">
                    <?php foreach ($employer_documents as $document): ?>
                    <div class="document-card">
                        <div class="document-icon">
                            <i class="<?= $document['icon'] ?>"></i>
                        </div>
                        <div class="document-info">
                            <div class="document-type"><?= $document['type'] ?></div>
                            <button class="document-view" onclick="openDocumentModal('<?= htmlspecialchars($document['file_path']) ?>', '<?= htmlspecialchars($document['type']) ?>', '<?= htmlspecialchars($document['file_type']) ?>')">
                                <i class="fas fa-eye"></i> View Document
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Jobs List -->
            <div class="section-header">
                <h3 class="section-title">Available Jobs</h3>
                <div class="section-icon">
                    <i class="fas fa-briefcase"></i>
                </div>
            </div>
            
            <?php if (!empty($employer_jobs)): ?>
                <ul class="jobs-list">
                    <?php foreach ($employer_jobs as $job): ?>
                    <li class="job-item">
                        <div class="job-title"><?= htmlspecialchars($job['job_title']) ?></div>
                        
                        <div class="job-details">
                            <div class="job-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= htmlspecialchars($job['job_location']) ?>
                            </div>
                            
                            <div class="job-detail">
                                <i class="fas fa-clock"></i>
                                <?= ucfirst(htmlspecialchars($job['job_type'])) ?>
                            </div>
                            
                            <?php if (!empty($job['job_salary_range'])): ?>
                            <div class="job-detail">
                                <i class="fas fa-money-bill-wave"></i>
                                <?= htmlspecialchars($job['job_salary_range']) ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="job-detail">
                                <i class="fas fa-calendar"></i>
                                <?= formatDate($job['job_created_at']) ?>
                            </div>
                        </div>
                        
                        <div class="job-actions">
                            <?php if ($job['has_applied']): ?>
                                <button class="applied-btn" disabled>
                                    <i class="fas fa-check"></i> Already Applied
                                </button>
                            <?php else: ?>
                                <button class="view-job-btn" onclick="openJobDetailModal(<?= htmlspecialchars(json_encode($job)) ?>, '<?= htmlspecialchars($selected_employer['emp_company_name']) ?>')">
                                    <i class="fas fa-eye"></i> View Job Details
                                </button>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No active job postings from this employer at the moment.</p>
            <?php endif; ?>
            
            <!-- Back Button -->
            <div style="display: flex; justify-content: flex-end;">
                <button class="back-button" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i> Back to Employers List
                </button>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Employers List -->
        <div class="content-section">
            <div class="section-header">
                <h3 class="section-title">All Employers</h3>
                <div class="section-icon">
                    <i class="fas fa-building"></i>
                </div>
            </div>
            
            <ul class="employers-list">
                <?php if (!empty($all_employers)): ?>
                    <?php foreach ($all_employers as $employer): ?>
                    <li class="employer-item" onclick="window.location.href='?employer_id=<?= $employer['emp_usr_id'] ?>'">
                        <div class="employer-header">
                            <div class="employer-name"><?= htmlspecialchars($employer['emp_company_name']) ?></div>
                            <span class="employer-jobs"><?= $employer['job_count'] ?> job<?= $employer['job_count'] != 1 ? 's' : '' ?></span>
                        </div>
                        
                        <div class="employer-details">
                            <span class="employer-industry"><?= htmlspecialchars($employer['emp_industry']) ?></span>
                            <span class="employer-date">
                                <i class="far fa-calendar"></i>
                                Joined <?= date('M j, Y', strtotime($employer['usr_created_at'])) ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="employer-item">No active employers found</li>
                <?php endif; ?>
            </ul>
        </div>
        
        <!-- Job Search Tips -->
        <div class="content-section">
            <div class="section-header">
                <h3 class="section-title">Job Search Tips</h3>
                <div class="section-icon">
                    <i class="fas fa-lightbulb"></i>
                </div>
            </div>
            
            <div class="tips-box">
                <div class="tips-title">Maximize your job search with these tips:</div>
                <ul class="tips-list">
                    <li>Keep your digital portfolio updated with your latest skills and projects</li>
                    <li>Customize your resume for each job application</li>
                    <li>Research companies before applying to understand their culture and values</li>
                    <li>Network with professionals in your desired industry</li>
                    <li>Set up job alerts to be notified of new opportunities</li>
                    <li>Review company documents to verify employer credibility</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
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
                
                // Only mark as read if it's unread
                if (notificationItem.hasClass('unread')) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'graduate_employers.php',
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
            $('.stat-card, .content-section, .employer-detail, .employer-item').hover(
                function() {
                    $(this).css('transform', 'translateY(-5px)');
                    $(this).css('box-shadow', '0 8px 25px rgba(0, 0, 0, 0.12)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                    $(this).css('box-shadow', '0 5px 20px rgba(0, 0, 0, 0.08)');
                }
            );
        });

        // Enhanced Job Details Modal Functions
        function openJobDetailModal(jobData, companyName) {
            // Populate modal with job data
            document.getElementById('detail-job-title').textContent = jobData.job_title;
            document.getElementById('detail-job-company').textContent = companyName;
            document.getElementById('detail-company-name').textContent = companyName;
            document.getElementById('detail-job-location').textContent = jobData.job_location || 'Not specified';
            document.getElementById('detail-job-type').textContent = jobData.job_type ? jobData.job_type.replace('-', ' ') : 'Not specified';
            document.getElementById('detail-job-salary').textContent = jobData.job_salary_range || 'Salary not specified';
            document.getElementById('detail-job-domain').textContent = jobData.job_domain || 'Not specified';
            document.getElementById('detail-job-description').innerHTML = formatText(jobData.job_description);
            document.getElementById('detail-job-requirements').innerHTML = formatText(jobData.job_requirements);
            
            // Format and display skills
            const skillsContainer = document.getElementById('detail-job-skills');
            if (jobData.job_skills && jobData.job_skills.trim() !== '') {
                const skills = jobData.job_skills.split(',').map(skill => skill.trim());
                skillsContainer.innerHTML = skills.map(skill => 
                    `<span class="skill">${skill}</span>`
                ).join('');
            } else {
                skillsContainer.innerHTML = '<span class="skill">No specific skills required</span>';
            }
            
            // Format date
            const postedDate = new Date(jobData.job_created_at);
            document.getElementById('detail-job-posted').textContent = postedDate.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Set up apply button
            const applyBtn = document.getElementById('detail-apply-btn');
            applyBtn.onclick = function() {
                closeJobDetailModal();
                // Redirect to jobs page to apply
                window.location.href = 'graduate_jobs.php?apply_job=' + jobData.job_id;
            };
            
            // Show modal
            document.getElementById('jobDetailModal').classList.add('active');
        }
        
        function closeJobDetailModal() {
            document.getElementById('jobDetailModal').classList.remove('active');
        }

        // Document View Modal Functions
        function openDocumentModal(filePath, documentType, fileType) {
            // Set modal title
            document.getElementById('document-modal-title').textContent = 'View ' + documentType;
            document.getElementById('document-type-preview').textContent = documentType;
            document.getElementById('document-file-type').textContent = fileType.toUpperCase();
            
            // Set download link
            document.getElementById('document-download-btn').href = filePath;
            
            // Create preview based on file type
            const previewContainer = document.getElementById('document-preview');
            previewContainer.innerHTML = '';
            
            const fileExtension = fileType.toLowerCase();
            
            if (fileExtension === 'pdf') {
                // PDF preview using iframe
                const iframe = document.createElement('iframe');
                iframe.src = filePath;
                iframe.className = 'document-iframe';
                iframe.onload = function() {
                    // Iframe loaded successfully
                };
                iframe.onerror = function() {
                    previewContainer.innerHTML = `
                        <div class="document-placeholder">
                            <i class="fas fa-file-pdf"></i>
                            <p>Unable to load PDF preview. The file may be too large or inaccessible.</p>
                            <p><a href="${filePath}" target="_blank" class="document-view">Open in new tab</a></p>
                        </div>
                    `;
                };
                previewContainer.appendChild(iframe);
            } else if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) {
                // Image preview
                const img = document.createElement('img');
                img.src = filePath;
                img.style.maxWidth = '100%';
                img.style.maxHeight = '100%';
                img.style.objectFit = 'contain';
                img.onload = function() {
                    previewContainer.appendChild(img);
                };
                img.onerror = function() {
                    previewContainer.innerHTML = `
                        <div class="document-placeholder">
                            <i class="fas fa-file-image"></i>
                            <p>Unable to load image preview.</p>
                            <p><a href="${filePath}" target="_blank" class="document-view">Open in new tab</a></p>
                        </div>
                    `;
                };
            } else {
                // Unsupported file type
                previewContainer.innerHTML = `
                    <div class="document-placeholder">
                        <i class="fas fa-file"></i>
                        <p>Preview not available for ${fileType.toUpperCase()} files.</p>
                        <p>Please download the file to view it.</p>
                        <p><a href="${filePath}" target="_blank" class="document-view">Open in new tab</a></p>
                    </div>
                `;
            }
            
            // Show modal
            document.getElementById('documentModal').classList.add('active');
        }
        
        function closeDocumentModal() {
            document.getElementById('documentModal').classList.remove('active');
        }
        
        // Helper function to format text with line breaks
        function formatText(text) {
            if (!text) return '<p>No information provided.</p>';
            return text.split('\n').map(paragraph => 
                paragraph.trim() ? `<p>${paragraph}</p>` : ''
            ).join('');
        }
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeJobDetailModal();
                closeDocumentModal();
            }
        });
        
        // Close modals when clicking outside
        document.getElementById('jobDetailModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeJobDetailModal();
            }
        });
        
        document.getElementById('documentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDocumentModal();
            }
        });
    </script>
</body>
</html>