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
    
    // Handle job approval/rejection
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['job_action'])) {
        $job_id = $_POST['job_id'];
        $action = $_POST['action'];
        $comments = $_POST['comments'] ?? '';
        
        try {
            $status_map = [
                'approve' => 'active',
                'reject' => 'rejected',
                'edit' => 'pending' // Keep as pending if edits are requested
            ];
            
            if (isset($status_map[$action])) {
                // Update job status and record who approved/rejected it
                $stmt = $conn->prepare("
                    UPDATE jobs 
                    SET job_status = :status, 
                        job_reviewed_by = :reviewed_by,
                        job_reviewed_at = NOW()
                    WHERE job_id = :job_id
                ");
                $stmt->bindParam(':status', $status_map[$action]);
                $stmt->bindParam(':reviewed_by', $staff_id);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->execute();
                
                // Get employer user ID for notification
                $emp_stmt = $conn->prepare("SELECT job_emp_usr_id FROM jobs WHERE job_id = :job_id");
                $emp_stmt->bindParam(':job_id', $job_id);
                $emp_stmt->execute();
                $employer = $emp_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($employer) {
                    $message = "Your job posting has been " . $status_map[$action];
                    if (!empty($comments)) {
                        $message .= ". Comments: " . $comments;
                    }
                    
                    // Add notification for employer
                    $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, :message, 'job_approval')");
                    $notif_stmt->bindParam(':user_id', $employer['job_emp_usr_id']);
                    $notif_stmt->bindParam(':message', $message);
                    $notif_stmt->execute();
                    
                    // Add notification for admin
                    $admin_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_role = 'admin' LIMIT 1");
                    $admin_stmt->execute();
                    $admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($admin) {
                        $admin_message = "Staff " . $staff['usr_name'] . " has " . $status_map[$action] . " a job posting";
                        $admin_notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, :message, 'job_review')");
                        $admin_notif_stmt->bindParam(':user_id', $admin['usr_id']);
                        $admin_notif_stmt->bindParam(':message', $admin_message);
                        $admin_notif_stmt->execute();
                    }
                }
                
                // Refresh page to show updated status
                header("Location: staff_validate_jobs.php?success=1");
                exit();
            }
        } catch (PDOException $e) {
            $error = "Error updating job status: " . $e->getMessage();
        }
    }
    
    // Get ALL job postings with reviewer information
    $jobs_stmt = $conn->prepare("
        SELECT j.*, 
               u.usr_name as employer_name, 
               e.emp_company_name,
               reviewer.usr_name as reviewer_name,
               j.job_reviewed_at
        FROM jobs j
        JOIN users u ON j.job_emp_usr_id = u.usr_id
        JOIN employers e ON u.usr_id = e.emp_usr_id
        LEFT JOIN users reviewer ON j.job_reviewed_by = reviewer.usr_id
        ORDER BY 
            CASE 
                WHEN j.job_status = 'pending' THEN 1
                WHEN j.job_status = 'active' THEN 2
                WHEN j.job_status = 'rejected' THEN 3
                WHEN j.job_status = 'closed' THEN 4
                ELSE 5
            END,
            j.job_created_at DESC
    ");
    $jobs_stmt->execute();
    $all_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count jobs by status for statistics
    $status_counts = [
        'pending' => 0,
        'active' => 0,
        'rejected' => 0,
        'closed' => 0,
        'inactive' => 0
    ];
    
    foreach ($all_jobs as $job) {
        if (isset($status_counts[$job['job_status']])) {
            $status_counts[$job['job_status']]++;
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
            header("Location: staff_validate_jobs.php");
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
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Handle AJAX request for job details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_job_details' && isset($_GET['id'])) {
    $job_id = $_GET['id'];
    
    try {
        $stmt = $conn->prepare("
            SELECT j.*, 
                   e.emp_company_name as company_name,
                   reviewer.usr_name as reviewer_name,
                   j.job_reviewed_at
            FROM jobs j 
            JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id 
            LEFT JOIN users reviewer ON j.job_reviewed_by = reviewer.usr_id
            WHERE j.job_id = :job_id
        ");
        $stmt->bindParam(':job_id', $job_id);
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($job);
        exit();
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to fetch job details']);
        exit();
    }
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

/**
 * Convert dollar amounts to Philippine Peso format
 */
function convertToPeso($amount) {
    // If it's a range like "15000-18000"
    if (strpos($amount, '-') !== false) {
        $parts = explode('-', $amount);
        $converted = '₱' . number_format(intval(trim($parts[0])), 0, '.', ',') . 
                     ' - ₱' . number_format(intval(trim($parts[1])), 0, '.', ',');
        return $converted;
    }
    
    // If it's a single amount
    if (is_numeric($amount)) {
        return '₱' . number_format($amount, 0, '.', ',');
    }
    
    // If it's already in peso format or unknown format, return as is
    return $amount;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Validate Job Postings</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    
    /* MODIFIED: Enhanced Status Summary Cards - Now in one row */
    .status-summary {
      display: flex;
      justify-content: space-between;
      gap: 15px;
      margin-bottom: 30px;
    }
    
    .status-item {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
      padding: 25px 20px;
      transition: all 0.3s;
      border-top: 4px solid var(--secondary-color);
      position: relative;
      overflow: hidden;
      text-align: center;
      flex: 1;
      min-width: 0; /* Allows flex items to shrink properly */
    }
    
    .status-item:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .status-count {
      font-size: 2.2rem;
      font-weight: 700;
      color: var(--primary-color);
      margin-bottom: 10px;
      line-height: 1;
    }
    
    .status-label {
      font-size: 0.95rem;
      color: #666;
      font-weight: 500;
    }
    
    /* Enhanced Table Container */
    .table-container {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
      padding: 25px;
      margin-bottom: 30px;
      border-top: 4px solid var(--secondary-color);
      position: relative;
      overflow: hidden;
    }
    
    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }
    
    .table-title {
      font-size: 1.3rem;
      color: var(--primary-color);
      font-weight: 600;
    }
    
    .table-controls {
      display: flex;
      gap: 10px;
    }
    
    .filter-select {
      padding: 8px 12px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 0.9rem;
      background-color: white;
    }
    
    /* Enhanced Table Styles */
    .jobs-table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .jobs-table thead {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
    }
    
    .jobs-table th {
      padding: 15px;
      text-align: left;
      font-weight: 500;
      font-size: 0.9rem;
    }
    
    .jobs-table tbody tr {
      transition: all 0.3s;
    }
    
    .jobs-table tbody tr:hover {
      background-color: #f8f9fa;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
    }
    
    .jobs-table td {
      padding: 12px 15px;
      border-bottom: 1px solid #eee;
      font-size: 0.9rem;
    }
    
    .jobs-table tr:last-child td {
      border-bottom: none;
    }
    
    .status-badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 500;
    }
    
    .status-pending {
      background-color: #fff8e1;
      color: #f57f17;
    }
    
    .status-active {
      background-color: #e8f5e9;
      color: #2e7d32;
    }
    
    .status-rejected {
      background-color: #ffebee;
      color: #c62828;
    }
    
    .status-closed {
      background-color: #f5f5f5;
      color: #616161;
    }
    
    .status-inactive {
      background-color: #f5f5f5;
      color: #9e9e9e;
    }
    
    .tag {
      display: inline-block;
      background: #f0f0f0;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 0.75rem;
      margin: 2px;
      color: #555;
    }
    
    .reviewer-info {
      font-size: 0.75rem;
      color: #666;
      margin-top: 3px;
    }
    
    .action-btn {
      display: inline-block;
      padding: 8px 15px;
      border-radius: 6px;
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 500;
      transition: all 0.3s;
      border: none;
      cursor: pointer;
    }
    
    .review-btn {
      background: var(--secondary-color);
      color: white;
    }
    
    .review-btn:hover {
      background: var(--accent-color);
      transform: translateY(-2px);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .view-btn {
      background: var(--blue);
      color: white;
    }
    
    .view-btn:hover {
      background: #0033cc;
      transform: translateY(-2px);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    /* Job Details Modal - Enhanced Professional Design */
    .modal {
      display: none;
      position: fixed;
      z-index: 2000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(3px);
    }
    
    .modal-content {
      background-color: white;
      padding: 0;
      border-radius: 12px;
      width: 900px;
      max-width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
      from { transform: translateY(-50px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    
    .modal-header {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      padding: 25px 30px;
      border-radius: 12px 12px 0 0;
      position: relative;
    }
    
    .modal-header h2 {
      color: white;
      margin: 0;
      font-size: 1.6rem;
      font-weight: 600;
      padding-right: 40px;
    }
    
    .job-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-top: 10px;
      color: rgba(255,255,255,0.9);
      font-size: 0.9rem;
      padding-right: 40px;
    }
    
    .job-meta-item {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    
    .job-meta-item i {
      font-size: 0.8rem;
    }
    
    .close-modal {
      position: absolute;
      top: 25px;
      right: 30px;
      font-size: 24px;
      cursor: pointer;
      color: white;
      background: rgba(0,0,0,0.2);
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s;
      z-index: 10;
    }
    
    .close-modal:hover {
      background: rgba(0,0,0,0.4);
      transform: rotate(90deg);
    }
    
    .job-details-container {
      padding: 30px;
    }
    
    .job-details-section {
      margin-bottom: 25px;
      padding-bottom: 20px;
      border-bottom: 1px solid #eee;
    }
    
    .job-details-section:last-of-type {
      border-bottom: none;
    }
    
    .section-title {
      color: var(--primary-color);
      margin-bottom: 15px;
      font-size: 1.2rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .section-title i {
      color: var(--secondary-color);
    }
    
    .detail-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 15px;
      margin-bottom: 20px;
    }
    
    .detail-card {
      background: #f9f9f9;
      border-radius: 8px;
      padding: 15px;
      border-left: 4px solid var(--secondary-color);
    }
    
    .detail-label {
      font-size: 0.85rem;
      color: #666;
      margin-bottom: 5px;
      font-weight: 500;
    }
    
    .detail-value {
      font-size: 1rem;
      color: #333;
      font-weight: 500;
    }
    
    .detail-value.status-badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 15px;
      font-size: 0.8rem;
      margin-top: 5px;
    }
    
    .skills-container {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 10px;
    }
    
    .skill-tag {
      background: #e9ecef;
      color: #495057;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 500;
    }
    
    .content-box {
      background: #f9f9f9;
      border-radius: 8px;
      padding: 15px;
      margin-top: 10px;
      line-height: 1.6;
    }
    
    .content-box p {
      margin-bottom: 10px;
    }
    
    .content-box p:last-child {
      margin-bottom: 0;
    }
    
    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 10px;
      margin-top: 25px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }
    
    .approve-btn {
      background: #4caf50;
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s;
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    
    .approve-btn:hover {
      background: #388e3c;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    .revision-btn {
      background: #ff9800;
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s;
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    
    .revision-btn:hover {
      background: #ef6c00;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    .reject-btn {
      background: #f44336;
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s;
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    
    .reject-btn:hover {
      background: #d32f2f;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    /* Comments Section */
    .comments-section {
      margin-top: 20px;
    }
    
    .comments-section h3 {
      margin-bottom: 10px;
      color: var(--text-color);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    textarea {
      width: 100%;
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 6px;
      resize: vertical;
      min-height: 100px;
      font-family: inherit;
      margin-bottom: 10px;
      transition: border-color 0.3s;
    }
    
    textarea:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
    }
    
    /* Success Message */
    .success-message {
      background-color: #e8f5e9;
      color: #2e7d32;
      padding: 15px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
      border-left: 4px solid #2e7d32;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    /* Error Message */
    .error-message {
      background-color: #ffebee;
      color: #c62828;
      padding: 15px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
      border-left: 4px solid #c62828;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .status-summary {
        flex-wrap: wrap;
      }
      
      .status-item {
        flex: 1 1 calc(33.333% - 10px);
        min-width: 150px;
      }
    }
    
    @media (max-width: 900px) {
      .status-item {
        flex: 1 1 calc(50% - 10px);
      }
      
      .status-count {
        font-size: 2rem;
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
      
      .jobs-table {
        display: block;
        overflow-x: auto;
      }
      
      .action-buttons {
        flex-direction: column;
      }
      
      .dropdown {
        width: 90%;
        right: 5%;
      }
      
      .modal-content {
        width: 95%;
        padding: 0;
      }
      
      .status-summary {
        flex-direction: column;
      }
      
      .status-item {
        flex: 1 1 100%;
      }
      
      .job-details-container {
        padding: 15px;
      }
      
      .modal-header {
        padding: 15px 20px;
      }
      
      .modal-header h2 {
        padding-right: 35px;
        font-size: 1.3rem;
      }
      
      .job-meta {
        padding-right: 35px;
      }
      
      .close-modal {
        top: 15px;
        right: 20px;
        width: 28px;
        height: 28px;
        font-size: 20px;
      }
      
      .detail-grid {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 480px) {
      .status-summary {
        flex-direction: column;
      }
      
      .status-item {
        flex: 1 1 100%;
      }
    }
  </style>
</head>
<body>
  <!-- Enhanced Sidebar -->
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
          <a href="staff_track_stats.php">
            <i class="fas fa-location-dot"></i>
            <span>Track Alumni</span>
          </a>
        </li>
        <li>
          <a href="staff_validate_jobs.php" class="active">
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
      <p>Review and validate job postings submitted by employers before they go live. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Validate Job Postings</h1>
    </div>

    <?php if (!empty($error)): ?>
      <div class="error-message">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
      <div class="success-message">
        <i class="fas fa-check-circle"></i>
        Job status updated successfully!
      </div>
    <?php endif; ?>

    <!-- MODIFIED: Job Status Summary - Now in one row -->
    <div class="status-summary">
      <div class="status-item">
        <div class="status-count"><?= $status_counts['pending'] ?></div>
        <div class="status-label">Pending</div>
      </div>
      <div class="status-item">
        <div class="status-count"><?= $status_counts['active'] ?></div>
        <div class="status-label">Active</div>
      </div>
      <div class="status-item">
        <div class="status-count"><?= $status_counts['rejected'] ?></div>
        <div class="status-label">Rejected</div>
      </div>
      <div class="status-item">
        <div class="status-count"><?= $status_counts['closed'] ?></div>
        <div class="status-label">Closed</div>
      </div>
      <div class="status-item">
        <div class="status-count"><?= $status_counts['inactive'] ?></div>
        <div class="status-label">Inactive</div>
      </div>
    </div>

    <!-- All Job Posts Table -->
    <div class="table-container">
      <div class="table-header">
        <h2 class="table-title">All Job Postings</h2>
        <div class="table-controls">
          <select class="filter-select" id="statusFilter">
            <option value="all">All Status</option>
            <option value="pending">Pending</option>
            <option value="active">Active</option>
            <option value="rejected">Rejected</option>
            <option value="closed">Closed</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <table class="jobs-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Employer</th>
            <th>Tags</th>
            <th>Domain</th>
            <th>Status</th>
            <th>Reviewed By</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="jobTableBody">
          <?php if (!empty($all_jobs)): ?>
            <?php foreach ($all_jobs as $job): 
              // Parse job skills if stored as JSON or comma-separated
              $tags = [];
              if (!empty($job['job_skills'])) {
                // Try to decode as JSON first
                $decoded_skills = json_decode($job['job_skills'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_skills)) {
                  $tags = $decoded_skills;
                } else {
                  // Fallback to comma-separated
                  $tags = array_map('trim', explode(',', $job['job_skills']));
                }
              }
              
              // Limit to 3 tags for display
              $display_tags = array_slice($tags, 0, 3);
              
              // Determine status badge class
              $status_class = 'status-' . $job['job_status'];
              
              // Format reviewer info
              $reviewer_info = '';
              if (!empty($job['reviewer_name'])) {
                $review_date = !empty($job['job_reviewed_at']) ? date('M j, Y', strtotime($job['job_reviewed_at'])) : '';
                $reviewer_info = $job['reviewer_name'] . ($review_date ? '<br><small>' . $review_date . '</small>' : '');
              }
            ?>
            <tr data-status="<?= $job['job_status'] ?>">
              <td><?= htmlspecialchars($job['job_title']) ?></td>
              <td><?= htmlspecialchars($job['emp_company_name']) ?></td>
              <td>
                <?php foreach ($display_tags as $tag): ?>
                <span class="tag"><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
                <?php if (count($tags) > 3): ?>
                <span class="tag">+<?= count($tags) - 3 ?> more</span>
                <?php endif; ?>
              </td>
              <td><?= !empty($job['job_domain']) ? htmlspecialchars($job['job_domain']) : 'N/A' ?></td>
              <td>
                <span class="status-badge <?= $status_class ?>"><?= ucfirst($job['job_status']) ?></span>
              </td>
              <td>
                <?php if (!empty($reviewer_info)): ?>
                  <div class="reviewer-info"><?= $reviewer_info ?></div>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">Not reviewed</span>
                <?php endif; ?>
              </td>
              <td>
                <!-- MODIFIED: Always show "Review" button regardless of status -->
                <button class="action-btn review-btn" onclick="showJobDetails(<?= $job['job_id'] ?>)">Review</button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" style="text-align: center; padding: 20px;">No job postings found</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Job Details Modal -->
  <div class="modal" id="jobDetailsModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="jobTitle">Job Title</h2>
        <div class="job-meta" id="jobMeta">
          <!-- Job meta information will be populated by JavaScript -->
        </div>
        <span class="close-modal" onclick="closeModal('jobDetailsModal')">&times;</span>
      </div>
      
      <div class="job-details-container">
        <!-- Job Information Section -->
        <div class="job-details-section">
          <h3 class="section-title"><i class="fas fa-info-circle"></i> Job Information</h3>
          <div class="detail-grid" id="jobInfoGrid">
            <!-- Job information will be populated by JavaScript -->
          </div>
        </div>
        
        <!-- Skills Section -->
        <div class="job-details-section">
          <h3 class="section-title"><i class="fas fa-tools"></i> Required Skills</h3>
          <div class="skills-container" id="jobSkills">
            <!-- Skills will be populated by JavaScript -->
          </div>
        </div>
        
        <!-- Job Description Section -->
        <div class="job-details-section">
          <h3 class="section-title"><i class="fas fa-file-alt"></i> Job Description</h3>
          <div class="content-box" id="jobDescription">
            Job description will appear here...
          </div>
        </div>
        
        <!-- Requirements Section -->
        <div class="job-details-section">
          <h3 class="section-title"><i class="fas fa-list-check"></i> Job Requirements</h3>
          <div class="content-box" id="jobRequirements">
            Job requirements will appear here...
          </div>
        </div>
        
        <!-- Review Information Section -->
        <div class="job-details-section">
          <h3 class="section-title"><i class="fas fa-clipboard-check"></i> Review Information</h3>
          <div class="detail-grid" id="reviewInfoGrid">
            <!-- Review information will be populated by JavaScript -->
          </div>
        </div>
        
        <form id="jobActionForm" method="POST">
          <input type="hidden" name="job_action" value="1">
          <input type="hidden" id="job_id" name="job_id" value="">
          
          <!-- MODIFIED: Action Buttons - Always show for all job statuses -->
          <div class="action-buttons" id="actionButtons">
            <button type="button" class="approve-btn" onclick="submitAction('approve')">
              <i class="fas fa-check-circle"></i> Approve
            </button>
            <button type="button" class="revision-btn" onclick="submitAction('edit')">
              <i class="fas fa-edit"></i> Request Revision
            </button>
            <button type="button" class="reject-btn" onclick="submitAction('reject')">
              <i class="fas fa-times-circle"></i> Reject
            </button>
          </div>
          
          <!-- Comments Section -->
          <div class="comments-section" id="commentsSection">
            <h3><i class="fas fa-comment-dots"></i> Comments</h3>
            <textarea id="comments" name="comments" placeholder="Add your comments here..."></textarea>
            <input type="hidden" id="action" name="action" value="">
          </div>
        </form>
      </div>
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
            url: 'staff_validate_jobs.php',
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
      
      // Status filter functionality
      $('#statusFilter').on('change', function() {
        const status = $(this).val();
        const rows = $('#jobTableBody tr');
        
        if (status === 'all') {
          rows.show();
        } else {
          rows.hide();
          rows.filter('[data-status="' + status + '"]').show();
        }
      });
      
      // Add hover effects to cards
      $('.status-item').hover(
        function() {
          $(this).css('transform', 'translateY(-5px)');
        },
        function() {
          $(this).css('transform', 'translateY(0)');
        }
      );
    });

    let currentJobId = null;
    let currentJobStatus = null;

    function showJobDetails(jobId) {
      currentJobId = jobId;
      document.getElementById('job_id').value = jobId;
      
      // Fetch job details via AJAX
      fetch('?ajax=get_job_details&id=' + jobId)
        .then(response => response.json())
        .then(job => {
          if (job.error) {
            alert(job.error);
            return;
          }
          
          // Update job title
          document.getElementById('jobTitle').textContent = job.job_title || 'N/A';
          
          // Update job meta information
          const jobMeta = document.getElementById('jobMeta');
          jobMeta.innerHTML = '';
          
          if (job.company_name) {
            const companyItem = document.createElement('div');
            companyItem.className = 'job-meta-item';
            companyItem.innerHTML = `<i class="fas fa-building"></i> ${job.company_name}`;
            jobMeta.appendChild(companyItem);
          }
          
          if (job.job_domain) {
            const domainItem = document.createElement('div');
            domainItem.className = 'job-meta-item';
            domainItem.innerHTML = `<i class="fas fa-layer-group"></i> ${job.job_domain}`;
            jobMeta.appendChild(domainItem);
          }
          
          if (job.job_type) {
            const typeItem = document.createElement('div');
            typeItem.className = 'job-meta-item';
            typeItem.innerHTML = `<i class="fas fa-briefcase"></i> ${job.job_type.charAt(0).toUpperCase() + job.job_type.slice(1)}`;
            jobMeta.appendChild(typeItem);
          }
          
          // Update job information grid
          const jobInfoGrid = document.getElementById('jobInfoGrid');
          jobInfoGrid.innerHTML = '';
          
          // MODIFIED: Convert salary to Philippine Peso format
          const salaryRange = job.job_salary_range ? convertToPeso(job.job_salary_range) : 'Not specified';
          
          const jobInfoItems = [
            { label: 'Salary Range', value: salaryRange, icon: 'dollar-sign' },
            { label: 'Location', value: job.job_location || 'Not specified', icon: 'map-marker-alt' },
            { label: 'Job Type', value: job.job_type ? job.job_type.charAt(0).toUpperCase() + job.job_type.slice(1) : 'Not specified', icon: 'briefcase' },
            { label: 'Status', value: job.job_status ? job.job_status.charAt(0).toUpperCase() + job.job_status.slice(1) : 'N/A', 
              badge: true, badgeClass: `status-${job.job_status}`, icon: 'info-circle' }
          ];
          
          jobInfoItems.forEach(item => {
            const card = document.createElement('div');
            card.className = 'detail-card';
            
            const label = document.createElement('div');
            label.className = 'detail-label';
            label.innerHTML = `<i class="fas fa-${item.icon}"></i> ${item.label}`;
            
            const value = document.createElement('div');
            value.className = 'detail-value';
            
            if (item.badge) {
              value.innerHTML = `<span class="status-badge ${item.badgeClass}">${item.value}</span>`;
            } else {
              value.textContent = item.value;
            }
            
            card.appendChild(label);
            card.appendChild(value);
            jobInfoGrid.appendChild(card);
          });
          
          // Parse and display skills
          const skillsContainer = document.getElementById('jobSkills');
          skillsContainer.innerHTML = '';
          
          let tags = [];
          if (job.job_skills) {
            // Try to decode as JSON first
            try {
              const decodedSkills = JSON.parse(job.job_skills);
              if (Array.isArray(decodedSkills)) {
                tags = decodedSkills;
              }
            } catch (e) {
              // Fallback to comma-separated
              tags = job.job_skills.split(',').map(tag => tag.trim());
            }
          }
          
          if (tags.length > 0) {
            tags.forEach(tag => {
              const skillTag = document.createElement('span');
              skillTag.className = 'skill-tag';
              skillTag.textContent = tag;
              skillsContainer.appendChild(skillTag);
            });
          } else {
            skillsContainer.innerHTML = '<p style="color: #666; font-style: italic;">No specific skills listed</p>';
          }
          
          // Update job description and requirements
          document.getElementById('jobDescription').innerHTML = job.job_description ? 
            `<p>${job.job_description.replace(/\n/g, '</p><p>')}</p>` : 
            '<p style="color: #666; font-style: italic;">No description provided</p>';
            
          document.getElementById('jobRequirements').innerHTML = job.job_requirements ? 
            `<p>${job.job_requirements.replace(/\n/g, '</p><p>')}</p>` : 
            '<p style="color: #666; font-style: italic;">No requirements provided</p>';
          
          // Update review information
          const reviewInfoGrid = document.getElementById('reviewInfoGrid');
          reviewInfoGrid.innerHTML = '';
          
          const reviewInfoItems = [
            { label: 'Reviewed By', value: job.reviewer_name || 'Not reviewed', icon: 'user-check' },
            { label: 'Reviewed At', value: job.job_reviewed_at ? new Date(job.job_reviewed_at).toLocaleDateString() : '-', icon: 'calendar-alt' }
          ];
          
          reviewInfoItems.forEach(item => {
            const card = document.createElement('div');
            card.className = 'detail-card';
            
            const label = document.createElement('div');
            label.className = 'detail-label';
            label.innerHTML = `<i class="fas fa-${item.icon}"></i> ${item.label}`;
            
            const value = document.createElement('div');
            value.className = 'detail-value';
            value.textContent = item.value;
            
            card.appendChild(label);
            card.appendChild(value);
            reviewInfoGrid.appendChild(card);
          });
          
          // MODIFIED: Always show action buttons regardless of job status
          const actionButtons = document.getElementById('actionButtons');
          const commentsSection = document.getElementById('commentsSection');
          currentJobStatus = job.job_status;
          
          // Always show action buttons and comments section
          actionButtons.style.display = 'flex';
          commentsSection.style.display = 'block';
          
          // Show modal
          document.getElementById('jobDetailsModal').style.display = 'flex';
          document.getElementById('comments').value = '';
        })
        .catch(error => {
          console.error('Error fetching job details:', error);
          alert('Error loading job details. Please try again.');
        });
    }
    
    function submitAction(action) {
      const comments = document.getElementById('comments').value.trim();
      
      // MODIFIED: Remove restriction on pending jobs only
      // Allow comments for all actions except approve
      if (action !== 'approve' && !comments) {
        alert("Please provide comments explaining the reason.");
        return;
      }
      
      document.getElementById('action').value = action;
      document.getElementById('jobActionForm').submit();
    }
    
    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
      if (modalId === 'jobDetailsModal') {
        document.getElementById('comments').value = '';
      }
    }
    
    // MODIFIED: Function to convert salary to Philippine Peso format
    function convertToPeso(amount) {
      // If it's a range like "15000-18000"
      if (amount && amount.indexOf('-') !== -1) {
        const parts = amount.split('-');
        if (parts.length === 2) {
          const min = parseInt(parts[0].trim());
          const max = parseInt(parts[1].trim());
          if (!isNaN(min) && !isNaN(max)) {
            return '₱' + min.toLocaleString() + ' - ₱' + max.toLocaleString();
          }
        }
      }
      
      // If it's a single amount
      const numAmount = parseInt(amount);
      if (!isNaN(numAmount)) {
        return '₱' + numAmount.toLocaleString();
      }
      
      // If it's already in peso format or unknown format, return as is
      return amount;
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
      const modals = document.getElementsByClassName('modal');
      for (let i = 0; i < modals.length; i++) {
        if (event.target == modals[i]) {
          modals[i].style.display = 'none';
        }
      }
    }
  </script>
</body>
</html>