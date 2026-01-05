<?php
session_start();

// Database connection
$host = "localhost";
$dbname = "growth_db";
$username = "root";
$password = "06162004";

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
    
    // ============================================================================
    // ENHANCED NOTIFICATION SYSTEM FOR APPLICATIONS PAGE
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
     * Enhanced applications notifications generator
     */
    function generateApplicationNotifications($conn, $graduate_id) {
        $totalNotifications = 0;
        
        // 1. Check for application status updates
        $totalNotifications += checkApplicationUpdates($conn, $graduate_id);
        
        return $totalNotifications;
    }
    
    // Generate application-specific notifications
    $notificationsGenerated = generateApplicationNotifications($conn, $graduate_id);
    
    // Initialize filter variables
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
    $date_filter = isset($_GET['date']) ? $_GET['date'] : 'all';
    $search_query = isset($_GET['search']) ? $_GET['search'] : '';
    
    // Build the base query for applications
    $query = "
        SELECT a.*, j.job_title, j.job_location, j.job_type, j.job_salary_range,
               e.emp_company_name, e.emp_industry,
               DATEDIFF(NOW(), a.app_applied_at) as days_ago
        FROM applications a
        JOIN jobs j ON a.app_job_id = j.job_id
        JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
        WHERE a.app_grad_usr_id = :graduate_id
    ";
    
    $params = [':graduate_id' => $graduate_id];
    
    // Add filters to the query
    if ($status_filter !== 'all') {
        $query .= " AND a.app_status = :status";
        $params[':status'] = $status_filter;
    }
    
    if ($date_filter !== 'all') {
        $date_condition = "";
        switch ($date_filter) {
            case 'last_7_days':
                $date_condition = " AND a.app_applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'last_30_days':
                $date_condition = " AND a.app_applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'last_90_days':
                $date_condition = " AND a.app_applied_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                break;
        }
        $query .= $date_condition;
    }
    
    if (!empty($search_query)) {
        $query .= " AND (j.job_title LIKE :search OR e.emp_company_name LIKE :search OR j.job_location LIKE :search)";
        $params[':search'] = "%$search_query%";
    }
    
    // Complete the query with ordering
    $query .= " ORDER BY a.app_applied_at DESC";
    
    // Prepare and execute the query
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get application statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN app_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN app_status = 'reviewed' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN app_status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
            SUM(CASE WHEN app_status = 'qualified' THEN 1 ELSE 0 END) as qualified,
            SUM(CASE WHEN app_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN app_status = 'hired' THEN 1 ELSE 0 END) as hired
        FROM applications
        WHERE app_grad_usr_id = :graduate_id
    ";
    
    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->bindParam(':graduate_id', $graduate_id);
    $stats_stmt->execute();
    $application_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
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
            $notifications = generateDefaultNotifications($conn, $graduate_id, $application_stats);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultNotifications($conn, $graduate_id, $application_stats);
        $unread_notif_count = count($notifications);
    }
    
    // Handle application withdrawal
    if (isset($_GET['withdraw_application'])) {
        $application_id = $_GET['withdraw_application'];
        
        // Verify the application belongs to the current user
        $verify_stmt = $conn->prepare("
            SELECT * FROM applications 
            WHERE app_id = :app_id AND app_grad_usr_id = :graduate_id
        ");
        $verify_stmt->bindParam(':app_id', $application_id);
        $verify_stmt->bindParam(':graduate_id', $graduate_id);
        $verify_stmt->execute();
        
        if ($verify_stmt->rowCount() > 0) {
            $application = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            $delete_stmt = $conn->prepare("
                DELETE FROM applications 
                WHERE app_id = :app_id AND app_grad_usr_id = :graduate_id
            ");
            $delete_stmt->bindParam(':app_id', $application_id);
            $delete_stmt->bindParam(':graduate_id', $graduate_id);
            
            if ($delete_stmt->execute()) {
                $success_message = "Application withdrawn successfully!";
                
                // Create notification for application withdrawal
                $job_title = $application['job_title'] ?? 'Unknown Job';
                createGraduateNotification($conn, $graduate_id, 'application_update', "Application for '{$job_title}' withdrawn");
                
                // Refresh applications
                $stmt->execute();
                $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                // Refresh stats
                $stats_stmt->execute();
                $application_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error_message = "Error withdrawing application.";
            }
        } else {
            $error_message = "Application not found or you don't have permission to withdraw it.";
        }
    }
    
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
}

/**
 * Generate default notifications based on user data and activities
 */
function generateDefaultNotifications($conn, $graduate_id, $application_stats) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to your Application Status page! Track all your job applications here.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Application statistics notification
    $total_apps = $application_stats['total_applications'] ?? 0;
    if ($total_apps > 0) {
        $default_notifications[] = [
            'notif_message' => "You have {$total_apps} active job applications. Keep track of their status here.",
            'notif_type' => 'application_update',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    } else {
        $default_notifications[] = [
            'notif_message' => 'Start applying to jobs! Browse available positions in the Jobs section.',
            'notif_type' => 'application_tip',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ];
    }
    
    // Application tips
    $default_notifications[] = [
        'notif_message' => 'Tip: Follow up on pending applications after 1-2 weeks to show continued interest.',
        'notif_type' => 'application_tip',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
    ];
    
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
        header("Location: graduate_applications.php");
        exit();
    } catch (PDOException $e) {
        error_log("Mark notifications as read error: " . $e->getMessage());
    }
}

// Handle mark single notification as read via AJAX
if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
    $notif_id = $_POST['mark_as_read'];
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
        case 'application_tip':
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
    
    if (strpos($type, 'application_update') !== false) {
        return 'high';
    } elseif (strpos($type, 'application_tip') !== false) {
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
    } elseif (strpos($message, 'job') !== false) {
        return 'graduate_jobs.php';
    } else {
        return 'graduate_applications.php';
    }
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending':
            return 'status-pending';
        case 'reviewed':
            return 'status-reviewed';
        case 'shortlisted':
            return 'status-shortlisted';
        case 'qualified':
            return 'status-qualified';
        case 'rejected':
            return 'status-rejected';
        case 'hired':
            return 'status-hired';
        default:
            return 'status-pending';
    }
}

// Function to get status text
function getStatusText($status) {
    switch ($status) {
        case 'pending':
            return 'Pending Review';
        case 'reviewed':
            return 'Under Review';
        case 'shortlisted':
            return 'Shortlisted';
        case 'qualified':
            return 'Qualified';
        case 'rejected':
            return 'Rejected';
        case 'hired':
            return 'Hired';
        default:
            return $status;
    }
}

// Function to get file name from path
function getFileNameFromPath($path) {
    return basename($path);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Application Status</title>
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
            margin-bottom: 25px;
            color: var(--primary-color);
            font-size: 1.9rem;
            font-weight: 700;
            position: relative;
            padding-bottom: 10px;
        }
        
        
        /* Application Stats - Single Row - MODIFIED */
        .stats-section {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 12px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: all 0.3s;
            border-top: 4px solid var(--primary-color);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 60px;
            height: 60px;
            background: rgba(110, 3, 3, 0.05);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
            display: block;
            color: var(--primary-color);
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            display: block;
            color: var(--primary-color);
        }
        
        .stat-label {
            color: #666;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        /* Enhanced Filter Section - MODIFIED */
        .filter-section {
            background-color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            border-top: 4px solid var(--secondary-color);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        
        .filter-group select:focus, .filter-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .search-group {
            flex: 2;
        }
        
        .search-box {
            display: flex;
        }
        
        .search-box input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px 0 0 8px;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .search-box button {
            padding: 10px 18px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border: none;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .search-box button:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-1px);
        }
        
        /* Results Header */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .results-count {
            font-size: 1.1rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        /* LIST STYLE Applications */
        .applications-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .application-item {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            transition: all 0.3s;
            border-top: 4px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .application-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .application-item::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: rgba(110, 3, 3, 0.05);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .application-main {
            flex: 1;
        }
        
        .application-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .application-job {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
            line-height: 1.3;
        }
        
        .application-company {
            font-size: 1rem;
            color: #666;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            min-width: 120px;
            text-align: center;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
        }
        
        .status-reviewed {
            background: linear-gradient(135deg, #cce5ff, #99ccff);
            color: #004085;
        }
        
        .status-shortlisted {
            background: linear-gradient(135deg, #e6d9ec, #d4c4e0);
            color: #4a3c55;
        }
        
        .status-qualified {
            background: linear-gradient(135deg, #ffe0b2, #ffcc80);
            color: #e65100;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, #f8d7da, #f1b0b7);
            color: #721c24;
        }
        
        .status-hired {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .application-details {
            display: flex;
            gap: 25px;
            margin-bottom: 15px;
        }
        
        .application-detail {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
        }
        
        .application-detail i {
            margin-right: 8px;
            color: var(--primary-color);
            width: 16px;
            text-align: center;
        }
        
        .application-date {
            font-size: 0.85rem;
            color: #999;
        }
        
        .application-actions {
            display: flex;
            gap: 10px;
            flex-direction: column;
            align-items: flex-end;
            min-width: 200px;
        }
        
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
        }
        
        .btn-view {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #333;
        }
        
        .btn-view:hover {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            transform: translateY(-1px);
        }
        
        .btn-withdraw {
            background: linear-gradient(135deg, #f8d7da, #f1b0b7);
            color: #721c24;
        }
        
        .btn-withdraw:hover {
            background: linear-gradient(135deg, #f1b0b7, #ea8a94);
            transform: translateY(-1px);
        }
        
        /* Enhanced Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: white;
            border-radius: 12px;
            border-top: 4px solid var(--secondary-color);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
            color: var(--primary-color);
        }
        
        .empty-state h3 {
            margin-bottom: 15px;
            color: var(--primary-color);
            font-size: 1.5rem;
        }
        
        .empty-state p {
            margin-bottom: 25px;
            color: #666;
            font-size: 1.1rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }
        
        /* Enhanced Browse Jobs Button - MODIFIED */
        .browse-jobs-btn {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(110, 3, 3, 0.2);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .browse-jobs-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.7s;
            z-index: -1;
        }
        
        .browse-jobs-btn:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-3px);
            color: white;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(110, 3, 3, 0.3);
        }
        
        .browse-jobs-btn:hover::before {
            left: 100%;
        }
        
        .browse-jobs-btn:active {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(110, 3, 3, 0.3);
        }
        
        .browse-jobs-btn i {
            font-size: 1.1rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-1px);
            color: white;
            text-decoration: none;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        .alert i {
            font-size: 1.2rem;
        }
        
        /* Enhanced Modal Overlay */
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
        
        /* Enhanced Application Detail Modal */
        .application-detail-modal {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .application-detail-modal {
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
        
        .application-detail-content {
            display: grid;
            gap: 25px;
        }
        
        .detail-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 25px;
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
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .detail-value {
            color: #555;
            font-weight: 500;
        }
        
        .files-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #eee;
            transition: all 0.3s;
        }
        
        .file-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .file-item i {
            color: var(--primary-color);
            font-size: 1.2rem;
        }
        
        .file-item a {
            color: var(--blue);
            text-decoration: none;
            word-break: break-all;
            flex: 1;
        }
        
        .file-item a:hover {
            text-decoration: underline;
        }
        
        .message-content {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #eee;
            white-space: pre-wrap;
            line-height: 1.5;
        }
        
        /* Withdrawal Confirmation Modal */
        .withdrawal-modal {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .withdrawal-modal {
            transform: translateY(0);
        }
        
        .modal-body {
            padding: 30px;
            text-align: center;
        }
        
        .modal-icon {
            font-size: 4rem;
            color: var(--red);
            margin-bottom: 20px;
        }
        
        .modal-message {
            font-size: 1.2rem;
            margin-bottom: 25px;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .btn-cancel {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #333;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-cancel:hover {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            transform: translateY(-1px);
        }
        
        .btn-confirm {
            background: linear-gradient(135deg, var(--red), #e74c3c);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-confirm:hover {
            background: linear-gradient(135deg, #e74c3c, var(--red));
            transform: translateY(-1px);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-section {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .application-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .application-actions {
                width: 100%;
                flex-direction: row;
                justify-content: flex-end;
            }
            
            .btn {
                width: auto;
            }
        }
        
        @media (max-width: 992px) {
            .stats-section {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .filter-row {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            
            .filter-group {
                margin-bottom: 0;
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
            
            .stats-section {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .application-details {
                flex-direction: column;
                gap: 10px;
            }
            
            .application-actions {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .modal-content {
                padding: 20px;
            }
            
            .empty-state {
                padding: 40px 20px;
            }
            
            .empty-state i {
                font-size: 3rem;
            }
            
            .empty-state h3 {
                font-size: 1.3rem;
            }
            
            .empty-state p {
                font-size: 1rem;
            }
            
            .browse-jobs-btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .stats-section {
                grid-template-columns: 1fr;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .application-item {
                padding: 20px;
            }
            
            .application-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .modal-content {
                padding: 15px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .empty-state {
                padding: 30px 15px;
            }
            
            .empty-state i {
                font-size: 2.5rem;
                margin-bottom: 15px;
            }
            
            .empty-state h3 {
                font-size: 1.2rem;
            }
            
            .empty-state p {
                font-size: 0.95rem;
                margin-bottom: 20px;
            }
            
            .browse-jobs-btn {
                padding: 10px 18px;
                font-size: 0.85rem;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <!-- Application Detail Modal -->
    <div class="modal-overlay" id="applicationModal">
        <div class="application-detail-modal">
            <div class="modal-header">
                <h2 class="modal-title">Application Details</h2>
                <button class="modal-close" onclick="closeApplicationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <div class="application-detail-content">
                    <div class="detail-section">
                        <h3 class="section-title"><i class="fas fa-briefcase"></i> Job Information</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Job Title</span>
                                <span class="detail-value" id="detail-job-title"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Company</span>
                                <span class="detail-value" id="detail-company-name"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Industry</span>
                                <span class="detail-value" id="detail-industry"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Location</span>
                                <span class="detail-value" id="detail-location"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h3 class="section-title"><i class="fas fa-info-circle"></i> Application Details</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <span class="detail-label">Date Applied</span>
                                <span class="detail-value" id="detail-date-applied"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="detail-value" id="detail-status"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Job Type</span>
                                <span class="detail-value" id="detail-job-type"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Salary Range</span>
                                <span class="detail-value" id="detail-salary"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h3 class="section-title"><i class="fas fa-paperclip"></i> Uploaded Files</h3>
                        <div class="files-list" id="detail-files"></div>
                    </div>
                    
                    <div class="detail-section">
                        <h3 class="section-title"><i class="fas fa-envelope"></i> Message to Employer</h3>
                        <div class="message-content" id="detail-message"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdrawal Confirmation Modal -->
    <div class="modal-overlay" id="withdrawalModal">
        <div class="withdrawal-modal">
            <div class="modal-header">
                <h2 class="modal-title">Withdraw Application</h2>
                <button class="modal-close" onclick="closeWithdrawalModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="modal-message" id="withdrawalMessage">
                    Are you sure you want to withdraw this application?
                </div>
                <div class="modal-actions">
                    <button class="btn-cancel" onclick="closeWithdrawalModal()">Cancel</button>
                    <a href="#" class="btn-confirm" id="confirmWithdrawal">Yes, Withdraw</a>
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
                    <a href="graduate_applications.php" class="active">
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
            <p>Track your job applications and manage your career progress. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new application notifications." : "" ?></p>
        </div>

        <!-- Page Content -->
        <h1 class="page-title">Application Status</h1>
        
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= $success_message ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= $error_message ?>
        </div>
        <?php endif; ?>
        
        <!-- Application Statistics - Single Row - MODIFIED -->
        <div class="stats-section">
            <div class="stat-card">
                <i class="fas fa-file-alt stat-icon"></i>
                <span class="stat-number"><?= $application_stats['total_applications'] ?? 0 ?></span>
                <span class="stat-label">Total Applications</span>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock stat-icon"></i>
                <span class="stat-number"><?= $application_stats['pending'] ?? 0 ?></span>
                <span class="stat-label">Pending Review</span>
            </div>
            <div class="stat-card">
                <i class="fas fa-search stat-icon"></i>
                <span class="stat-number"><?= $application_stats['under_review'] ?? 0 ?></span>
                <span class="stat-label">Under Review</span>
            </div>
            <div class="stat-card">
                <i class="fas fa-list-alt stat-icon"></i>
                <span class="stat-number"><?= $application_stats['shortlisted'] ?? 0 ?></span>
                <span class="stat-label">Shortlisted</span>
            </div>
            <div class="stat-card">
                <i class="fas fa-star stat-icon"></i>
                <span class="stat-number"><?= $application_stats['qualified'] ?? 0 ?></span>
                <span class="stat-label">Qualified</span>
            </div>
            <div class="stat-card">
                <i class="fas fa-times stat-icon"></i>
                <span class="stat-number"><?= $application_stats['rejected'] ?? 0 ?></span>
                <span class="stat-label">Rejected</span>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle stat-icon"></i>
                <span class="stat-number"><?= $application_stats['hired'] ?? 0 ?></span>
                <span class="stat-label">Hired</span>
            </div>
        </div>
        
        <!-- Enhanced Filter Section - MODIFIED -->
        <div class="filter-section">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group search-group">
                        <label for="search">Search</label>
                        <div class="search-box">
                            <input type="text" name="search" placeholder="Search by job title, company, or location..." value="<?= htmlspecialchars($search_query) ?>">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="filter-group">
                        <label for="status">Application Status</label>
                        <select id="status" name="status">
                            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending Review</option>
                            <option value="reviewed" <?= $status_filter == 'reviewed' ? 'selected' : '' ?>>Under Review</option>
                            <option value="shortlisted" <?= $status_filter == 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                            <option value="qualified" <?= $status_filter == 'qualified' ? 'selected' : '' ?>>Qualified</option>
                            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <option value="hired" <?= $status_filter == 'hired' ? 'selected' : '' ?>>Hired</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date">Date Applied</label>
                        <select id="date" name="date">
                            <option value="all" <?= $date_filter == 'all' ? 'selected' : '' ?>>All Time</option>
                            <option value="last_7_days" <?= $date_filter == 'last_7_days' ? 'selected' : '' ?>>Last 7 Days</option>
                            <option value="last_30_days" <?= $date_filter == 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                            <option value="last_90_days" <?= $date_filter == 'last_90_days' ? 'selected' : '' ?>>Last 90 Days</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Results Header -->
        <div class="results-header">
            <div class="results-count">
                <i class="fas fa-file-alt"></i> <?= count($applications) ?> Application<?= count($applications) != 1 ? 's' : '' ?> Found
            </div>
        </div>
        
        <!-- LIST STYLE Applications -->
        <div class="applications-list">
            <?php if (count($applications) > 0): ?>
                <?php foreach ($applications as $application): ?>
                <?php 
                    // Decode files if exist
                    $files = !empty($application['app_files']) ? json_decode($application['app_files'], true) : [];
                    $files_list = '';
                    if (!empty($files)) {
                        foreach ($files as $file) {
                            $file_name = getFileNameFromPath($file);
                            $files_list .= '<div class="file-item"><i class="fas fa-file"></i><a href="' . htmlspecialchars($file) . '" target="_blank">' . htmlspecialchars($file_name) . '</a></div>';
                        }
                    } else {
                        $files_list = '<p>No files uploaded</p>';
                    }
                    
                    $message = !empty($application['app_message']) ? htmlspecialchars($application['app_message']) : 'No message provided';
                ?>
                <div class="application-item">
                    <div class="application-main">
                        <div class="application-header">
                            <div>
                                <h3 class="application-job"><?= htmlspecialchars($application['job_title']) ?></h3>
                                <div class="application-company"><?= htmlspecialchars($application['emp_company_name']) ?></div>
                            </div>
                            <span class="status-badge <?= getStatusBadgeClass($application['app_status']) ?>">
                                <?= getStatusText($application['app_status']) ?>
                            </span>
                        </div>
                        
                        <div class="application-details">
                            <div class="application-detail">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($application['job_location']) ?></span>
                            </div>
                            <div class="application-detail">
                                <i class="fas fa-industry"></i>
                                <span><?= htmlspecialchars($application['emp_industry']) ?></span>
                            </div>
                            <div class="application-detail">
                                <i class="fas fa-clock"></i>
                                <span><?= ucfirst(str_replace('-', ' ', $application['job_type'])) ?></span>
                            </div>
                            <div class="application-detail">
                                <i class="fas fa-money-bill-wave"></i>
                                <span><?= htmlspecialchars($application['job_salary_range'] ?? 'Salary not specified') ?></span>
                            </div>
                        </div>
                        
                        <div class="application-date">
                            <i class="far fa-calendar"></i> Applied <?= formatDate($application['app_applied_at']) ?>
                        </div>
                    </div>
                    
                    <div class="application-actions">
                        <button class="btn btn-view view-application" 
                                data-app-id="<?= $application['app_id'] ?>"
                                data-job-title="<?= htmlspecialchars($application['job_title']) ?>"
                                data-company-name="<?= htmlspecialchars($application['emp_company_name']) ?>"
                                data-industry="<?= htmlspecialchars($application['emp_industry']) ?>"
                                data-location="<?= htmlspecialchars($application['job_location']) ?>"
                                data-job-type="<?= htmlspecialchars($application['job_type']) ?>"
                                data-salary="<?= htmlspecialchars($application['job_salary_range'] ?? 'Salary not specified') ?>"
                                data-date-applied="<?= date('M j, Y', strtotime($application['app_applied_at'])) ?>"
                                data-status="<?= getStatusText($application['app_status']) ?>"
                                data-status-class="<?= getStatusBadgeClass($application['app_status']) ?>"
                                data-files='<?= htmlspecialchars(json_encode($files), ENT_QUOTES) ?>'
                                data-message="<?= htmlspecialchars($application['app_message'] ?? 'No message provided', ENT_QUOTES) ?>">
                            <i class="fas fa-eye"></i> View Details
                        </button>
                        <?php if ($application['app_status'] != 'hired'): ?>
                        <button class="btn btn-withdraw withdraw-application" 
                                data-app-id="<?= $application['app_id'] ?>"
                                data-job-title="<?= htmlspecialchars($application['job_title']) ?>">
                            <i class="fas fa-times"></i> Withdraw
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <h3>No applications found</h3>
                <p>You haven't applied to any jobs yet, or no applications match your filters.</p>
                <a href="graduate_jobs.php" class="browse-jobs-btn">
                    <i class="fas fa-briefcase"></i> Browse Available Jobs
                </a>
            </div>
            <?php endif; ?>
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
                
                // Only mark as read if it's unread
                if (notificationItem.hasClass('unread')) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'graduate_applications.php',
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
            $('.stat-card, .application-item').hover(
                function() {
                    $(this).css('transform', 'translateY(-5px)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                }
            );
            
            // Enhanced hover effect for browse jobs button
            $('.browse-jobs-btn').hover(
                function() {
                    $(this).css('transform', 'translateY(-3px)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                }
            );
        });
        
        // Application Detail Modal Functions
        function openApplicationModal(applicationData) {
            // Populate modal with application data
            document.getElementById('detail-job-title').textContent = applicationData.jobTitle;
            document.getElementById('detail-company-name').textContent = applicationData.companyName;
            document.getElementById('detail-industry').textContent = applicationData.industry;
            document.getElementById('detail-location').textContent = applicationData.location;
            document.getElementById('detail-job-type').textContent = applicationData.jobType;
            document.getElementById('detail-salary').textContent = applicationData.salary;
            document.getElementById('detail-date-applied').textContent = applicationData.dateApplied;
            
            // Set status with badge
            const statusElement = document.getElementById('detail-status');
            statusElement.innerHTML = `<span class="status-badge ${applicationData.statusClass}">${applicationData.status}</span>`;
            
            // Handle files
            const filesList = document.getElementById('detail-files');
            filesList.innerHTML = '';
            let files = [];
            try {
                files = JSON.parse(applicationData.files);
            } catch (e) {
                filesList.innerHTML = '<p>No files uploaded</p>';
            }
            
            if (files.length > 0) {
                files.forEach(file => {
                    const fileName = file.split('/').pop();
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.innerHTML = `
                        <i class="fas fa-file"></i>
                        <a href="${file}" target="_blank">${fileName}</a>
                    `;
                    filesList.appendChild(fileItem);
                });
            } else {
                filesList.innerHTML = '<p>No files uploaded</p>';
            }
            
            // Set message
            document.getElementById('detail-message').textContent = applicationData.message;
            
            // Show modal
            document.getElementById('applicationModal').classList.add('active');
        }
        
        function closeApplicationModal() {
            document.getElementById('applicationModal').classList.remove('active');
        }
        
        // Withdrawal Modal Functions
        function openWithdrawalModal(appId, jobTitle) {
            document.getElementById('withdrawalMessage').textContent = 
                `Are you sure you want to withdraw your application for "${jobTitle}"?`;
            
            document.getElementById('confirmWithdrawal').href = 
                `?withdraw_application=${appId}`;
            
            document.getElementById('withdrawalModal').classList.add('active');
        }
        
        function closeWithdrawalModal() {
            document.getElementById('withdrawalModal').classList.remove('active');
        }
        
        // Event listeners for view application buttons
        document.addEventListener('DOMContentLoaded', function() {
            // View application buttons
            document.querySelectorAll('.view-application').forEach(button => {
                button.addEventListener('click', function() {
                    const applicationData = {
                        jobTitle: this.getAttribute('data-job-title'),
                        companyName: this.getAttribute('data-company-name'),
                        industry: this.getAttribute('data-industry'),
                        location: this.getAttribute('data-location'),
                        jobType: this.getAttribute('data-job-type'),
                        salary: this.getAttribute('data-salary'),
                        dateApplied: this.getAttribute('data-date-applied'),
                        status: this.getAttribute('data-status'),
                        statusClass: this.getAttribute('data-status-class'),
                        files: this.getAttribute('data-files'),
                        message: this.getAttribute('data-message')
                    };
                    openApplicationModal(applicationData);
                });
            });
            
            // Withdraw application buttons
            document.querySelectorAll('.withdraw-application').forEach(button => {
                button.addEventListener('click', function() {
                    const appId = this.getAttribute('data-app-id');
                    const jobTitle = this.getAttribute('data-job-title');
                    openWithdrawalModal(appId, jobTitle);
                });
            });
            
            // Close modal when clicking outside
            document.getElementById('applicationModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeApplicationModal();
                }
            });
            
            document.getElementById('withdrawalModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeWithdrawalModal();
                }
            });
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeApplicationModal();
                    closeWithdrawalModal();
                }
            });
        });
    </script>
</body>
</html>