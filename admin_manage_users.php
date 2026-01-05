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
        $sql = "SELECT usr_id, usr_name, usr_role, usr_email, usr_created_at
                FROM users 
                WHERE usr_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND usr_role != 'admin'
                ORDER BY usr_created_at DESC";
        $newUsersStmt = $conn->query($sql);
        $newUsers = $newUsersStmt->fetchAll(PDO::FETCH_ASSOC);
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
    $pendingEmployersStmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role = 'employer' AND usr_is_approved = FALSE");
    $pendingEmployers = $pendingEmployersStmt->fetch(PDO::FETCH_ASSOC);
    
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
    $pendingJobsStmt = $conn->query("SELECT COUNT(*) as count FROM jobs WHERE job_status = 'pending'");
    $pendingJobs = $pendingJobsStmt->fetch(PDO::FETCH_ASSOC);
    
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
    $incompleteProfilesStmt = $conn->query("
        SELECT COUNT(*) as count FROM users u 
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id 
        WHERE u.usr_role = 'graduate' 
        AND (g.grad_id IS NULL OR g.grad_school_id = '' OR g.grad_degree = '' OR g.grad_job_preference = '')
    ");
    $incompleteProfiles = $incompleteProfilesStmt->fetch(PDO::FETCH_ASSOC);
    
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
    $recentApplicationsStmt = $conn->query("
        SELECT COUNT(*) as count FROM applications 
        WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $recentApplications = $recentApplicationsStmt->fetch(PDO::FETCH_ASSOC);
    
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
    $lastAppNotifStmt = $conn->query("SELECT MAX(notif_created_at) FROM notifications WHERE notif_type = 'new_application'");
    $lastAppNotif = $lastAppNotifStmt->fetchColumn();
    
    if ($lastAppNotif) {
        $newApplicationsStmt = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at > '$lastAppNotif'
        ");
        $newApplications = $newApplicationsStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $newApplicationsStmt = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $newApplications = $newApplicationsStmt->fetch(PDO::FETCH_ASSOC);
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
    
    // Check employment rate trends
    $employmentRateStmt = $conn->query("
        SELECT 
            COUNT(*) as total_graduates,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_graduates
        FROM graduates g
        LEFT JOIN users u ON g.grad_usr_id = u.usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
    ");
    $employmentRate = $employmentRateStmt->fetch(PDO::FETCH_ASSOC);
    
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
    
    // Check top skills in demand - FIXED QUERY
    $topSkillsStmt = $conn->query("
        SELECT s.skill_name, COUNT(gs.skill_id) as demand_count
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        GROUP BY s.skill_name
        ORDER BY demand_count DESC
        LIMIT 1
    ");
    $topSkills = $topSkillsStmt->fetch(PDO::FETCH_ASSOC);
    
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
// EXISTING USER MANAGEMENT FUNCTIONALITY
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_manage_users.php");
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

// Handle user approval/decline actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_user'])) {
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET usr_is_approved = TRUE, usr_account_status = 'active' WHERE usr_id = :id AND usr_role != 'admin'");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
        // Add notification
        $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your account has been approved by administrator', 'user_approval')");
        $notif_stmt->bindParam(':user_id', $user_id);
        $notif_stmt->execute();
        
    } elseif (isset($_POST['decline_user'])) {
        $user_id = $_POST['user_id'];
        
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
        
        $stmt = $conn->prepare("DELETE FROM users WHERE usr_id = :id AND usr_role != 'admin'");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
    } elseif (isset($_POST['deactivate_user'])) {
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET usr_is_approved = FALSE, usr_account_status = 'suspended' WHERE usr_id = :id AND usr_role != 'admin'");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
        // Add notification
        $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your account has been suspended by administrator', 'user_suspension')");
        $notif_stmt->bindParam(':user_id', $user_id);
        $notif_stmt->execute();
        
    } elseif (isset($_POST['activate_user'])) {
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET usr_is_approved = TRUE, usr_account_status = 'active' WHERE usr_id = :id AND usr_role != 'admin'");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
        // Add notification
        $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your account has been reactivated by administrator', 'user_activation')");
        $notif_stmt->bindParam(':user_id', $user_id);
        $notif_stmt->execute();
        
    } elseif (isset($_POST['archive_user'])) {
        $user_id = $_POST['user_id'];
        // FIXED: Using 'inactive' instead of 'archived' to match database ENUM
        $stmt = $conn->prepare("UPDATE users SET usr_account_status = 'inactive', usr_is_approved = FALSE WHERE usr_id = :id AND usr_role != 'admin'");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
        // Add notification
        $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your account has been archived by administrator', 'user_archival')");
        $notif_stmt->bindParam(':user_id', $user_id);
        $notif_stmt->execute();
        
    } elseif (isset($_POST['restore_user'])) {
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET usr_account_status = 'active', usr_is_approved = TRUE WHERE usr_id = :id AND usr_role != 'admin'");
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();
        
        // Add notification
        $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) VALUES (:user_id, 'Your account has been restored by administrator', 'user_restoration')");
        $notif_stmt->bindParam(':user_id', $user_id);
        $notif_stmt->execute();
    }
}

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

// Get user details for modal if requested
$user_details = null;
$grad_skills = [];
$grad_applications = [];
$alumni_photos = ['front' => null, 'back' => null];
$staff_photos = ['front' => null, 'back' => null];

// ============================================================================
// ENHANCED: MODIFIED JOB PREFERENCES RETRIEVAL SYSTEM - FIXED VERSION
// ============================================================================
$job_preferences = [];

if (isset($_GET['view_user'])) {
    $user_id = $_GET['view_user'];
    $stmt = $conn->prepare("SELECT usr_id, usr_name, usr_email, usr_phone, usr_role, usr_gender, usr_birthdate, usr_address, usr_is_approved, usr_account_status, usr_created_at, usr_profile_photo FROM users WHERE usr_id = :id AND usr_role != 'admin'");
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();
    $user_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_details && $user_details['usr_role'] === 'graduate') {
        $grad_stmt = $conn->prepare("SELECT * FROM graduates WHERE grad_usr_id = :id");
        $grad_stmt->bindParam(':id', $user_id);
        $grad_stmt->execute();
        $user_details['graduate_info'] = $grad_stmt->fetch(PDO::FETCH_ASSOC);
        
        // ENHANCED: Get job preferences from graduates table (stored as JSON) - FIXED VERSION
        if (!empty($user_details['graduate_info']['grad_job_preference'])) {
            $job_preferences_json = $user_details['graduate_info']['grad_job_preference'];
            $job_preferences = json_decode($job_preferences_json, true) ?? [];
            
            // If job preferences is a string (not an array), try to parse it
            if (is_string($job_preferences)) {
                $job_preferences = json_decode($job_preferences, true) ?? [];
            }
            
            // If still not an array, try to explode by comma
            if (!is_array($job_preferences) && is_string($job_preferences_json)) {
                $job_preferences = array_map('trim', explode(',', $job_preferences_json));
            }
            
            // Ensure we have an array
            if (!is_array($job_preferences)) {
                $job_preferences = [];
            }
        } else {
            // If grad_job_preference is empty, use empty array
            $job_preferences = [];
        }
        
        // Store job preferences in user_details for easy access in HTML
        $user_details['job_preferences'] = $job_preferences;
        
        // Process alumni photos - FIXED: Handle JSON format
        if (!empty($user_details['graduate_info']['grad_alumni_id_photo'])) {
            $alumni_photos_data = json_decode($user_details['graduate_info']['grad_alumni_id_photo'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($alumni_photos_data)) {
                $alumni_photos = $alumni_photos_data;
            } else {
                // Fallback: treat as single string (old format)
                $alumni_photos['front'] = $user_details['graduate_info']['grad_alumni_id_photo'];
            }
        }
        
        // Get graduate skills
        $skills_stmt = $conn->prepare("
            SELECT s.skill_name, s.skill_category, gs.skill_level 
            FROM graduate_skills gs
            JOIN skills s ON gs.skill_id = s.skill_id
            WHERE gs.grad_usr_id = :user_id
            ORDER BY gs.skill_level DESC, s.skill_name
        ");
        $skills_stmt->bindParam(':user_id', $user_id);
        $skills_stmt->execute();
        $grad_skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent applications
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
        $apps_stmt->bindParam(':user_id', $user_id);
        $apps_stmt->execute();
        $grad_applications = $apps_stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($user_details && $user_details['usr_role'] === 'employer') {
        $emp_stmt = $conn->prepare("SELECT * FROM employers WHERE emp_usr_id = :id");
        $emp_stmt->bindParam(':id', $user_id);
        $emp_stmt->execute();
        $user_details['employer_info'] = $emp_stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($user_details && $user_details['usr_role'] === 'staff') {
        $staff_stmt = $conn->prepare("SELECT * FROM staff WHERE staff_usr_id = :id");
        $staff_stmt->bindParam(':id', $user_id);
        $staff_stmt->execute();
        $user_details['staff_info'] = $staff_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Process staff ID photos - FIXED: Handle JSON format
        if (!empty($user_details['staff_info']['staff_id_photo'])) {
            $staff_photos_data = json_decode($user_details['staff_info']['staff_id_photo'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($staff_photos_data)) {
                $staff_photos = $staff_photos_data;
            } else {
                // Fallback: treat as single string (old format)
                $staff_photos['front'] = $user_details['staff_info']['staff_id_photo'];
            }
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// MODIFIED: Exclude inactive users from the main table
$query = "SELECT * FROM users WHERE usr_role != 'admin' AND usr_account_status != 'inactive'";
$params = [];

if ($status_filter === 'pending') {
    $query .= " AND usr_is_approved = FALSE AND usr_account_status != 'inactive'";
} elseif ($status_filter === 'approved') {
    $query .= " AND usr_is_approved = TRUE AND usr_account_status = 'active'";
} elseif ($status_filter === 'suspended') {
    $query .= " AND usr_account_status = 'suspended'";
} elseif ($status_filter === 'inactive') {
    // MODIFIED: If inactive filter is selected, show empty since they're in archive
    $query .= " AND 1=0"; // This will return no results
}

if ($type_filter !== 'all') {
    $query .= " AND usr_role = :type";
    $params[':type'] = $type_filter;
}

if (!empty($search_query)) {
    $query .= " AND (usr_name LIKE :search OR usr_email LIKE :search OR usr_phone LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$query .= " ORDER BY usr_created_at DESC";

try {
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching users: " . $e->getMessage();
    $users = [];
}

// Get inactive users for the archive modal
$inactive_users = [];
try {
    $inactive_stmt = $conn->prepare("SELECT * FROM users WHERE usr_account_status = 'inactive' AND usr_role != 'admin' ORDER BY usr_created_at DESC");
    $inactive_stmt->execute();
    $inactive_users = $inactive_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching inactive users: " . $e->getMessage();
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

// Get user statistics for cards
$total_users_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role != 'admin' AND usr_account_status != 'inactive'");
$total_users = $total_users_stmt->fetchColumn();

$pending_users_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_is_approved = FALSE AND usr_account_status != 'inactive' AND usr_role != 'admin'");
$pending_users = $pending_users_stmt->fetchColumn();

$active_users_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_is_approved = TRUE AND usr_account_status = 'active' AND usr_role != 'admin'");
$active_users = $active_users_stmt->fetchColumn();

$suspended_users_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_account_status = 'suspended' AND usr_role != 'admin'");
$suspended_users = $suspended_users_stmt->fetchColumn();

$inactive_users_count_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_account_status = 'inactive' AND usr_role != 'admin'");
$inactive_users_count = $inactive_users_count_stmt->fetchColumn();

// Get user type statistics
$graduate_count_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'graduate' AND usr_role != 'admin' AND usr_account_status != 'inactive'");
$graduate_count = $graduate_count_stmt->fetchColumn();

$employer_count_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'employer' AND usr_role != 'admin' AND usr_account_status != 'inactive'");
$employer_count = $employer_count_stmt->fetchColumn();

$staff_count_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'staff' AND usr_role != 'admin' AND usr_account_status != 'inactive'");
$staff_count = $staff_count_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Manage Users</title>
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
        
        .page-actions {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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
        
        .btn-inactive {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .btn-inactive:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
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
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-top: 4px solid var(--accent-color);
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
        }
        
        .filter-select {
            padding: 11px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            min-width: 170px;
            background: white;
            transition: all 0.3s;
            font-size: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
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
            width: 350px;
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
        
        /* Enhanced Users Table */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 20px;
            border-top: 4px solid var(--primary-color);
        }
        
        .users-table {
            width: 100%;
            background-color: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-header {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr 1fr 2fr;
            padding: 18px 25px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .table-row {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr 1fr 2fr;
            padding: 16px 25px;
            border-bottom: 1px solid #f0f0f0;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .table-row:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        
        .table-row:last-child {
            border-bottom: none;
        }
        
        .status-badge {
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .status-active {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .status-pending {
            background: linear-gradient(135deg, var(--secondary-color), #ffa700);
            color: white;
        }
        
        .status-suspended {
            background: linear-gradient(135deg, var(--red), #e74c3c);
            color: white;
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #6c757d, #95a5a6);
            color: white;
        }
        
        /* Enhanced Action Buttons - Uniform Sizes */
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
            font-size: 0.75rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            white-space: nowrap;
            min-width: 80px;
            height: 34px;
            text-align: center;
        }
        
        .action-btn.view {
            background: linear-gradient(135deg, var(--blue), #3498db);
            color: white;
        }
        
        .action-btn.edit {
            background: linear-gradient(135deg, var(--secondary-color), #ffa700);
            color: white;
        }
        
        .action-btn.approve {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .action-btn.decline {
            background: linear-gradient(135deg, var(--red), #e74c3c);
            color: white;
        }
        
        .action-btn.deactivate {
            background: linear-gradient(135deg, var(--red), #e74c3c);
            color: white;
        }
        
        .action-btn.activate {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .action-btn.archive {
            background: linear-gradient(135deg, #6c757d, #95a5a6);
            color: white;
        }
        
        .action-btn.restore {
            background: linear-gradient(135deg, var(--blue), #3498db);
            color: white;
        }
        
        .action-btn.undo {
            background: linear-gradient(135deg, var(--secondary-color), #ffa700);
            color: white;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        /* Enhanced User Profile Modal Styles */
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
        
        .user-modal {
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
        
        .modal-overlay.active .user-modal {
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
        
        .user-content {
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
            display: flex;
            flex-direction: column;
            gap: 25px; /* Increased spacing between sections */
        }
        
        .profile-header-info {
            text-align: center;
            margin-bottom: 0;
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
        
        .status-active-badge { background-color: #1f7a11; }
        .status-pending-badge { background-color: #f7a100; }
        .status-suspended-badge { background-color: #d32f2f; }
        .status-inactive-badge { background-color: #6c757d; }
        
        .profile-name {
            font-size: 26px;
            margin-bottom: 8px;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .profile-role {
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
        
        .status-active {
            background: linear-gradient(135deg, #1f7a11, #2ecc71);
            color: white;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #f7a100, #ffa700);
            color: white;
        }
        
        .status-suspended {
            background: linear-gradient(135deg, #d32f2f, #e74c3c);
            color: white;
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #6c757d, #95a5a6);
            color: white;
        }
        
        .profile-details {
            margin-top: 0;
            margin-bottom: 0;
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
        
        /* MODIFIED: Enhanced Address Information Section - Better Spacing */
        .address-section {
            margin-top: 0;
            margin-bottom: 0;
            padding-top: 20px;
            border-top: 2px solid rgba(110, 3, 3, 0.1);
        }
        
        .address-title {
            font-size: 18px;
            color: var(--primary-color);
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .address-title i {
            color: var(--secondary-color);
        }
        
        .address-content {
            background: white;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary-color);
        }
        
        .address-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 12px;
            padding: 8px 0;
            line-height: 1.6;
        }
        
        .address-label {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 100px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding-top: 2px;
        }
        
        .address-label i {
            width: 18px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .address-text {
            color: #555;
            flex: 1;
            line-height: 1.6;
            padding-left: 5px;
            font-size: 15px;
        }
        
        .no-address {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 25px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed #ddd;
        }
        
        .no-address i {
            font-size: 2rem;
            margin-bottom: 15px;
            color: #ccc;
            display: block;
        }
        
        /* MODIFIED: Enhanced Job Preferences Section - Better Spacing */
        .preferences-section {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--purple);
        }
        
        .preferences-title {
            font-size: 18px;
            color: var(--primary-color);
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 2px solid rgba(110, 3, 3, 0.1);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .preferences-title i {
            color: var(--purple);
        }
        
        .preferences-count {
            font-size: 14px;
            color: #666;
            margin-bottom: 18px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            border-left: 3px solid var(--purple);
        }
        
        .preferences-count i {
            color: var(--purple);
            font-size: 1rem;
        }
        
        .preference-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 5px;
        }
        
        .preference-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: linear-gradient(135deg, var(--purple), #8a2be2);
            color: white;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 3px 8px rgba(106, 13, 173, 0.2);
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .preference-tag:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(106, 13, 173, 0.3);
        }
        
        .preference-tag i {
            font-size: 0.9rem;
        }
        
        .no-preferences {
            color: #666;
            font-style: italic;
            font-size: 14px;
            padding: 25px 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            text-align: center;
            border: 1px dashed #ddd;
        }
        
        .no-preferences i {
            font-size: 2rem;
            margin-bottom: 12px;
            color: #ccc;
            display: block;
        }
        
        .no-preferences p {
            margin: 0;
            line-height: 1.5;
        }
        
        /* MODIFIED: Enhanced Academic Information Section - Single Row Layout */
        .academic-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--blue);
        }
        
        .academic-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 15px;
        }
        
        .academic-item {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border-left: 4px solid var(--blue);
        }
        
        .academic-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        
        .academic-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .academic-value {
            font-size: 18px;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        /* Enhanced Alumni ID Photo Section - FIXED */
        .alumni-photo-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--secondary-color);
        }
        
        .alumni-photo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }
        
        .alumni-photo-item {
            text-align: center;
        }
        
        .alumni-photo-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .alumni-photo-container {
            text-align: center;
        }
        
        .alumni-photo {
            max-width: 100%;
            max-height: 300px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            border: 3px solid var(--primary-color);
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .alumni-photo:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .alumni-photo-actions {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        
        .photo-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .photo-btn.view {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .photo-btn.download {
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
        }
        
        .photo-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Enhanced Staff ID Photo Section - NEW */
        .staff-photo-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--purple);
        }
        
        .staff-photo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 15px;
        }
        
        .staff-photo-item {
            text-align: center;
        }
        
        .staff-photo-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .staff-photo-container {
            text-align: center;
        }
        
        .staff-photo {
            max-width: 100%;
            max-height: 300px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            border: 3px solid var(--purple);
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .staff-photo:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .staff-photo-actions {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
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
            border-top: 4px solid var(--green);
        }
        
        .summary-content {
            line-height: 1.7;
            color: #555;
            font-size: 15px;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        /* Enhanced Company Document Viewer */
        .document-viewer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .document-viewer h3 {
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .document-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .document-item {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            transition: all 0.3s;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .document-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.12);
        }
        
        .document-title {
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary-color);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .document-title i {
            color: var(--secondary-color);
        }
        
        .document-preview {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
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
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        
        .document-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .document-btn.view {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .document-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Document Viewer Modal */
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
        
        /* Confirmation Modal Styles */
        .confirmation-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2100;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        
        .confirmation-modal.active {
            opacity: 1;
            visibility: visible;
        }
        
        .confirmation-content {
            background-color: white;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            animation: modalFadeIn 0.3s;
            text-align: center;
            padding: 30px;
        }
        
        .confirmation-icon {
            font-size: 3.5rem;
            margin-bottom: 20px;
        }
        
        .confirmation-icon.approve {
            color: var(--green);
        }
        
        .confirmation-icon.decline {
            color: var(--red);
        }
        
        .confirmation-icon.suspend {
            color: var(--secondary-color);
        }
        
        .confirmation-icon.archive {
            color: #9e9e9e;
        }
        
        .confirmation-title {
            font-size: 1.6rem;
            color: var(--primary-color);
            margin-bottom: 15px;
            font-weight: 700;
        }
        
        .confirmation-message {
            color: #555;
            margin-bottom: 25px;
            line-height: 1.5;
            font-size: 1rem;
        }
        
        .confirmation-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        .confirmation-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            min-width: 120px;
            font-size: 0.95rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .confirmation-btn.confirm {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .confirmation-btn.confirm:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .confirmation-btn.cancel {
            background: #f1f1f1;
            color: #333;
        }
        
        .confirmation-btn.cancel:hover {
            background: #e5e5e5;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        /* Inactive Modal Styles - UPDATED TO MATCH MAIN UI COLOR SCHEME */
        .inactive-modal {
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
        
        .modal-overlay.active .inactive-modal {
            transform: translateY(0);
        }
        
        .inactive-header {
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
        
        .inactive-title {
            font-size: 1.8rem;
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .inactive-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .inactive-content {
            padding: 30px;
        }
        
        .inactive-table {
            width: 100%;
            background-color: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary-color);
        }
        
        .inactive-table-header {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr 1fr 2fr;
            padding: 18px 25px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .inactive-table-row {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr 1fr 2fr;
            padding: 16px 25px;
            border-bottom: 1px solid #f0f0f0;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .inactive-table-row:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        
        .inactive-table-row:last-child {
            border-bottom: none;
        }
        
        .inactive-actions {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            justify-content: flex-start;
            align-items: center;
        }
        
        .inactive-empty {
            text-align: center;
            padding: 40px 20px;
            color: #999;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .inactive-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .inactive-empty h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #777;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .table-header, .table-row {
                grid-template-columns: 2fr 2fr 1fr 1.5fr;
            }
            
            .inactive-table-header, .inactive-table-row {
                grid-template-columns: 2fr 2fr 1fr 1.5fr;
            }
            
            .last-login {
                display: none;
            }
        }
        
        @media (max-width: 900px) {
            .table-header, .table-row {
                grid-template-columns: 2fr 1.5fr 1.5fr;
            }
            
            .inactive-table-header, .inactive-table-row {
                grid-template-columns: 2fr 1.5fr 1.5fr;
            }
            
            .email {
                display: none;
            }
            
            .user-content {
                grid-template-columns: 1fr;
            }
            
            .document-list {
                grid-template-columns: 1fr;
            }
            
            .academic-info-grid {
                grid-template-columns: 1fr;
            }
            
            .alumni-photo-grid, .staff-photo-grid {
                grid-template-columns: 1fr;
            }
            
            /* MODIFIED: Adjust spacing for mobile */
            .profile-section {
                gap: 20px;
            }
            
            .address-section, .preferences-section {
                margin-top: 15px;
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
            
            .table-header, .table-row {
                grid-template-columns: 2fr 1fr;
            }
            
            .inactive-table-header, .inactive-table-row {
                grid-template-columns: 2fr 1fr;
            }
            
            .email, .user-type, .last-login {
                display: none;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .filters {
                flex-direction: column;
                gap: 10px;
            }
            
            .search-filter-container {
                width: 100%;
                margin-top: 10px;
            }
            
            .search-filter-group {
                width: 100%;
            }
            
            .top-nav {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .document-viewer-content {
                width: 98%;
                height: 90vh;
            }
            
            .document-viewer-header {
                flex-direction: column;
                gap: 10px;
                padding: 10px;
            }
            
            .document-viewer-controls {
                width: 100%;
                justify-content: space-between;
            }
            
            .scroll-hint {
                bottom: 10px;
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .skills-container {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            }
            
            /* Adjust action buttons for mobile */
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .action-btn {
                font-size: 0.7rem;
                padding: 6px 10px;
                min-width: 70px;
                height: 32px;
            }
            
            .inactive-actions {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            /* MODIFIED: Adjust spacing for mobile */
            .address-content {
                padding: 15px;
            }
            
            .preference-tags {
                gap: 8px;
            }
            
            .preference-tag {
                padding: 8px 14px;
                font-size: 13px;
            }
        }
        
        @media (max-width: 576px) {
            .page-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .btn {
                width: 48%;
                justify-content: center;
            }
            
            .table-header, .table-row {
                grid-template-columns: 1fr;
                padding: 10px;
                gap: 5px;
            }
            
            .inactive-table-header, .inactive-table-row {
                grid-template-columns: 1fr;
                padding: 10px;
                gap: 5px;
            }
            
            .action-buttons {
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            
            .document-actions {
                flex-direction: column;
            }
            
            .document-viewer-content {
                width: 100%;
                height: 100vh;
                border-radius: 0;
            }
            
            .zoom-controls {
                display: none;
            }
            
            .scroll-hint {
                display: none;
            }
            
            .confirmation-content {
                width: 95%;
                padding: 20px 15px;
            }
            
            .confirmation-buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .confirmation-btn {
                width: 100%;
            }
            
            .inactive-actions {
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            
            .alumni-photo-actions, .staff-photo-actions {
                flex-direction: column;
                align-items: center;
            }
            
            /* MODIFIED: Further spacing adjustments for small screens */
            .profile-section {
                padding: 20px;
                gap: 18px;
            }
            
            .address-section, .preferences-section {
                padding: 18px;
            }
            
            .address-content {
                padding: 12px;
            }
            
            .preference-tags {
                gap: 6px;
            }
            
            .preference-tag {
                padding: 6px 12px;
                font-size: 12px;
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
                    <a href="admin_manage_users.php" class="active">
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
            <p>Here you can manage all user accounts in the system. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Manage Users</h1>
            <div class="page-actions">
                <button class="btn btn-inactive" onclick="showInactiveModal()">
                    <i class="fas fa-archive"></i>Archived
                </button>
            </div>
        </div>
        
        <!-- Enhanced Filter Container -->
        <div class="filters">
            <div class="search-filter-group">
                <label class="filter-label">Search Users</label>
                <div class="search-filter-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by name, email, or phone..." value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
            <div class="filter-group">
                <label class="filter-label">Account Status</label>
                <select class="filter-select" id="statusFilter" onchange="updateFilters()">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <!-- MODIFIED: Removed inactive option from main filter -->
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">User Type</label>
                <select class="filter-select" id="typeFilter" onchange="updateFilters()">
                    <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="graduate" <?= $type_filter === 'graduate' ? 'selected' : '' ?>>Alumni</option>
                    <option value="employer" <?= $type_filter === 'employer' ? 'selected' : '' ?>>Employers</option>
                    <option value="staff" <?= $type_filter === 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
            </div>
        </div>
        
        <!-- Users Table -->
        <div class="table-container">
            <div class="users-table">
                <div class="table-header">
                    <div>Name</div>
                    <div>Email</div>
                    <div>Status</div>
                    <div>Registered On</div>
                    <div>Actions</div>
                </div>
                <?php if (!empty($users)): ?>
                    <?php foreach ($users as $user): 
                        $status_class = '';
                        $status_text = '';
                        if ($user['usr_account_status'] === 'inactive') {
                            $status_class = 'status-inactive';
                            $status_text = 'Inactive';
                        } elseif ($user['usr_is_approved']) {
                            $status_class = 'status-active';
                            $status_text = 'Approved';
                        } else {
                            $status_class = 'status-pending';
                            $status_text = 'Pending';
                        }
                    ?>
                        <div class="table-row" data-status="<?= $user['usr_is_approved'] ? 'approved' : 'pending' ?>" data-type="<?= $user['usr_role'] ?>">
                            <div><?= htmlspecialchars($user['usr_name']) ?></div>
                            <div><?= htmlspecialchars($user['usr_email']) ?></div>
                            <div>
                                <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                            </div>
                            <div><?= date('M j, Y', strtotime($user['usr_created_at'])) ?></div>
                            <div class="action-buttons">
                                <button class="action-btn view" onclick="viewUser(<?= $user['usr_id'] ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if ($user['usr_account_status'] === 'inactive'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['usr_id'] ?>">
                                        <button type="submit" name="restore_user" class="action-btn restore">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if (!$user['usr_is_approved']): ?>
                                        <button type="button" onclick="showConfirmation('approve', <?= $user['usr_id'] ?>, '<?= htmlspecialchars($user['usr_name']) ?>')" class="action-btn approve">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" onclick="showConfirmation('decline', <?= $user['usr_id'] ?>, '<?= htmlspecialchars($user['usr_name']) ?>')" class="action-btn decline">
                                            <i class="fas fa-times"></i> Decline
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="showConfirmation('suspend', <?= $user['usr_id'] ?>, '<?= htmlspecialchars($user['usr_name']) ?>')" class="action-btn deactivate">
                                            <i class="fas fa-user-slash"></i> Suspend
                                        </button>
                                        <button type="button" onclick="showConfirmation('archive', <?= $user['usr_id'] ?>, '<?= htmlspecialchars($user['usr_name']) ?>')" class="action-btn archive">
                                            <i class="fas fa-archive"></i> Archive
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="table-row">
                        <div style="text-align: center; padding: 20px; grid-column: 1 / -1;">
                            No users found matching your criteria
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="confirmation-modal" id="confirmationModal">
        <div class="confirmation-content">
            <div class="confirmation-icon" id="confirmationIcon">
                <i class="fas fa-question-circle"></i>
            </div>
            <h3 class="confirmation-title" id="confirmationTitle">Confirm Action</h3>
            <p class="confirmation-message" id="confirmationMessage">Are you sure you want to perform this action?</p>
            <div class="confirmation-buttons">
                <form method="POST" id="confirmationForm" style="display: none;">
                    <input type="hidden" name="user_id" id="confirmationUserId">
                </form>
                <button class="confirmation-btn confirm" id="confirmationConfirm">Confirm</button>
                <button class="confirmation-btn cancel" onclick="hideConfirmation()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Inactive Users Modal -->
    <div class="modal-overlay" id="inactiveModal">
        <div class="inactive-modal">
            <div class="inactive-header">
                <h2 class="inactive-title">
                    <i class="fas fa-archive"></i> Archived Users
                    <span class="inactive-count"><?= count($inactive_users) ?> users</span>
                </h2>
                <button class="modal-close" onclick="closeInactiveModal()">&times;</button>
            </div>
            <div class="inactive-content">
                <?php if (!empty($inactive_users)): ?>
                    <div class="inactive-table">
                        <div class="inactive-table-header">
                            <div>Name</div>
                            <div>Email</div>
                            <div>User Type</div>
                            <div>Inactive Since</div>
                            <div>Actions</div>
                        </div>
                        <?php foreach ($inactive_users as $user): ?>
                            <div class="inactive-table-row">
                                <div><?= htmlspecialchars($user['usr_name']) ?></div>
                                <div><?= htmlspecialchars($user['usr_email']) ?></div>
                                <div><?= ucfirst(htmlspecialchars($user['usr_role'])) ?></div>
                                <div><?= date('M j, Y', strtotime($user['usr_created_at'])) ?></div>
                                <div class="inactive-actions">
                                    <button class="action-btn view" onclick="viewUser(<?= $user['usr_id'] ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['usr_id'] ?>">
                                        <button type="submit" name="restore_user" class="action-btn restore">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="inactive-empty">
                        <i class="fas fa-archive"></i>
                        <h3>No Archived Users</h3>
                        <p>There are currently no archived users in the system.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Enhanced User Details Modal -->
    <?php if ($user_details): ?>
    <div class="modal-overlay active" id="userModal">
        <div class="user-modal">
            <div class="modal-header">
                <h2 class="modal-title">User Profile</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-content">
                <div class="user-content">
                    <div class="profile-section">
                        <div class="profile-header-info">
                            <div class="profile-img-container">
                                <?php
                                    // MODIFIED: Get user profile photo with fallback
                                    $profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($user_details['usr_name']) . "&background=3498db&color=fff";
                                    if (!empty($user_details['usr_profile_photo'])) {
                                        if (file_exists("uploads/profile_photos/" . $user_details['usr_profile_photo'])) {
                                            $profile_photo = "uploads/profile_photos/" . $user_details['usr_profile_photo'];
                                        } elseif (file_exists($user_details['usr_profile_photo'])) {
                                            $profile_photo = $user_details['usr_profile_photo'];
                                        }
                                    }
                                    
                                    $status_class = '';
                                    $status_badge_class = '';
                                    if ($user_details['usr_account_status'] === 'inactive') {
                                        $status_class = 'status-inactive';
                                        $status_badge_class = 'status-inactive-badge';
                                    } elseif ($user_details['usr_is_approved']) {
                                        $status_class = 'status-active';
                                        $status_badge_class = 'status-active-badge';
                                    } else {
                                        $status_class = 'status-pending';
                                        $status_badge_class = 'status-pending-badge';
                                    }
                                ?>
                                <img src="<?= $profile_photo ?>" alt="<?= htmlspecialchars($user_details['usr_name']) ?>" class="profile-img">
                                <div class="profile-status-badge <?= $status_badge_class ?>"></div>
                            </div>
                            <h2 class="profile-name"><?= htmlspecialchars($user_details['usr_name']) ?></h2>
                            <p class="profile-role"><?= ucfirst(htmlspecialchars($user_details['usr_role'])) ?></p>
                            <span class="profile-status <?= $status_class ?>">
                                <?php 
                                if ($user_details['usr_account_status'] === 'inactive') {
                                    echo 'Inactive';
                                } elseif ($user_details['usr_is_approved']) {
                                    echo 'Approved';
                                } else {
                                    echo 'Pending Approval';
                                }
                                ?>
                            </span>
                        </div>
                        
                        <div class="profile-details">
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-envelope"></i> Email:</span>
                                <span class="detail-value"><?= htmlspecialchars($user_details['usr_email']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-phone"></i> Phone:</span>
                                <span class="detail-value"><?= !empty($user_details['usr_phone']) ? htmlspecialchars($user_details['usr_phone']) : 'Not provided' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-venus-mars"></i> Gender:</span>
                                <span class="detail-value"><?= !empty($user_details['usr_gender']) ? htmlspecialchars($user_details['usr_gender']) : 'Not provided' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-birthday-cake"></i> Birthdate:</span>
                                <span class="detail-value"><?= !empty($user_details['usr_birthdate']) ? date('M j, Y', strtotime($user_details['usr_birthdate'])) : 'Not provided' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label"><i class="fas fa-calendar"></i> Registered:</span>
                                <span class="detail-value"><?= date('M j, Y', strtotime($user_details['usr_created_at'])) ?></span>
                            </div>
                        </div>
                        
                        <!-- MODIFIED: Enhanced Address Information Section with Better Spacing -->
                        <div class="address-section">
                            <h3 class="address-title"><i class="fas fa-map-marker-alt"></i> Address Information</h3>
                            <div class="address-content">
                                <?php if (!empty($user_details['usr_address'])): ?>
                                    <div class="address-item">
                                        <div class="address-label">
                                            <i class="fas fa-home"></i> Address:
                                        </div>
                                        <div class="address-text">
                                            <?= htmlspecialchars($user_details['usr_address']) ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-address">
                                        <i class="fas fa-map-marker-slash"></i>
                                        <p>No address information provided</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- MODIFIED: Enhanced Job Preferences Section with Better Spacing -->
                        <?php if ($user_details['usr_role'] === 'graduate' && isset($user_details['job_preferences'])): ?>
                        <div class="preferences-section">
                            <h3 class="preferences-title"><i class="fas fa-bullseye"></i> Job Preferences</h3>
                            <div class="preferences-count">
                                <i class="fas fa-list"></i> 
                                <?= count($user_details['job_preferences']) ?> preference(s) set
                            </div>
                            <div class="preference-tags">
                                <?php if (!empty($user_details['job_preferences'])): ?>
                                    <?php foreach ($user_details['job_preferences'] as $preference): ?>
                                        <div class="preference-tag">
                                            <i class="fas fa-check"></i>
                                            <?= htmlspecialchars($preference) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-preferences">
                                        <i class="fas fa-info-circle"></i>
                                        No job preferences set yet
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <?php if ($user_details['usr_role'] === 'graduate' && isset($user_details['graduate_info'])): ?>
                        <!-- MODIFIED: Enhanced Academic Information Section - Single Row Layout -->
                        <div class="academic-section">
                            <h3 class="section-title"><i class="fas fa-graduation-cap"></i> Academic Information</h3>
                            <div class="academic-info-grid">
                                <div class="academic-item">
                                    <div class="academic-label">Alumni ID Number</div>
                                    <div class="academic-value"><?= htmlspecialchars($user_details['graduate_info']['grad_school_id']) ?></div>
                                </div>
                                <div class="academic-item">
                                    <div class="academic-label">Degree/Course</div>
                                    <div class="academic-value"><?= htmlspecialchars($user_details['graduate_info']['grad_degree']) ?></div>
                                </div>
                                <div class="academic-item">
                                    <div class="academic-label">Year Graduated</div>
                                    <div class="academic-value"><?= htmlspecialchars($user_details['graduate_info']['grad_year_graduated']) ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Enhanced Alumni ID Photo Section - FIXED -->
                        <?php if (!empty($alumni_photos['front']) || !empty($alumni_photos['back'])): ?>
                        <div class="alumni-photo-section">
                            <h3 class="section-title"><i class="fas fa-id-card"></i> Alumni ID Photos</h3>
                            <div class="alumni-photo-grid">
                                <?php if (!empty($alumni_photos['front'])): ?>
                                <div class="alumni-photo-item">
                                    <div class="alumni-photo-title">
                                        <i class="fas fa-id-card"></i> Front of Alumni ID
                                    </div>
                                    <div class="alumni-photo-container">
                                        <img src="<?= htmlspecialchars($alumni_photos['front']) ?>" 
                                             alt="Alumni ID Front" 
                                             class="alumni-photo"
                                             onclick="viewAlumniPhoto('<?= htmlspecialchars($alumni_photos['front']) ?>', 'Alumni ID Front')">
                                        <div class="alumni-photo-actions">
                                            <button class="photo-btn view" onclick="viewAlumniPhoto('<?= htmlspecialchars($alumni_photos['front']) ?>', 'Alumni ID Front')">
                                                <i class="fas fa-expand"></i> View Full Size
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($alumni_photos['back'])): ?>
                                <div class="alumni-photo-item">
                                    <div class="alumni-photo-title">
                                        <i class="fas fa-id-card-alt"></i> Back of Alumni ID
                                    </div>
                                    <div class="alumni-photo-container">
                                        <img src="<?= htmlspecialchars($alumni_photos['back']) ?>" 
                                             alt="Alumni ID Back" 
                                             class="alumni-photo"
                                             onclick="viewAlumniPhoto('<?= htmlspecialchars($alumni_photos['back']) ?>', 'Alumni ID Back')">
                                        <div class="alumni-photo-actions">
                                            <button class="photo-btn view" onclick="viewAlumniPhoto('<?= htmlspecialchars($alumni_photos['back']) ?>', 'Alumni ID Back')">
                                                <i class="fas fa-expand"></i> View Full Size
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alumni-photo-section">
                            <h3 class="section-title"><i class="fas fa-id-card"></i> Alumni ID Photos</h3>
                            <div class="no-data">
                                <i class="fas fa-camera-slash fa-2x"></i>
                                <p>No alumni ID photos uploaded yet</p>
                            </div>
                        </div>
                        <?php endif; ?>

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
                        
                        <?php if (!empty($user_details['graduate_info']['grad_summary'])): ?>
                        <div class="summary-section">
                            <h3 class="section-title"><i class="fas fa-file-alt"></i> Career Summary</h3>
                            <div class="summary-content">
                                <?= nl2br(htmlspecialchars($user_details['graduate_info']['grad_summary'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php elseif ($user_details['usr_role'] === 'employer' && isset($user_details['employer_info'])): ?>
                        <div class="summary-section">
                            <h3 class="section-title"><i class="fas fa-building"></i> Company Information</h3>
                            <div class="profile-details">
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-building"></i> Company Name:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['employer_info']['emp_company_name']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-industry"></i> Industry:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['employer_info']['emp_industry']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-user-tie"></i> Contact Person:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['employer_info']['emp_contact_person']) ?></span>
                                </div>
                                <?php if (!empty($user_details['employer_info']['emp_company_description'])): ?>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-file-alt"></i> Description:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['employer_info']['emp_company_description']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($user_details['employer_info']['emp_business_permit']) || !empty($user_details['employer_info']['emp_dti_sec'])): ?>
                        <div class="document-viewer">
                            <h3 class="section-title"><i class="fas fa-file-contract"></i> Company Documents</h3>
                            <div class="document-list">
                                <?php if (!empty($user_details['employer_info']['emp_business_permit'])): 
                                    $business_permit = $user_details['employer_info']['emp_business_permit'];
                                    $file_ext = pathinfo($business_permit, PATHINFO_EXTENSION);
                                ?>
                                <div class="document-item">
                                    <div class="document-title"><i class="fas fa-file-invoice"></i> Business Permit</div>
                                    <div class="document-preview" onclick="viewDocument('<?= htmlspecialchars($business_permit) ?>', 'Business Permit')">
                                        <?php if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                            <img src="<?= htmlspecialchars($business_permit) ?>" alt="Business Permit">
                                        <?php else: ?>
                                            <div class="file-icon">
                                                <i class="fas fa-file-pdf"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="document-actions">
                                        <button class="document-btn view" onclick="viewDocument('<?= htmlspecialchars($business_permit) ?>', 'Business Permit')">
                                            <i class="fas fa-eye"></i> View Document
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($user_details['employer_info']['emp_dti_sec'])): 
                                    $dti_sec = $user_details['employer_info']['emp_dti_sec'];
                                    $file_ext = pathinfo($dti_sec, PATHINFO_EXTENSION);
                                ?>
                                <div class="document-item">
                                    <div class="document-title"><i class="fas fa-certificate"></i> DTI/SEC Certificate</div>
                                    <div class="document-preview" onclick="viewDocument('<?= htmlspecialchars($dti_sec) ?>', 'DTI/SEC Certificate')">
                                        <?php if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                            <img src="<?= htmlspecialchars($dti_sec) ?>" alt="DTI/SEC Certificate">
                                        <?php else: ?>
                                            <div class="file-icon">
                                                <i class="fas fa-file-pdf"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="document-actions">
                                        <button class="document-btn view" onclick="viewDocument('<?= htmlspecialchars($dti_sec) ?>', 'DTI/SEC Certificate')">
                                            <i class="fas fa-eye"></i> View Document
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php elseif ($user_details['usr_role'] === 'staff' && isset($user_details['staff_info'])): ?>
                        <div class="summary-section">
                            <h3 class="section-title"><i class="fas fa-user-tie"></i> Staff Information</h3>
                            <div class="profile-details">
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-building"></i> Department:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['staff_info']['staff_department']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-briefcase"></i> Position:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['staff_info']['staff_position']) ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><i class="fas fa-id-card"></i> Employee ID:</span>
                                    <span class="detail-value"><?= htmlspecialchars($user_details['staff_info']['staff_employee_id']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Enhanced Staff ID Photo Section - NEW -->
                        <?php if (!empty($staff_photos['front']) || !empty($staff_photos['back'])): ?>
                        <div class="staff-photo-section">
                            <h3 class="section-title"><i class="fas fa-id-badge"></i> Staff ID Photos</h3>
                            <div class="staff-photo-grid">
                                <?php if (!empty($staff_photos['front'])): ?>
                                <div class="staff-photo-item">
                                    <div class="staff-photo-title">
                                        <i class="fas fa-id-card"></i> Front of Staff ID
                                    </div>
                                    <div class="staff-photo-container">
                                        <img src="<?= htmlspecialchars($staff_photos['front']) ?>" 
                                             alt="Staff ID Front" 
                                             class="staff-photo"
                                             onclick="viewStaffPhoto('<?= htmlspecialchars($staff_photos['front']) ?>', 'Staff ID Front')">
                                        <div class="staff-photo-actions">
                                            <button class="photo-btn view" onclick="viewStaffPhoto('<?= htmlspecialchars($staff_photos['front']) ?>', 'Staff ID Front')">
                                                <i class="fas fa-expand"></i> View Full Size
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($staff_photos['back'])): ?>
                                <div class="staff-photo-item">
                                    <div class="staff-photo-title">
                                        <i class="fas fa-id-card-alt"></i> Back of Staff ID
                                    </div>
                                    <div class="staff-photo-container">
                                        <img src="<?= htmlspecialchars($staff_photos['back']) ?>" 
                                             alt="Staff ID Back" 
                                             class="staff-photo"
                                             onclick="viewStaffPhoto('<?= htmlspecialchars($staff_photos['back']) ?>', 'Staff ID Back')">
                                        <div class="staff-photo-actions">
                                            <button class="photo-btn view" onclick="viewStaffPhoto('<?= htmlspecialchars($staff_photos['back']) ?>', 'Staff ID Back')">
                                                <i class="fas fa-expand"></i> View Full Size
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="staff-photo-section">
                            <h3 class="section-title"><i class="fas fa-id-badge"></i> Staff ID Photos</h3>
                            <div class="no-data">
                                <i class="fas fa-camera-slash fa-2x"></i>
                                <p>No staff ID photos uploaded yet</p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
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
                        url: 'admin_manage_users.php',
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
            const statusFilter = document.getElementById('statusFilter').value;
            const typeFilter = document.getElementById('typeFilter').value;
            const searchQuery = searchInput.value;
            
            const url = new URL(window.location.href);
            url.searchParams.set('status', statusFilter);
            url.searchParams.set('type', typeFilter);
            url.searchParams.set('search', searchQuery);
            url.searchParams.delete('view_user');
            window.location.href = url.toString();
        }
        
        // User Management Functions
        function viewUser(userId) {
            const url = new URL(window.location.href);
            url.searchParams.set('view_user', userId);
            window.location.href = url.toString();
        }
        
        function closeModal() {
            const url = new URL(window.location.href);
            url.searchParams.delete('view_user');
            window.location.href = url.toString();
        }
        
        // Inactive Modal Functions
        function showInactiveModal() {
            const inactiveModal = document.getElementById('inactiveModal');
            inactiveModal.classList.add('active');
        }
        
        function closeInactiveModal() {
            const inactiveModal = document.getElementById('inactiveModal');
            inactiveModal.classList.remove('active');
        }
        
        // Confirmation Modal Functions
        function showConfirmation(action, userId, userName) {
            const modal = document.getElementById('confirmationModal');
            const icon = document.getElementById('confirmationIcon');
            const title = document.getElementById('confirmationTitle');
            const message = document.getElementById('confirmationMessage');
            const confirmBtn = document.getElementById('confirmationConfirm');
            const form = document.getElementById('confirmationForm');
            const userIdInput = document.getElementById('confirmationUserId');
            
            userIdInput.value = userId;
            
            switch(action) {
                case 'approve':
                    icon.innerHTML = '<i class="fas fa-check-circle approve"></i>';
                    title.textContent = 'Approve User';
                    message.textContent = `Are you sure you want to approve ${userName}? This will activate their account.`;
                    confirmBtn.onclick = function() { 
                        form.action = '';
                        form.innerHTML = '<input type="hidden" name="user_id" value="' + userId + '">';
                        form.innerHTML += '<input type="hidden" name="approve_user" value="1">';
                        form.submit();
                    };
                    break;
                    
                case 'decline':
                    icon.innerHTML = '<i class="fas fa-times-circle decline"></i>';
                    title.textContent = 'Decline User';
                    message.textContent = `Are you sure you want to decline ${userName}? This will permanently delete their account.`;
                    confirmBtn.onclick = function() { 
                        form.action = '';
                        form.innerHTML = '<input type="hidden" name="user_id" value="' + userId + '">';
                        form.innerHTML += '<input type="hidden" name="decline_user" value="1">';
                        form.submit();
                    };
                    break;
                    
                case 'suspend':
                    icon.innerHTML = '<i class="fas fa-user-slash suspend"></i>';
                    title.textContent = 'Suspend User';
                    message.textContent = `Are you sure you want to suspend ${userName}? They will not be able to access their account.`;
                    confirmBtn.onclick = function() { 
                        form.action = '';
                        form.innerHTML = '<input type="hidden" name="user_id" value="' + userId + '">';
                        form.innerHTML += '<input type="hidden" name="deactivate_user" value="1">';
                        form.submit();
                    };
                    break;
                    
                case 'archive':
                    icon.innerHTML = '<i class="fas fa-archive archive"></i>';
                    title.textContent = 'Archive User';
                    message.textContent = `Are you sure you want to archive ${userName}? This will remove them from active users.`;
                    confirmBtn.onclick = function() { 
                        form.action = '';
                        form.innerHTML = '<input type="hidden" name="user_id" value="' + userId + '">';
                        form.innerHTML += '<input type="hidden" name="archive_user" value="1">';
                        form.submit();
                    };
                    break;
            }
            
            modal.classList.add('active');
        }
        
        function hideConfirmation() {
            const modal = document.getElementById('confirmationModal');
            modal.classList.remove('active');
        }
        
        // Document Viewer Functions
        function viewDocument(documentPath, documentTitle) {
            const documentViewerModal = document.getElementById('documentViewerModal');
            const documentViewerTitle = document.getElementById('documentViewerTitle');
            const documentContainer = document.getElementById('documentContainer');
            
            currentZoom = 1;
            document.getElementById('zoomLevel').textContent = '100%';
            documentViewerTitle.innerHTML = `<i class="fas fa-file-contract"></i> ${documentTitle}`;
            documentContainer.innerHTML = '';
            
            const fileExt = documentPath.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExt);
            
            if (isImage) {
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
                const iframe = document.createElement('iframe');
                iframe.src = documentPath;
                iframe.className = 'document-viewer-iframe';
                iframe.id = 'viewerIframe';
                documentContainer.appendChild(iframe);
                currentDocumentElement = iframe;
            }
            
            documentViewerModal.classList.add('active');
        }
        
        // Alumni Photo Viewer Function
        function viewAlumniPhoto(photoPath, photoTitle) {
            const documentViewerModal = document.getElementById('documentViewerModal');
            const documentViewerTitle = document.getElementById('documentViewerTitle');
            const documentContainer = document.getElementById('documentContainer');
            
            currentZoom = 1;
            document.getElementById('zoomLevel').textContent = '100%';
            documentViewerTitle.innerHTML = `<i class="fas fa-id-card"></i> ${photoTitle}`;
            documentContainer.innerHTML = '';
            
            const img = document.createElement('img');
            img.src = photoPath;
            img.className = 'document-viewer-image';
            img.id = 'viewerImage';
            img.style.transform = `scale(${currentZoom})`;
            img.addEventListener('mousedown', startDrag);
            img.addEventListener('touchstart', startDragTouch, { passive: false });
            documentContainer.appendChild(img);
            currentDocumentElement = img;
            
            documentViewerModal.classList.add('active');
        }
        
        // Staff Photo Viewer Function - NEW
        function viewStaffPhoto(photoPath, photoTitle) {
            const documentViewerModal = document.getElementById('documentViewerModal');
            const documentViewerTitle = document.getElementById('documentViewerTitle');
            const documentContainer = document.getElementById('documentContainer');
            
            currentZoom = 1;
            document.getElementById('zoomLevel').textContent = '100%';
            documentViewerTitle.innerHTML = `<i class="fas fa-id-badge"></i> ${photoTitle}`;
            documentContainer.innerHTML = '';
            
            const img = document.createElement('img');
            img.src = photoPath;
            img.className = 'document-viewer-image';
            img.id = 'viewerImage';
            img.style.transform = `scale(${currentZoom})`;
            img.addEventListener('mousedown', startDrag);
            img.addEventListener('touchstart', startDragTouch, { passive: false });
            documentContainer.appendChild(img);
            currentDocumentElement = img;
            
            documentViewerModal.classList.add('active');
        }
        
        function startDrag(e) {
            if (!currentDocumentElement || currentDocumentElement.tagName !== 'IMG') return;
            
            isDragging = true;
            startX = e.pageX - scrollLeft;
            startY = e.pageY - scrollTop;
            
            document.addEventListener('mousemove', doDrag);
            document.addEventListener('mouseup', stopDrag);
        }
        
        function startDragTouch(e) {
            if (!currentDocumentElement || currentDocumentElement.tagName !== 'IMG') return;
            
            e.preventDefault();
            isDragging = true;
            const touch = e.touches[0];
            startX = touch.pageX - scrollLeft;
            startY = touch.pageY - scrollTop;
            
            document.addEventListener('touchmove', doDragTouch, { passive: false });
            document.addEventListener('touchend', stopDrag);
        }
        
        function doDrag(e) {
            if (!isDragging) return;
            
            const container = document.getElementById('documentContainer');
            scrollLeft = e.pageX - startX;
            scrollTop = e.pageY - startY;
            container.scrollLeft = scrollLeft;
            container.scrollTop = scrollTop;
        }
        
        function doDragTouch(e) {
            if (!isDragging) return;
            
            e.preventDefault();
            const touch = e.touches[0];
            const container = document.getElementById('documentContainer');
            scrollLeft = touch.pageX - startX;
            scrollTop = touch.pageY - startY;
            container.scrollLeft = scrollLeft;
            container.scrollTop = scrollTop;
        }

        function stopDrag() {
            isDragging = false;
            document.removeEventListener('mousemove', doDrag);
            document.removeEventListener('mouseup', stopDrag);
            document.removeEventListener('touchmove', doDragTouch);
            document.removeEventListener('touchend', stopDrag);
        }

        function zoomIn() {
            if (currentDocumentElement) {
                currentZoom += 0.1;
                currentZoom = Math.min(currentZoom, 3); // Max zoom 300%
                updateZoom();
            }
        }

        function zoomOut() {
            if (currentDocumentElement) {
                currentZoom -= 0.1;
                currentZoom = Math.max(currentZoom, 0.5); // Min zoom 50%
                updateZoom();
            }
        }

        function resetZoom() {
            if (currentDocumentElement) {
                currentZoom = 1;
                updateZoom();
            }
        }

        function updateZoom() {
            if (currentDocumentElement) {
                currentDocumentElement.style.transform = `scale(${currentZoom})`;
                document.getElementById('zoomLevel').textContent = `${Math.round(currentZoom * 100)}%`;
            }
        }

        function closeDocumentViewer() {
            const documentViewerModal = document.getElementById('documentViewerModal');
            documentViewerModal.classList.remove('active');
            document.getElementById('documentContainer').innerHTML = '';
            currentDocumentElement = null;
            currentZoom = 1;
            document.getElementById('zoomLevel').textContent = '100%';
        }

        // Initialize modal state
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('view_user')) {
                const userModal = document.getElementById('userModal');
                if (userModal) {
                    userModal.classList.add('active');
                }
            }
        });

        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDocumentViewer();
                hideConfirmation();
                closeInactiveModal();
            }
        });
    </script>
</body>
</html>