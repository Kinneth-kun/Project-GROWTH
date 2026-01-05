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
// EMPLOYERS MANAGEMENT FUNCTIONALITY
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_employers.php");
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

// Get search parameters
$search_query = $_GET['search'] ?? '';

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10; // Number of records per page
$offset = ($page - 1) * $per_page;

// Build query for employers with search and filters - MODIFIED: Only show approved employers
$query = "
    SELECT u.usr_id, u.usr_name, u.usr_email, u.usr_phone, u.usr_is_approved, u.usr_created_at,
           e.emp_id, e.emp_company_name, e.emp_industry, e.emp_contact_person, 
           e.emp_company_description, e.emp_business_permit, e.emp_dti_sec
    FROM users u
    JOIN employers e ON u.usr_id = e.emp_usr_id
    WHERE u.usr_role = 'employer'
    AND u.usr_is_approved = TRUE  -- MODIFIED: Only show approved employers
";

$count_query = "
    SELECT COUNT(*) as total
    FROM users u
    JOIN employers e ON u.usr_id = e.emp_usr_id
    WHERE u.usr_role = 'employer'
    AND u.usr_is_approved = TRUE  -- MODIFIED: Only show approved employers
";

$params = [];
$count_params = [];

// Add search condition
if (!empty($search_query)) {
    $query .= " AND (e.emp_company_name LIKE :search OR e.emp_industry LIKE :search OR u.usr_name LIKE :search OR u.usr_email LIKE :search)";
    $count_query .= " AND (e.emp_company_name LIKE :search OR e.emp_industry LIKE :search OR u.usr_name LIKE :search OR u.usr_email LIKE :search)";
    $params[':search'] = "%$search_query%";
    $count_params[':search'] = "%$search_query%";
}

// Add ordering and pagination
$query .= " ORDER BY u.usr_created_at DESC LIMIT :limit OFFSET :offset";

try {
    // Get total count for pagination
    $count_stmt = $conn->prepare($count_query);
    foreach ($count_params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get paginated results
    $employers_stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $employers_stmt->bindValue($key, $value);
    }
    $employers_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $employers_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $employers_stmt->execute();
    $employers = $employers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $total_pages = ceil($total_count / $per_page);
    
} catch (PDOException $e) {
    $employers = [];
    $total_count = 0;
    $total_pages = 1;
    $error = "Error fetching employers: " . $e->getMessage();
}

// Handle employer approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_employer'])) {
        $user_id = $_POST['user_id'];
        
        try {
            $stmt = $conn->prepare("UPDATE users SET usr_is_approved = TRUE, usr_account_status = 'active' WHERE usr_id = :id");
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            
            // Add notification - FIXED: corrected column names
            $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your employer account has been approved by administrator', 'employer_approval')");
            $notif_stmt->bindParam(':user_id', $user_id);
            $notif_stmt->execute();
            
            // Refresh page
            header("Location: admin_employers.php");
            exit();
            
        } catch (PDOException $e) {
            $error = "Error approving employer: " . $e->getMessage();
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
            
            // Refresh page
            header("Location: admin_employers.php");
            exit();
            
        } catch (PDOException $e) {
            $error = "Error rejecting employer: " . $e->getMessage();
        }
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

// Get employer details for view modal if requested
$view_employer = null;
if (isset($_GET['view_id'])) {
    $view_id = $_GET['view_id'];
    $view_stmt = $conn->prepare("
        SELECT u.usr_id, u.usr_name, u.usr_email, u.usr_phone, u.usr_is_approved, u.usr_created_at,
               e.emp_id, e.emp_company_name, e.emp_industry, e.emp_contact_person, 
               e.emp_company_description, e.emp_business_permit, e.emp_dti_sec
        FROM users u
        JOIN employers e ON u.usr_id = e.emp_usr_id
        WHERE u.usr_id = :id
    ");
    $view_stmt->bindParam(':id', $view_id);
    $view_stmt->execute();
    $view_employer = $view_stmt->fetch(PDO::FETCH_ASSOC);
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
    <title>CTU-PESO - Approved Employers</title>
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
        
        /* Enhanced Filter Container - MODIFIED: Removed status filter, adjusted search width */
        .filters {
            display: flex;
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            align-items: center;
            flex-wrap: wrap;
            gap: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-top: 4px solid var(--accent-color);
        }
        
        .search-filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: 100%; /* MODIFIED: Make search take full width */
        }
        
        .filter-label {
            font-weight: 600;
            font-size: 14px;
            color: var(--primary-color);
        }
        
        .search-filter-container {
            display: flex;
            position: relative;
            width: 100%; /* MODIFIED: Full width search */
        }
        
        .search-filter-container input {
            padding: 11px 15px 11px 45px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
            width: 100%;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
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
            font-size: 1.1rem;
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
            min-width: 600px;
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
        
        .status-approved {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .doc-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .doc-ok {
            color: var(--green);
            font-size: 14px;
        }
        
        .doc-missing {
            color: var(--red);
            font-size: 14px;
        }
        
        /* Enhanced Action Buttons - Fixed size and in one row */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            justify-content: flex-start;
            align-items: center;
        }
        
        .action-btn {
            padding: 8px 12px;
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
            min-width: 100px;
            height: 36px;
            box-sizing: border-box;
            justify-content: center;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .action-btn.view {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .action-btn.approve {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .action-btn.reject {
            background: linear-gradient(135deg, var(--red), #e74c3c);
            color: white;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
            gap: 10px;
        }
        
        .pagination-btn {
            padding: 10px 16px;
            border: 1px solid #ddd;
            background: white;
            color: var(--primary-color);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .pagination-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination-btn.disabled {
            background: #f5f5f5;
            color: #999;
            cursor: not-allowed;
            border-color: #ddd;
        }
        
        .pagination-info {
            color: #666;
            font-size: 14px;
            margin: 0 15px;
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
        
        .modal {
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
        
        .modal-overlay.active .modal {
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
        
        .modal-body {
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
        
        /* Enhanced Document Viewer Modal */
        .document-viewer-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.95);
            z-index: 2200;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        
        .document-viewer-modal.active {
            opacity: 1;
            visibility: visible;
        }
        
        .document-viewer-content {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            height: 95vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            border: 1px solid #ddd;
            max-width: 1200px;
            overflow: hidden;
        }
        
        .document-viewer-header {
            padding: 18px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        
        .document-viewer-title {
            font-size: 1.3rem;
            color: var(--primary-color);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .document-viewer-title i {
            color: var(--secondary-color);
        }
        
        .document-viewer-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .zoom-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        
        .zoom-btn {
            background-color: #f1f1f1;
            border: 1px solid #ddd;
            border-radius: 6px;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .zoom-btn:hover {
            background-color: #e5e5e5;
            transform: scale(1.05);
        }
        
        .zoom-level {
            min-width: 50px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .document-viewer-close {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            font-size: 1.2rem;
            cursor: pointer;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .document-viewer-close:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: scale(1.05);
        }
        
        .document-viewer-body {
            flex: 1;
            padding: 0;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2c3e50;
        }
        
        .document-container {
            position: relative;
            overflow: auto;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .document-viewer-iframe {
            width: 100%;
            height: 100%;
            border: none;
            transition: transform 0.3s ease;
            border-radius: 8px;
        }
        
        .document-viewer-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transition: transform 0.3s ease;
            cursor: grab;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .document-viewer-image:active {
            cursor: grabbing;
        }
        
        .scroll-hint {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.8), rgba(50, 50, 50, 0.9));
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeInOut 3s infinite;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0.7; }
            50% { opacity: 1; }
            100% { opacity: 0.7; }
        }
        
        /* Enhanced Alert Styles */
        .error-message {
            background: linear-gradient(135deg, #ffebee, #f8d7da);
            color: #c62828;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #c62828;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
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
            
            .employer-details {
                grid-template-columns: 1fr;
            }
            
            .documents-grid {
                grid-template-columns: 1fr;
            }
            
            .filters {
                flex-direction: column;
                gap: 15px;
            }
            
            .search-filter-container {
                width: 100%;
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
            
            .document-viewer-content {
                width: 100%;
                height: 100vh;
                border-radius: 0;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .action-btn {
                width: 100%;
                min-width: auto;
            }
            
            .document-actions {
                flex-direction: column;
            }
            
            .btn-download {
                width: 100%;
                min-width: auto;
            }
            
            .zoom-controls {
                display: none;
            }
            
            .scroll-hint {
                display: none;
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
            
            .pagination {
                flex-wrap: wrap;
            }
            
            .pagination-info {
                margin: 10px 0;
                text-align: center;
                width: 100%;
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
            
            .modal-body {
                padding: 15px;
            }
            
            .employer-details {
                gap: 15px;
            }
            
            .pagination-btn {
                padding: 8px 12px;
                font-size: 0.9rem;
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
            
            .btn-download {
                padding: 8px 12px;
                font-size: 0.8rem;
                min-width: 100px;
                height: 36px;
            }
            
            .pagination {
                gap: 5px;
            }
            
            .pagination-btn {
                padding: 6px 10px;
                font-size: 0.8rem;
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
        .btn, .form-control, .user-type-card, .action-btn, .btn-download {
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
                    <a href="admin_job_post.php">
                        <i class="fas fa-briefcase"></i>
                        <span>Job Posts</span>
                    </a>
                </li>
                <li>
                    <a href="admin_employers.php" class="active">
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
            <p>Here's the approved employer management panel. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Approved Employers</h1>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <!-- Enhanced Filter Container - MODIFIED: Removed status filter -->
        <div class="filters">
            <div class="search-filter-group">
                <label class="filter-label">Search Approved Employers</label>
                <div class="search-filter-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by company name, industry, contact person, or email" value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
        </div>
        
        <!-- Enhanced Table Container -->
        <div class="table-container">
            <div class="table-header">
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Company Name</th>
                        <th>Industry</th>
                        <th>Docs Uploaded</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($employers)): ?>
                        <?php foreach ($employers as $employer): ?>
                        <tr>
                            <td><?= htmlspecialchars($employer['emp_company_name']) ?></td>
                            <td><?= htmlspecialchars($employer['emp_industry']) ?></td>
                            <td>
                                <div class="doc-status">
                                    <?php if (!empty($employer['emp_business_permit']) && !empty($employer['emp_dti_sec'])): ?>
                                        <i class="fas fa-check-circle doc-ok"></i>
                                        <span>All Documents</span>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle doc-missing"></i>
                                        <span>Missing Documents</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-approved">
                                    Approved
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn view" onclick="viewEmployer(<?= $employer['usr_id'] ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 30px; color: #999;">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                                No approved employers found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1&search=<?= urlencode($search_query) ?>" class="pagination-btn">
                        <i class="fas fa-angle-double-left"></i> First
                    </a>
                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search_query) ?>" class="pagination-btn">
                        <i class="fas fa-angle-left"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="pagination-btn disabled">
                        <i class="fas fa-angle-double-left"></i> First
                    </span>
                    <span class="pagination-btn disabled">
                        <i class="fas fa-angle-left"></i> Previous
                    </span>
                <?php endif; ?>
                
                <span class="pagination-info">
                    Page <?= $page ?> of <?= $total_pages ?>
                </span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search_query) ?>" class="pagination-btn">
                        Next <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?= $total_pages ?>&search=<?= urlencode($search_query) ?>" class="pagination-btn">
                        Last <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php else: ?>
                    <span class="pagination-btn disabled">
                        Next <i class="fas fa-angle-right"></i>
                    </span>
                    <span class="pagination-btn disabled">
                        Last <i class="fas fa-angle-double-right"></i>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Employer View Modal -->
    <?php if ($view_employer): ?>
    <div class="modal-overlay active" id="employerModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Employer Details</h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
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
                            <span class="status-badge status-approved">
                                Approved
                            </span>
                        </div>
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
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enhanced Document Viewer Modal -->
    <div class="document-viewer-modal" id="documentViewerModal">
        <div class="document-viewer-content">
            <div class="document-viewer-header">
                <h3 class="document-viewer-title" id="documentViewerTitle"><i class="fas fa-file-contract"></i> Document Viewer</h3>
                <div class="document-viewer-controls">
                    <div class="zoom-controls">
                        <button class="zoom-btn" onclick="zoomOut()"><i class="fas fa-search-minus"></i></button>
                        <span class="zoom-level" id="zoomLevel">100%</span>
                        <button class="zoom-btn" onclick="zoomIn()"><i class="fas fa-search-plus"></i></button>
                        <button class="zoom-btn" onclick="resetZoom()"><i class="fas fa-sync-alt"></i></button>
                    </div>
                    <button class="document-viewer-close" onclick="closeDocumentViewer()">&times;</button>
                </div>
            </div>
            <div class="document-viewer-body" id="documentViewerBody">
                <div class="document-container" id="documentContainer">
                    <!-- Content will be dynamically inserted here -->
                </div>
                <div class="scroll-hint" id="scrollHint">
                    <i class="fas fa-mouse"></i> Scroll to zoom • Click and drag to pan
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentZoom = 1;
        let currentDocumentElement = null;
        let isDragging = false;
        let startX, startY, scrollLeft, scrollTop;
        
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
        
        // Search functionality with debounce - MODIFIED: Removed status filter
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
        
        // Update filters function - MODIFIED: Removed status filter
        function updateFilters() {
            const searchQuery = searchInput.value;
            
            const url = new URL(window.location.href);
            url.searchParams.set('search', searchQuery);
            url.searchParams.set('page', '1'); // Reset to first page when filtering
            url.searchParams.delete('view_id');
            window.location.href = url.toString();
        }
        
        function viewEmployer(employerId) {
            const url = new URL(window.location.href);
            url.searchParams.set('view_id', employerId);
            window.location.href = url.toString();
        }
        
        function closeModal() {
            const url = new URL(window.location.href);
            url.searchParams.delete('view_id');
            window.location.href = url.toString();
        }
        
        function viewDocument(documentPath, documentTitle) {
            const documentViewerModal = document.getElementById('documentViewerModal');
            const documentViewerTitle = document.getElementById('documentViewerTitle');
            const documentContainer = document.getElementById('documentContainer');
            
            // Reset zoom
            currentZoom = 1;
            document.getElementById('zoomLevel').textContent = '100%';
            
            // Set the document title
            documentViewerTitle.innerHTML = `<i class="fas fa-file-contract"></i> ${documentTitle}`;
            
            // Clear previous content
            documentContainer.innerHTML = '';
            
            // Check file type
            const fileExt = documentPath.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExt);
            
            if (isImage) {
                // For images
                const img = document.createElement('img');
                img.src = documentPath;
                img.className = 'document-viewer-image';
                img.id = 'viewerImage';
                img.style.transform = `scale(${currentZoom})`;
                img.addEventListener('mousedown', startDrag);
                img.addEventListener('touchstart', startDragTouch, { passive: false });
                documentContainer.appendChild(img);
                currentDocumentElement = img;
            } else {
                // For PDFs and other documents
                const iframe = document.createElement('iframe');
                iframe.src = documentPath;
                iframe.className = 'document-viewer-iframe';
                iframe.id = 'viewerIframe';
                documentContainer.appendChild(iframe);
                currentDocumentElement = iframe;
            }
            
            // Show the document viewer
            documentViewerModal.classList.add('active');
            
            // Add scroll event listener for zooming
            documentContainer.addEventListener('wheel', handleScroll, { passive: false });
        }
        
        function handleScroll(e) {
            if (e.ctrlKey) {
                e.preventDefault();
                if (e.deltaY < 0) {
                    zoomIn();
                } else {
                    zoomOut();
                }
            }
        }
        
        function startDrag(e) {
            if (currentZoom <= 1) return;
            
            isDragging = true;
            startX = e.pageX - document.getElementById('documentContainer').offsetLeft;
            startY = e.pageY - document.getElementById('documentContainer').offsetTop;
            scrollLeft = document.getElementById('documentContainer').scrollLeft;
            scrollTop = document.getElementById('documentContainer').scrollTop;
            
            document.addEventListener('mousemove', doDrag);
            document.addEventListener('mouseup', stopDrag);
            document.getElementById('viewerImage').style.cursor = 'grabbing';
        }
        
        function startDragTouch(e) {
            if (currentZoom <= 1) return;
            
            isDragging = true;
            startX = e.touches[0].pageX - document.getElementById('documentContainer').offsetLeft;
            startY = e.touches[0].pageY - document.getElementById('documentContainer').offsetTop;
            scrollLeft = document.getElementById('documentContainer').scrollLeft;
            scrollTop = document.getElementById('documentContainer').scrollTop;
            
            document.addEventListener('touchmove', doDragTouch, { passive: false });
            document.addEventListener('touchend', stopDrag);
        }
        
        function doDrag(e) {
            if (!isDragging) return;
            e.preventDefault();
            
            const x = e.pageX - document.getElementById('documentContainer').offsetLeft;
            const y = e.pageY - document.getElementById('documentContainer').offsetTop;
            const walkX = (x - startX) * 2;
            const walkY = (y - startY) * 2;
            
            document.getElementById('documentContainer').scrollLeft = scrollLeft - walkX;
            document.getElementById('documentContainer').scrollTop = scrollTop - walkY;
        }
        
        function doDragTouch(e) {
            if (!isDragging) return;
            e.preventDefault();
            
            const x = e.touches[0].pageX - document.getElementById('documentContainer').offsetLeft;
            const y = e.touches[0].pageY - document.getElementById('documentContainer').offsetTop;
            const walkX = (x - startX) * 2;
            const walkY = (y - startY) * 2;
            
            document.getElementById('document-container').scrollLeft = scrollLeft - walkX;
            document.getElementById('document-container').scrollTop = scrollTop - walkY;
        }
        
        function stopDrag() {
            isDragging = false;
            document.removeEventListener('mousemove', doDrag);
            document.removeEventListener('touchmove', doDragTouch);
            document.removeEventListener('mouseup', stopDrag);
            document.removeEventListener('touchend', stopDrag);
            
            if (currentDocumentElement && currentDocumentElement.tagName === 'IMG') {
                currentDocumentElement.style.cursor = 'grab';
            }
        }
        
        function zoomIn() {
            if (currentZoom < 3) {
                currentZoom += 0.1;
                updateZoom();
            }
        }
        
        function zoomOut() {
            if (currentZoom > 0.5) {
                currentZoom -= 0.1;
                updateZoom();
            }
        }
        
        function resetZoom() {
            currentZoom = 1;
            updateZoom();
            // Reset scroll position
            document.getElementById('documentContainer').scrollLeft = 0;
            document.getElementById('documentContainer').scrollTop = 0;
        }
        
        function updateZoom() {
            if (currentDocumentElement) {
                if (currentDocumentElement.tagName === 'IMG') {
                    currentDocumentElement.style.transform = `scale(${currentZoom})`;
                    if (currentZoom > 1) {
                        currentDocumentElement.style.cursor = 'grab';
                    } else {
                        currentDocumentElement.style.cursor = 'default';
                    }
                }
                document.getElementById('zoomLevel').textContent = Math.round(currentZoom * 100) + '%';
            }
        }
        
        function closeDocumentViewer() {
            const documentViewerModal = document.getElementById('documentViewerModal');
            const documentContainer = document.getElementById('documentContainer');
            
            // Remove event listeners
            documentContainer.removeEventListener('wheel', handleScroll);
            document.removeEventListener('mousemove', doDrag);
            document.removeEventListener('touchmove', doDragTouch);
            
            // Clear the content
            documentContainer.innerHTML = '';
            currentDocumentElement = null;
            isDragging = false;
            
            // Hide the document viewer
            documentViewerModal.classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('employerModal');
            if (modal && e.target === modal) {
                closeModal();
            }
            
            const documentViewerModal = document.getElementById('documentViewerModal');
            if (documentViewerModal && e.target === documentViewerModal) {
                closeDocumentViewer();
            }
        });
        
        // Keyboard shortcuts for zoom and closing
        document.addEventListener('keydown', function(e) {
            const documentViewerModal = document.getElementById('documentViewerModal');
            if (documentViewerModal.classList.contains('active')) {
                if (e.key === 'Escape') {
                    closeDocumentViewer();
                } else if (e.key === '+' || e.key === '=') {
                    e.preventDefault();
                    zoomIn();
                } else if (e.key === '-' || e.key === '_') {
                    e.preventDefault();
                    zoomOut();
                } else if (e.key === '0') {
                    e.preventDefault();
                    resetZoom();
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
            // Handle notification click and mark as read
            $('.notification-link').on('click', function(e) {
                const notifId = $(this).data('notif-id');
                const notificationItem = $(this);
                
                // Only mark as read if it's unread
                if (notificationItem.hasClass('unread')) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'admin_employers.php',
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
                url: 'admin_employers.php?ajax=get_notifications',
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
    </script>
</body>
</html>