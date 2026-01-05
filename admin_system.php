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
// COMPREHENSIVE AUDIT LOGGING SYSTEM (No Schema Changes Required)
// ============================================================================

/**
 * Enhanced audit logging function using existing table structure
 */
function logAuditEvent($conn, $user_id, $activity_type, $details) {
    try {
        $stmt = $conn->prepare("INSERT INTO user_activities (activity_usr_id, activity_type, activity_details, activity_date) 
                               VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $activity_type, $details]);
        return true;
    } catch (Exception $e) {
        error_log("Audit logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log user authentication events
 */
function logAuthEvent($conn, $user_id, $event_type, $details = '') {
    $activity_type = 'authentication_' . $event_type;
    $details = "User ID: $user_id - " . $details;
    return logAuditEvent($conn, $user_id, $activity_type, $details);
}

/**
 * Log user management events
 */
function logUserManagementEvent($conn, $admin_id, $action, $target_user_id, $details = '') {
    $activity_type = 'user_management_' . $action;
    $details = "Target User ID: $target_user_id - " . $details;
    return logAuditEvent($conn, $admin_id, $activity_type, $details);
}

/**
 * Log job management events
 */
function logJobManagementEvent($conn, $admin_id, $action, $job_id, $details = '') {
    $activity_type = 'job_management_' . $action;
    $details = "Job ID: $job_id - " . $details;
    return logAuditEvent($conn, $admin_id, $activity_type, $details);
}

/**
 * Log system configuration events
 */
function logSystemEvent($conn, $admin_id, $event_type, $details = '') {
    $activity_type = 'system_' . $event_type;
    return logAuditEvent($conn, $admin_id, $activity_type, $details);
}

/**
 * Log report and export events
 */
function logReportEvent($conn, $admin_id, $report_type, $details = '') {
    $activity_type = 'report_' . $report_type;
    return logAuditEvent($conn, $admin_id, $activity_type, $details);
}

/**
 * Log security events
 */
function logSecurityEvent($conn, $user_id, $event_type, $details = '') {
    $activity_type = 'security_' . $event_type;
    $details = "User ID: $user_id - " . $details;
    return logAuditEvent($conn, $user_id, $activity_type, $details);
}

// ============================================================================
// ENHANCED NOTIFICATION GENERATION SYSTEM - UPDATED FOR EXISTING DB SCHEMA
// ============================================================================

/**
 * Function to create notifications for admin - UPDATED
 */
function createAdminNotification($conn, $type, $message, $related_id = null) {
    // Get all admin users
    $adminStmt = $conn->query("SELECT usr_id FROM users WHERE usr_role = 'admin'");
    $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($admins as $adminId) {
        // Use existing columns only
        $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                               VALUES (?, ?, ?, NOW())");
        $stmt->execute([$adminId, $message, $type]);
    }
    
    // Log notification creation
    logSystemEvent($conn, $adminId, 'notification_created', "Notification type: $type - $message");
}

/**
 * Check for new user registrations and generate notifications - UPDATED
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
                AND usr_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
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
            
            // Log new user registration detection
            logSystemEvent($conn, $_SESSION['user_id'], 'new_user_detected', "User ID: {$user['usr_id']} - {$user['usr_name']} ({$user['usr_email']}) - Role: {$user['usr_role']}");
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
 * Check for pending approvals and generate notifications - UPDATED
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
            
            // Log pending approvals detection
            logSystemEvent($conn, $_SESSION['user_id'], 'pending_approvals_detected', "{$pendingEmployers['count']} employers pending approval");
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
            
            // Log pending jobs detection
            logSystemEvent($conn, $_SESSION['user_id'], 'pending_jobs_detected', "{$pendingJobs['count']} jobs pending approval");
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Check for profile issues and generate notifications - SIMPLIFIED
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
                
            // Log profile issues detection
            logSystemEvent($conn, $_SESSION['user_id'], 'profile_issues_detected', "{$incompleteProfiles['count']} incomplete graduate profiles");
            return 1;
        }
    }
    
    return 0;
}

/**
 * Check for application trends and generate notifications - SIMPLIFIED
 */
function checkApplicationTrends($conn) {
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
            
        // Log new applications detection
        logSystemEvent($conn, $_SESSION['user_id'], 'new_applications_detected', "{$newApplications['count']} new applications");
        return 1;
    }
    
    return 0;
}

/**
 * Check for system analytics and generate notifications - SIMPLIFIED
 */
function checkSystemAnalytics($conn) {
    $notificationsGenerated = 0;
    
    // Check employment rate trends - SIMPLIFIED QUERY
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
                
                // Log employment success
                logSystemEvent($conn, $_SESSION['user_id'], 'employment_success', "Employment rate: {$employmentPercentage}%");
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
                
                // Log low employment rate
                logSystemEvent($conn, $_SESSION['user_id'], 'employment_low', "Employment rate: {$employmentPercentage}%");
            }
        }
    }
    
    // Check top skills in demand - SIMPLIFIED
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
            
            // Log skill demand
            logSystemEvent($conn, $_SESSION['user_id'], 'skill_demand_detected', "High demand for: {$topSkills['skill_name']} - {$topSkills['demand_count']} graduates");
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Check for system health and generate notifications - SIMPLIFIED
 */
function checkSystemHealth($conn) {
    $notificationsGenerated = 0;
    
    // Check for failed login attempts
    $failedLogins = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_failed_login_attempts > 0")->fetch(PDO::FETCH_ASSOC);
    
    if ($failedLogins['count'] >= 5) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'security_alert' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'security_alert', 
                "Security alert: {$failedLogins['count']} accounts with failed login attempts.");
            $notificationsGenerated++;
            
            // Log security alert
            logSecurityEvent($conn, $_SESSION['user_id'], 'failed_logins_alert', "{$failedLogins['count']} accounts with failed login attempts");
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Enhanced system notifications generator - MAIN FUNCTION (SIMPLIFIED)
 */
function generateSystemNotifications($conn) {
    $totalNotifications = 0;
    
    try {
        // Log notification generation start
        logSystemEvent($conn, $_SESSION['user_id'], 'notification_generation_started', 'System notification generation initiated');
        
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
        
        // 6. Check for system health
        $totalNotifications += checkSystemHealth($conn);
        
        // Log notification generation completion
        logSystemEvent($conn, $_SESSION['user_id'], 'notification_generation_completed', "Generated {$totalNotifications} notifications");
        
    } catch (Exception $e) {
        // Log error but don't break the page
        error_log("Notification generation error: " . $e->getMessage());
        logSystemEvent($conn, $_SESSION['user_id'], 'notification_generation_error', "Error: " . $e->getMessage());
    }
    
    return $totalNotifications;
}

// Generate notifications on every page load (with error handling)
try {
    $notificationsGenerated = generateSystemNotifications($conn);
} catch (Exception $e) {
    $notificationsGenerated = 0;
    error_log("System notification error: " . $e->getMessage());
}

// ============================================================================
// EXISTING SYSTEM PERFORMANCE FUNCTIONALITY - WITH COMPREHENSIVE AUDIT LOGGING
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    try {
        $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
        $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
        $mark_read_stmt->execute();
        
        // Log the action
        logSystemEvent($conn, $_SESSION['user_id'], 'notifications_marked_read', 'All notifications marked as read');
        
        // Refresh page to update notification count
        header("Location: admin_system.php");
        exit();
    } catch (Exception $e) {
        error_log("Mark all read error: " . $e->getMessage());
        logSystemEvent($conn, $_SESSION['user_id'], 'notification_mark_read_error', "Error: " . $e->getMessage());
    }
}

// Handle mark single notification as read via AJAX
if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
    try {
        $notif_id = $_POST['notif_id'];
        $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :admin_id");
        $mark_read_stmt->bindParam(':notif_id', $notif_id);
        $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
        $mark_read_stmt->execute();
        
        // Log the action
        logSystemEvent($conn, $_SESSION['user_id'], 'notification_marked_read', "Notification ID: $notif_id marked as read");
        
        // Return success response
        echo json_encode(['success' => true]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Handle data backup
if (isset($_POST['backup_database'])) {
    $backup_result = backupDatabase($conn);
    if ($backup_result['success']) {
        $_SESSION['success_message'] = "Database backup created successfully: " . $backup_result['filename'];
        // Create notification for backup
        createAdminNotification($conn, 'system_backup', 
            "Database backup created successfully: " . $backup_result['filename']);
    } else {
        $_SESSION['error_message'] = "Backup failed: " . $backup_result['error'];
        // Create notification for backup failure
        createAdminNotification($conn, 'system_alert', 
            "Database backup failed: " . $backup_result['error']);
    }
    header("Location: admin_system.php");
    exit();
}

// Handle audit log search
$audit_search = isset($_GET['audit_search']) ? $_GET['audit_search'] : '';

// Log page access
logSystemEvent($conn, $_SESSION['user_id'], 'page_accessed', 'Accessed System Performance Monitor page');

// Get admin user details
try {
    $admin_id = $_SESSION['user_id'];
    $admin_stmt = $conn->prepare("SELECT * FROM users WHERE usr_id = :id");
    $admin_stmt->bindParam(':id', $admin_id);
    $admin_stmt->execute();
    $admin_user = $admin_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin_user) {
        throw new Exception("Admin user not found");
    }

    // Get profile photo path or use default
    $profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($admin_user['usr_name']) . "&background=3498db&color=fff";
    if (!empty($admin_user['usr_profile_photo']) && file_exists("uploads/profile_photos/" . $admin_user['usr_profile_photo'])) {
        $profile_photo = "uploads/profile_photos/" . $admin_user['usr_profile_photo'];
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
} catch (Exception $e) {
    error_log("User data fetch error: " . $e->getMessage());
    $admin_user = ['usr_name' => 'Admin'];
    $profile_photo = "https://ui-avatars.com/api/?name=Admin&background=3498db&color=fff";
    $notification_count = 0;
    $recent_notifications = [];
}

// Function to determine where a notification should link to based on its content
function getNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'system_settings') !== false || 
        strpos($type, 'system_alert') !== false || 
        strpos($type, 'system_backup') !== false ||
        strpos($type, 'security_alert') !== false ||
        strpos($message, 'setting') !== false || 
        strpos($message, 'maintenance') !== false || 
        strpos($message, 'auto-approve') !== false ||
        strpos($message, 'backup') !== false ||
        strpos($message, 'disk space') !== false ||
        strpos($message, 'security') !== false) {
        return 'admin_system.php';
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
    } elseif (strpos($type, 'system') !== false || strpos($type, 'security') !== false || strpos($type, 'setting') !== false || strpos($type, 'backup') !== false) {
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

// Fetch system performance data with error handling
try {
    // Total users count
    $total_users_stmt = $conn->query("SELECT COUNT(*) as count FROM users");
    $total_users = $total_users_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Active users in last 24 hours
    $active_users_stmt = $conn->query("SELECT COUNT(DISTINCT usr_id) as count FROM users WHERE usr_last_login >= NOW() - INTERVAL 1 DAY");
    $active_users = $active_users_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Failed login attempts
    $failed_logins_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_failed_login_attempts > 0");
    $failed_logins = $failed_logins_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Pending approvals
    $pending_approvals_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_is_approved = FALSE AND usr_role != 'admin'");
    $pending_approvals = $pending_approvals_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Login frequency data (last 7 days)
    $login_frequency_stmt = $conn->query("
        SELECT 
            DATE(usr_last_login) as login_date,
            COUNT(*) as login_count
        FROM users 
        WHERE usr_last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(usr_last_login)
        ORDER BY login_date
    ");
    $login_frequency = $login_frequency_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Security alerts (failed login attempts)
    $security_alerts_stmt = $conn->query("
        SELECT 
            usr_name,
            usr_email,
            usr_failed_login_attempts,
            usr_last_login
        FROM users 
        WHERE usr_failed_login_attempts > 0
        ORDER BY usr_last_login DESC
        LIMIT 5
    ");
    $security_alerts = $security_alerts_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get user role distribution for pie chart (excluding admin)
    $role_distribution_stmt = $conn->query("
        SELECT 
            usr_role,
            COUNT(*) as count
        FROM users 
        WHERE usr_is_approved = TRUE AND usr_role != 'admin'
        GROUP BY usr_role
    ");
    $role_distribution = $role_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("System data fetch error: " . $e->getMessage());
    // Set default values if queries fail
    $total_users = 0;
    $active_users = 0;
    $failed_logins = 0;
    $pending_approvals = 0;
    $login_frequency = [];
    $security_alerts = [];
    $role_distribution = [];
}

// Calculate system metrics (simulated for demonstration)
$system_uptime = 99.97;
$server_load = 42;
$response_time = 128;
$db_performance = 98.2;
$error_rate = 0.8;

// Handle button actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login_frequency'])) {
        // Generate login frequency report
        $login_report = generateLoginFrequencyReport($conn);
        $_SESSION['report_data'] = $login_report;
        $_SESSION['report_type'] = 'login_frequency';
        
        // Log report generation
        logReportEvent($conn, $_SESSION['user_id'], 'login_frequency_generated', 'Login frequency report generated');
        
        // Create notification for report generation
        createAdminNotification($conn, 'system_report', 
            "Login frequency report generated successfully.");
    } elseif (isset($_POST['download_logs'])) {
        // Generate system logs
        $logs = generateSystemLogs($conn);
        
        // Log system logs download
        logReportEvent($conn, $_SESSION['user_id'], 'system_logs_downloaded', 'System logs downloaded');
        
        // Set headers for download
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="system_logs.txt"');
        echo $logs;
        exit();
    } elseif (isset($_POST['download_audit_logs'])) {
        // Generate audit logs
        $logs = generateAuditLogs($conn, $audit_search);
        
        // Log audit logs download
        logReportEvent($conn, $_SESSION['user_id'], 'audit_logs_downloaded', "Audit logs downloaded with search: $audit_search");
        
        // Set headers for download
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="audit_logs.txt"');
        echo $logs;
        exit();
    }
}

// Function to generate login frequency report
function generateLoginFrequencyReport($conn) {
    $report = "CTU-PESO SYSTEM LOGIN FREQUENCY REPORT\n";
    $report .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
    $report .= "=============================================\n\n";
    
    try {
        // Get login data for the last 30 days
        $stmt = $conn->query("
            SELECT 
                DATE(usr_last_login) as login_date,
                COUNT(*) as login_count,
                usr_role,
                COUNT(DISTINCT usr_id) as unique_users
            FROM users 
            WHERE usr_last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(usr_last_login), usr_role
            ORDER BY login_date DESC, usr_role
        ");
        
        $login_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $current_date = '';
        foreach ($login_data as $row) {
            if ($current_date != $row['login_date']) {
                $current_date = $row['login_date'];
                $report .= "\nDate: " . $current_date . "\n";
                $report .= "---------------------------------------------\n";
            }
            
            $report .= sprintf("  %-12s: %d logins (%d unique users)\n", 
                              ucfirst($row['usr_role']), 
                              $row['login_count'],
                              $row['unique_users']);
        }
    } catch (Exception $e) {
        $report .= "Error generating login data: " . $e->getMessage() . "\n";
    }
    
    return $report;
}

// Function to generate system logs
function generateSystemLogs($conn) {
    $logs = "CTU-PESO SYSTEM LOGS\n";
    $logs .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
    $logs .= "=============================================\n\n";
    
    try {
        // Get user activity logs
        $logs .= "USER ACTIVITY LOGS (Last 100 entries):\n";
        $logs .= "---------------------------------------------\n";
        
        $stmt = $conn->query("
            SELECT 
                u.usr_name,
                u.usr_role,
                u.usr_last_login,
                u.usr_failed_login_attempts
            FROM users u
            ORDER BY u.usr_last_login DESC
            LIMIT 100
        ");
        
        $user_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($user_activities as $activity) {
            $logs .= sprintf("%-20s %-10s %s Failed: %d\n",
                $activity['usr_name'],
                '(' . $activity['usr_role'] . ')',
                $activity['usr_last_login'] ? date('Y-m-d H:i', strtotime($activity['usr_last_login'])) : 'Never',
                $activity['usr_failed_login_attempts']
            );
        }
        
        // Get system statistics
        $logs .= "\nSYSTEM STATISTICS:\n";
        $logs .= "---------------------------------------------\n";
        
        $stats = $conn->query("
            SELECT 
                (SELECT COUNT(*) FROM users) as total_users,
                (SELECT COUNT(*) FROM users WHERE usr_role = 'graduate') as graduates,
                (SELECT COUNT(*) FROM users WHERE usr_role = 'employer') as employers,
                (SELECT COUNT(*) FROM users WHERE usr_role = 'admin') as admins,
                (SELECT COUNT(*) FROM users WHERE usr_role = 'staff') as staff,
                (SELECT COUNT(*) FROM jobs) as total_jobs,
                (SELECT COUNT(*) FROM applications) as total_applications
        ");
        
        $statistics = $stats->fetch(PDO::FETCH_ASSOC);
        
        $logs .= "Total Users: " . $statistics['total_users'] . "\n";
        $logs .= "Graduates: " . $statistics['graduates'] . "\n";
        $logs .= "Employers: " . $statistics['employers'] . "\n";
        $logs .= "Admins: " . $statistics['admins'] . "\n";
        $logs .= "Staff: " . $statistics['staff'] . "\n";
        $logs .= "Total Jobs: " . $statistics['total_jobs'] . "\n";
        $logs .= "Total Applications: " . $statistics['total_applications'] . "\n";
    } catch (Exception $e) {
        $logs .= "Error generating system logs: " . $e->getMessage() . "\n";
    }
    
    return $logs;
}

// Function to generate audit logs
function generateAuditLogs($conn, $search) {
    $logs = "CTU-PESO AUDIT LOGS\n";
    $logs .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
    $logs .= "Search: " . (empty($search) ? 'None' : $search) . "\n";
    $logs .= "=============================================\n\n";
    
    try {
        $query = "SELECT ua.*, u.usr_name, u.usr_role 
                  FROM user_activities ua 
                  LEFT JOIN users u ON ua.activity_usr_id = u.usr_id 
                  WHERE 1=1";
        if (!empty($search)) {
            $query .= " AND (ua.activity_details LIKE :search OR ua.activity_type LIKE :search OR u.usr_name LIKE :search)";
        }
        $query .= " ORDER BY ua.activity_date DESC LIMIT 500";
        
        $stmt = $conn->prepare($query);
        if (!empty($search)) {
            $search_term = '%' . $search . '%';
            $stmt->bindParam(':search', $search_term);
        }
        $stmt->execute();
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($activities as $activity) {
            $user_info = $activity['usr_name'] ? "{$activity['usr_name']} ({$activity['usr_role']})" : "User ID: {$activity['activity_usr_id']}";
            $logs .= sprintf("[%s] %s - %s: %s\n",
                date('Y-m-d H:i:s', strtotime($activity['activity_date'])),
                $user_info,
                $activity['activity_type'],
                $activity['activity_details']
            );
        }
    } catch (Exception $e) {
        $logs .= "Error generating audit logs: " . $e->getMessage() . "\n";
    }
    
    return $logs;
}

// Function to backup database
function backupDatabase($conn) {
    try {
        // Create backups directory if it doesn't exist
        if (!is_dir('backups')) {
            mkdir('backups', 0755, true);
        }
        
        $filename = 'backups/backup_' . date('Y-m-d_H-i-s') . '.sql';
        
        // Get all table names
        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        $output = '';
        foreach ($tables as $table) {
            // Drop table if exists
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // Create table
            $create_table = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $output .= $create_table['Create Table'] . ";\n\n";
            
            // Insert data
            $rows = $conn->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $output .= "INSERT INTO `$table` VALUES ";
                $values = [];
                foreach ($rows as $row) {
                    $row_values = array_map(function($value) use ($conn) {
                        if ($value === null) return 'NULL';
                        return $conn->quote($value);
                    }, $row);
                    $values[] = "(" . implode(',', $row_values) . ")";
                }
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        // Write to file
        if (file_put_contents($filename, $output)) {
            // Log the backup activity using our new comprehensive system
            logSystemEvent($conn, $_SESSION['user_id'], 'backup_created', "Database backup created: $filename");
            
            return ['success' => true, 'filename' => $filename];
        } else {
            return ['success' => false, 'error' => 'Could not write to file'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Prepare data for charts
$login_data = [
    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    'values' => [0, 0, 0, 0, 0, 0, 0]
];

// Map database results to days of the week
foreach ($login_frequency as $login) {
    $day_of_week = date('N', strtotime($login['login_date'])); // 1 (Monday) through 7 (Sunday)
    $login_data['values'][$day_of_week - 1] = $login['login_count'];
}

// Prepare role distribution data for pie chart (excluding admin)
$role_labels = [];
$role_counts = [];
$role_percentages = [];
// Updated colors to match USER DISTRIBUTION chart from dashboard
$role_colors = ['rgba(110, 3, 3, 0.8)', 'rgba(255, 167, 0, 0.8)', 'rgba(106, 13, 173, 0.8)'];

// Calculate total for percentages (excluding admin)
$total_users_approved = 0;
foreach ($role_distribution as $role) {
    $total_users_approved += $role['count'];
}

foreach ($role_distribution as $role) {
    $role_labels[] = ucfirst($role['usr_role']);
    $role_counts[] = $role['count'];
    $role_percentages[] = round(($role['count'] / $total_users_approved) * 100, 1);
}

// Get backup files
$backup_files = [];
$backup_dir = 'backups/';
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $backup_files[] = [
                'name' => $file,
                'size' => filesize($backup_dir . $file),
                'date' => date('Y-m-d H:i:s', filemtime($backup_dir . $file))
            ];
        }
    }
}

// Get audit logs
try {
    $audit_query = "SELECT ua.*, u.usr_name, u.usr_role 
                  FROM user_activities ua 
                  LEFT JOIN users u ON ua.activity_usr_id = u.usr_id 
                  WHERE 1=1";
    if (!empty($audit_search)) {
        $audit_query .= " AND (ua.activity_details LIKE :search OR ua.activity_type LIKE :search OR u.usr_name LIKE :search)";
    }
    $audit_query .= " ORDER BY ua.activity_date DESC LIMIT 100";

    $audit_stmt = $conn->prepare($audit_query);
    if (!empty($audit_search)) {
        $search_term = '%' . $audit_search . '%';
        $audit_stmt->bindParam(':search', $search_term);
    }
    $audit_stmt->execute();
    $audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Audit logs fetch error: " . $e->getMessage());
    $audit_logs = [];
}

// Handle AJAX request for notification refresh
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_notifications') {
    try {
        $notif_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE notif_usr_id = :admin_id AND notif_is_read = FALSE");
        $notif_stmt->bindParam(':admin_id', $admin_id);
        $notif_stmt->execute();
        $notification_count = $notif_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
        
        $recent_notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE notif_usr_id = :admin_id ORDER BY notif_created_at DESC LIMIT 10");
        $recent_notif_stmt->bindParam(':admin_id', $admin_id);
        $recent_notif_stmt->execute();
        $recent_notifications = $recent_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode([
            'unreadCount' => $notification_count,
            'notifications' => $recent_notifications
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'unreadCount' => 0,
            'notifications' => []
        ]);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - System Performance Monitor</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Dashboard Content */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }
        
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
        }
        
        /* System Performance Specific Styles */
        .chart-container {
            height: 200px;
            margin: 20px 0;
            position: relative;
        }
        
        .chart {
            display: flex;
            align-items: flex-end;
            gap: 15px;
            height: 100%;
            padding: 0 10px;
        }
        
        .bar {
            background: linear-gradient(to top, var(--primary-color), var(--sidebar-hover));
            width: 40px;
            border-radius: 6px 6px 0 0;
            position: relative;
            transition: height 0.3s ease;
        }
        
        .bar-label {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            color: #666;
        }
        
        .y-axis {
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 12px;
            color: #666;
        }
        
        .chart-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
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
            background-color: var(--sidebar-active);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline:hover {
            background-color: #fff5e6;
        }
        
        .alert-list {
            list-style-type: none;
        }
        
        .alert-item {
            padding: 15px;
            border-left: 4px solid var(--secondary-color);
            background-color: #fff9e6;
            margin-bottom: 15px;
            border-radius: 0 8px 8px 0;
        }
        
        .alert-date {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        
        /* Report Modal */
        .report-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            overflow: hidden;
        }
        
        .report-modal.active {
            display: block;
        }
        
        .modal-header {
            padding: 15px 25px;
            background: var(--primary-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 22px;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.3s;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-modal:hover {
            color: var(--secondary-color);
        }
        
        .modal-content {
            height: calc(100% - 60px);
            overflow-y: auto;
            padding: 20px;
            background-color: #f8f9fa;
            font-family: monospace;
            white-space: pre-wrap;
            line-height: 1.5;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 999;
        }
        
        .modal-overlay.active {
            display: block;
        }
        
        /* Audit Logs Section */
        .audit-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 30px;
            transition: all 0.3s;
        }
        
        .audit-section:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .section-title {
            font-size: 1.5rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .filters {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .search-input {
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
            min-width: 250px;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .search-btn {
            padding: 10px 15px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .search-btn:hover {
            background-color: var(--sidebar-active);
            transform: translateY(-2px);
        }
        
        /* NEW: Scrollable Audit Table Container */
        .audit-table-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .audit-table th, .audit-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .audit-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--primary-color);
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 1px 0 #eee;
        }
        
        .audit-table tr:hover {
            background-color: #f5f5f5;
        }
        
        .backup-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 30px;
            transition: all 0.3s;
        }
        
        .backup-section:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .backup-list {
            list-style-type: none;
            margin-top: 15px;
        }
        
        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: all 0.3s;
        }
        
        .backup-item:hover {
            background-color: #f8f9fa;
            padding-left: 20px;
            border-radius: 5px;
        }
        
        .backup-item:last-child {
            border-bottom: none;
        }
        
        .backup-info {
            flex: 1;
        }
        
        .backup-name {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .backup-details {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
        }
        
        .backup-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-sm {
            padding: 8px 12px;
            font-size: 0.8rem;
        }
        
        .chart-card {
            grid-column: span 2;
        }
        
        .chart-wrapper {
            height: 250px;
            margin-top: 15px;
        }
        
        /* Message alerts */
        .alert-message {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-card {
                grid-column: span 2;
            }
        }
        
        @media (max-width: 1024px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-bottom: 25px;
                }
                
            .chart-card {
                grid-column: span 2;
            }
        }
        
        @media (max-width: 900px) {
            .dashboard-cards {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }
        
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
            
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .chart-card {
                grid-column: span 1;
            }
            
            .chart-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .report-modal {
                width: 95%;
                height: 90%;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .filters {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .audit-table-container {
                overflow-x: auto;
            }
            
            .audit-table {
                min-width: 700px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-value {
                font-size: 1.8rem;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .page-title {
                font-size: 1.6rem;
            }
            
            .audit-table-container {
                max-height: 400px;
            }
        }
    </style>
</head>
<body>
    <!-- Modal Overlay -->
    <div class="modal-overlay" id="modalOverlay"></div>
    
    <!-- Report Modal -->
    <div class="report-modal" id="reportModal">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">System Report</h2>
            <button class="close-modal" id="closeModal">&times;</button>
        </div>
        <div class="modal-content" id="modalContent">
            <!-- Report content will be inserted here -->
        </div>
    </div>

    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= $profile_photo ?>" alt="Admin">
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
                    <a href="admin_system.php" class="active">
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
                    <img src="<?= $profile_photo ?>" alt="Admin">
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
            <p>Monitor system performance, security alerts, and generate reports. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">System Performance Monitor</h1>
        </div>
        
        <!-- Display success/error messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert-message alert-success">
                <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
                <?php unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert-message alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error_message'] ?>
                <?php unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>
        
        <div class="dashboard-cards">
            <!-- System Uptime Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">SYSTEM UPTIME</h3>
                    <i class="fas fa-server card-icon"></i>
                </div>
                <div class="card-value"><?= $system_uptime ?>%</div>
                <div class="card-footer">over the last 30 days</div>
            </div>
            
            <!-- Active Users Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">ACTIVE USERS</h3>
                    <i class="fas fa-users card-icon"></i>
                </div>
                <div class="card-value"><?= $active_users ?></div>
                <div class="card-footer">in the last 24 hours</div>
            </div>
            
            <!-- Server Load Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">SERVER LOAD</h3>
                    <i class="fas fa-microchip card-icon"></i>
                </div>
                <div class="card-value"><?= $server_load ?>%</div>
                <div class="card-footer">CPU usage average</div>
            </div>
            
            <!-- Response Time Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">RESPONSE TIME</h3>
                    <i class="fas fa-tachometer-alt card-icon"></i>
                </div>
                <div class="card-value"><?= $response_time ?>ms</div>
                <div class="card-footer">average API response</div>
            </div>
            
            <!-- Database Performance Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">DATABASE PERFORMANCE</h3>
                    <i class="fas fa-database card-icon"></i>
                </div>
                <div class="card-value"><?= $db_performance ?>%</div>
                <div class="card-footer">query success rate</div>
            </div>
            
            <!-- Error Rate Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">ERROR RATE</h3>
                    <i class="fas fa-exclamation-triangle card-icon"></i>
                </div>
                <div class="card-value"><?= $error_rate ?>%</div>
                <div class="card-footer">of all requests</div>
            </div>

            <!-- Pending Approvals Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PENDING APPROVALS</h3>
                    <i class="fas fa-clock card-icon"></i>
                </div>
                <div class="card-value"><?= $pending_approvals ?></div>
                <div class="card-footer">users waiting for approval</div>
            </div>

            <!-- Failed Logins Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">FAILED LOGINS</h3>
                    <i class="fas fa-lock card-icon"></i>
                </div>
                <div class="card-value"><?= $failed_logins ?></div>
                <div class="card-footer">failed login attempts</div>
            </div>
            
            <!-- User Role Distribution Chart -->
            <div class="card chart-card">
                <div class="card-header">
                    <h3 class="card-title">USER ROLE DISTRIBUTION</h3>
                    <i class="fas fa-chart-pie card-icon"></i>
                </div>
                
                <div class="chart-wrapper">
                    <canvas id="roleChart"></canvas>
                </div>
            </div>
            
            <!-- Login Activity Card -->
            <div class="card chart-card">
                <div class="card-header">
                    <h3 class="card-title">LOGIN FREQUENCY (LAST 7 DAYS)</h3>
                    <i class="fas fa-sign-in-alt card-icon"></i>
                </div>
                
                <div class="chart-wrapper">
                    <canvas id="loginChart"></canvas>
                </div>
                
                <div class="chart-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="login_frequency" class="btn btn-primary">
                            <i class="fas fa-chart-line"></i> Login Frequency Report
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="download_logs" class="btn btn-outline">
                            <i class="fas fa-download"></i> Download System Logs
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Security Alerts & Data Backup Cards Side by Side -->
            <div class="card chart-card">
                <div class="card-header">
                    <h3 class="card-title">SECURITY ALERTS</h3>
                    <i class="fas fa-shield-alt card-icon"></i>
                </div>
                
                <ul class="alert-list">
                    <?php if (!empty($security_alerts)): ?>
                        <?php foreach ($security_alerts as $alert): ?>
                        <li class="alert-item">
                            <i class="fas fa-exclamation-circle"></i> 
                            <?= $alert['usr_failed_login_attempts'] ?> failed login attempts for <?= htmlspecialchars($alert['usr_name']) ?>
                            <div class="alert-date"><?= date('M j, Y', strtotime($alert['usr_last_login'])) ?></div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="alert-item">
                            <i class="fas fa-check-circle"></i> No security alerts
                            <div class="alert-date"><?= date('M j, Y') ?></div>
                        </li>
                    <?php endif; ?>
                </ul>
                
                <div class="chart-actions">
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="download_logs" class="btn btn-outline">
                            <i class="fas fa-download"></i> Download Security Logs
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Data Backup Card -->
            <div class="card chart-card">
                <div class="card-header">
                    <h3 class="card-title">DATA BACKUP</h3>
                    <i class="fas fa-database card-icon"></i>
                </div>
                
                <p style="margin-bottom: 20px;">Backup your database to protect against data loss. Backups are stored in the backups/ directory.</p>
                
                <form method="POST" style="margin-bottom: 20px;">
                    <button type="submit" name="backup_database" class="btn btn-primary">
                        <i class="fas fa-database"></i> Create Backup
                    </button>
                </form>
                
                <?php if (!empty($backup_files)): ?>
                    <ul class="backup-list">
                        <?php foreach ($backup_files as $file): ?>
                        <li class="backup-item">
                            <div class="backup-info">
                                <div class="backup-name"><?= $file['name'] ?></div>
                                <div class="backup-details">
                                    <?= round($file['size'] / 1024, 2) ?> KB - <?= $file['date'] ?>
                                </div>
                            </div>
                            <div class="backup-actions">
                                <a href="backups/<?= $file['name'] ?>" download class="btn btn-outline btn-sm">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align: center; padding: 20px; color: #666;">No backup files found</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Audit Logs Section -->
        <div class="audit-section">
            <div class="section-header">
                <h2 class="section-title">Audit Logs</h2>
                <div class="filters">
                    <form method="GET" class="filter-form">
                        <input type="text" name="audit_search" class="search-input" placeholder="Search audit logs..." value="<?= htmlspecialchars($audit_search) ?>">
                        <button type="submit" class="search-btn">Search</button>
                    </form>
                </div>
            </div>
            
            <!-- NEW: Scrollable Table Container -->
            <div class="audit-table-container">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>User</th>
                            <th>Activity Type</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($audit_logs)): ?>
                            <?php foreach ($audit_logs as $log): ?>
                            <tr>
                                <td><?= date('M j, Y H:i:s', strtotime($log['activity_date'])) ?></td>
                                <td><?= htmlspecialchars($log['usr_name'] ?? 'Unknown') ?> (<?= htmlspecialchars($log['usr_role'] ?? 'Unknown') ?>)</td>
                                <td><?= ucfirst(str_replace('_', ' ', $log['activity_type'])) ?></td>
                                <td><?= htmlspecialchars($log['activity_details']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center;">No audit logs found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="chart-actions" style="margin-top: 20px;">
                <form method="POST" style="display: inline;">
                    <button type="submit" name="download_audit_logs" class="btn btn-outline">
                        <i class="fas fa-download"></i> Download Audit Logs
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
                        url: 'admin_system.php',
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
        
        // Function to show report modal
        function showReportModal(type, content) {
            const modal = document.getElementById('reportModal');
            const overlay = document.getElementById('modalOverlay');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            
            if (type === 'login_frequency') {
                modalTitle.textContent = 'Login Frequency Report';
            } else {
                modalTitle.textContent = 'System Report';
            }
            
            modalContent.textContent = content;
            modal.classList.add('active');
            overlay.classList.add('active');
        }
        
        // Function to close modal
        function closeModal() {
            const modal = document.getElementById('reportModal');
            const overlay = document.getElementById('modalOverlay');
            
            modal.classList.remove('active');
            overlay.classList.remove('active');
        }
        
        // Close modal when clicking outside or on close button
        document.getElementById('modalOverlay').addEventListener('click', closeModal);
        document.getElementById('closeModal').addEventListener('click', closeModal);
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Role Distribution Chart (Pie) - Updated colors to match dashboard
            const roleCtx = document.getElementById('roleChart').getContext('2d');
            new Chart(roleCtx, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($role_labels) ?>,
                    datasets: [{
                        data: <?= json_encode($role_counts) ?>,
                        backgroundColor: <?= json_encode($role_colors) ?>,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const percentage = <?= json_encode($role_percentages) ?>[context.dataIndex];
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Login Frequency Chart (Bar) - Updated colors to match dashboard
            const loginCtx = document.getElementById('loginChart').getContext('2d');
            
            // Calculate percentage for login data
            const loginValues = <?= json_encode($login_data['values']) ?>;
            const totalLogins = loginValues.reduce((a, b) => a + b, 0);
            const loginPercentages = loginValues.map(value => {
                return totalLogins > 0 ? Math.round((value / totalLogins) * 100) : 0;
            });
            
            new Chart(loginCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($login_data['labels']) ?>,
                    datasets: [{
                        label: 'Logins',
                        data: loginValues,
                        backgroundColor: [
                            'rgba(110, 3, 3, 0.8)',
                            'rgba(138, 4, 4, 0.8)',
                            'rgba(165, 6, 6, 0.8)',
                            'rgba(255, 167, 0, 0.8)',
                            'rgba(255, 183, 51, 0.8)',
                            'rgba(106, 13, 173, 0.8)',
                            'rgba(138, 4, 4, 0.6)'
                        ],
                        borderColor: [
                            'rgba(110, 3, 3, 1)',
                            'rgba(138, 4, 4, 1)',
                            'rgba(165, 6, 6, 1)',
                            'rgba(255, 167, 0, 1)',
                            'rgba(255, 183, 51, 1)',
                            'rgba(106, 13, 173, 1)',
                            'rgba(138, 4, 4, 1)'
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
                            ticks: {
                                stepSize: 5
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw || 0;
                                    const percentage = loginPercentages[context.dataIndex];
                                    return `Logins: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Show report modal if there's report data
            <?php if (isset($_SESSION['report_data'])): ?>
            showReportModal('<?= $_SESSION['report_type'] ?>', `<?= addslashes($_SESSION['report_data']) ?>`);
            <?php 
            unset($_SESSION['report_data']);
            unset($_SESSION['report_type']);
            endif; ?>
        });
    </script>
</body>
</html>