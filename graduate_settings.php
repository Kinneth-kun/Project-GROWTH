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
        SELECT g.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, u.usr_gender, u.usr_birthdate,
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

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['change_password'])) {
        // Change password
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Verify current password
        $check_stmt = $conn->prepare("SELECT usr_password FROM users WHERE usr_id = :graduate_id");
        $check_stmt->bindParam(':graduate_id', $graduate_id);
        $check_stmt->execute();
        $user = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($current_password, $user['usr_password'])) {
            if ($new_password === $confirm_password) {
                // Validate password requirements
                $password_errors = validatePassword($new_password);
                
                if (empty($password_errors)) {
                    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    
                    $update_stmt = $conn->prepare("
                        UPDATE users 
                        SET usr_password = :password,
                            usr_updated_at = NOW()
                        WHERE usr_id = :graduate_id
                    ");
                    
                    $update_stmt->bindParam(':password', $password_hash);
                    $update_stmt->bindParam(':graduate_id', $graduate_id);
                    
                    if ($update_stmt->execute()) {
                        $success = "Password changed successfully!";
                        
                        // Create notification for password change
                        createGraduateNotification($conn, $graduate_id, 'security', 'Your password has been changed successfully.');
                    } else {
                        $error = "Failed to change password.";
                    }
                } else {
                    $error = implode('<br>', $password_errors);
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
                
                // 1. Delete portfolio items
                $delete_portfolio = $conn->prepare("DELETE FROM portfolio_items WHERE port_usr_id = :graduate_id");
                $delete_portfolio->bindParam(':graduate_id', $graduate_id);
                $delete_portfolio->execute();
                
                // 2. Delete applications
                $delete_applications = $conn->prepare("DELETE FROM applications WHERE app_grad_usr_id = :graduate_id");
                $delete_applications->bindParam(':graduate_id', $graduate_id);
                $delete_applications->execute();
                
                // 3. Delete notifications
                $delete_notifications = $conn->prepare("DELETE FROM notifications WHERE notif_usr_id = :graduate_id");
                $delete_notifications->bindParam(':graduate_id', $graduate_id);
                $delete_notifications->execute();
                
                // 4. Delete shared resources
                $delete_resources = $conn->prepare("DELETE FROM shared_resources WHERE grad_usr_id = :graduate_id");
                $delete_resources->bindParam(':graduate_id', $graduate_id);
                $delete_resources->execute();
                
                // 5. Delete profile views
                $delete_views = $conn->prepare("DELETE FROM employer_profile_views WHERE view_grad_usr_id = :graduate_id");
                $delete_views->bindParam(':graduate_id', $graduate_id);
                $delete_views->execute();
                
                // 6. Delete graduate record
                $delete_graduate = $conn->prepare("DELETE FROM graduates WHERE grad_usr_id = :graduate_id");
                $delete_graduate->bindParam(':graduate_id', $graduate_id);
                $delete_graduate->execute();
                
                // 7. Delete user record
                $delete_user = $conn->prepare("DELETE FROM users WHERE usr_id = :graduate_id");
                $delete_user->bindParam(':graduate_id', $graduate_id);
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
                error_log("Account deletion error: " . $e->getMessage());
                $error = "Failed to delete account. Please try again or contact support.";
            }
        } else {
            $error = "Please type 'DELETE' in the confirmation field to delete your account.";
        }
    }
}

} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
}

/**
 * Validate password against requirements
 */
function validatePassword($password) {
    $errors = [];
    
    // Check minimum length
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    
    // Check for uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    
    // Check for lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    
    // Check for number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    
    return $errors;
}

/**
 * Generate default notifications based on user data and activities
 */
function generateDefaultNotifications($conn, $graduate_id, $graduate, $app_stats, $portfolio_stats) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Account Settings! Manage your security preferences here.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Security notifications
    $default_notifications[] = [
        'notif_message' => 'Keep your account secure by regularly updating your password.',
        'notif_type' => 'security',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
    ];
    
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
        header("Location: graduate_settings.php");
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
        case 'security':
            return 'fas fa-shield-alt';
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
        strpos($type, 'qualified') !== false ||
        strpos($type, 'security') !== false) {
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
    } elseif (strpos($type, 'security') !== false || strpos($message, 'password') !== false || strpos($message, 'account') !== false) {
        return 'graduate_settings.php';
    } else {
        return 'graduate_dashboard.php';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Alumni Settings</title>
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
        
        /* Settings Content Styles */
        .settings-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }
        
        .settings-card {
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
        
        .settings-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .settings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .settings-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .settings-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #fff5e6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
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
        
        .btn-danger {
            background-color: var(--red);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .btn:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            accent-color: var(--blue);
        }
        
        .checkbox-group label {
            font-weight: 500;
            cursor: pointer;
        }
        
        .radio-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .radio-group input[type="radio"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            accent-color: var(--blue);
        }
        
        .radio-group label {
            font-weight: 500;
            cursor: pointer;
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
        
        .password-strength {
            height: 5px;
            background: #e0e0e0;
            border-radius: 3px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s, background-color 0.3s;
        }
        
        .password-requirements {
            margin-top: 10px;
            font-size: 0.9rem;
            color: #666;
        }
        
        .password-requirements ul {
            margin-top: 5px;
            padding-left: 20px;
        }
        
        .password-requirements li {
            margin-bottom: 3px;
        }
        
        .requirement-met {
            color: var(--green);
        }
        
        .requirement-not-met {
            color: #999;
        }
        
        .requirement-met::before {
            content: "✓ ";
            font-weight: bold;
        }
        
        .requirement-not-met::before {
            content: "✗ ";
            font-weight: bold;
        }
        
        .danger-zone {
            border-left: 4px solid var(--red);
            padding-left: 15px;
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
        
        /* Responsive Design */
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
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .settings-card {
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
            <p>Manage your account settings and security preferences. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>
        
        <h1 class="page-title">Account Settings</h1>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="settings-container">
            
            <!-- Change Password -->
            <div class="settings-card">
                <div class="settings-header">
                    <h3 class="settings-title">Change Password</h3>
                    <div class="settings-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                </div>
                
                <form action="graduate_settings.php" method="POST" id="passwordForm">
                    <div class="form-group">
                        <label class="form-label" for="current_password">Current Password *</label>
                        <input type="password" id="current_password" name="current_password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" class="form-input" required>
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
                            </ul>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                        <div id="passwordMatchMessage" style="margin-top: 5px; font-size: 0.9rem;"></div>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn btn-primary" id="submitPasswordBtn" disabled>Change Password</button>
                </form>
            </div>
            
            <!-- Danger Zone -->
            <div class="settings-card">
                <div class="settings-header">
                    <h3 class="settings-title">Danger Zone</h3>
                    <div class="settings-icon">
                        <i class="fas fa-exclamation-triangle" style="color: var(--red);"></i>
                    </div>
                </div>
                
                <div class="danger-zone">
                    <p style="margin-bottom: 15px;">Once you delete your account, there is no going back. Please be certain.</p>
                    <button type="button" class="btn btn-danger" onclick="showDeleteModal()">Delete Account</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeDeleteModal()">&times;</span>
            <h2 class="modal-title">Delete Account</h2>
            
            <div style="margin-bottom: 20px;">
                <p>Are you sure you want to delete your account? This action cannot be undone.</p>
                <p class="confirmation-text">All your data, including applications, portfolio, and profile information will be permanently deleted.</p>
            </div>
            
            <form id="deleteForm" action="graduate_settings.php" method="POST">
                <div class="form-group">
                    <label class="form-label" for="confirm_delete">
                        Type <strong>DELETE</strong> to confirm:
                    </label>
                    <input type="text" id="confirm_delete" name="confirm_delete" class="form-input" 
                           placeholder="Type DELETE here" required>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn" onclick="closeDeleteModal()" 
                            style="background-color: #6c757d; color: white;">Cancel</button>
                    <button type="submit" name="delete_account" class="btn btn-danger">Delete My Account</button>
                </div>
            </form>
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
                        url: 'graduate_settings.php',
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
            $('.settings-card').hover(
                function() {
                    $(this).css('transform', 'translateY(-5px)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                }
            );
        });
        
        // Password validation and strength checker
        const passwordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('passwordStrengthBar');
        const lengthRequirement = document.getElementById('lengthRequirement');
        const uppercaseRequirement = document.getElementById('uppercaseRequirement');
        const lowercaseRequirement = document.getElementById('lowercaseRequirement');
        const numberRequirement = document.getElementById('numberRequirement');
        const passwordMatchMessage = document.getElementById('passwordMatchMessage');
        const submitPasswordBtn = document.getElementById('submitPasswordBtn');
        
        let passwordValid = false;
        let passwordsMatch = false;
        
        function validatePasswordRequirements(password) {
            let isValid = true;
            
            // Check length
            if (password.length >= 8) {
                lengthRequirement.className = 'requirement-met';
            } else {
                lengthRequirement.className = 'requirement-not-met';
                isValid = false;
            }
            
            // Check uppercase
            if (/[A-Z]/.test(password)) {
                uppercaseRequirement.className = 'requirement-met';
            } else {
                uppercaseRequirement.className = 'requirement-not-met';
                isValid = false;
            }
            
            // Check lowercase
            if (/[a-z]/.test(password)) {
                lowercaseRequirement.className = 'requirement-met';
            } else {
                lowercaseRequirement.className = 'requirement-not-met';
                isValid = false;
            }
            
            // Check number
            if (/[0-9]/.test(password)) {
                numberRequirement.className = 'requirement-met';
            } else {
                numberRequirement.className = 'requirement-not-met';
                isValid = false;
            }
            
            return isValid;
        }
        
        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            
            if (password && confirmPassword) {
                if (password === confirmPassword) {
                    passwordMatchMessage.innerHTML = '<span style="color: var(--green);">✓ Passwords match</span>';
                    passwordsMatch = true;
                } else {
                    passwordMatchMessage.innerHTML = '<span style="color: var(--red);">✗ Passwords do not match</span>';
                    passwordsMatch = false;
                }
            } else {
                passwordMatchMessage.innerHTML = '';
                passwordsMatch = false;
            }
            
            updateSubmitButton();
        }
        
        function updateSubmitButton() {
            if (passwordValid && passwordsMatch) {
                submitPasswordBtn.disabled = false;
            } else {
                submitPasswordBtn.disabled = true;
            }
        }
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            
            // Validate password requirements
            passwordValid = validatePasswordRequirements(password);
            
            // Calculate strength
            let strength = 0;
            if (password.length >= 8) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[a-z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            
            // Update strength bar
            strengthBar.style.width = strength + '%';
            
            if (strength < 50) {
                strengthBar.style.backgroundColor = '#e74c3c';
            } else if (strength < 100) {
                strengthBar.style.backgroundColor = '#f39c12';
            } else {
                strengthBar.style.backgroundColor = '#2ecc71';
            }
            
            // Check password match
            checkPasswordMatch();
        });
        
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        
        // Form submission validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (!currentPassword) {
                e.preventDefault();
                alert('Please enter your current password.');
                return;
            }
            
            if (!passwordValid) {
                e.preventDefault();
                alert('Please ensure your new password meets all requirements.');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match.');
                return;
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