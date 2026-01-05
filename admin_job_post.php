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
 * Check for system analytics and generate notifications - FIXED FUNCTION
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
    
    // Check top skills in demand - FIXED QUERY (using job_skills field in jobs table)
    $topSkills = $conn->query("
        SELECT job_skills, COUNT(*) as demand_count
        FROM jobs 
        WHERE job_status = 'active' 
        AND job_skills IS NOT NULL 
        AND job_skills != ''
        GROUP BY job_skills
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
                "High demand for skills: '{$topSkills['job_skills']}' ({$topSkills['demand_count']} job postings).");
            $notificationsGenerated++;
        }
    }
    
    return $notificationsGenerated;
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
// JOB POSTINGS FUNCTIONALITY
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_job_post.php");
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
$profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($admin_user['usr_name']) . "&background=3498db&color=fff";
if (!empty($admin_user['usr_profile_photo']) && file_exists("uploads/profile_photos/" . $admin_user['usr_profile_photo'])) {
    $profile_photo = "uploads/profile_photos/" . $admin_user['usr_profile_photo'];
}

// Get counts for dashboard
try {
    // Pending job postings count
    $pending_jobs_count = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'pending'")->fetchColumn();
    
    // Approved job posts count
    $approved_jobs_count = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'active'")->fetchColumn();
    
    // Rejected job posts count - FIXED: Ensure we're counting 'rejected' status
    $rejected_jobs_count = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'rejected'")->fetchColumn();
    
    // Pending employer accounts count
    $pending_employers_count = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'employer' AND usr_is_approved = FALSE")->fetchColumn();
    
} catch (PDOException $e) {
    // Set default values if there's an error
    $pending_jobs_count = $approved_jobs_count = $rejected_jobs_count = $pending_employers_count = 0;
}

// Get job postings for the table with reviewer information
try {
    $jobs_stmt = $conn->prepare("
        SELECT j.*, 
               e.emp_company_name as company_name,
               reviewer.usr_name as reviewer_name,
               j.job_reviewed_at
        FROM jobs j 
        JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id 
        LEFT JOIN users reviewer ON j.job_reviewed_by = reviewer.usr_id
        ORDER BY j.job_created_at DESC
    ");
    $jobs_stmt->execute();
    $jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $jobs = [];
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

// Function to determine where a notification should link to based on its content
function getNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    // FIXED: Check for system settings FIRST before other types
    // This ensures system settings notifications always go to admin_settings.php
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

// Check if auto-approve jobs is enabled
$auto_approve_jobs_setting = 0;
try {
    // First check if system_settings table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'system_settings'")->fetchColumn();
    if ($tableExists) {
        $settings_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_jobs'");
        $settings_stmt->execute();
        $auto_approve_jobs_setting = $settings_stmt->fetchColumn();
    }
} catch (PDOException $e) {
    // If table doesn't exist yet, use default value
    $auto_approve_jobs_setting = 0;
}

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
            $stmt->bindParam(':reviewed_by', $admin_id);
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
            }
            
            // Refresh page to show updated status
            header("Location: admin_job_post.php");
            exit();
        }
    } catch (PDOException $e) {
        $error = "Error updating job status: " . $e->getMessage();
    }
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

// Handle AJAX request for notification refresh
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_notifications') {
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Job Postings Oversight</title>
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
            --light-gray: #f5f6f8;
            --medium-gray: #e9ecef;
            --dark-gray: #6c757d;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            display: flex;
            background-color: var(--light-gray);
            min-height: 100vh;
            color: var(--text-color);
            overflow-x: hidden;
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
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.2rem;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
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
        
        /* Enhanced Dashboard Cards - Mobile Responsive */
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
            display: flex;
            flex-direction: column;
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
            margin-top: auto;
        }
        
        /* Enhanced Table Styles */
        .table-container {
            background-color: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            overflow-x: auto;
            border-top: 4px solid var(--primary-color);
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .table-header h2 {
            color: var(--primary-color);
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th, td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--primary-color);
            position: sticky;
            top: 0;
        }
        
        tr:hover {
            background-color: #f9f9f9;
        }
        
        .status-badge {
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .status-pending {
            background: linear-gradient(135deg, var(--secondary-color), #ffa700);
            color: white;
        }
        
        .status-active {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .status-rejected {
            background: linear-gradient(135deg, var(--red), #e74c3c);
            color: white;
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #6c757d, #95a5a6);
            color: white;
        }
        
        .reviewer-info {
            font-size: 11px;
            color: #666;
            margin-top: 3px;
        }
        
        /* Enhanced Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-height: 44px;
        }
        
        .action-btn.review {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Enhanced Modal Styles */
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
        
        .job-modal {
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
        
        .modal-overlay.active .job-modal {
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
        
        /* Enhanced Detail Row Styles */
        .detail-row {
            display: flex;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-label {
            width: 180px;
            font-weight: 600;
            color: var(--primary-color);
            flex-shrink: 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        
        .detail-label i {
            width: 20px;
            text-align: center;
            color: var(--secondary-color);
        }
        
        .detail-value {
            flex: 1;
            word-break: break-word;
            color: #555;
            line-height: 1.6;
        }
        
        .detail-value strong {
            color: var(--primary-color);
        }
        
        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        
        .tag {
            background: rgba(110, 3, 3, 0.1);
            color: var(--primary-color);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid rgba(110, 3, 3, 0.2);
        }
        
        .job-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid var(--secondary-color);
        }
        
        .summary-item {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .summary-item i {
            width: 20px;
            text-align: center;
            color: var(--secondary-color);
        }
        
        .positive {
            color: var(--green);
        }
        
        .negative {
            color: var(--red);
        }
        
        .action-form {
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
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
        
        select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
            min-height: 44px;
        }
        
        select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            min-height: 44px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Enhanced Alert Styles */
        .auto-approve-notice {
            background: linear-gradient(135deg, #e8f5e8, #d4edda);
            border-left: 4px solid var(--green);
            padding: 18px 20px;
            margin-bottom: 25px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .auto-approve-notice i {
            color: var(--green);
            font-size: 1.5rem;
        }
        
        .auto-approve-notice div {
            flex: 1;
        }
        
        .auto-approve-notice strong {
            color: var(--primary-color);
        }
        
        .auto-approve-notice a {
            color: var(--primary-color);
            text-decoration: underline;
            font-weight: 600;
        }
        
        .error-message {
            background: linear-gradient(135deg, #ffebee, #f8d7da);
            color: #c62828;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #c62828;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .success-message {
            background: linear-gradient(135deg, #e8f5e9, #d4edda);
            color: #2e7d32;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #2e7d32;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        /* Enhanced Job Details Section */
        .job-details-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary-color);
        }
        
        .section-title {
            font-size: 1.3rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--secondary-color);
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-item-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .detail-item-value {
            color: #555;
            font-size: 1rem;
        }
        
        /* ============================================================================
           MOBILE RESPONSIVE DESIGN - ENHANCED FOR PHONES
        ============================================================================ */
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .notification-dropdown {
                width: 350px;
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
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
                margin-left: 0;
                width: 100%;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
            
            .top-nav {
                padding: 12px 20px;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .detail-row {
                flex-direction: column;
                margin-bottom: 15px;
            }
            
            .detail-label {
                width: 100%;
                margin-bottom: 8px;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .table-container {
                padding: 20px;
            }
            
            th, td {
                padding: 12px 15px;
            }
            
            .modal {
                width: 98%;
                margin: 10px;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -80px;
            }
            
            .job-modal {
                width: 98%;
                margin: 10px;
            }
            
            .modal-content {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
            
            .welcome-message {
                padding: 15px 20px;
            }
            
            .welcome-message h2 {
                font-size: 1.4rem;
            }
            
            .page-title {
                font-size: 1.6rem;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-value {
                font-size: 1.8rem;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .top-nav {
                padding: 10px 15px;
                flex-direction: column;
                gap: 10px;
                align-items: flex-end;
            }
            
            .nav-right {
                width: 100%;
                justify-content: flex-end;
            }
            
            .card {
                padding: 15px;
            }
            
            .table-container {
                padding: 15px;
            }
            
            .form-title {
                font-size: 1.5rem;
            }
            
            .form-subtitle {
                font-size: 0.9rem;
            }
            
            .notification-dropdown {
                width: 250px;
                right: -60px;
            }
            
            .profile-dropdown {
                width: 200px;
            }
            
            .welcome-message {
                padding: 12px 15px;
            }
            
            .welcome-message h2 {
                font-size: 1.2rem;
            }
            
            .page-title {
                font-size: 1.4rem;
            }
            
            .admin-profile {
                padding: 6px 12px;
            }
            
            .admin-profile img {
                width: 35px;
                height: 35px;
            }
            
            .admin-name {
                font-size: 0.9rem;
            }
            
            .modal-header {
                padding: 20px;
            }
            
            .modal-title {
                font-size: 1.5rem;
            }
            
            .modal-content {
                padding: 15px;
            }
            
            .job-details-section {
                padding: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .table-container {
                padding: 12px;
            }
            
            .notification-dropdown {
                width: 220px;
                right: -40px;
            }
            
            .dropdown-header {
                padding: 12px 15px;
                font-size: 0.9rem;
            }
            
            .dropdown-item {
                padding: 12px 15px;
            }
            
            .notification-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .notification-icon {
                margin-bottom: 5px;
            }
            
            .welcome-message {
                padding: 10px 12px;
            }
            
            .welcome-message h2 {
                font-size: 1.1rem;
            }
            
            .page-title {
                font-size: 1.3rem;
            }
            
            .admin-profile {
                padding: 5px 10px;
            }
            
            .admin-profile img {
                width: 30px;
                height: 30px;
                margin-right: 8px;
            }
            
            .admin-name {
                font-size: 0.8rem;
            }
            
            .action-btn {
                padding: 6px 10px;
                font-size: 0.75rem;
                min-width: 80px;
                height: 32px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
            
            .card {
                padding: 12px;
            }
            
            .card-value {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 8px;
            }
            
            .top-nav {
                padding: 8px 12px;
            }
            
            .notification-dropdown {
                width: 200px;
                right: -30px;
            }
            
            .welcome-message {
                padding: 10px 12px;
            }
            
            .welcome-message h2 {
                font-size: 1.1rem;
            }
            
            .page-title {
                font-size: 1.3rem;
            }
            
            .admin-profile {
                padding: 5px 10px;
            }
            
            .admin-profile img {
                width: 30px;
                height: 30px;
                margin-right: 8px;
            }
            
            .admin-name {
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 350px) {
            .notification-dropdown {
                width: 180px;
                right: -20px;
            }
            
            .profile-dropdown {
                width: 180px;
            }
        }

        /* Ensure form elements are always properly sized */
        input, button, select, textarea {
            max-width: 100%;
        }

        /* Prevent horizontal scrolling */
        body, .container, .form-section {
            overflow-x: hidden;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Improved scrolling for mobile */
        .form-section {
            -webkit-overflow-scrolling: touch;
        }
        
        /* Better touch targets for mobile */
        .btn, .form-control, .user-type-card, .action-btn {
            min-height: 44px;
        }
        
        /* Prevent zoom on input focus for mobile */
        @media (max-width: 768px) {
            input, select, textarea {
                font-size: 16px; /* Prevents zoom on iOS */
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Enhanced Sidebar -->
    <div class="sidebar" id="sidebar">
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
                    <a href="admin_job_post.php" class="active">
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
            <p>Here's the job postings oversight panel. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Job Postings Oversight</h1>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
            <div class="success-message">
                Job status updated successfully!
            </div>
        <?php endif; ?>
        
        <?php if ($auto_approve_jobs_setting): ?>
            <div class="auto-approve-notice">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Auto-approval is enabled:</strong> New job postings are automatically approved. 
                    You can disable this in <a href="admin_settings.php">System Settings</a>.
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Enhanced Dashboard Cards - Mobile Responsive -->
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PENDING JOB POSTINGS</h3>
                    <i class="fas fa-clock card-icon"></i>
                </div>
                <div class="card-value"><?= $pending_jobs_count ?></div>
                <div class="card-footer">Awaiting approval</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPROVED JOB POSTS</h3>
                    <i class="fas fa-check-circle card-icon"></i>
                </div>
                <div class="card-value"><?= $approved_jobs_count ?></div>
                <div class="card-footer">Active job posts</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">REJECTED JOB POSTS</h3>
                    <i class="fas fa-times-circle card-icon"></i>
                </div>
                <div class="card-value"><?= $rejected_jobs_count ?></div>
                <div class="card-footer">Not approved</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PENDING EMPLOYER ACCOUNTS</h3>
                    <i class="fas fa-building card-icon"></i>
                </div>
                <div class="card-value"><?= $pending_employers_count ?></div>
                <div class="card-footer">Awaiting verification</div>
            </div>
        </div>
        
        <!-- Enhanced Table Container -->
        <div class="table-container">
            <div class="table-header">
                <h2>Job Postings</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Job Title</th>
                        <th>Company</th>
                        <th>Location</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Reviewed By</th>
                        <th>Posted Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($jobs)): ?>
                        <?php foreach ($jobs as $job): 
                            // Format reviewer info
                            $reviewer_info = '';
                            if (!empty($job['reviewer_name'])) {
                                $review_date = !empty($job['job_reviewed_at']) ? date('M j, Y', strtotime($job['job_reviewed_at'])) : '';
                                $reviewer_info = $job['reviewer_name'] . ($review_date ? '<br><small>' . $review_date . '</small>' : '');
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($job['job_title']) ?></td>
                            <td><?= htmlspecialchars($job['company_name']) ?></td>
                            <td><?= htmlspecialchars($job['job_location'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(ucfirst($job['job_type'] ?? 'N/A')) ?></td>
                            <td>
                                <span class="status-badge status-<?= htmlspecialchars($job['job_status']) ?>">
                                    <?= htmlspecialchars(ucfirst($job['job_status'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($reviewer_info)): ?>
                                    <div class="reviewer-info"><?= $reviewer_info ?></div>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">Not reviewed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M j, Y', strtotime($job['job_created_at'])) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn review review-btn" data-job-id="<?= $job['job_id'] ?>">
                                        <i class="fas fa-eye"></i> Review
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: #999;">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                                No job postings found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Enhanced Job Details Modal -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="job-modal">
            <div class="modal-header">
                <h2 class="modal-title">Job Post Details</h2>
                <button class="modal-close" id="closeModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <form id="jobActionForm" method="POST">
                    <input type="hidden" name="job_action" value="1">
                    <input type="hidden" id="job_id" name="job_id" value="">
                    
                    <!-- Job Overview Section -->
                    <div class="job-details-section">
                        <h3 class="section-title"><i class="fas fa-info-circle"></i> Job Overview</h3>
                        <div class="details-grid">
                            <div class="detail-item">
                                <span class="detail-item-label">Job Title</span>
                                <span class="detail-item-value" id="detail-title"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Company</span>
                                <span class="detail-item-value" id="detail-company"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Location</span>
                                <span class="detail-item-value" id="detail-location"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Job Type</span>
                                <span class="detail-item-value" id="detail-type"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Salary Range</span>
                                <span class="detail-item-value" id="detail-salary"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Status</span>
                                <span class="detail-item-value" id="detail-status"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Reviewed By</span>
                                <span class="detail-item-value" id="detail-reviewer">Not reviewed</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-item-label">Reviewed At</span>
                                <span class="detail-item-value" id="detail-reviewed-at">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Job Description Section -->
                    <div class="job-details-section">
                        <h3 class="section-title"><i class="fas fa-file-alt"></i> Job Description</h3>
                        <div class="detail-value" id="detail-description"></div>
                    </div>
                    
                    <!-- Requirements Section -->
                    <div class="job-details-section">
                        <h3 class="section-title"><i class="fas fa-list-check"></i> Requirements</h3>
                        <div class="detail-value" id="detail-requirements"></div>
                    </div>
                    
                    <?php if ($auto_approve_jobs_setting): ?>
                    <div class="auto-approve-notice" style="margin: 25px 0;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Note:</strong> Auto-approval is currently enabled. 
                            New job postings are automatically approved. You can still manually review and modify job status if needed.
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Review Action Section -->
                    <div class="action-form">
                        <h3 style="margin-bottom: 20px; color: var(--primary-color); font-size: 1.3rem; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-clipboard-check"></i> Review Action
                        </h3>
                        
                        <div class="form-group">
                            <label for="action">Select Action</label>
                            <select id="action" name="action" required>
                                <option value="">Select Action</option>
                                <option value="approve">Approve</option>
                                <option value="edit">Request Edit</option>
                                <option value="reject">Reject</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="comments">Comments (Optional)</label>
                            <textarea id="comments" name="comments" placeholder="Add comments for the employer..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Decision
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 992) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickInsideToggle = mobileMenuToggle.contains(event.target);
                
                if (!isClickInsideSidebar && !isClickInsideToggle && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                }
            }
        });
        
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
            // Auto-refresh notifications every 30 seconds
            setInterval(function() {
                refreshNotifications();
            }, 30000);
            
            // Handle notification click and mark as read
            $('.notification-link').on('click', function(e) {
                const notifId = $(this).data('notif-id');
                const notificationItem = $(this);
                
                // Only mark as read if it's unread
                if (notificationItem.hasClass('unread')) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'admin_job_post.php',
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
        
        // Function to refresh notifications
        function refreshNotifications() {
            $.ajax({
                url: 'admin_job_post.php?ajax=get_notifications',
                type: 'GET',
                success: function(response) {
                    const data = JSON.parse(response);
                    updateNotificationUI(data);
                },
                error: function() {
                    console.log('Error refreshing notifications');
                }
            });
        }
        
        // Function to update notification UI
        function updateNotificationUI(data) {
            // Update notification count
            if (data.unreadCount > 0) {
                if ($('#notificationCount').length) {
                    $('#notificationCount').text(data.unreadCount);
                } else {
                    $('#notification i').after('<span class="notification-count" id="notificationCount">' + data.unreadCount + '</span>');
                }
            } else {
                $('#notificationCount').remove();
            }
        }
        
        // Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            const modalOverlay = document.getElementById('modalOverlay');
            const closeModal = document.getElementById('closeModal');
            const reviewButtons = document.querySelectorAll('.review-btn');
            const jobActionForm = document.getElementById('jobActionForm');
            const jobIdInput = document.getElementById('job_id');
            
            // Close modal
            closeModal.addEventListener('click', function() {
                modalOverlay.classList.remove('active');
            });
            
            // Close modal when clicking outside
            modalOverlay.addEventListener('click', function(e) {
                if (e.target === modalOverlay) {
                    modalOverlay.classList.remove('active');
                }
            });
            
            // Review button functionality
            reviewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const jobId = this.getAttribute('data-job-id');
                    jobIdInput.value = jobId;
                    
                    // Fetch job details via AJAX
                    fetch('?ajax=get_job_details&id=' + jobId)
                        .then(response => response.json())
                        .then(job => {
                            if (job.error) {
                                alert(job.error);
                                return;
                            }
                            
                            // Update job details
                            document.getElementById('detail-title').textContent = job.job_title || 'N/A';
                            document.getElementById('detail-company').textContent = job.company_name || 'N/A';
                            document.getElementById('detail-location').textContent = job.job_location || 'N/A';
                            document.getElementById('detail-type').textContent = job.job_type ? job.job_type.charAt(0).toUpperCase() + job.job_type.slice(1) : 'N/A';
                            document.getElementById('detail-salary').textContent = job.job_salary_range || 'N/A';
                            document.getElementById('detail-status').textContent = job.job_status ? job.job_status.charAt(0).toUpperCase() + job.job_status.slice(1) : 'N/A';
                            document.getElementById('detail-reviewer').textContent = job.reviewer_name || 'Not reviewed';
                            document.getElementById('detail-reviewed-at').textContent = job.job_reviewed_at ? new Date(job.job_reviewed_at).toLocaleDateString() : '-';
                            document.getElementById('detail-requirements').textContent = job.job_requirements || 'N/A';
                            document.getElementById('detail-description').textContent = job.job_description || 'N/A';
                            
                            // Show modal
                            modalOverlay.classList.add('active');
                            document.body.style.overflow = 'hidden';
                        })
                        .catch(error => {
                            console.error('Error fetching job details:', error);
                            alert('Error loading job details. Please try again.');
                        });
                });
            });
            
            // Re-enable body scrolling when modal is closed
            modalOverlay.addEventListener('transitionend', function() {
                if (!modalOverlay.classList.contains('active')) {
                    document.body.style.overflow = 'auto';
                }
            });
        });
    </script>
</body>
</html>