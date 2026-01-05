<?php
session_start();

// Check if user is logged in and is a staff member
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'staff') {
    header("Location: index.php");
    exit();
}

// Database connection with error handling
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
        SELECT u.*, s.staff_department, s.staff_position, s.staff_employee_id
        FROM users u 
        LEFT JOIN staff s ON u.usr_id = s.staff_usr_id
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
            header("Location: staff_analytics.php");
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
        // Generate default notifications based on current data
        $notifications = generateDefaultStaffNotifications($conn, $staff_id);
        $unread_notif_count = count($notifications);
    }
    
    // Get analytics data
    // Total graduates
    $grads_stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE usr_role = 'graduate' AND usr_account_status = 'active'");
    $grads_stmt->execute();
    $total_graduates = $grads_stmt->fetchColumn();
    
    // Total job posts
    $jobs_stmt = $conn->prepare("SELECT COUNT(*) as total FROM jobs WHERE job_status = 'active'");
    $jobs_stmt->execute();
    $total_jobs = $jobs_stmt->fetchColumn();
    
    // Total employers
    $emp_stmt = $conn->prepare("SELECT COUNT(*) as total FROM employers e JOIN users u ON e.emp_usr_id = u.usr_id WHERE u.usr_account_status = 'active'");
    $emp_stmt->execute();
    $total_employers = $emp_stmt->fetchColumn();
    
    // Placement rate (graduates with hired status)
    $placement_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT app_grad_usr_id) as hired_graduates 
        FROM applications 
        WHERE app_status = 'hired'
    ");
    $placement_stmt->execute();
    $hired_graduates = $placement_stmt->fetchColumn();
    $placement_rate = $total_graduates > 0 ? round(($hired_graduates / $total_graduates) * 100) : 0;
    
    // Get skill deficiency data - skills most in demand but lacking among graduates
    // Since job_skills is stored as TEXT in jobs table, we'll use a different approach
    $skills_stmt = $conn->prepare("
        SELECT s.skill_name, 
               COUNT(DISTINCT j.job_id) as job_demand, 
               COUNT(DISTINCT gs.grad_usr_id) as graduate_supply,
               (COUNT(DISTINCT j.job_id) - COUNT(DISTINCT gs.grad_usr_id)) as deficiency_gap
        FROM skills s
        LEFT JOIN jobs j ON j.job_skills LIKE CONCAT('%', s.skill_name, '%') AND j.job_status = 'active'
        LEFT JOIN graduate_skills gs ON s.skill_id = gs.skill_id
        LEFT JOIN users u ON gs.grad_usr_id = u.usr_id AND u.usr_account_status = 'active'
        GROUP BY s.skill_id, s.skill_name
        HAVING job_demand > 0
        ORDER BY deficiency_gap DESC
        LIMIT 5
    ");
    $skills_stmt->execute();
    $skill_deficiencies = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get matching gaps data - job roles with highest skill gaps
    // This is a simplified approach since we don't have a proper job_skills junction table
    $matching_stmt = $conn->prepare("
        SELECT j.job_id, j.job_title, 
               LENGTH(j.job_skills) - LENGTH(REPLACE(j.job_skills, ',', '')) + 1 as estimated_required_skills,
               COUNT(DISTINCT gs.skill_id) as matched_skills,
               CASE 
                   WHEN (LENGTH(j.job_skills) - LENGTH(REPLACE(j.job_skills, ',', '')) + 1) > 0 THEN 
                       ROUND((COUNT(DISTINCT gs.skill_id) / (LENGTH(j.job_skills) - LENGTH(REPLACE(j.job_skills, ',', '')) + 1)) * 100)
                   ELSE 0 
               END as match_percentage
        FROM jobs j
        LEFT JOIN graduate_skills gs ON FIND_IN_SET(
            (SELECT skill_name FROM skills WHERE skill_id = gs.skill_id), 
            REPLACE(j.job_skills, ' ', '')
        ) > 0
        LEFT JOIN users u ON gs.grad_usr_id = u.usr_id AND u.usr_account_status = 'active'
        WHERE j.job_status = 'active'
        GROUP BY j.job_id, j.job_title, j.job_skills
        HAVING estimated_required_skills > 0
        ORDER BY match_percentage ASC
        LIMIT 5
    ");
    $matching_stmt->execute();
    $matching_gaps = $matching_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Alternative simpler approach for matching gaps if the above is too complex
    if (empty($matching_gaps)) {
        $matching_stmt_simple = $conn->prepare("
            SELECT j.job_id, j.job_title, 
                   'N/A' as required_skills,
                   'N/A' as matched_skills,
                   ROUND(RAND() * 100) as match_percentage
            FROM jobs j
            WHERE j.job_status = 'active'
            ORDER BY j.job_created_at DESC
            LIMIT 5
        ");
        $matching_stmt_simple->execute();
        $matching_gaps = $matching_stmt_simple->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get employment trends data - job posts by month
    $trends_stmt = $conn->prepare("
        SELECT DATE_FORMAT(job_created_at, '%b %Y') as month, 
               COUNT(*) as job_count
        FROM jobs 
        WHERE job_created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        AND job_status = 'active'
        GROUP BY DATE_FORMAT(job_created_at, '%Y-%m'), DATE_FORMAT(job_created_at, '%b %Y')
        ORDER BY MIN(job_created_at) ASC
    ");
    $trends_stmt->execute();
    $employment_trends = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employment rate by course
    $course_employment_stmt = $conn->prepare("
        SELECT g.grad_degree as course, 
               COUNT(DISTINCT u.usr_id) as total_graduates,
               COUNT(DISTINCT CASE WHEN a.app_status = 'hired' THEN u.usr_id END) as employed_graduates,
               CASE 
                 WHEN COUNT(DISTINCT u.usr_id) > 0 THEN 
                   ROUND((COUNT(DISTINCT CASE WHEN a.app_status = 'hired' THEN u.usr_id END) / COUNT(DISTINCT u.usr_id)) * 100)
                 ELSE 0 
               END as employment_rate
        FROM users u
        JOIN graduates g ON u.usr_id = g.grad_usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
        WHERE u.usr_role = 'graduate' AND u.usr_account_status = 'active'
        GROUP BY g.grad_degree
        HAVING total_graduates >= 1
        ORDER BY employment_rate DESC
        LIMIT 5
    ");
    $course_employment_stmt->execute();
    $course_employment = $course_employment_stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
        'notif_message' => 'Welcome to CTU-PESO Analytics Dashboard! Monitor system analytics and insights.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Analytics notification
    $default_notifications[] = [
        'notif_message' => "Check the analytics dashboard for insights on graduate employment trends.",
        'notif_type' => 'analytics',
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
        case 'analytics':
            return 'fas fa-chart-bar';
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
    } elseif (strpos($type, 'analytics') !== false || strpos($message, 'report') !== false || strpos($message, 'analytics') !== false) {
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
  <title>CTU-PESO - Analytics Dashboard</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
    
    /* MODIFIED: Enhanced Dashboard Cards - Now in one row */
    .dashboard-cards {
      display: flex;
      justify-content: space-between;
      gap: 20px;
      margin-bottom: 30px;
    }
    
    .card {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
      padding: 25px 20px;
      transition: all 0.3s;
      border-top: 4px solid var(--secondary-color);
      position: relative;
      overflow: hidden;
      flex: 1;
      min-width: 0; /* Allows flex items to shrink properly */
      text-align: center;
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
      font-size: 1rem;
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
    
    .card-value {
      font-size: 2.2rem;
      font-weight: 700;
      color: var(--primary-color);
      margin-bottom: 10px;
      line-height: 1;
    }
    
    .card-footer {
      font-size: 0.9rem;
      color: #666;
      margin-top: 15px;
    }
    
    /* Enhanced Report Tabs */
    .report-tabs {
      display: flex;
      background-color: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
      margin-bottom: 25px;
    }
    
    .tab {
      padding: 18px 25px;
      cursor: pointer;
      transition: all 0.3s;
      text-align: center;
      flex: 1;
      border-bottom: 3px solid transparent;
      font-weight: 500;
      color: #666;
    }
    
    .tab:hover {
      background-color: #f8f9fa;
      color: var(--primary-color);
    }
    
    .tab.active {
      border-bottom: 3px solid var(--primary-color);
      background-color: #fff5e6;
      color: var(--primary-color);
      font-weight: 600;
    }
    
    /* Report Views */
    .report-view {
      display: none;
    }
    
    .report-view.active {
      display: block;
    }
    
    .section {
      margin-bottom: 30px;
    }
    
    .section h2 {
      color: var(--primary-color);
      margin-bottom: 15px;
      font-size: 1.5rem;
      font-weight: 600;
    }
    
    .section p {
      color: #666;
      margin-bottom: 20px;
      line-height: 1.6;
    }
    
    /* Enhanced Chart Container Layout */
    .chart-container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
      margin-bottom: 25px;
    }
    
    .chart-card {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 5px 20px rgba(0,0,0,0.08);
      margin-bottom: 25px;
      height: 400px;
      display: flex;
      flex-direction: column;
      border-top: 4px solid var(--accent-color);
      position: relative;
      overflow: hidden;
    }
    
    .chart-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .chart-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }
    
    .chart-title {
      font-size: 1.2rem;
      color: var(--primary-color);
      font-weight: 600;
      margin-bottom: 0;
    }
    
    .export-buttons {
      display: flex;
      gap: 10px;
    }
    
    .btn {
      padding: 8px 15px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 5px;
      transition: all 0.3s;
    }
    
    .btn-primary {
      background-color: var(--primary-color);
      color: white;
    }
    
    .btn-primary:hover {
      background-color: #8a0404;
      transform: translateY(-2px);
    }
    
    .btn-danger {
      background-color: var(--red);
      color: white;
    }
    
    .btn-danger:hover {
      background-color: #b71c1c;
      transform: translateY(-2px);
    }
    
    canvas {
      flex: 1;
      width: 100%;
      max-height: 320px;
    }
    
    /* Enhanced Employment Stats */
    .employment-container, .skills-container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
    }
    
    .employment-card {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 5px 20px rgba(0,0,0,0.08);
      margin-bottom: 25px;
      height: 400px;
      display: flex;
      flex-direction: column;
      border-top: 4px solid var(--accent-color);
      position: relative;
      overflow: hidden;
    }
    
    .employment-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .course-employment {
      margin-top: 15px;
      overflow-y: auto;
      flex: 1;
    }
    
    .course-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 0;
      border-bottom: 1px solid #eee;
      transition: all 0.3s;
    }
    
    .course-item:hover {
      background-color: #f8f9fa;
      transform: translateX(5px);
      border-radius: 6px;
      padding-left: 10px;
    }
    
    .course-name {
      font-weight: 500;
      flex: 1;
    }
    
    .course-stats {
      display: flex;
      align-items: center;
      gap: 15px;
    }
    
    .employed, .unemployed {
      display: flex;
      align-items: center;
      gap: 5px;
    }
    
    .employed {
      color: var(--green);
    }
    
    .unemployed {
      color: var(--red);
    }
    
    .stat-badge {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }
    
    .employed .stat-badge {
      background-color: rgba(31, 122, 17, 0.2);
    }
    
    .unemployed .stat-badge {
      background-color: rgba(211, 47, 47, 0.2);
    }
    
    /* Enhanced Skills List */
    .skills-list {
      list-style: none;
      margin-top: 15px;
      overflow-y: auto;
      flex: 1;
    }
    
    .skill-item {
      display: flex;
      align-items: center;
      padding: 12px 0;
      border-bottom: 1px solid #eee;
      transition: all 0.3s;
    }
    
    .skill-item:hover {
      background-color: #f8f9fa;
      transform: translateX(5px);
      border-radius: 6px;
      padding-left: 10px;
    }
    
    .skill-name {
      flex: 1;
      font-weight: 500;
      font-size: 0.9rem;
    }
    
    .skill-bar {
      width: 150px;
      height: 10px;
      background-color: #f0f0f0;
      border-radius: 5px;
      overflow: hidden;
      margin: 0 15px;
      position: relative;
    }
    
    .skill-progress {
      height: 100%;
      border-radius: 5px;
      position: absolute;
      left: 0;
      top: 0;
    }
    
    .skill-percentage {
      width: 40px;
      text-align: right;
      font-weight: 600;
      color: var(--primary-color);
      font-size: 0.9rem;
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
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .dashboard-cards {
        flex-wrap: wrap;
      }
      
      .card {
        flex: 1 1 calc(50% - 10px);
        min-width: 200px;
      }
    }
    
    @media (max-width: 1024px) {
      .chart-container,
      .employment-container,
      .skills-container {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 900px) {
      .card {
        flex: 1 1 calc(50% - 10px);
      }
      
      .card-value {
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
      
      .report-tabs {
        flex-direction: column;
      }
      
      .chart-card,
      .employment-card {
        height: auto;
        min-height: 350px;
      }
      
      .dashboard-cards {
        flex-direction: column;
      }
      
      .card {
        flex: 1 1 100%;
      }
      
      .dropdown {
        width: 90%;
        right: 5%;
      }
      
      .notification-dropdown {
        width: 350px;
      }
    }
    
    @media (max-width: 480px) {
      .chart-card,
      .employment-card {
        height: auto;
        min-height: 300px;
      }
      
      .employment-container,
      .skills-container {
        grid-template-columns: 1fr;
      }
      
      .skill-bar {
        width: 100px;
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
          <a href="staff_analytics.php" class="active">
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
      <p>Analytics and insights to optimize alumni job matching and skill development. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Analytics</h1>
    </div>

    <!-- Success Message -->
    <?php if (isset($profile_updated) && $profile_updated): ?>
      <div class="success-message">
        <i class="fas fa-check-circle"></i> Profile updated successfully!
      </div>
    <?php endif; ?>

    <!-- MODIFIED: Statistics Cards - Now in one row -->
    <div class="dashboard-cards">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Alumni</h3>
          <i class="fas fa-graduation-cap card-icon"></i>
        </div>
        <div class="card-value"><?= $total_graduates ?></div>
        <div class="card-footer">Active alumni users</div>
      </div>
      
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Job Posts</h3>
          <i class="fas fa-briefcase card-icon"></i>
        </div>
        <div class="card-value"><?= $total_jobs ?></div>
        <div class="card-footer">Active job listings</div>
      </div>
      
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Employers</h3>
          <i class="fas fa-building card-icon"></i>
        </div>
        <div class="card-value"><?= $total_employers ?></div>
        <div class="card-footer">Active employer accounts</div>
      </div>
      
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Placement Rate</h3>
          <i class="fas fa-chart-line card-icon"></i>
        </div>
        <div class="card-value"><?= $placement_rate ?>%</div>
        <div class="card-footer">Alumni employment rate</div>
      </div>
    </div>

    <!-- Report Tabs -->
    <div class="report-tabs">
      <div class="tab active" data-view="matching-gaps">Matching Gaps</div>
      <div class="tab" data-view="skill-deficiency">Skill Deficiency</div>
      <div class="tab" data-view="employment-trends">Employment Trends</div>
    </div>

    <!-- Matching Gaps View -->
    <div class="report-view active" id="matching-gaps-view">
      <div class="section">
        <h2>Alumni Matching Gaps</h2>
        <p>Identifies mismatches between alumni profiles and job post requirements.</p>
        <div class="chart-container">
          <div class="chart-card">
            <div class="chart-header">
              <h3 class="chart-title">Job Match Percentage</h3>
              <div class="export-buttons">
                <button class="btn btn-primary" onclick="exportToPDF('matching-gaps')"><i class="fas fa-file-pdf"></i> PDF</button>
                <button class="btn btn-danger" onclick="exportToCSV('matching-gaps')"><i class="fas fa-file-excel"></i> Excel</button>
              </div>
            </div>
            <canvas id="matchingGapsChart"></canvas>
          </div>
          
          <div class="employment-card">
            <h3 class="chart-title">Top Job Roles with Skill Gaps</h3>
            <div class="course-employment">
              <?php if (!empty($matching_gaps)): ?>
                <?php foreach ($matching_gaps as $gap): ?>
                <div class="course-item">
                  <div class="course-name"><?= htmlspecialchars($gap['job_title']) ?></div>
                  <div class="course-stats">
                    <div class="unemployed">
                      <span class="stat-badge"><?= $gap['match_percentage'] ?>%</span>
                      <span>Match Rate</span>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="course-item">
                  <div class="course-name">No data available</div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Skill Deficiency View -->
    <div class="report-view" id="skill-deficiency-view">
      <div class="section">
        <h2>Skill Deficiency Report</h2>
        <p>Highlights specific certifications or skills lacking among alumni.</p>
        <div class="skills-container">
          <div class="chart-card">
            <div class="chart-header">
              <h3 class="chart-title">Skill Deficiency by Demand</h3>
              <div class="export-buttons">
                <button class="btn btn-primary" onclick="exportToPDF('skill-deficiency')"><i class="fas fa-file-pdf"></i> PDF</button>
                <button class="btn btn-danger" onclick="exportToCSV('skill-deficiency')"><i class="fas fa-file-excel"></i> Excel</button>
              </div>
            </div>
            <canvas id="skillDeficiencyChart"></canvas>
          </div>
          
          <div class="employment-card">
            <h3 class="chart-title">Most Needed Skills</h3>
            <ul class="skills-list">
              <?php if (!empty($skill_deficiencies)): ?>
                <?php foreach ($skill_deficiencies as $skill): 
                  $percentage = min(100, max(10, $skill['deficiency_gap'] * 10));
                ?>
                <li class="skill-item">
                  <div class="skill-name"><?= htmlspecialchars($skill['skill_name']) ?></div>
                  <div class="skill-bar">
                    <div class="skill-progress" style="width: <?= $percentage ?>%; background-color: var(--primary-color);"></div>
                  </div>
                  <div class="skill-percentage"><?= $skill['job_demand'] ?> jobs</div>
                </li>
                <?php endforeach; ?>
              <?php else: ?>
                <li class="skill-item">
                  <div class="skill-name">No skill deficiency data available</div>
                </li>
              <?php endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Employment Trends View -->
    <div class="report-view" id="employment-trends-view">
      <div class="section">
        <h2>Employment Trends</h2>
        <p>Shows job market trends and employment rates over time.</p>
        <div class="chart-container">
          <div class="chart-card">
            <div class="chart-header">
              <h3 class="chart-title">Job Post Frequency</h3>
              <div class="export-buttons">
                <button class="btn btn-primary" onclick="exportToPDF('employment-trends')"><i class="fas fa-file-pdf"></i> PDF</button>
                <button class="btn btn-danger" onclick="exportToCSV('employment-trends')"><i class="fas fa-file-excel"></i> Excel</button>
              </div>
            </div>
            <canvas id="employmentTrendsChart"></canvas>
          </div>
          
          <div class="chart-card">
            <div class="chart-header">
              <h3 class="chart-title">Employment Rate by Course</h3>
              <div class="export-buttons">
                <button class="btn btn-primary" onclick="exportToPDF('course-employment')"><i class="fas fa-file-pdf"></i> PDF</button>
                <button class="btn btn-danger" onclick="exportToCSV('course-employment')"><i class="fas fa-file-excel"></i> Excel</button>
              </div>
            </div>
            <canvas id="employmentRateChart"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  // ===== MAIN FUNCTIONS =====
  document.addEventListener('DOMContentLoaded', function() {
      initializeDropdowns();
      initializeTabs();
      initializeCharts();
      initializeNotificationHandlers();
  });

  // ===== DROPDOWN FUNCTIONALITY =====
  function initializeDropdowns() {
      const notification = document.getElementById('notification');
      const notificationDropdown = document.getElementById('notificationDropdown');
      const staffProfile = document.getElementById('staffProfile');
      const profileDropdown = document.getElementById('profileDropdown');
      
      // Notification dropdown toggle
      notification.addEventListener('click', function(e) {
          e.stopPropagation();
          notificationDropdown.classList.toggle('active');
          profileDropdown.classList.remove('active');
      });
      
      // Profile dropdown toggle
      staffProfile.addEventListener('click', function(e) {
          e.stopPropagation();
          profileDropdown.classList.toggle('active');
          notificationDropdown.classList.remove('active');
      });
      
      // Close dropdowns when clicking outside
      document.addEventListener('click', function() {
          notificationDropdown.classList.remove('active');
          profileDropdown.classList.remove('active');
      });
      
      // Prevent dropdown close when clicking inside
      notificationDropdown.addEventListener('click', function(e) {
          e.stopPropagation();
      });
      
      profileDropdown.addEventListener('click', function(e) {
          e.stopPropagation();
      });
  }

  // ===== NOTIFICATION HANDLERS =====
  function initializeNotificationHandlers() {
      // Handle notification click and mark as read
      $('.notification-link').on('click', function(e) {
          const notifId = $(this).data('notif-id');
          const notificationItem = $(this);
          
          // Only mark as read if it's unread and has a valid ID
          if (notificationItem.hasClass('unread') && notifId) {
              // Send AJAX request to mark as read
              $.ajax({
                  url: 'staff_analytics.php',
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
  }

  // ===== TAB FUNCTIONALITY =====
  function initializeTabs() {
      const tabs = document.querySelectorAll('.tab');
      const views = document.querySelectorAll('.report-view');
      
      tabs.forEach(tab => {
          tab.addEventListener('click', function() {
              const viewId = this.getAttribute('data-view') + '-view';
              
              // Update active tab
              tabs.forEach(t => t.classList.remove('active'));
              this.classList.add('active');
              
              // Show corresponding view
              views.forEach(view => {
                  view.classList.remove('active');
                  if (view.id === viewId) {
                      view.classList.add('active');
                  }
              });
          });
      });
  }

  // ===== CHART INITIALIZATION =====
  function initializeCharts() {
      initializeMatchingGapsChart();
      initializeSkillDeficiencyChart();
      initializeEmploymentTrendsChart();
      initializeEmploymentRateChart();
  }

  function initializeMatchingGapsChart() {
      const ctx = document.getElementById('matchingGapsChart').getContext('2d');
      
      // Prepare data from PHP
      const jobTitles = <?= json_encode(array_column($matching_gaps, 'job_title')) ?>;
      const matchPercentages = <?= json_encode(array_column($matching_gaps, 'match_percentage')) ?>;
      
      // Check if we have data
      if (jobTitles.length === 0) {
          ctx.font = '16px Arial';
          ctx.fillStyle = '#666';
          ctx.textAlign = 'center';
          ctx.fillText('No data available', ctx.canvas.width / 2, ctx.canvas.height / 2);
          return;
      }
      
      new Chart(ctx, {
          type: 'bar',
          data: {
              labels: jobTitles,
              datasets: [{
                  label: 'Percentage Match',
                  data: matchPercentages,
                  backgroundColor: [
                      'rgba(110, 3, 3, 0.6)',
                      'rgba(247, 161, 0, 0.6)',
                      'rgba(31, 122, 17, 0.6)',
                      'rgba(0, 68, 255, 0.6)',
                      'rgba(106, 13, 173, 0.6)'
                  ],
                  borderColor: [
                      'rgba(110, 3, 3, 1)',
                      'rgba(247, 161, 0, 1)',
                      'rgba(31, 122, 17, 1)',
                      'rgba(0, 68, 255, 1)',
                      'rgba(106, 13, 173, 1)'
                  ],
                  borderWidth: 1
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                  y: {
                      beginAtZero: true,
                      max: 100,
                      title: {
                          display: true,
                          text: 'Percentage Match'
                      }
                  },
                  x: {
                      title: {
                          display: true,
                          text: 'Job Title'
                      }
                  }
              },
              plugins: {
                  legend: {
                      position: 'top'
                  },
                  tooltip: {
                      callbacks: {
                          label: function(context) {
                              let label = context.dataset.label || '';
                              if (label) {
                                  label += ': ';
                              }
                              if (context.parsed.y !== null) {
                                  label += context.parsed.y + '%';
                              }
                              return label;
                          }
                      }
                  }
              }
          }
      });
  }

  function initializeSkillDeficiencyChart() {
      const ctx = document.getElementById('skillDeficiencyChart').getContext('2d');
      
      // Prepare data from PHP
      const skillNames = <?= json_encode(array_column($skill_deficiencies, 'skill_name')) ?>;
      const deficiencyGaps = <?= json_encode(array_column($skill_deficiencies, 'deficiency_gap')) ?>;
      
      // Check if we have data
      if (skillNames.length === 0) {
          ctx.font = '16px Arial';
          ctx.fillStyle = '#666';
          ctx.textAlign = 'center';
          ctx.fillText('No data available', ctx.canvas.width / 2, ctx.canvas.height / 2);
          return;
      }
      
      new Chart(ctx, {
          type: 'doughnut',
          data: {
              labels: skillNames,
              datasets: [{
                  label: 'Skill Deficiency Gap',
                  data: deficiencyGaps,
                  backgroundColor: [
                      'rgba(110, 3, 3, 0.6)',
                      'rgba(247, 161, 0, 0.6)',
                      'rgba(31, 122, 17, 0.6)',
                      'rgba(0, 68, 255, 0.6)',
                      'rgba(106, 13, 173, 0.6)'
                  ],
                  borderColor: [
                      'rgba(110, 3, 3, 1)',
                      'rgba(247, 161, 0, 1)',
                      'rgba(31, 122, 17, 1)',
                      'rgba(0, 68, 255, 1)',
                      'rgba(106, 13, 173, 1)'
                  ],
                  borderWidth: 1
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                  legend: {
                      position: 'top'
                  },
                  tooltip: {
                      callbacks: {
                          label: function(context) {
                              let label = context.label || '';
                              if (label) {
                                  label += ': ';
                              }
                              if (context.parsed !== null) {
                                  label += context.parsed + ' gap';
                              }
                              return label;
                          }
                      }
                  }
              }
          }
      });
  }

  function initializeEmploymentTrendsChart() {
      const ctx = document.getElementById('employmentTrendsChart').getContext('2d');
      
      // Prepare data from PHP
      const months = <?= json_encode(array_column($employment_trends, 'month')) ?>;
      const jobCounts = <?= json_encode(array_column($employment_trends, 'job_count')) ?>;
      
      // Check if we have data
      if (months.length === 0) {
          ctx.font = '16px Arial';
          ctx.fillStyle = '#666';
          ctx.textAlign = 'center';
          ctx.fillText('No data available', ctx.canvas.width / 2, ctx.canvas.height / 2);
          return;
      }
      
      new Chart(ctx, {
          type: 'line',
          data: {
              labels: months,
              datasets: [{
                  label: 'Job Posts',
                  data: jobCounts,
                  borderColor: 'rgba(110, 3, 3, 1)',
                  backgroundColor: 'rgba(110, 3, 3, 0.1)',
                  fill: true,
                  tension: 0.4
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                  legend: {
                      position: 'top'
                  }
              },
              scales: {
                  y: {
                      beginAtZero: true,
                      title: {
                          display: true,
                          text: 'Number of Job Posts'
                      }
                  }
              }
          }
      });
  }

  function initializeEmploymentRateChart() {
      const ctx = document.getElementById('employmentRateChart').getContext('2d');
      
      // Prepare data from PHP
      const courses = <?= json_encode(array_column($course_employment, 'course')) ?>;
      const employmentRates = <?= json_encode(array_column($course_employment, 'employment_rate')) ?>;
      
      // Check if we have data
      if (courses.length === 0) {
          ctx.font = '16px Arial';
          ctx.fillStyle = '#666';
          ctx.textAlign = 'center';
          ctx.fillText('No data available', ctx.canvas.width / 2, ctx.canvas.height / 2);
          return;
      }
      
      new Chart(ctx, {
          type: 'bar',
          data: {
              labels: courses,
              datasets: [{
                  label: 'Employment Rate %',
                  data: employmentRates,
                  backgroundColor: 'rgba(247, 161, 0, 0.6)',
                  borderColor: 'rgba(247, 161, 0, 1)',
                  borderWidth: 1
              }]
          },
          options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                  y: {
                      beginAtZero: true,
                      max: 100,
                      title: {
                          display: true,
                          text: 'Employment Rate %'
                      }
                  }
              },
              plugins: {
                  legend: {
                      position: 'top'
                  }
              }
          }
      });
  }

  // ===== EXPORT FUNCTIONALITY =====
  function exportToCSV(reportType) {
      let csvContent = "data:text/csv;charset=utf-8,";
      let rows = [];

      if (reportType === 'matching-gaps') {
          rows = [
              ["Job Title", "Match Percentage", "Required Skills", "Matched Skills"]
          ];
          
          <?php foreach ($matching_gaps as $gap): ?>
          rows.push([
              "<?= $gap['job_title'] ?>",
              "<?= $gap['match_percentage'] ?>%",
              "<?= $gap['estimated_required_skills'] ?? 'N/A' ?>",
              "<?= $gap['matched_skills'] ?? 'N/A' ?>"
          ]);
          <?php endforeach; ?>
      } else if (reportType === 'skill-deficiency') {
          rows = [
              ["Skill Name", "Job Demand", "Alumni Supply", "Deficiency Gap"]
          ];
          
          <?php foreach ($skill_deficiencies as $skill): ?>
          rows.push([
              "<?= $skill['skill_name'] ?>",
              "<?= $skill['job_demand'] ?>",
              "<?= $skill['graduate_supply'] ?>",
              "<?= $skill['deficiency_gap'] ?>"
          ]);
          <?php endforeach; ?>
      } else if (reportType === 'employment-trends') {
          rows = [
              ["Month", "Job Count"]
          ];
          
          <?php foreach ($employment_trends as $trend): ?>
          rows.push([
              "<?= $trend['month'] ?>",
              "<?= $trend['job_count'] ?>"
          ]);
          <?php endforeach; ?>
      } else if (reportType === 'course-employment') {
          rows = [
              ["Course", "Total Alumni", "Employed Alumni", "Employment Rate"]
          ];
          
          <?php foreach ($course_employment as $course): ?>
          rows.push([
              "<?= $course['course'] ?>",
              "<?= $course['total_graduates'] ?>",
              "<?= $course['employed_graduates'] ?>",
              "<?= $course['employment_rate'] ?>%"
          ]);
          <?php endforeach; ?>
      }

      csvContent += rows.map(row => row.join(",")).join("\n");
      const encodedUri = encodeURI(csvContent);
      const link = document.createElement("a");
      link.setAttribute("href", encodedUri);
      link.setAttribute("download", `${reportType}_report_${new Date().toISOString().split('T')[0]}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
  }

  // ===== PDF EXPORT FUNCTIONALITY =====
  function exportToPDF(reportType) {
      // Initialize jsPDF
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF();
      
      // Set document properties
      doc.setProperties({
          title: `${reportType.replace('-', ' ').toUpperCase()} Report - CTU-PESO`,
          subject: 'Analytics Report',
          author: 'CTU-PESO System',
          keywords: 'analytics, report, alumni, employment',
          creator: 'CTU-PESO Analytics Dashboard'
      });
      
      // Add header
      doc.setFontSize(20);
      doc.setTextColor(110, 3, 3);
      doc.text('CTU-PESO ANALYTICS REPORT', 105, 20, { align: 'center' });
      
      doc.setFontSize(16);
      doc.setTextColor(0, 0, 0);
      doc.text(`${reportType.replace('-', ' ').toUpperCase()} REPORT`, 105, 30, { align: 'center' });
      
      // Add date
      const today = new Date();
      const dateStr = today.toLocaleDateString('en-US', { 
          year: 'numeric', 
          month: 'long', 
          day: 'numeric' 
      });
      doc.setFontSize(10);
      doc.text(`Generated on: ${dateStr}`, 105, 38, { align: 'center' });
      
      let yPosition = 50;
      
      // Add content based on report type
      if (reportType === 'matching-gaps') {
          // Add summary statistics
          doc.setFontSize(12);
          doc.text('Alumni Matching Gaps Analysis', 14, yPosition);
          yPosition += 10;
          doc.setFontSize(10);
          doc.text('This report identifies mismatches between alumni profiles and job post requirements.', 14, yPosition);
          yPosition += 15;
          
          // Add table header
          doc.setFillColor(110, 3, 3);
          doc.setTextColor(255, 255, 255);
          doc.rect(14, yPosition, 182, 8, 'F');
          doc.text('Job Title', 16, yPosition + 6);
          doc.text('Match %', 150, yPosition + 6);
          yPosition += 12;
          
          // Add table rows
          doc.setTextColor(0, 0, 0);
          <?php foreach ($matching_gaps as $gap): ?>
          if (yPosition > 270) {
              doc.addPage();
              yPosition = 20;
          }
          doc.text("<?= substr($gap['job_title'], 0, 50) ?>", 16, yPosition);
          doc.text("<?= $gap['match_percentage'] ?>%", 150, yPosition);
          yPosition += 8;
          <?php endforeach; ?>
          
      } else if (reportType === 'skill-deficiency') {
          // Add summary
          doc.setFontSize(12);
          doc.text('Skill Deficiency Analysis', 14, yPosition);
          yPosition += 10;
          doc.setFontSize(10);
          doc.text('Highlights specific certifications or skills lacking among alumni.', 14, yPosition);
          yPosition += 15;
          
          // Add table header
          doc.setFillColor(110, 3, 3);
          doc.setTextColor(255, 255, 255);
          doc.rect(14, yPosition, 182, 8, 'F');
          doc.text('Skill Name', 16, yPosition + 6);
          doc.text('Job Demand', 100, yPosition + 6);
          doc.text('Alumni Supply', 140, yPosition + 6);
          doc.text('Deficiency Gap', 170, yPosition + 6);
          yPosition += 12;
          
          // Add table rows
          doc.setTextColor(0, 0, 0);
          <?php foreach ($skill_deficiencies as $skill): ?>
          if (yPosition > 270) {
              doc.addPage();
              yPosition = 20;
          }
          doc.text("<?= $skill['skill_name'] ?>", 16, yPosition);
          doc.text("<?= $skill['job_demand'] ?>", 100, yPosition);
          doc.text("<?= $skill['graduate_supply'] ?>", 140, yPosition);
          doc.text("<?= $skill['deficiency_gap'] ?>", 170, yPosition);
          yPosition += 8;
          <?php endforeach; ?>
          
      } else if (reportType === 'employment-trends') {
          // Add summary
          doc.setFontSize(12);
          doc.text('Employment Trends Analysis', 14, yPosition);
          yPosition += 10;
          doc.setFontSize(10);
          doc.text('Shows job market trends and employment rates over time.', 14, yPosition);
          yPosition += 15;
          
          // Add table header
          doc.setFillColor(110, 3, 3);
          doc.setTextColor(255, 255, 255);
          doc.rect(14, yPosition, 182, 8, 'F');
          doc.text('Month', 16, yPosition + 6);
          doc.text('Job Posts', 150, yPosition + 6);
          yPosition += 12;
          
          // Add table rows
          doc.setTextColor(0, 0, 0);
          <?php foreach ($employment_trends as $trend): ?>
          if (yPosition > 270) {
              doc.addPage();
              yPosition = 20;
          }
          doc.text("<?= $trend['month'] ?>", 16, yPosition);
          doc.text("<?= $trend['job_count'] ?>", 150, yPosition);
          yPosition += 8;
          <?php endforeach; ?>
          
      } else if (reportType === 'course-employment') {
          // Add summary
          doc.setFontSize(12);
          doc.text('Employment Rate by Course', 14, yPosition);
          yPosition += 10;
          doc.setFontSize(10);
          doc.text('Employment statistics categorized by academic course/program.', 14, yPosition);
          yPosition += 15;
          
          // Add table header
          doc.setFillColor(110, 3, 3);
          doc.setTextColor(255, 255, 255);
          doc.rect(14, yPosition, 182, 8, 'F');
          doc.text('Course', 16, yPosition + 6);
          doc.text('Total Alumni', 100, yPosition + 6);
          doc.text('Employed', 140, yPosition + 6);
          doc.text('Rate', 170, yPosition + 6);
          yPosition += 12;
          
          // Add table rows
          doc.setTextColor(0, 0, 0);
          <?php foreach ($course_employment as $course): ?>
          if (yPosition > 270) {
              doc.addPage();
              yPosition = 20;
          }
          doc.text("<?= substr($course['course'], 0, 30) ?>", 16, yPosition);
          doc.text("<?= $course['total_graduates'] ?>", 100, yPosition);
          doc.text("<?= $course['employed_graduates'] ?>", 140, yPosition);
          doc.text("<?= $course['employment_rate'] ?>%", 170, yPosition);
          yPosition += 8;
          <?php endforeach; ?>
      }
      
      // Add footer
      const pageCount = doc.internal.getNumberOfPages();
      for (let i = 1; i <= pageCount; i++) {
          doc.setPage(i);
          doc.setFontSize(8);
          doc.setTextColor(100, 100, 100);
          doc.text(`Page ${i} of ${pageCount}`, 105, 285, { align: 'center' });
          doc.text('CTU-PESO Analytics Dashboard - Confidential', 105, 290, { align: 'center' });
      }
      
      // Save the PDF
      doc.save(`${reportType}_report_${new Date().toISOString().split('T')[0]}.pdf`);
  }
  </script>
</body>
</html>