<?php
// staff_changepass.php
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
        SELECT u.*, s.staff_department, s.staff_position, s.staff_employee_id
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
            header("Location: staff_changepass.php");
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
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['change_password'])) {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Verify current password
            if (password_verify($current_password, $user['usr_password'])) {
                if ($new_password === $confirm_password) {
                    // Strict password requirements check
                    $uppercase = preg_match('@[A-Z]@', $new_password);
                    $lowercase = preg_match('@[a-z]@', $new_password);
                    $number = preg_match('@[0-9]@', $new_password);
                    $specialChars = preg_match('@[^\w]@', $new_password);
                    $length = strlen($new_password) >= 8;
                    
                    if ($uppercase && $lowercase && $number && $specialChars && $length) {
                        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                        
                        $update_stmt = $conn->prepare("
                            UPDATE users 
                            SET usr_password = :password,
                                usr_updated_at = NOW()
                            WHERE usr_id = :user_id
                        ");
                        
                        $update_stmt->bindParam(':password', $password_hash);
                        $update_stmt->bindParam(':user_id', $user_id);
                        
                        if ($update_stmt->execute()) {
                            $success = "Password changed successfully!";
                            
                            // Create a notification for password change
                            createStaffNotification($conn, $user_id, 'password_change', 'Your password was successfully changed. If this was not you, please contact support immediately.');
                        } else {
                            $error = "Failed to change password.";
                        }
                    } else {
                        $error = "Password does not meet all requirements. Please ensure it has at least 8 characters, one uppercase letter, one lowercase letter, one number, and one special character.";
                    }
                } else {
                    $error = "New passwords do not match.";
                }
            } else {
                $error = "Current password is incorrect.";
            }
        } elseif (isset($_POST['delete_account'])) {
            // Handle account deletion
            $confirm_delete = $_POST['confirm_delete'] ?? '';
            
            if ($confirm_delete === 'DELETE') {
                try {
                    // Begin transaction
                    $conn->beginTransaction();
                    
                    // 1. Delete notifications
                    $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE notif_usr_id = :user_id");
                    $delete_notifications->bindParam(':user_id', $user_id);
                    $delete_notifications->execute();
                    
                    // 2. Delete staff record
                    $delete_staff = $conn->prepare("DELETE FROM staff WHERE staff_usr_id = :user_id");
                    $delete_staff->bindParam(':user_id', $user_id);
                    $delete_staff->execute();
                    
                    // 3. Delete user record
                    $delete_user = $conn->prepare("DELETE FROM users WHERE usr_id = :user_id");
                    $delete_user->bindParam(':user_id', $user_id);
                    $delete_user->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    // Destroy session and redirect
                    session_destroy();
                    header("Location: index.php?account_deleted=1");
                    exit();
                    
                } catch (PDOException $e) {
                    // Rollback transaction on error
                    $conn->rollBack();
                    error_log("Staff account deletion error: " . $e->getMessage());
                    $error = "Failed to delete account. Please try again or contact support.";
                }
            } else {
                $error = "Please type 'DELETE' in the confirmation field to delete your account.";
            }
        }
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
        'notif_message' => 'Welcome to CTU-PESO Password Management! Keep your account secure.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Security reminder notification
    $default_notifications[] = [
        'notif_message' => 'Regularly update your password to maintain account security.',
        'notif_type' => 'security_reminder',
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
        case 'password_change':
            return 'fas fa-key';
        case 'security_reminder':
            return 'fas fa-shield-alt';
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
    } elseif (strpos($type, 'password') !== false || strpos($message, 'password') !== false || strpos($message, 'security') !== false) {
        return 'staff_changepass.php';
    } else {
        return 'staff_dashboard.php';
    }
}

// Get profile photo path or use default
$profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($user['usr_name']) . "&background=6e0303&color=fff";
if (!empty($user['usr_profile_photo']) && file_exists($user['usr_profile_photo'])) {
    $profile_photo = $user['usr_profile_photo'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Change Password</title>
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
    
    /* Enhanced Alert Styles */
    .alert {
      padding: 15px 20px;
      border-radius: 8px;
      margin-bottom: 25px;
      border-left: 4px solid transparent;
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
      display: flex;
      align-items: center;
      gap: 12px;
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
    
    /* Enhanced Password Strength Styles */
    .password-strength {
      height: 6px;
      background: #e0e0e0;
      border-radius: 3px;
      margin-top: 8px;
      overflow: hidden;
      box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
    }
    
    .password-strength-bar {
      height: 100%;
      border-radius: 3px;
      transition: width 0.3s, background-color 0.3s;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }
    
    .password-requirements {
      margin-top: 15px;
      padding: 15px;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      border-radius: 8px;
      border-left: 4px solid var(--primary-color);
    }
    
    .password-requirements p {
      font-weight: 600;
      color: var(--primary-color);
      margin-bottom: 10px;
      font-size: 0.9rem;
    }
    
    .password-requirements ul {
      margin-top: 5px;
      padding-left: 20px;
    }
    
    .password-requirements li {
      margin-bottom: 5px;
      font-size: 0.85rem;
      transition: color 0.3s;
    }
    
    .requirement-met {
      color: var(--green);
      font-weight: 600;
    }
    
    .requirement-met::before {
      content: '✓ ';
      font-weight: bold;
    }
    
    .requirement-not-met {
      color: #999;
    }
    
    .requirement-not-met::before {
      content: '○ ';
    }
    
    /* Danger Zone Styles */
    .danger-zone {
      margin-top: 40px;
      padding: 25px;
      background: linear-gradient(135deg, #fff5f5, #fed7d7);
      border-radius: 12px;
      border-left: 4px solid var(--red);
    }
    
    .danger-zone h3 {
      color: var(--red);
      margin-bottom: 15px;
      font-size: 1.2rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .danger-zone p {
      margin-bottom: 20px;
      color: #7a1c1c;
      font-size: 0.95rem;
    }
    
    .btn-danger {
      background: linear-gradient(135deg, var(--red), #e74c3c);
      color: white;
      box-shadow: 0 4px 15px rgba(211, 47, 47, 0.3);
    }
    
    .btn-danger:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(211, 47, 47, 0.4);
    }
    
    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0, 0, 0, 0.5);
    }
    
    .modal-content {
      background-color: white;
      margin: 10% auto;
      padding: 20px;
      border-radius: 8px;
      width: 80%;
      max-width: 500px;
      position: relative;
    }
    
    .close-modal {
      position: absolute;
      top: 15px;
      right: 15px;
      font-size: 1.5rem;
      cursor: pointer;
      color: #aaa;
    }
    
    .close-modal:hover {
      color: #000;
    }
    
    .modal-title {
      margin-bottom: 20px;
      color: var(--primary-color);
    }
    
    .confirmation-text {
      color: var(--red);
      font-weight: bold;
      margin: 10px 0;
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
      .stats-grid {
        grid-template-columns: 1fr 1fr;
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
      
      .stats-grid {
        grid-template-columns: 1fr;
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
      <p>Manage your account security and password settings. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Account Settings</h1>
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
    
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Change Password</h3>
        <i class="fas fa-lock card-icon"></i>
      </div>
      
      <form method="POST" action="">
        <div class="form-group">
          <label class="form-label" for="current_password">
            <i class="fas fa-key"></i> Current Password *
          </label>
          <input type="password" id="current_password" name="current_password" class="form-control" required placeholder="Enter your current password">
        </div>
        
        <div class="form-group">
          <label class="form-label" for="new_password">
            <i class="fas fa-lock"></i> New Password *
          </label>
          <input type="password" id="new_password" name="new_password" class="form-control" required placeholder="Enter your new password">
          <div class="password-strength">
            <div class="password-strength-bar" id="passwordStrengthBar"></div>
          </div>
          <div class="password-requirements">
            <p>Password must meet the following requirements:</p>
            <ul>
              <li id="lengthRequirement" class="requirement-not-met">At least 8 characters</li>
              <li id="uppercaseRequirement" class="requirement-not-met">One uppercase letter</li>
              <li id="lowercaseRequirement" class="requirement-not-met">One lowercase letter</li>
              <li id="numberRequirement" class="requirement-not-met">One number</li>
              <li id="specialRequirement" class="requirement-not-met">One special character</li>
            </ul>
          </div>
        </div>
        
        <div class="form-group">
          <label class="form-label" for="confirm_password">
            <i class="fas fa-lock"></i> Confirm New Password *
          </label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="Confirm your new password">
        </div>
        
        <button type="submit" name="change_password" class="btn btn-primary">
          <i class="fas fa-save"></i> Change Password
        </button>
      </form>
    </div>

    <!-- Danger Zone -->
    <div class="danger-zone">
      <h3>
        <i class="fas fa-exclamation-triangle"></i> Danger Zone
      </h3>
      <p>Once you delete your account, there is no going back. Please be certain.</p>
      <button type="button" class="btn btn-danger" onclick="showDeleteModal()">
        <i class="fas fa-trash-alt"></i> Delete Account
      </button>
    </div>
  </div>

  <!-- Delete Account Modal -->
  <div id="deleteModal" class="modal">
    <div class="modal-content">
      <span class="close-modal" onclick="closeDeleteModal()">&times;</span>
      <h2 class="modal-title">Delete Account</h2>
      
      <div style="margin-bottom: 20px;">
        <p>Are you sure you want to delete your account? This action cannot be undone.</p>
        <p class="confirmation-text">All your data, including profile information and notifications will be permanently deleted.</p>
      </div>
      
      <form id="deleteForm" action="staff_changepass.php" method="POST">
        <div class="form-group">
          <label class="form-label" for="confirm_delete">
            Type <strong>DELETE</strong> to confirm:
          </label>
          <input type="text" id="confirm_delete" name="confirm_delete" class="form-control" 
                 placeholder="Type DELETE here" required>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 20px;">
          <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
          <button type="submit" name="delete_account" class="btn btn-danger">Delete My Account</button>
        </div>
      </form>
    </div>
  </div>

  <script>
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
            url: 'staff_changepass.php',
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
    
    // Password strength checker
    const passwordInput = document.getElementById('new_password');
    const strengthBar = document.getElementById('passwordStrengthBar');
    const lengthRequirement = document.getElementById('lengthRequirement');
    const uppercaseRequirement = document.getElementById('uppercaseRequirement');
    const lowercaseRequirement = document.getElementById('lowercaseRequirement');
    const numberRequirement = document.getElementById('numberRequirement');
    const specialRequirement = document.getElementById('specialRequirement');
    
    passwordInput.addEventListener('input', function() {
      const password = this.value;
      let strength = 0;
      let requirementsMet = 0;
      const totalRequirements = 5;
      
      // Check length
      if (password.length >= 8) {
        strength += 20;
        requirementsMet++;
        lengthRequirement.className = 'requirement-met';
      } else {
        lengthRequirement.className = 'requirement-not-met';
      }
      
      // Check uppercase
      if (/[A-Z]/.test(password)) {
        strength += 20;
        requirementsMet++;
        uppercaseRequirement.className = 'requirement-met';
      } else {
        uppercaseRequirement.className = 'requirement-not-met';
      }
      
      // Check lowercase
      if (/[a-z]/.test(password)) {
        strength += 20;
        requirementsMet++;
        lowercaseRequirement.className = 'requirement-met';
      } else {
        lowercaseRequirement.className = 'requirement-not-met';
      }
      
      // Check number
      if (/[0-9]/.test(password)) {
        strength += 20;
        requirementsMet++;
        numberRequirement.className = 'requirement-met';
      } else {
        numberRequirement.className = 'requirement-not-met';
      }
      
      // Check special characters
      if (/[^\w]/.test(password)) {
        strength += 20;
        requirementsMet++;
        specialRequirement.className = 'requirement-met';
      } else {
        specialRequirement.className = 'requirement-not-met';
      }
      
      // Update strength bar
      strengthBar.style.width = strength + '%';
      
      if (strength < 60) {
        strengthBar.style.backgroundColor = '#e74c3c';
      } else if (strength < 100) {
        strengthBar.style.backgroundColor = '#f39c12';
      } else {
        strengthBar.style.backgroundColor = '#2ecc71';
      }
      
      // Add visual feedback for all requirements met
      if (requirementsMet === totalRequirements) {
        strengthBar.style.boxShadow = '0 0 10px rgba(46, 204, 113, 0.5)';
      } else {
        strengthBar.style.boxShadow = 'none';
      }
    });
    
    // Delete Account Modal Functions
    const deleteModal = document.getElementById('deleteModal');
    
    function showDeleteModal() {
      deleteModal.style.display = 'block';
    }
    
    function closeDeleteModal() {
      deleteModal.style.display = 'none';
      // Reset the confirmation input
      document.getElementById('confirm_delete').value = '';
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
      if (event.target == deleteModal) {
        closeDeleteModal();
      }
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