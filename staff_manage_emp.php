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
    
    // Handle sending messages to employers
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
        $employer_company = $_POST['company_name'];
        $contact_person = $_POST['contact_person'];
        $message_type = $_POST['message_type'];
        $message_content = $_POST['message_content'];
        $staff_id = $_SESSION['user_id'];
        
        try {
            // Get employer user ID and approval status
            $employer_stmt = $conn->prepare("
                SELECT e.emp_usr_id, u.usr_is_approved, u.usr_account_status 
                FROM employers e 
                JOIN users u ON e.emp_usr_id = u.usr_id
                WHERE e.emp_company_name = :company_name 
                AND e.emp_contact_person = :contact_person
            ");
            $employer_stmt->bindParam(':company_name', $employer_company);
            $employer_stmt->bindParam(':contact_person', $contact_person);
            $employer_stmt->execute();
            $employer = $employer_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($employer) {
                // Check if employer is approved and active
                if (!$employer['usr_is_approved'] || $employer['usr_account_status'] !== 'active') {
                    $_SESSION['error_message'] = "Cannot send message to pending or inactive employer: " . $employer_company;
                    header("Location: staff_manage_emp.php");
                    exit();
                }
                
                // Determine resource type based on message type
                $resource_type = 'career_advice'; // default
                $resource_title = "Message from CTU-PESO Staff";
                
                switch ($message_type) {
                    case 'job_update':
                        $resource_type = 'career_advice';
                        $resource_title = "Job Posting Update";
                        break;
                    case 'partnership_renewal':
                        $resource_type = 'skill_development';
                        $resource_title = "Partnership Renewal";
                        break;
                    case 'job_fair':
                        $resource_type = 'interview_guide';
                        $resource_title = "Job Fair Invitation";
                        break;
                    case 'follow_up':
                        $resource_type = 'career_advice';
                        $resource_title = "Follow-up Message";
                        break;
                }
                
                // Insert into shared_resources table
                $insert_stmt = $conn->prepare("
                    INSERT INTO shared_resources (
                        grad_usr_id, 
                        staff_usr_id, 
                        resource_type, 
                        resource_title, 
                        resource_description, 
                        resource_url,
                        is_read,
                        shared_at
                    ) VALUES (
                        :employer_id, 
                        :staff_id, 
                        :resource_type, 
                        :title, 
                        :description, 
                        :url,
                        0,
                        NOW()
                    )
                ");
                
                $description = $message_content;
                $url = ""; // No URL for simple messages
                
                $insert_stmt->bindParam(':employer_id', $employer['emp_usr_id']);
                $insert_stmt->bindParam(':staff_id', $staff_id);
                $insert_stmt->bindParam(':resource_type', $resource_type);
                $insert_stmt->bindParam(':title', $resource_title);
                $insert_stmt->bindParam(':description', $description);
                $insert_stmt->bindParam(':url', $url);
                $insert_stmt->execute();
                
                // Also create a notification for the employer
                $notification_message = "New message from CTU-PESO staff: " . $resource_title;
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                    VALUES (:employer_id, :message, 'staff_message', 0, NOW())
                ");
                $notif_stmt->bindParam(':employer_id', $employer['emp_usr_id']);
                $notif_stmt->bindParam(':message', $notification_message);
                $notif_stmt->execute();
                
                $_SESSION['success_message'] = "Message sent successfully to " . $employer_company;
            } else {
                $_SESSION['error_message'] = "Employer not found";
            }
            
            header("Location: staff_manage_emp.php");
            exit();
            
        } catch (PDOException $e) {
            error_log("Error sending message: " . $e->getMessage());
            $_SESSION['error_message'] = "Error sending message. Please try again.";
            header("Location: staff_manage_emp.php");
            exit();
        }
    }

    // Handle employer approval/rejection
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['approve_employer'])) {
            $user_id = $_POST['user_id'];
            
            try {
                $stmt = $conn->prepare("UPDATE users SET usr_is_approved = TRUE, usr_account_status = 'active' WHERE usr_id = :id");
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();
                
                // Add notification
                $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your employer account has been approved by CTU-PESO staff', 'employer_approval')");
                $notif_stmt->bindParam(':user_id', $user_id);
                $notif_stmt->execute();
                
                $_SESSION['success_message'] = "Employer approved successfully!";
                header("Location: staff_manage_emp.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error approving employer: " . $e->getMessage();
                header("Location: staff_manage_emp.php");
                exit();
            }
        } 
        elseif (isset($_POST['reject_employer'])) {
            $user_id = $_POST['user_id'];
            
            try {
                // Get employer documents before deleting
                $emp_stmt = $conn->prepare("SELECT emp_business_permit, emp_dti_sec FROM employers WHERE emp_usr_id = :id");
                $emp_stmt->bindParam(':id', $user_id);
                $emp_stmt->execute();
                $employer_docs = $emp_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Delete files from server
                if ($employer_docs) {
                    if (!empty($employer_docs['emp_business_permit']) && file_exists($employer_docs['emp_business_permit'])) {
                        unlink($employer_docs['emp_business_permit']);
                    }
                    if (!empty($employer_docs['emp_dti_sec']) && file_exists($employer_docs['emp_dti_sec'])) {
                        unlink($employer_docs['emp_dti_sec']);
                    }
                }
                
                // Delete employer record
                $emp_del_stmt = $conn->prepare("DELETE FROM employers WHERE emp_usr_id = :id");
                $emp_del_stmt->bindParam(':id', $user_id);
                $emp_del_stmt->execute();
                
                // Delete user record
                $user_del_stmt = $conn->prepare("DELETE FROM users WHERE usr_id = :id");
                $user_del_stmt->bindParam(':id', $user_id);
                $user_del_stmt->execute();
                
                $_SESSION['success_message'] = "Employer rejected and removed from system!";
                header("Location: staff_manage_emp.php");
                exit();
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error rejecting employer: " . $e->getMessage();
                header("Location: staff_manage_emp.php");
                exit();
            }
        }
    }

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
    
    // Get employer data for management
    $employers_stmt = $conn->prepare("
        SELECT e.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_account_status, u.usr_is_approved
        FROM employers e
        JOIN users u ON e.emp_usr_id = u.usr_id
        ORDER BY u.usr_created_at DESC
    ");
    $employers_stmt->execute();
    $employers = $employers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
            header("Location: staff_manage_emp.php");
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
    
    // Get employer details for view modal if requested
    $view_employer = null;
    if (isset($_GET['view_id'])) {
        $view_id = $_GET['view_id'];
        $view_stmt = $conn->prepare("
            SELECT e.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_account_status, u.usr_is_approved, u.usr_created_at
            FROM employers e
            JOIN users u ON e.emp_usr_id = u.usr_id
            WHERE u.usr_id = :id
        ");
        $view_stmt->bindParam(':id', $view_id);
        $view_stmt->execute();
        $view_employer = $view_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
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
  <title>CTU-PESO - Manage Employer Partnerships</title>
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
    
    /* Enhanced Employer Statistics */
    .employer-stats {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    
        .stat-card {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
      padding: 25px;
      transition: all 0.3s;
      border-top: 4px solid var(--secondary-color);
      position: relative;
      overflow: hidden;
      text-align: center;
    }
    
    .stat-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    
    .stat-icon {
      font-size: 2.5rem;
      color: var(--secondary-color);
      margin-bottom: 15px;
    }
    
    .stat-value {
      font-size: 2.2rem;
      font-weight: 700;
      color: var(--primary-color);
      margin-bottom: 5px;
      line-height: 1;
    }
    
    .stat-label {
      font-size: 1rem;
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
    .employer-table {
      width: 100%;
      border-collapse: collapse;
      background: white;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 20px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .employer-table thead {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
    }
    
    .employer-table th {
      padding: 15px;
      text-align: left;
      font-weight: 500;
      font-size: 0.9rem;
    }
    
    .employer-table tbody tr {
      transition: all 0.3s;
    }
    
    .employer-table tbody tr:hover {
      background-color: #f8f9fa;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
    }
    
    .employer-table td {
      padding: 12px 15px;
      border-bottom: 1px solid #eee;
      font-size: 0.9rem;
    }
    
    .employer-table tr:last-child td {
      border-bottom: none;
    }
    
    .status-badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 500;
    }
    
    .status-active {
      background-color: #e8f5e9;
      color: #2e7d32;
    }
    
    .status-inactive {
      background-color: #ffebee;
      color: #c62828;
    }
    
    .status-pending {
      background-color: #fff8e1;
      color: #f57f17;
    }
    
    .action-buttons {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
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
      margin: 2px;
    }
    
    .message-btn {
      background: var(--blue);
      color: white;
    }
    
    .message-btn:hover {
      background: #0033cc;
      transform: translateY(-2px);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .message-btn:disabled {
      background: #cccccc;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }
    
    .view-btn {
      background: var(--primary-color);
      color: white;
    }
    
    .view-btn:hover {
      background: #8a0404;
      transform: translateY(-2px);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .approve-btn {
      background: var(--green);
      color: white;
    }
    
    .approve-btn:hover {
      background: #16680f;
      transform: translateY(-2px);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .reject-btn {
      background: var(--red);
      color: white;
    }
    
    .reject-btn:hover {
      background: #b71c1c;
      transform: translateY(-2px);
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    /* Enhanced Modal Styles */
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
      width: 500px;
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
      padding: 20px 25px;
      border-radius: 12px 12px 0 0;
      position: relative;
    }
    
    .modal-header h2 {
      color: white;
      margin: 0;
      font-size: 1.4rem;
      font-weight: 600;
      padding-right: 40px;
    }
    
    .close-modal {
      position: absolute;
      top: 20px;
      right: 25px;
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
    
    .modal-body {
      padding: 25px;
    }
    
    textarea {
      width: 100%;
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 6px;
      resize: vertical;
      min-height: 100px;
      font-family: inherit;
      margin-bottom: 15px;
      transition: border-color 0.3s;
    }
    
    textarea:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
    }
    
    .submit-btn {
      background: var(--primary-color);
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s;
      width: 100%;
    }
    
    .submit-btn:hover {
      background: #8a0404;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    .template-select {
      width: 100%;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      margin-bottom: 15px;
      font-size: 0.9rem;
    }
    
    /* Success and Error Messages */
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
    
    /* Enhanced Employer View Modal Styles */
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
    
    .modal-large {
      background-color: white;
      border-radius: 12px;
      width: 95%;
      max-width: 1000px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
      transform: translateY(-50px);
      transition: transform 0.3s ease;
    }
    
    .modal-overlay.active .modal-large {
      transform: translateY(0);
    }
    
    .modal-header-large {
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
    
    .modal-title-large {
      font-size: 1.8rem;
      color: white;
      font-weight: 600;
    }
    
    .modal-close-large {
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
    
    .modal-close-large:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: rotate(90deg);
    }
    
    .modal-body-large {
      padding: 30px;
    }
    
    .employer-details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
      margin-bottom: 25px;
    }
    
    .detail-group {
      margin-bottom: 20px;
      display: flex;
      flex-direction: column;
    }
    
    .detail-label {
      font-weight: 600;
      color: var(--primary-color);
      margin-bottom: 8px;
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
      font-size: 1rem;
      color: #555;
      line-height: 1.6;
    }
    
    .documents-section {
      margin-top: 30px;
      padding-top: 25px;
      border-top: 1px solid #eee;
    }
    
    .documents-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
    }
    
    .document-card {
      border: 1px solid #eee;
      border-radius: 10px;
      padding: 20px;
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
      transition: all 0.3s;
    }
    
    .document-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.12);
    }
    
    .document-title {
      font-weight: 600;
      margin-bottom: 15px;
      color: var(--primary-color);
      font-size: 1.1rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .document-title i {
      color: var(--secondary-color);
    }
    
    .document-preview {
      margin-top: 15px;
      border: 1px solid #ddd;
      border-radius: 8px;
      overflow: hidden;
      height: 200px;
      display: flex;
      align-items: center;
      justify-content: center;
      background-color: white;
      cursor: pointer;
      transition: all 0.3s;
    }
    
    .document-preview:hover {
      border-color: var(--primary-color);
    }
    
    .document-preview img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
      transition: transform 0.3s ease;
    }
    
    .document-preview:hover img {
      transform: scale(1.05);
    }
    
    .document-preview .file-icon {
      font-size: 3rem;
      color: #b0b0b0;
      transition: all 0.3s;
    }
    
    .document-preview:hover .file-icon {
      color: var(--primary-color);
      transform: scale(1.1);
    }
    
    .document-actions {
      margin-top: 15px;
      display: flex;
      gap: 10px;
    }
    
    .btn-download {
      padding: 10px 18px;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      font-size: 0.85rem;
      font-weight: 500;
      transition: all 0.3s;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      min-width: 120px;
      height: 40px;
      box-sizing: border-box;
    }
    
    .btn-download:hover {
      background: linear-gradient(135deg, #8a0404, #6e0303);
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }
    
    .no-document {
      color: #999;
      font-style: italic;
      text-align: center;
      padding: 20px;
      background: rgba(255, 255, 255, 0.5);
      border-radius: 8px;
      border: 1px dashed #ddd;
    }
    
    .validation-actions {
      margin-top: 30px;
      padding-top: 25px;
      border-top: 1px solid #eee;
      display: flex;
      gap: 15px;
      justify-content: flex-end;
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .employer-stats {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      }
    }
    
    @media (max-width: 900px) {
      .employer-stats {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
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
      
      .employer-table {
        display: block;
        overflow-x: auto;
      }
      
      .dropdown {
        width: 90%;
        right: 5%;
      }
      
      .modal-content {
        width: 95%;
        padding: 0;
      }
      
      .employer-stats {
        grid-template-columns: 1fr;
      }
      
      .modal-header {
        padding: 15px 20px;
      }
      
      .modal-header h2 {
        padding-right: 35px;
        font-size: 1.3rem;
      }
      
      .close-modal {
        top: 15px;
        right: 20px;
        width: 28px;
        height: 28px;
        font-size: 20px;
      }
      
      .modal-body {
        padding: 15px;
      }
      
      .employer-details {
        grid-template-columns: 1fr;
      }
      
      .documents-grid {
        grid-template-columns: 1fr;
      }
      
      .validation-actions {
        flex-direction: column;
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
          <a href="staff_manage_emp.php" class="active">
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
      <p>Manage employer partnerships and maintain strong relationships with hiring organizations. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Manage Employer Partnerships</h1>
    </div>

    <!-- Success and Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="success-message">
      <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="error-message">
      <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error_message'] ?>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Employer Statistics -->
    <div class="employer-stats">
      <?php
      // Calculate statistics - FIXED: Now includes all statuses properly
      $total_employers = count($employers);
      $active_employers = 0;
      $pending_employers = 0;
      $inactive_employers = 0;
      
      foreach ($employers as $employer) {
        if ($employer['usr_is_approved'] && $employer['usr_account_status'] === 'active') {
          $active_employers++;
        } elseif (!$employer['usr_is_approved']) {
          $pending_employers++;
        } else {
          $inactive_employers++;
        }
      }
      ?>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-building"></i>
        </div>
        <div class="stat-value"><?= $total_employers ?></div>
        <div class="stat-label">Total Employers</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-value"><?= $active_employers ?></div>
        <div class="stat-label">Active Partners</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-value"><?= $pending_employers ?></div>
        <div class="stat-label">Pending Approval</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-pause-circle"></i>
        </div>
        <div class="stat-value"><?= $inactive_employers ?></div>
        <div class="stat-label">Inactive</div>
      </div>
    </div>

    <!-- Employer Relationship Table -->
    <div class="table-container">
      <div class="table-header">
        <h2 class="table-title">All Employer Partnerships</h2>
        <div class="table-controls">
          <select class="filter-select" id="statusFilter">
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="pending">Pending</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <table class="employer-table">
        <thead>
          <tr>
            <th>Company Name</th>
            <th>Contact Person</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="employerTableBody">
          <?php if (!empty($employers)): ?>
            <?php foreach ($employers as $employer): 
              // Determine status - FIXED: Consistent with statistics
              $status_class = 'status-pending';
              $status_text = 'Pending';
              
              if ($employer['usr_is_approved'] && $employer['usr_account_status'] === 'active') {
                $status_class = 'status-active';
                $status_text = 'Active';
              } elseif ($employer['usr_account_status'] === 'inactive' || $employer['usr_account_status'] === 'suspended') {
                $status_class = 'status-inactive';
                $status_text = 'Inactive';
              }
              
              // Check if employer is approved and active for action buttons
              $is_approved_active = $employer['usr_is_approved'] && $employer['usr_account_status'] === 'active';
            ?>
            <tr data-status="<?= strtolower($status_text) ?>">
              <td><?= htmlspecialchars($employer['emp_company_name']) ?></td>
              <td><?= htmlspecialchars($employer['emp_contact_person']) ?></td>
              <td><?= htmlspecialchars($employer['usr_email']) ?></td>
              <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
              <td>
                <div class="action-buttons">
                  <button class="action-btn view-btn" 
                          onclick="viewEmployer(<?= $employer['emp_usr_id'] ?>)">
                    <i class="fas fa-eye"></i> View
                  </button>
                  <button class="action-btn message-btn" 
                          onclick="showMessageModal('<?= htmlspecialchars($employer['emp_company_name']) ?>', '<?= htmlspecialchars($employer['emp_contact_person']) ?>')"
                          <?= !$is_approved_active ? 'disabled title="Cannot send messages to pending or inactive employers"' : '' ?>>
                    <i class="fas fa-envelope"></i> Message
                  </button>
                  <?php if (!$employer['usr_is_approved'] && $employer['usr_account_status'] === 'active'): ?>
                  <button class="action-btn approve-btn" onclick="approveEmployer(<?= $employer['emp_usr_id'] ?>)">
                    <i class="fas fa-check"></i> Approve
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" style="text-align: center; padding: 20px;">No employers found</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Message Modal -->
  <div class="modal" id="messageModal">
    <div class="modal-content">
      <form method="POST" action="">
        <div class="modal-header">
          <h2>Send Message to <span id="companyName"></span></h2>
          <span class="close-modal" onclick="closeModal('messageModal')">&times;</span>
        </div>
        <div class="modal-body">
          <p style="margin-bottom: 15px; color: #666;">Contact: <strong id="contactPerson"></strong></p>
          <input type="hidden" name="company_name" id="messageCompanyNameInput">
          <input type="hidden" name="contact_person" id="messageContactPersonInput">
          <select class="template-select" id="messageTemplate" name="message_type">
            <option value="">Select a message template</option>
            <option value="job_update">Job Posting Update Reminder</option>
            <option value="partnership_renewal">Partnership Renewal Inquiry</option>
            <option value="job_fair">Job Fair Invitation</option>
            <option value="follow_up">Follow-up on Previous Discussion</option>
          </select>
          <textarea id="messageContent" name="message_content" placeholder="Compose your message..." rows="6"></textarea>
          <button type="submit" name="send_message" class="submit-btn">Send Message</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Enhanced Employer View Modal -->
  <?php if ($view_employer): ?>
  <div class="modal-overlay active" id="employerModal">
    <div class="modal-large">
      <div class="modal-header-large">
        <h2 class="modal-title-large">Employer Details - <?= htmlspecialchars($view_employer['emp_company_name']) ?></h2>
        <button class="modal-close-large" onclick="closeViewModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="modal-body-large">
        <div class="employer-details">
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-building"></i>
              Company Name
            </span>
            <div class="detail-value"><?= htmlspecialchars($view_employer['emp_company_name']) ?></div>
          </div>
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-industry"></i>
              Industry
            </span>
            <div class="detail-value"><?= htmlspecialchars($view_employer['emp_industry']) ?></div>
          </div>
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-user-tie"></i>
              Contact Person
            </span>
            <div class="detail-value"><?= htmlspecialchars($view_employer['emp_contact_person']) ?></div>
          </div>
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-envelope"></i>
              Email
            </span>
            <div class="detail-value"><?= htmlspecialchars($view_employer['usr_email']) ?></div>
          </div>
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-phone"></i>
              Phone
            </span>
            <div class="detail-value"><?= htmlspecialchars($view_employer['usr_phone']) ?></div>
          </div>
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-info-circle"></i>
              Status
            </span>
            <div class="detail-value">
              <?php
              $status_class = 'status-pending';
              $status_text = 'Pending';
              
              if ($view_employer['usr_is_approved'] && $view_employer['usr_account_status'] === 'active') {
                $status_class = 'status-active';
                $status_text = 'Active';
              } elseif ($view_employer['usr_account_status'] === 'inactive' || $view_employer['usr_account_status'] === 'suspended') {
                $status_class = 'status-inactive';
                $status_text = 'Inactive';
              }
              ?>
              <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
            </div>
          </div>
          <div class="detail-group">
            <span class="detail-label">
              <i class="fas fa-calendar"></i>
              Registered Date
            </span>
            <div class="detail-value"><?= date('F j, Y', strtotime($view_employer['usr_created_at'])) ?></div>
          </div>
        </div>
        
        <?php if (!empty($view_employer['emp_company_description'])): ?>
        <div class="detail-group">
          <span class="detail-label">
            <i class="fas fa-file-alt"></i>
            Company Description
          </span>
          <div class="detail-value"><?= htmlspecialchars($view_employer['emp_company_description']) ?></div>
        </div>
        <?php endif; ?>
        
        <div class="documents-section">
          <h3 style="color: var(--primary-color); margin-bottom: 20px; font-size: 1.3rem;">
            <i class="fas fa-file-contract"></i> Uploaded Documents
          </h3>
          <div class="documents-grid">
            <div class="document-card">
              <div class="document-title">
                <i class="fas fa-file-invoice"></i> Business Permit
              </div>
              <?php if (!empty($view_employer['emp_business_permit'])): ?>
                <div class="document-preview" onclick="viewDocument('<?= htmlspecialchars($view_employer['emp_business_permit']) ?>', 'Business Permit')">
                  <?php
                  $file_ext = pathinfo($view_employer['emp_business_permit'], PATHINFO_EXTENSION);
                  if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                    <img src="<?= htmlspecialchars($view_employer['emp_business_permit']) ?>" alt="Business Permit">
                  <?php else: ?>
                    <div class="file-icon">
                      <i class="fas fa-file-pdf"></i>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="no-document">No business permit uploaded</div>
              <?php endif; ?>
            </div>
            
            <div class="document-card">
              <div class="document-title">
                <i class="fas fa-certificate"></i> DTI/SEC Certificate
              </div>
              <?php if (!empty($view_employer['emp_dti_sec'])): ?>
                <div class="document-preview" onclick="viewDocument('<?= htmlspecialchars($view_employer['emp_dti_sec']) ?>', 'DTI/SEC Certificate')">
                  <?php
                  $file_ext = pathinfo($view_employer['emp_dti_sec'], PATHINFO_EXTENSION);
                  if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                    <img src="<?= htmlspecialchars($view_employer['emp_dti_sec']) ?>" alt="DTI/SEC Certificate">
                  <?php else: ?>
                    <div class="file-icon">
                      <i class="fas fa-file-pdf"></i>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="no-document">No DTI/SEC certificate uploaded</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Validation Actions -->
        <?php if (!$view_employer['usr_is_approved'] && $view_employer['usr_account_status'] === 'active'): ?>
        <div class="validation-actions">
          <form method="POST" style="display: inline;">
            <input type="hidden" name="user_id" value="<?= $view_employer['emp_usr_id'] ?>">
            <button type="submit" name="reject_employer" class="action-btn reject-btn" onclick="return confirm('Are you sure you want to reject this employer? This action cannot be undone.')">
              <i class="fas fa-times"></i> Reject Employer
            </button>
          </form>
          <form method="POST" style="display: inline;">
            <input type="hidden" name="user_id" value="<?= $view_employer['emp_usr_id'] ?>">
            <button type="submit" name="approve_employer" class="action-btn approve-btn">
              <i class="fas fa-check"></i> Approve Employer
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

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
            url: 'staff_manage_emp.php',
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
        const rows = $('#employerTableBody tr');
        
        if (status === 'all') {
          rows.show();
        } else {
          rows.hide();
          rows.filter('[data-status="' + status + '"]').show();
        }
      });
      
      // Pre-fill message templates
      const messageTemplates = {
        job_update: "Dear {contact},\n\nWe hope this message finds you well. We wanted to kindly remind you to update your job postings on our platform to ensure our alumni have access to your latest opportunities.\n\nThank you for your continued partnership.\n\nBest regards,\nCTU-PESO Team",
        partnership_renewal: "Dear {contact},\n\nAs we approach the end of our current partnership agreement, we would like to discuss renewing our collaboration for the upcoming year. Your organization has been a valuable partner in helping our graduates launch their careers.\n\nPlease let us know a convenient time to discuss this further.\n\nBest regards,\nCTU-PESO Team",
        job_fair: "Dear {contact},\n\nWe are excited to invite you to our upcoming Career Fair on [date]. This event provides an excellent opportunity to connect with our talented alumni and promote your organization.\n\nPlease let us know if you would like to participate.\n\nBest regards,\nCTU-PESO Team",
        follow_up: "Dear {contact},\n\nFollowing up on our previous discussion about [topic], we would like to [purpose of follow-up].\n\nWe value your feedback and look forward to continuing our collaboration.\n\nBest regards,\nCTU-PESO Team"
      };
      
      // Update message content when template is selected
      document.getElementById('messageTemplate').addEventListener('change', function() {
        const template = this.value;
        const contact = document.getElementById('contactPerson').textContent;
        if (template && messageTemplates[template]) {
          document.getElementById('messageContent').value = 
            messageTemplates[template].replace('{contact}', contact);
        }
      });
      
      // Add hover effects to cards
      $('.stat-card').hover(
        function() {
          $(this).css('transform', 'translateY(-5px)');
        },
        function() {
          $(this).css('transform', 'translateY(0)');
        }
      );
    });
    
    // Close modal
    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
      if (modalId === 'messageModal') {
        document.getElementById('messageTemplate').selectedIndex = 0;
        document.getElementById('messageContent').value = '';
      }
    }
    
    // Close view modal
    function closeViewModal() {
      const url = new URL(window.location.href);
      url.searchParams.delete('view_id');
      window.location.href = url.toString();
    }
    
    // Close modal if clicked outside
    window.onclick = function(event) {
      const modals = document.getElementsByClassName('modal');
      for (let i = 0; i < modals.length; i++) {
        if (event.target == modals[i]) {
          modals[i].style.display = 'none';
          if (modals[i].id === 'messageModal') {
            document.getElementById('messageTemplate').selectedIndex = 0;
            document.getElementById('messageContent').value = '';
          }
        }
      }
      
      // Close view modal if clicked outside
      const viewModal = document.getElementById('employerModal');
      if (viewModal && event.target === viewModal) {
        closeViewModal();
      }
    }
    
    // Show message modal
    function showMessageModal(company, contact) {
      document.getElementById('companyName').textContent = company;
      document.getElementById('contactPerson').textContent = contact;
      document.getElementById('messageCompanyNameInput').value = company;
      document.getElementById('messageContactPersonInput').value = contact;
      document.getElementById('messageModal').style.display = 'flex';
    }
    
    // View employer details
    function viewEmployer(employerId) {
      const url = new URL(window.location.href);
      url.searchParams.set('view_id', employerId);
      window.location.href = url.toString();
    }
    
    // Approve employer
    function approveEmployer(employerId) {
      if (confirm('Are you sure you want to approve this employer?')) {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'user_id';
        input.value = employerId;
        
        const approveInput = document.createElement('input');
        approveInput.type = 'hidden';
        approveInput.name = 'approve_employer';
        approveInput.value = '1';
        
        form.appendChild(input);
        form.appendChild(approveInput);
        document.body.appendChild(form);
        form.submit();
      }
    }
    
    // View document (placeholder function - implement as needed)
    function viewDocument(documentPath, documentTitle) {
      // This would open the document in a new tab or modal
      window.open(documentPath, '_blank');
    }
  </script>
</body>
</html>