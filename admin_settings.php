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
    
    // Check if admin exists, if not create one
    $adminCheck = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'admin'")->fetchColumn();
    if ($adminCheck == 0) {
        $adminPassword = password_hash('admin123', PASSWORD_BCRYPT);
        $conn->exec("INSERT INTO users (usr_name, usr_email, usr_password, usr_role, usr_gender, usr_birthdate, usr_is_approved, usr_account_status) 
                    VALUES ('Admin User', 'admin@ctu.edu.ph', '$adminPassword', 'admin', 'Male', '1980-01-01', TRUE, 'active')");
    }
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Check if user is logged in as admin
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// ============================================================================
// ENHANCED NOTIFICATION GENERATION SYSTEM - FIXED AND IMPROVED
// ============================================================================

/**
 * Function to create notifications for admin
 */
function createAdminNotification($conn, $type, $message, $related_id = null) {
    // Get all admin users
    $adminStmt = $conn->query("SELECT usr_id FROM users WHERE usr_role = 'admin'");
    $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($admins as $adminId) {
        $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                               VALUES (?, ?, ?, NOW())");
        $stmt->execute([$adminId, $message, $type]);
    }
}

/**
 * Check for new user registrations and generate notifications - FIXED VERSION
 */
function checkNewUserRegistrations($conn) {
    // Get the last notification time for user registrations
    $lastNotifStmt = $conn->query("SELECT MAX(notif_created_at) FROM notifications WHERE notif_type = 'new_user_registration'");
    $lastNotif = $lastNotifStmt->fetchColumn();
    
    $newUsers = [];
    
    // Build query based on whether we have previous notifications
    if ($lastNotif) {
        $sql = "SELECT usr_id, usr_name, usr_role, usr_email, usr_created_at
                FROM users 
                WHERE usr_created_at > ? 
                AND usr_role != 'admin'
                AND usr_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) -- Limit to last 7 days to avoid old users
                ORDER BY usr_created_at DESC";
        $newUsersStmt = $conn->prepare($sql);
        $newUsersStmt->execute([$lastNotif]);
        $newUsers = $newUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // If no previous notifications, check last 24 hours
        $newUsers = $conn->query("
            SELECT usr_id, usr_name, usr_role, usr_email, usr_created_at
            FROM users 
            WHERE usr_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND usr_role != 'admin'
            ORDER BY usr_created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (!empty($newUsers)) {
        // Create notification for each new user
        foreach ($newUsers as $user) {
            $message = "New {$user['usr_role']} registered: {$user['usr_name']} ({$user['usr_email']})";
            
            // Insert notification for all admins
            $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                                   SELECT usr_id, ?, 'new_user_registration', NOW()
                                   FROM users WHERE usr_role = 'admin'");
            $stmt->execute([$message]);
        }
        
        // Create summary notification
        $userCounts = [];
        foreach ($newUsers as $user) {
            $role = $user['usr_role'];
            $userCounts[$role] = ($userCounts[$role] ?? 0) + 1;
        }
        
        $summaryParts = [];
        foreach ($userCounts as $role => $count) {
            $summaryParts[] = "$count $role" . ($count > 1 ? "s" : "");
        }
        
        $summaryMessage = "New user registrations: " . implode(", ", $summaryParts);
        createAdminNotification($conn, 'new_user_summary', $summaryMessage);
        
        return count($newUsers);
    }
    
    return 0;
}

/**
 * Check for pending approvals and generate notifications - ENHANCED VERSION
 */
function checkPendingApprovals($conn) {
    $notificationsGenerated = 0;
    
    // 1. Check for pending employer approvals
    $pendingEmployers = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role = 'employer' AND usr_is_approved = FALSE")->fetch(PDO::FETCH_ASSOC);
    
    if ($pendingEmployers['count'] > 0) {
        // Check if notification already exists for today
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'employer_approval' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'employer_approval', 
                "{$pendingEmployers['count']} employer(s) awaiting approval. Review pending registrations.");
            $notificationsGenerated++;
        }
    }
    
    // 2. Check for pending job approvals
    $pendingJobs = $conn->query("SELECT COUNT(*) as count FROM jobs WHERE job_status = 'pending'")->fetch(PDO::FETCH_ASSOC);
    
    if ($pendingJobs['count'] > 0) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'job_approval' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'job_approval', 
                "{$pendingJobs['count']} job posting(s) awaiting approval. Review pending job posts.");
            $notificationsGenerated++;
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Check for profile issues and generate notifications - ENHANCED VERSION
 */
function checkProfileIssues($conn) {
    // Check for incomplete graduate profiles
    $incompleteProfiles = $conn->query("
        SELECT COUNT(*) as count FROM users u 
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id 
        WHERE u.usr_role = 'graduate' 
        AND (g.grad_id IS NULL OR g.grad_school_id = '' OR g.grad_degree = '' OR g.grad_job_preference = '')
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($incompleteProfiles['count'] > 0) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'profile_issue' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'profile_issue', 
                "{$incompleteProfiles['count']} graduate profile(s) are incomplete. Review portfolio issues.");
            return 1;
        }
    }
    
    return 0;
}

/**
 * Check for application trends and generate notifications - ENHANCED VERSION
 */
function checkApplicationTrends($conn) {
    // Check for application spikes (last hour)
    $recentApplications = $conn->query("
        SELECT COUNT(*) as count FROM applications 
        WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($recentApplications['count'] >= 5) { // Lowered threshold for testing
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'application_spike' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'application_spike', 
                "Application spike: {$recentApplications['count']} applications in the last hour.");
            return 1;
        }
    }
    
    // Check for new applications since last check
    $lastAppNotif = $conn->query("SELECT MAX(notif_created_at) FROM notifications WHERE notif_type = 'new_application'")->fetchColumn();
    
    if ($lastAppNotif) {
        $newApplications = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at > '$lastAppNotif'
        ")->fetch(PDO::FETCH_ASSOC);
    } else {
        $newApplications = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($newApplications['count'] > 0) {
        createAdminNotification($conn, 'new_application', 
            "{$newApplications['count']} new application(s) submitted.");
        return 1;
    }
    
    return 0;
}

/**
 * Check for system analytics and generate notifications - FIXED VERSION
 */
function checkSystemAnalytics($conn) {
    $notificationsGenerated = 0;
    
    // Check employment rate trends - FIXED QUERY
    $employmentRate = $conn->query("
        SELECT 
            COUNT(DISTINCT g.grad_usr_id) as total_graduates,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_graduates
        FROM graduates g
        LEFT JOIN applications a ON g.grad_usr_id = a.app_grad_usr_id
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($employmentRate['total_graduates'] > 0) {
        $employmentPercentage = round(($employmentRate['hired_graduates'] / $employmentRate['total_graduates']) * 100);
        
        if ($employmentPercentage >= 70) {
            $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                            WHERE notif_type = 'employment_success' 
                                            AND DATE(notif_created_at) = CURDATE()");
            $existingNotif->execute();
            
            if ($existingNotif->fetchColumn() == 0) {
                createAdminNotification($conn, 'employment_success', 
                    "Great news! Employment rate is at {$employmentPercentage}% among graduates.");
                $notificationsGenerated++;
            }
        } elseif ($employmentPercentage <= 30) {
            $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                            WHERE notif_type = 'employment_low' 
                                            AND DATE(notif_created_at) = CURDATE()");
            $existingNotif->execute();
            
            if ($existingNotif->fetchColumn() == 0) {
                createAdminNotification($conn, 'employment_low', 
                    "Employment rate is low at {$employmentPercentage}%. Consider career support initiatives.");
                $notificationsGenerated++;
            }
        }
    }
    
    // Check top skills in demand - FIXED QUERY (using graduate_skills table instead of non-existent job_skills)
    $topSkills = $conn->query("
        SELECT s.skill_name, COUNT(gs.skill_id) as demand_count
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        GROUP BY s.skill_name
        ORDER BY demand_count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($topSkills && $topSkills['demand_count'] >= 3) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'skill_demand' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'skill_demand', 
                "High demand for '{$topSkills['skill_name']}' skill ({$topSkills['demand_count']} graduates have this skill).");
            $notificationsGenerated++;
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Check for system settings notifications (ONLY when settings are actually changed)
 */
function checkSystemSettingsNotifications($conn, $old_settings, $new_settings) {
    $changed_settings = [];
    
    // Compare old and new settings to only notify on actual changes
    if ($old_settings['auto_approve_users'] != $new_settings['auto_approve_users']) {
        $changed_settings[] = "Auto-approve users: " . ($new_settings['auto_approve_users'] ? "Enabled" : "Disabled");
    }
    if ($old_settings['auto_approve_jobs'] != $new_settings['auto_approve_jobs']) {
        $changed_settings[] = "Auto-approve jobs: " . ($new_settings['auto_approve_jobs'] ? "Enabled" : "Disabled");
    }
    if ($old_settings['enable_staff_accounts'] != $new_settings['enable_staff_accounts']) {
        $changed_settings[] = "Staff accounts: " . ($new_settings['enable_staff_accounts'] ? "Enabled" : "Disabled");
    }
    if ($old_settings['maintenance_mode'] != $new_settings['maintenance_mode']) {
        $changed_settings[] = "Maintenance mode: " . ($new_settings['maintenance_mode'] ? "Enabled" : "Disabled");
    }
    
    // Only create notification if there are actual changes
    if (!empty($changed_settings)) {
        createAdminNotification($conn, 'system_settings', 
            "System settings updated: " . implode(", ", $changed_settings));
    }
    
    return !empty($changed_settings);
}

/**
 * Enhanced system notifications generator - MAIN FUNCTION
 */
function generateSystemNotifications($conn) {
    $totalNotifications = 0;
    
    // 1. Check for new user registrations
    $totalNotifications += checkNewUserRegistrations($conn);
    
    // 2. Check for pending approvals
    $totalNotifications += checkPendingApprovals($conn);
    
    // 3. Check for profile issues
    $totalNotifications += checkProfileIssues($conn);
    
    // 4. Check for application trends
    $totalNotifications += checkApplicationTrends($conn);
    
    // 5. Check for system analytics
    $totalNotifications += checkSystemAnalytics($conn);
    
    return $totalNotifications;
}

// Generate notifications on every page load
$notificationsGenerated = generateSystemNotifications($conn);

// ============================================================================
// ADMIN SETTINGS FUNCTIONALITY
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_settings.php");
    exit();
}

// Handle mark single notification as read via AJAX
if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
    $notif_id = $_POST['notif_id'];
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':notif_id', $notif_id);
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Return success response
    echo json_encode(['success' => true]);
    exit();
}

// Get admin user details
$admin_id = $_SESSION['user_id'];
$admin_stmt = $conn->prepare("SELECT * FROM users WHERE usr_id = :id");
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_user = $admin_stmt->fetch(PDO::FETCH_ASSOC);

// Get profile photo path or use default
$admin_profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($admin_user['usr_name']) . "&background=3498db&color=fff";
if (!empty($admin_user['usr_profile_photo']) && file_exists("uploads/profile_photos/" . $admin_user['usr_profile_photo'])) {
    $admin_profile_photo = "uploads/profile_photos/" . $admin_user['usr_profile_photo'];
}

// Handle password change
$password_success = false;
$password_error = false;
$password_error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    if (!password_verify($current_password, $admin_user['usr_password'])) {
        $password_error = true;
        $password_error_msg = 'Current password is incorrect';
    } 
    // Check if new password matches confirmation
    elseif ($new_password !== $confirm_password) {
        $password_error = true;
        $password_error_msg = 'New passwords do not match';
    }
    // Check password strength
    elseif (strlen($new_password) < 8) {
        $password_error = true;
        $password_error_msg = 'Password must be at least 8 characters long';
    }
    // Update password
    else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $update_stmt = $conn->prepare("UPDATE users SET usr_password = :password WHERE usr_id = :id");
        $update_stmt->bindParam(':password', $hashed_password);
        $update_stmt->bindParam(':id', $admin_id);
        
        if ($update_stmt->execute()) {
            $password_success = true;
            // Create notification for password change
            createAdminNotification($conn, 'security', 
                "Admin password was changed successfully.");
        } else {
            $password_error = true;
            $password_error_msg = 'Error updating password. Please try again.';
        }
    }
}

// Handle system settings update
$settings_success = false;
$settings_error = false;

// Get current settings first to compare changes
$auto_approve_users_setting = 0;
$auto_approve_jobs_setting = 0;
$enable_staff_accounts_setting = 0;
$maintenance_mode_setting = 0;

// Store old settings for comparison
$old_settings = [];

try {
    $settings_stmt = $conn->prepare("SELECT setting_name, setting_value FROM system_settings");
    $settings_stmt->execute();
    $system_settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (isset($system_settings['auto_approve_users'])) {
        $auto_approve_users_setting = intval($system_settings['auto_approve_users']);
        $old_settings['auto_approve_users'] = $auto_approve_users_setting;
    } else {
        $old_settings['auto_approve_users'] = 0;
    }
    
    if (isset($system_settings['auto_approve_jobs'])) {
        $auto_approve_jobs_setting = intval($system_settings['auto_approve_jobs']);
        $old_settings['auto_approve_jobs'] = $auto_approve_jobs_setting;
    } else {
        $old_settings['auto_approve_jobs'] = 0;
    }
    
    if (isset($system_settings['enable_staff_accounts'])) {
        $enable_staff_accounts_setting = intval($system_settings['enable_staff_accounts']);
        $old_settings['enable_staff_accounts'] = $enable_staff_accounts_setting;
    } else {
        $old_settings['enable_staff_accounts'] = 0;
    }
    
    if (isset($system_settings['maintenance_mode'])) {
        $maintenance_mode_setting = intval($system_settings['maintenance_mode']);
        $old_settings['maintenance_mode'] = $maintenance_mode_setting;
    } else {
        $old_settings['maintenance_mode'] = 0;
    }
} catch (PDOException $e) {
    // If table doesn't exist yet, use default values
    $old_settings = [
        'auto_approve_users' => 0,
        'auto_approve_jobs' => 0,
        'enable_staff_accounts' => 0,
        'maintenance_mode' => 0
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    // Get the settings
    $auto_approve_users = isset($_POST['auto_approve_users']) ? 1 : 0;
    $auto_approve_jobs = isset($_POST['auto_approve_jobs']) ? 1 : 0;
    $enable_staff_accounts = isset($_POST['enable_staff_accounts']) ? 1 : 0;
    $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
    
    // New settings for comparison
    $new_settings = [
        'auto_approve_users' => $auto_approve_users,
        'auto_approve_jobs' => $auto_approve_jobs,
        'enable_staff_accounts' => $enable_staff_accounts,
        'maintenance_mode' => $maintenance_mode
    ];
    
    // Update the settings in the database
    try {
        // Check if settings table exists, if not create it
        $tableCheck = $conn->query("SHOW TABLES LIKE 'system_settings'")->fetchColumn();
        if (!$tableCheck) {
            $conn->exec("CREATE TABLE system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_name VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        }
        
        // Settings to update
        $settings = [
            'auto_approve_users' => $auto_approve_users,
            'auto_approve_jobs' => $auto_approve_jobs,
            'enable_staff_accounts' => $enable_staff_accounts,
            'maintenance_mode' => $maintenance_mode
        ];
        
        foreach ($settings as $name => $value) {
            // Check if setting exists
            $check_stmt = $conn->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_name = :name");
            $check_stmt->bindParam(':name', $name);
            $check_stmt->execute();
            $exists = $check_stmt->fetchColumn();
            
            if ($exists) {
                $update_setting_stmt = $conn->prepare("UPDATE system_settings SET setting_value = :value WHERE setting_name = :name");
            } else {
                $update_setting_stmt = $conn->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES (:name, :value)");
            }
            
            $update_setting_stmt->bindParam(':name', $name);
            $update_setting_stmt->bindParam(':value', $value);
            $update_setting_stmt->execute();
        }
        
        $settings_success = true;
        
        // Only create notifications if settings actually changed
        $settings_changed = checkSystemSettingsNotifications($conn, $old_settings, $new_settings);
        
        // Update current settings variables
        $auto_approve_users_setting = $auto_approve_users;
        $auto_approve_jobs_setting = $auto_approve_jobs;
        $enable_staff_accounts_setting = $enable_staff_accounts;
        $maintenance_mode_setting = $maintenance_mode;
        
    } catch (PDOException $e) {
        $settings_error = true;
        error_log("Error updating settings: " . $e->getMessage());
    }
}

// Function to determine where a notification should link to based on its content
function getNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'system_settings') !== false || 
        strpos($type, 'system_alert') !== false || 
        strpos($message, 'setting') !== false || 
        strpos($message, 'maintenance') !== false || 
        strpos($message, 'auto-approve') !== false ||
        strpos($message, 'password') !== false) {
        return 'admin_settings.php';
    } elseif (strpos($type, 'user') !== false || strpos($message, 'user') !== false || strpos($message, 'registered') !== false) {
        return 'admin_manage_users.php';
    } elseif (strpos($type, 'job') !== false || strpos($message, 'job') !== false || strpos($message, 'posting') !== false) {
        return 'admin_job_post.php';
    } elseif (strpos($type, 'employer') !== false || strpos($message, 'employer') !== false) {
        return 'admin_employers.php';
    } elseif (strpos($type, 'portfolio') !== false || strpos($message, 'portfolio') !== false || strpos($type, 'profile') !== false) {
        return 'admin_portfolio.php';
    } elseif (strpos($type, 'report') !== false || strpos($message, 'report') !== false || strpos($type, 'analytics') !== false) {
        return 'admin_reports.php';
    } elseif (strpos($type, 'application') !== false || strpos($message, 'application') !== false) {
        return 'admin_job_post.php';
    } elseif (strpos($type, 'skill') !== false || strpos($message, 'skill') !== false) {
        return 'admin_reports.php';
    } elseif (strpos($type, 'employment') !== false || strpos($message, 'employment') !== false) {
        return 'admin_reports.php';
    } else {
        return 'admin_dashboard.php';
    }
}

// Function to get notification icon based on type
function getNotificationIcon($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'user') !== false) {
        return 'fas fa-user-plus';
    } elseif (strpos($type, 'job') !== false) {
        return 'fas fa-briefcase';
    } elseif (strpos($type, 'employer') !== false) {
        return 'fas fa-building';
    } elseif (strpos($type, 'application') !== false) {
        return 'fas fa-file-alt';
    } elseif (strpos($type, 'portfolio') !== false || strpos($type, 'profile') !== false) {
        return 'fas fa-file-contract';
    } elseif (strpos($type, 'system') !== false || strpos($type, 'security') !== false || strpos($type, 'setting') !== false) {
        return 'fas fa-cog';
    } elseif (strpos($type, 'skill') !== false) {
        return 'fas fa-tools';
    } elseif (strpos($type, 'employment') !== false) {
        return 'fas fa-chart-line';
    } else {
        return 'fas fa-bell';
    }
}

// Function to get notification priority based on type
function getNotificationPriority($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'system') !== false || 
        strpos($type, 'security') !== false || 
        strpos($type, 'spike') !== false ||
        strpos($type, 'alert') !== false) {
        return 'high';
    } elseif (strpos($type, 'approval') !== false || 
              strpos($type, 'pending') !== false ||
              strpos($type, 'issue') !== false) {
        return 'medium';
    } else {
        return 'low';
    }
}

// Get notification count for admin
$notif_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE notif_usr_id = :admin_id AND notif_is_read = FALSE");
$notif_stmt->bindParam(':admin_id', $admin_id);
$notif_stmt->execute();
$notification_count = $notif_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

// Get recent notifications for admin
$recent_notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE notif_usr_id = :admin_id ORDER BY notif_created_at DESC LIMIT 10");
$recent_notif_stmt->bindParam(':admin_id', $admin_id);
$recent_notif_stmt->execute();
$recent_notifications = $recent_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Admin Settings</title>
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
        
        .admin-role {
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
        
        .notification, .admin-profile {
            position: relative;
            margin-left: 20px;
            cursor: pointer;
        }
        
        .notification i, .admin-profile i {
            font-size: 1.3rem;
            color: var(--primary-color);
            transition: all 0.3s;
        }
        
        .notification:hover i, .admin-profile:hover i {
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
        
        .admin-profile {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 30px;
            transition: all 0.3s;
            background: rgba(110, 3, 3, 0.05);
        }
        
        .admin-profile:hover {
            background: rgba(110, 3, 3, 0.1);
        }
        
        .admin-profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        .admin-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-right: 8px;
        }
        
        .admin-profile i.fa-chevron-down {
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
        
        /* Settings Content */
        .settings-container {
            display: flex;
            flex-direction: column;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .settings-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .settings-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .settings-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .settings-header h2 {
            color: var(--primary-color);
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        .settings-header p {
            color: #666;
            margin-top: 8px;
            font-size: 0.95rem;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 42px;
            cursor: pointer;
            color: #777;
            transition: color 0.3s;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
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
            gap: 8px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #8a0404;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 30px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--primary-color);
        }
        
        input:checked + .slider:before {
            transform: translateX(30px);
        }
        
        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .toggle-container:hover {
            background-color: #f0f0f0;
        }
        
        .toggle-info {
            flex: 1;
        }
        
        .toggle-info h4 {
            margin-bottom: 5px;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .toggle-info p {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.4;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }
            
            .sidebar-header h3, .sidebar-menu span, .admin-role, .menu-label {
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
                width: 300px;
                right: -100px;
            }
            
            .settings-card {
                padding: 20px;
            }
            
            .toggle-container {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .toggle-switch {
                align-self: flex-end;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .settings-card {
                padding: 15px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .page-title {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= $admin_profile_photo ?>" alt="Admin">
            <div class="sidebar-header-text">
                <h3><?= htmlspecialchars($admin_user['usr_name']) ?></h3>
                <div class="admin-role">System Administrator</div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <ul>
                <li>
                    <a href="admin_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="admin_manage_users.php">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li>
                    <a href="admin_job_post.php">
                        <i class="fas fa-briefcase"></i>
                        <span>Job Posts</span>
                    </a>
                </li>
                <li>
                    <a href="admin_employers.php">
                        <i class="fas fa-building"></i>
                        <span>Employers</span>
                    </a>
                </li>
                <li>
                    <a href="admin_portfolio.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Portfolio</span>
                    </a>
                </li>
            </ul>
            
            <div class="menu-label">System</div>
            <ul>
                <li>
                    <a href="admin_reports.php">
                        <i class="fas fa-file-pdf"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li>
                    <a href="admin_system.php">
                        <i class="fas fa-cog"></i>
                        <span>System</span>
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
                    <?php if ($notification_count > 0): ?>
                    <span class="notification-count" id="notificationCount"><?= $notification_count ?></span>
                    <?php endif; ?>
                    <div class="dropdown notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            Notifications
                            <?php if ($notification_count > 0): ?>
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="mark_all_read" class="mark-all-read">Mark all as read</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($recent_notifications)): ?>
                            <?php foreach ($recent_notifications as $notif): 
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
                <div class="admin-profile" id="adminProfile">
                    <img src="<?= $admin_profile_photo ?>" alt="Admin">
                    <span class="admin-name"><?= htmlspecialchars($admin_user['usr_name']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                    <div class="dropdown profile-dropdown" id="profileDropdown">
                        <a href="admin_profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="admin_settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <a href="index.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?= htmlspecialchars($admin_user['usr_name']) ?>!</h2>
            <p>Manage your account settings and system preferences. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Admin Settings</h1>
        </div>
        
        <!-- Settings Content -->
        <div class="settings-container">
            <!-- Password Change Card -->
            <div class="settings-card">
                <div class="settings-header">
                    <h2>Change Password</h2>
                    <p>Update your account password for enhanced security</p>
                </div>
                
                <?php if ($password_success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Password updated successfully!
                </div>
                <?php endif; ?>
                
                <?php if ($password_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= $password_error_msg ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                        <span class="password-toggle" onclick="togglePassword('current_password')">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required>
                        <span class="password-toggle" onclick="togglePassword('new_password')">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        <span class="password-toggle" onclick="togglePassword('confirm_password')">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>
            
            <!-- System Settings Card -->
            <div class="settings-card">
                <div class="settings-header">
                    <h2>System Settings</h2>
                    <p>Configure system-wide preferences and behaviors</p>
                </div>
                
                <?php if ($settings_success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Settings updated successfully!
                </div>
                <?php endif; ?>
                
                <?php if ($settings_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> Error updating settings. Please try again.
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="toggle-container">
                        <div class="toggle-info">
                            <h4>Auto-approve User Registrations</h4>
                            <p>Automatically approve new user registrations (all user types)</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="auto_approve_users" <?= $auto_approve_users_setting ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="toggle-container">
                        <div class="toggle-info">
                            <h4>Auto-approve Job Postings</h4>
                            <p>Automatically approve new job postings from employers</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="auto_approve_jobs" <?= $auto_approve_jobs_setting ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="toggle-container">
                        <div class="toggle-info">
                            <h4>Enable Creating Staff Account</h4>
                            <p>Allow staff to create their own accounts during registration</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_staff_accounts" <?= $enable_staff_accounts_setting ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="toggle-container">
                        <div class="toggle-info">
                            <h4>Maintenance Mode</h4>
                            <p>Take the system offline for maintenance</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode" <?= $maintenance_mode_setting ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <button type="submit" name="update_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Common Dropdown Functionality
        const notification = document.getElementById('notification');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const adminProfile = document.getElementById('adminProfile');
        const profileDropdown = document.getElementById('profileDropdown');
        
        notification.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('active');
            profileDropdown.classList.remove('active');
        });
        
        adminProfile.addEventListener('click', function(e) {
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
                        url: 'admin_settings.php',
                        type: 'POST',
                        data: {
                            mark_as_read: true,
                            notif_id: notifId
                        },
                        success: function(response) {
                            // Update UI
                            notificationItem.removeClass('unread');
                            
                            // Update notification count
                            const currentCount = parseInt($('#notificationCount').text());
                            if (currentCount > 1) {
                                $('#notificationCount').text(currentCount - 1);
                            } else {
                                $('#notificationCount').remove();
                            }
                        },
                        error: function() {
                            console.log('Error marking notification as read');
                        }
                    });
                }
            });
        });
        
        // Password visibility toggle
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>