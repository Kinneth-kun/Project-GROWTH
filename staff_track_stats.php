<?php
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

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get staff data
    $staff_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT u.*
        FROM users u 
        WHERE u.usr_id = :staff_id
    ");
    $stmt->bindParam(':staff_id', $staff_id);
    $stmt->execute();
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$staff) {
        // If no staff record found, redirect to login
        header("Location: index.php");
        exit();
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
    $notificationsGenerated = generateStaffNotifications($conn, $staff_id);
    
    // Check if auto-approval is enabled (you might want to store this in a settings table)
    $auto_approve_enabled = false; // Default to false, you can change this based on your system settings
    
    // Get filter parameters
    $course_filter = $_GET['course'] ?? 'all';
    $year_filter = $_GET['year'] ?? 'all';
    $search_query = $_GET['search'] ?? '';
    
    // Build query to fetch graduates data for tracking - ONLY APPROVED GRADUATES
    $query = "
        SELECT 
            u.usr_id,
            u.usr_name,
            u.usr_email,
            u.usr_phone,
            u.usr_profile_photo,
            g.grad_degree,
            g.grad_year_graduated,
            g.grad_job_preference,
            g.grad_summary,
            COALESCE(MAX(j.job_title), 'No applications yet') as last_job_applied,
            COALESCE(MAX(a.app_status), 'Not applied') as application_status,
            CASE 
                WHEN MAX(a.app_status) = 'hired' THEN 'Employed'
                WHEN MAX(a.app_status) IN ('pending', 'reviewed', 'shortlisted') THEN 'Job Seeking'
                WHEN COUNT(a.app_id) = 0 THEN 'Not Applied'
                WHEN MAX(a.app_status) = 'rejected' THEN 'Unemployed'
                ELSE 'Not Seeking'
            END as employment_status
        FROM users u
        JOIN graduates g ON u.usr_id = g.grad_usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
        LEFT JOIN jobs j ON a.app_job_id = j.job_id
        WHERE u.usr_role = 'graduate'
    ";
    
    // Add approval condition - only show approved graduates unless auto-approval is enabled
    if (!$auto_approve_enabled) {
        $query .= " AND u.usr_is_approved = TRUE";
    }
    
    $params = [];
    
    // Apply course filter
    if ($course_filter !== 'all') {
        $query .= " AND g.grad_degree LIKE :course";
        $params[':course'] = "%$course_filter%";
    }
    
    // Apply year filter
    if ($year_filter !== 'all') {
        $query .= " AND g.grad_year_graduated = :year";
        $params[':year'] = $year_filter;
    }
    
    // Apply search filter
    if (!empty($search_query)) {
        $query .= " AND (u.usr_name LIKE :search OR u.usr_email LIKE :search OR g.grad_degree LIKE :search)";
        $params[':search'] = "%$search_query%";
    }
    
    // Complete query
    $query .= " GROUP BY u.usr_id ORDER BY u.usr_name";
    
    // Prepare and execute query
    $grads_stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $grads_stmt->bindValue($key, $value);
    }
    $grads_stmt->execute();
    $graduates = $grads_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get detailed graduate data for modal if requested - WITH APPROVAL CHECK
    $grad_details = null;
    $grad_skills = [];
    $grad_applications = [];
    
    if (isset($_GET['view_graduate'])) {
        $grad_id = $_GET['view_graduate'];
        
        // Get graduate details - WITH APPROVAL CHECK
        $stmt = $conn->prepare("
            SELECT 
                u.*,
                g.*
            FROM users u
            JOIN graduates g ON u.usr_id = g.grad_usr_id
            WHERE u.usr_id = :grad_id
            " . (!$auto_approve_enabled ? " AND u.usr_is_approved = TRUE" : "") . "
        ");
        $stmt->bindParam(':grad_id', $grad_id);
        $stmt->execute();
        $grad_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($grad_details) {
            // Get skills
            $skills_stmt = $conn->prepare("
                SELECT s.skill_name, s.skill_category, gs.skill_level 
                FROM graduate_skills gs
                JOIN skills s ON gs.skill_id = s.skill_id
                WHERE gs.grad_usr_id = :user_id
                ORDER BY gs.skill_level DESC, s.skill_name
            ");
            $skills_stmt->bindParam(':user_id', $grad_id);
            $skills_stmt->execute();
            $grad_skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get recent applications - FIXED: Using correct column names from your database
            $apps_stmt = $conn->prepare("
                SELECT 
                    a.*,
                    j.job_title,
                    j.job_location,
                    j.job_type,
                    emp.emp_company_name
                FROM applications a
                JOIN jobs j ON a.app_job_id = j.job_id
                JOIN employers emp ON j.job_emp_usr_id = emp.emp_usr_id
                WHERE a.app_grad_usr_id = :user_id
                ORDER BY a.app_applied_at DESC
                LIMIT 5
            ");
            $apps_stmt->bindParam(':user_id', $grad_id);
            $apps_stmt->execute();
            $grad_applications = $apps_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If graduate not found or not approved, redirect back to the list
            header("Location: staff_track_stats.php?course=" . urlencode($course_filter) . "&year=" . urlencode($year_filter) . "&search=" . urlencode($search_query));
            exit();
        }
    }
    
    // Get comprehensive notifications for staff
    $notifications = [];
    $unread_notif_count = 0;
    try {
        $notif_stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE notif_usr_id = :staff_id 
            ORDER BY notif_created_at DESC 
            LIMIT 15
        ");
        $notif_stmt->bindParam(':staff_id', $staff_id);
        $notif_stmt->execute();
        $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notifications as $notif) {
            if (!$notif['notif_is_read']) $unread_notif_count++;
        }
        
        // If no notifications found, create some default ones based on system status
        if (empty($notifications)) {
            $notifications = generateDefaultStaffNotifications($conn, $staff_id);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Staff notifications query error: " . $e->getMessage());
        // Generate default notifications
        $notifications = generateDefaultStaffNotifications($conn, $staff_id);
        $unread_notif_count = count($notifications);
    }
    
    // Handle mark as read for notifications
    if (isset($_POST['mark_all_read']) && $_POST['mark_all_read'] == '1') {
        try {
            $update_stmt = $conn->prepare("
                UPDATE notifications 
                SET notif_is_read = 1 
                WHERE notif_usr_id = :staff_id AND notif_is_read = 0
            ");
            $update_stmt->bindParam(':staff_id', $staff_id);
            $update_stmt->execute();
            
            // Refresh page to show updated notifications
            header("Location: staff_track_stats.php");
            exit();
        } catch (PDOException $e) {
            error_log("Mark notifications as read error: " . $e->getMessage());
        }
    }
    
    // Handle mark single notification as read via AJAX
    if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
        $notif_id = $_POST['notif_id'];
        try {
            $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :staff_id");
            $mark_read_stmt->bindParam(':notif_id', $notif_id);
            $mark_read_stmt->bindParam(':staff_id', $staff_id);
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
    
    // Define course options (same as in admin_portfolio.php)
    $course_options = [
        "BEEd",
        "BECEd",
        "BSNEd",
        "BSEd",
        "BSEd-Math",
        "BSEd-Sci",
        "BSEd-Eng",
        "BSEd-Fil",
        "BSEd-VE",
        "BTLEd",
        "BTLEd-IA",
        "BTLEd-HE",
        "BTLEd-ICT",
        "BTVTEd",
        "BTVTEd-AD",
        "BTVTEd-AT",
        "BTVTEd-FSMT",
        "BTVTEd-ET",
        "BTVTEd-ELXT",
        "BTVTEd-GFDT",
        "BTVTEd-WFT",
        "BSCE",
        "BSCpE",
        "BSECE",
        "BSEE",
        "BSIE",
        "BSME",
        "BSMx",
        "BSGD",
        "BSTechM",
        "BIT",
        "BIT-AT",
        "BIT-CvT",
        "BIT-CosT",
        "BIT-DT",
        "BIT-ET",
        "BIT-ELXT",
        "BIT-FPST",
        "BIT-FCM",
        "BIT-GT",
        "BIT-IDT",
        "BIT-MST",
        "BIT-PPT",
        "BIT-RAC",
        "BIT-WFT",
        "BPA",
        "BSHM",
        "BSBA-MM",
        "BSTM",
        "BSIT",
        "BSIS",
        "BIT-CT",
        "BAEL",
        "BAL",
        "BAF",
        "BS Math",
        "BS Stat",
        "BS DevCom",
        "BSPsy",
        "BSN"
    ];
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

/**
 * Generate default notifications for staff based on system status
 */
function generateDefaultStaffNotifications($conn, $staff_id) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Staff Dashboard! Monitor system activities and assist graduates.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // System status notification
    $default_notifications[] = [
        'notif_message' => 'System is running smoothly. Check the analytics section for detailed reports.',
        'notif_type' => 'system_status',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-4 hours'))
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
    } else {
        return 'staff_dashboard.php';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Track Graduate Status</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    
    /* Enhanced Filter Container */
    .filters {
      display: flex;
      background: white;
      padding: 25px;
      border-radius: 12px;
      margin-bottom: 25px;
      align-items: center;
      flex-wrap: wrap;
      gap: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--secondary-color);
    }
    
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    
    .filter-label {
      font-weight: 600;
      font-size: 14px;
      color: var(--primary-color);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    
    .filter-label i {
      color: var(--secondary-color);
    }
    
    .filter-select {
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #ddd;
      min-width: 180px;
      background: white;
      transition: all 0.3s;
      font-size: 14px;
    }
    
    .filter-select:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
    }
    
    .search-filter-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    
    .search-filter-container {
      display: flex;
      position: relative;
      width: 320px;
    }
    
    .search-filter-container input {
      padding: 10px 15px 10px 40px;
      border: 1px solid #ddd;
      border-radius: 8px;
      outline: none;
      width: 100%;
      font-size: 14px;
      transition: all 0.3s;
    }
    
    .search-filter-container input:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
    }
    
    .search-filter-container i {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      color: #777;
    }
    
    /* Enhanced Application Table */
    .table-container {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 30px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--accent-color);
    }
    
    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 2px solid var(--primary-color);
    }
    
    .table-title {
      color: var(--primary-color);
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .table-title i {
      color: var(--accent-color);
    }
    
    .application-table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .application-table thead {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
    }
    
    .application-table th {
      padding: 18px 20px;
      text-align: left;
      font-weight: 600;
      font-size: 15px;
    }
    
    .application-table td {
      padding: 16px 20px;
      border-bottom: 1px solid #eee;
      transition: all 0.3s;
    }
    
    .application-table tbody tr:hover {
      background-color: #f9f9f9;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    .application-table tr:last-child td {
      border-bottom: none;
    }
    
    .view-btn {
      display: inline-block;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      padding: 8px 16px;
      border-radius: 6px;
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: all 0.3s;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    
    .view-btn:hover {
      background: linear-gradient(135deg, #8a0404, #6e0303);
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    /* Enhanced Graduate Profile Modal Styles */
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
    }
    
    .modal-overlay.active {
      opacity: 1;
      visibility: visible;
    }
    
    .graduate-modal {
      background-color: white;
      border-radius: 12px;
      width: 95%;
      max-width: 1100px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
      transform: translateY(-50px);
      transition: transform 0.3s ease;
    }
    
    .modal-overlay.active .graduate-modal {
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
    
    .graduate-content {
      display: grid;
      grid-template-columns: 1fr 1.5fr;
      gap: 30px;
    }
    
    /* Profile Section - Enhanced */
    .profile-section {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-left: 5px solid var(--primary-color);
    }
    
    .profile-header-info {
      text-align: center;
      margin-bottom: 25px;
      padding-bottom: 25px;
      border-bottom: 2px solid rgba(110, 3, 3, 0.1);
      position: relative;
    }
    
    .profile-img-container {
      position: relative;
      display: inline-block;
      margin-bottom: 20px;
    }
    
    .profile-img {
      width: 140px;
      height: 140px;
      border-radius: 50%;
      object-fit: cover;
      border: 4px solid var(--primary-color);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }
    
    .profile-status-badge {
      position: absolute;
      bottom: 10px;
      right: 10px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      border: 3px solid white;
    }
    
    .status-employed-badge { background-color: #1f7a11; }
    .status-seeking-badge { background-color: #f7a100; }
    .status-unemployed-badge { background-color: #d32f2f; }
    .status-notapplied-badge { background-color: #6c757d; }
    
    .profile-name {
      font-size: 26px;
      margin-bottom: 8px;
      color: var(--primary-color);
      font-weight: 600;
    }
    
    .profile-course {
      color: #6c757d;
      margin-bottom: 15px;
      font-size: 18px;
      font-weight: 500;
    }
    
    .profile-status {
      display: inline-block;
      padding: 8px 20px;
      border-radius: 25px;
      font-size: 14px;
      font-weight: 600;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .status-employed {
      background: linear-gradient(135deg, #1f7a11, #2ecc71);
      color: white;
    }
    
    .status-seeking {
      background: linear-gradient(135deg, #f7a100, #ffa700);
      color: white;
    }
    
    .status-unemployed {
      background: linear-gradient(135deg, #d32f2f, #e74c3c);
      color: white;
    }
    
    .status-notapplied {
      background: linear-gradient(135deg, #6c757d, #95a5a6);
      color: white;
    }
    
    .profile-details {
      margin-top: 25px;
    }
    
    .detail-item {
      display: flex;
      justify-content: space-between;
      padding: 15px 0;
      border-bottom: 1px solid rgba(0, 0, 0, 0.08);
      transition: all 0.3s;
    }
    
    .detail-item:hover {
      background-color: rgba(255, 255, 255, 0.5);
      padding-left: 10px;
      padding-right: 10px;
      border-radius: 8px;
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
    }
    
    .detail-value {
      color: #555;
      font-weight: 500;
    }
    
    /* Skills Section - Enhanced */
    .skills-section {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--secondary-color);
    }
    
    .section-title {
      font-size: 20px;
      color: var(--primary-color);
      margin-bottom: 20px;
      padding-bottom: 12px;
      border-bottom: 2px solid var(--primary-color);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .section-title i {
      color: var(--secondary-color);
    }
    
    .skills-container {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: 15px;
    }
    
    .skill-card {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      border-radius: 10px;
      padding: 15px;
      text-align: center;
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
      transition: all 0.3s;
      border-left: 4px solid var(--secondary-color);
    }
    
    .skill-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
    }
    
    .skill-name {
      font-weight: 600;
      color: var(--primary-color);
      margin-bottom: 8px;
    }
    
    .skill-level {
      font-size: 12px;
      color: white;
      padding: 4px 10px;
      border-radius: 15px;
      font-weight: 500;
    }
    
    .level-beginner { background-color: #e74c3c; }
    .level-intermediate { background-color: #f39c12; }
    .level-advanced { background-color: #3498db; }
    .level-expert { background-color: #2ecc71; }
    
    /* Applications Section - Enhanced */
    .applications-section {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--blue);
    }
    
    .application-item {
      padding: 18px;
      border-radius: 10px;
      margin-bottom: 15px;
      background: #f8f9fa;
      border-left: 4px solid var(--blue);
      transition: all 0.3s;
    }
    
    .application-item:hover {
      transform: translateX(5px);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .application-job {
      font-weight: 600;
      color: var(--primary-color);
      font-size: 16px;
      margin-bottom: 5px;
    }
    
    .application-company {
      font-size: 14px;
      color: #6c757d;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    
    .application-details {
      font-size: 13px;
      color: #888;
      margin: 5px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .application-status {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      margin-top: 8px;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .status-pending { background: linear-gradient(135deg, #ffc107, #ff9800); color: white; }
    .status-reviewed { background: linear-gradient(135deg, #17a2b8, #20c997); color: white; }
    .status-shortlisted { background: linear-gradient(135deg, #6f42c1, #8e44ad); color: white; }
    .status-qualified { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
    .status-rejected { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; }
    .status-hired { background: linear-gradient(135deg, #1f7a11, #2ecc71); color: white; }
    
    .application-date {
      font-size: 12px;
      color: #999;
      margin-top: 8px;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    
    /* Summary Section - Enhanced */
    .summary-section {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--purple);
    }
    
    .summary-content {
      line-height: 1.7;
      color: #555;
      font-size: 15px;
    }
    
    /* Status badges */
    .status-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .status-employed {
      background: linear-gradient(135deg, #1f7a11, #2ecc71);
      color: white;
    }
    
    .status-seeking {
      background: linear-gradient(135deg, #f7a100, #ffa700);
      color: white;
    }
    
    .status-unemployed {
      background: linear-gradient(135deg, #d32f2f, #e74c3c);
      color: white;
    }
    
    .status-notapplied {
      background: linear-gradient(135deg, #6c757d, #95a5a6);
      color: white;
    }
    
    .no-data {
      text-align: center;
      color: #999;
      padding: 30px;
      font-style: italic;
      background: #f8f9fa;
      border-radius: 8px;
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .graduate-content {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 900px) {
      .application-table {
        display: block;
        overflow-x: auto;
      }
      
      .skills-container {
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
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
      
      .dropdown {
        width: 90%;
        right: 5%;
      }
      
      .filters {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
      
      .search-filter-container {
        width: 100%;
      }
      
      .graduate-content {
        grid-template-columns: 1fr;
      }
      
      .modal-header {
        padding: 20px;
      }
      
      .modal-title {
        font-size: 1.4rem;
      }
      
      .modal-content {
        padding: 20px;
      }
      
      .skills-container {
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      }
      
      .table-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
      }
    }
  </style>
</head>
<body>
  <!-- Modal Overlay -->
  <div class="modal-overlay <?= $grad_details ? 'active' : '' ?>" id="modalOverlay"></div>
  
  <!-- Enhanced Graduate Modal -->
  <?php if ($grad_details): ?>
  <div class="modal-overlay active" id="graduateModal">
    <div class="graduate-modal">
      <div class="modal-header">
        <h2 class="modal-title">Alumni Profile</h2>
        <button class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <div class="modal-content">
        <div class="graduate-content">
          <div class="profile-section">
            <div class="profile-header-info">
              <div class="profile-img-container">
                <?php
                  $profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($grad_details['usr_name']) . "&background=3498db&color=fff";
                  if (!empty($grad_details['usr_profile_photo']) && file_exists($grad_details['usr_profile_photo'])) {
                    $profile_photo = $grad_details['usr_profile_photo'];
                  }
                  
                  $status_class = '';
                  $employment_status = '';
                  $status_badge_class = '';
                  foreach ($graduates as $g) {
                    if ($g['usr_id'] == $grad_details['usr_id']) {
                      $employment_status = $g['employment_status'];
                      break;
                    }
                  }
                  if ($employment_status === 'Employed') {
                    $status_class = 'status-employed';
                    $status_badge_class = 'status-employed-badge';
                  } elseif ($employment_status === 'Job Seeking') {
                    $status_class = 'status-seeking';
                    $status_badge_class = 'status-seeking-badge';
                  } elseif ($employment_status === 'Unemployed') {
                    $status_class = 'status-unemployed';
                    $status_badge_class = 'status-unemployed-badge';
                  } else {
                    $status_class = 'status-notapplied';
                    $status_badge_class = 'status-notapplied-badge';
                  }
                ?>
                <img src="<?= $profile_photo ?>" alt="<?= htmlspecialchars($grad_details['usr_name']) ?>" class="profile-img">
                <div class="profile-status-badge <?= $status_badge_class ?>"></div>
              </div>
              <h2 class="profile-name"><?= htmlspecialchars($grad_details['usr_name']) ?></h2>
              <p class="profile-course"><?= htmlspecialchars($grad_details['grad_degree']) ?></p>
              <span class="profile-status <?= $status_class ?>"><?= $employment_status ?></span>
            </div>
            
            <div class="profile-details">
              <div class="detail-item">
                <span class="detail-label"><i class="fas fa-envelope"></i> Email:</span>
                <span class="detail-value"><?= htmlspecialchars($grad_details['usr_email']) ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label"><i class="fas fa-phone"></i> Phone:</span>
                <span class="detail-value"><?= !empty($grad_details['usr_phone']) ? htmlspecialchars($grad_details['usr_phone']) : 'Not provided' ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label"><i class="fas fa-graduation-cap"></i> Year Graduated:</span>
                <span class="detail-value"><?= htmlspecialchars($grad_details['grad_year_graduated']) ?></span>
              </div>
              <div class="detail-item">
                <span class="detail-label"><i class="fas fa-briefcase"></i> Job Preference:</span>
                <span class="detail-value"><?= htmlspecialchars($grad_details['grad_job_preference']) ?></span>
              </div>
            </div>
            
            <?php if (!empty($grad_details['grad_summary'])): ?>
            <div class="summary-section">
              <h3 class="section-title"><i class="fas fa-file-alt"></i> Career Summary</h3>
              <div class="summary-content">
                <?= nl2br(htmlspecialchars($grad_details['grad_summary'])) ?>
              </div>
            </div>
            <?php endif; ?>
          </div>
          
          <div>
            <div class="skills-section">
              <h3 class="section-title"><i class="fas fa-tools"></i> Skills (<?= count($grad_skills) ?>)</h3>
              <div class="skills-container">
                <?php if (!empty($grad_skills)): ?>
                  <?php foreach ($grad_skills as $skill): ?>
                    <div class="skill-card">
                      <div class="skill-name"><?= htmlspecialchars($skill['skill_name']) ?></div>
                      <div class="skill-level level-<?= strtolower($skill['skill_level']) ?>">
                        <?= ucfirst($skill['skill_level']) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="no-data">No skills added yet</div>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="applications-section">
              <h3 class="section-title"><i class="fas fa-file-contract"></i> Recent Applications (<?= count($grad_applications) ?>)</h3>
              <?php if (!empty($grad_applications)): ?>
                <?php foreach ($grad_applications as $application): ?>
                  <div class="application-item">
                    <div class="application-job"><?= htmlspecialchars($application['job_title']) ?></div>
                    <div class="application-company">
                      <i class="fas fa-building"></i> <?= htmlspecialchars($application['emp_company_name']) ?>
                    </div>
                    <div class="application-details">
                      <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($application['job_location']) ?>
                    </div>
                    <div class="application-details">
                      <i class="fas fa-clock"></i> <?= ucfirst(str_replace('-', ' ', $application['job_type'])) ?>
                    </div>
                    <span class="application-status status-<?= strtolower($application['app_status']) ?>">
                      <?= ucfirst($application['app_status']) ?>
                    </span>
                    <div class="application-date">
                      <i class="fas fa-calendar"></i> Applied: <?= date('M j, Y', strtotime($application['app_applied_at'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="no-data">No job applications yet</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Sidebar -->
  <div class="sidebar">
    <div class="sidebar-header">
      <img src="<?= !empty($staff['usr_profile_photo']) ? htmlspecialchars($staff['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($staff['usr_name']) . '&background=random' ?>" alt="Staff Avatar">
      <div class="sidebar-header-text">
        <h3><?= htmlspecialchars($staff['usr_name']) ?></h3>
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
          <a href="staff_track_stats.php" class="active">
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
          <img src="<?= !empty($staff['usr_profile_photo']) ? htmlspecialchars($staff['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($staff['usr_name']) . '&background=random' ?>" alt="profile">
          <span class="staff-name"><?= htmlspecialchars($staff['usr_name']) ?></span>
          <i class="fas fa-chevron-down"></i>
          <div class="dropdown profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">Account Menu</div>
            <a href="staff_profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
            <a href="staff_changepass.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
            <div class="dropdown-item" style="border-top: 1px solid #eee; margin-top: 5px; padding-top: 15px;">
              <i class="fas fa-user-circle"></i> Logged in as: <strong><?= htmlspecialchars($staff['usr_name']) ?></strong>
            </div>
            <a href="index.php" class="dropdown-item" style="color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Enhanced Welcome Message -->
    <div class="welcome-message">
      <h2>Welcome back, <?= htmlspecialchars($staff['usr_name']) ?>!</h2>
      <p>Track and monitor alumni employment status and job application activities. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>
    
    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Track Alumni Status</h1>
    </div>

    <!-- Enhanced Filter Container -->
    <div class="filters">
      <!-- Search Filter -->
      <div class="search-filter-group">
        <label class="filter-label"><i class="fas fa-search"></i> Search</label>
        <div class="search-filter-container">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search alumni..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
      </div>
      
      <!-- Course Filter -->
      <div class="filter-group">
        <label class="filter-label"><i class="fas fa-graduation-cap"></i> Course</label>
        <select class="filter-select" id="courseFilter" onchange="updateFilters()">
          <option value="all" <?= $course_filter === 'all' ? 'selected' : '' ?>>All Courses</option>
          <?php foreach ($course_options as $course): ?>
            <option value="<?= $course ?>" <?= $course_filter === $course ? 'selected' : '' ?>><?= $course ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <!-- Year Filter -->
      <div class="filter-group">
        <label class="filter-label"><i class="fas fa-calendar-alt"></i> Year</label>
        <select class="filter-select" id="yearFilter" onchange="updateFilters()">
          <option value="all" <?= $year_filter === 'all' ? 'selected' : '' ?>>All Years</option>
          <?php for ($year = 2022; $year <= 2050; $year++): ?>
            <option value="<?= $year ?>" <?= $year_filter == $year ? 'selected' : '' ?>><?= $year ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>

    <!-- Application Activity Table -->
    <div class="table-container">
      <div class="table-header">
        <h2 class="table-title"><i class="fas fa-table"></i> Alumni Tracking Overview</h2>
        <div class="table-info">
          <span style="color: #666; font-size: 14px;">Total Alumni: <?= count($graduates) ?></span>
        </div>
      </div>
      <table class="application-table" id="graduatesTable">
        <thead>
          <tr>
            <th>Name</th>
            <th>Course</th>
            <th>Last Job Applied</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($graduates)): ?>
          <?php foreach ($graduates as $g): 
            // Determine status class
            $status_class = '';
            if ($g['employment_status'] === 'Employed') {
              $status_class = 'status-employed';
            } elseif ($g['employment_status'] === 'Job Seeking') {
              $status_class = 'status-seeking';
            } elseif ($g['employment_status'] === 'Unemployed') {
              $status_class = 'status-unemployed';
            } else {
              $status_class = 'status-notapplied';
            }
          ?>
            <tr>
              <td><?= htmlspecialchars($g['usr_name']) ?></td>
              <td><?= htmlspecialchars($g['grad_degree']) ?></td>
              <td><?= htmlspecialchars($g['last_job_applied']) ?></td>
              <td><span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($g['employment_status']) ?></span></td>
              <td>
                <a href="?view_graduate=<?= $g['usr_id'] ?>&course=<?= $course_filter ?>&year=<?= $year_filter ?>&search=<?= urlencode($search_query) ?>" class="view-btn">
                  <i class="fas fa-eye"></i> View Profile
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" style="text-align: center; padding: 20px;">
              No approved alumni found matching your criteria
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    // ===== DROPDOWN FUNCTIONALITY =====
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
    
    // ===== ENHANCED NOTIFICATION FUNCTIONALITY =====
    $(document).ready(function() {
      // Handle notification click and mark as read
      $('.notification-link').on('click', function(e) {
        const notifId = $(this).data('notif-id');
        const notificationItem = $(this);
        
        // Only mark as read if it's unread and has a valid ID
        if (notificationItem.hasClass('unread') && notifId) {
          // Send AJAX request to mark as read
          $.ajax({
            url: 'staff_track_stats.php',
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
    });
    
    // Search functionality with debounce
    const searchInput = document.getElementById('searchInput');
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(function() {
        updateFilters();
      }, 500);
    });
    
    // Submit form when pressing Enter
    searchInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        updateFilters();
      }
    });
    
    // Update filters function
    function updateFilters() {
      const courseFilter = document.getElementById('courseFilter').value;
      const yearFilter = document.getElementById('yearFilter').value;
      const searchQuery = searchInput.value;
      
      // Build URL with parameters
      const url = new URL(window.location.href);
      url.searchParams.set('course', courseFilter);
      url.searchParams.set('year', yearFilter);
      url.searchParams.set('search', searchQuery);
      
      // Remove view_graduate parameter if present
      url.searchParams.delete('view_graduate');
      
      // Navigate to the new URL
      window.location.href = url.toString();
    }
    
    function closeModal() {
      // Remove the view_graduate parameter from the URL
      const url = new URL(window.location.href);
      url.searchParams.delete('view_graduate');
      window.location.href = url.toString();
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
      const modalOverlay = document.getElementById('modalOverlay');
      if (modalOverlay && e.target === modalOverlay) {
        closeModal();
      }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    });
  </script>
</body>
</html>