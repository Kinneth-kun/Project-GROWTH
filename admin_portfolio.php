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
        $newUsersStmt = $conn->query("
            SELECT usr_id, usr_name, usr_role, usr_email, usr_created_at
            FROM users 
            WHERE usr_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND usr_role != 'admin'
            ORDER BY usr_created_at DESC
        ");
        if ($newUsersStmt) {
            $newUsers = $newUsersStmt->fetchAll(PDO::FETCH_ASSOC);
        }
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
    if ($pendingEmployersStmt) {
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
    }
    
    // 2. Check for pending job approvals
    $pendingJobsStmt = $conn->query("SELECT COUNT(*) as count FROM jobs WHERE job_status = 'pending'");
    if ($pendingJobsStmt) {
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
    
    if ($incompleteProfilesStmt) {
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
    
    if ($recentApplicationsStmt) {
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
    }
    
    // Check for new applications since last check
    $lastAppNotifStmt = $conn->query("SELECT MAX(notif_created_at) FROM notifications WHERE notif_type = 'new_application'");
    $lastAppNotif = $lastAppNotifStmt ? $lastAppNotifStmt->fetchColumn() : null;
    
    if ($lastAppNotif) {
        $newApplicationsStmt = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at > '$lastAppNotif'
        ");
        if ($newApplicationsStmt) {
            $newApplications = $newApplicationsStmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        $newApplicationsStmt = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        if ($newApplicationsStmt) {
            $newApplications = $newApplicationsStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    if (isset($newApplications) && $newApplications['count'] > 0) {
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
    $employmentRateStmt = $conn->query("
        SELECT 
            COUNT(DISTINCT u.usr_id) as total_graduates,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_graduates
        FROM users u
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
        WHERE u.usr_role = 'graduate'
    ");
    
    if ($employmentRateStmt) {
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
    }
    
    // Check top skills in demand - FIXED QUERY (using skills from graduate_skills table)
    $topSkillsStmt = $conn->query("
        SELECT s.skill_name, COUNT(gs.skill_id) as demand_count
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        GROUP BY s.skill_name
        ORDER BY demand_count DESC
        LIMIT 1
    ");
    
    if ($topSkillsStmt) {
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
    }
    
    return $notificationsGenerated;
}

/**
 * Enhanced system notifications generator - MAIN FUNCTION
 */
function generateSystemNotifications($conn) {
    $totalNotifications = 0;
    
    try {
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
    } catch (Exception $e) {
        // Log error but don't break the page
        error_log("Notification generation error: " . $e->getMessage());
    }
    
    return $totalNotifications;
}

// Generate notifications on every page load
$notificationsGenerated = generateSystemNotifications($conn);

// ============================================================================
// PORTFOLIO MANAGEMENT FUNCTIONALITY WITH PHOTO UPLOAD
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_portfolio.php");
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

// Handle profile photo upload for admin
$update_success = false;
$update_error = false;
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_photo'])) {
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        // File upload details
        $file_name = $_FILES['profile_photo']['name'];
        $file_tmp = $_FILES['profile_photo']['tmp_name'];
        $file_size = $_FILES['profile_photo']['size'];
        $file_type = $_FILES['profile_photo']['type'];
        
        // Get file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Valid extensions
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif');
        
        // Check if extension is valid
        if (in_array($file_ext, $allowed_extensions)) {
            // Check file size (max 2MB)
            if ($file_size <= 2097152) {
                // Generate unique filename
                $new_file_name = "admin_" . $_SESSION['user_id'] . "_" . time() . "." . $file_ext;
                $upload_path = "uploads/profile_photos/" . $new_file_name;
                
                // Create directory if it doesn't exist
                if (!file_exists('uploads/profile_photos')) {
                    mkdir('uploads/profile_photos', 0777, true);
                }
                
                // Move uploaded file
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    // Update database with photo path
                    $update_photo_stmt = $conn->prepare("UPDATE users SET usr_profile_photo = :photo WHERE usr_id = :id");
                    $update_photo_stmt->bindParam(':photo', $new_file_name);
                    $update_photo_stmt->bindParam(':id', $_SESSION['user_id']);
                    
                    if ($update_photo_stmt->execute()) {
                        $update_success = true;
                        // Update session if needed
                        $_SESSION['user_profile_photo'] = $new_file_name;
                    } else {
                        $update_error = true;
                        $error_message = "Error updating database.";
                    }
                } else {
                    $update_error = true;
                    $error_message = "Error uploading file.";
                }
            } else {
                $update_error = true;
                $error_message = "File size must be less than 2MB.";
            }
        } else {
            $update_error = true;
            $error_message = "Only JPG, JPEG, PNG, and GIF files are allowed.";
        }
    } else {
        $update_error = true;
        $error_message = "Please select a valid image file.";
    }
}

// Get admin user details
$admin_id = $_SESSION['user_id'];
$admin_stmt = $conn->prepare("SELECT * FROM users WHERE usr_id = :id");
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_user = $admin_stmt->fetch(PDO::FETCH_ASSOC);

// Get profile photo path or use default - UPDATED PATH HANDLING
$admin_profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($admin_user['usr_name']) . "&background=3498db&color=fff";
if (!empty($admin_user['usr_profile_photo'])) {
    // Check if it's a full path or just filename
    if (file_exists($admin_user['usr_profile_photo'])) {
        $admin_profile_photo = $admin_user['usr_profile_photo'];
    } elseif (file_exists("uploads/profile_photos/" . $admin_user['usr_profile_photo'])) {
        $admin_profile_photo = "uploads/profile_photos/" . $admin_user['usr_profile_photo'];
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

// Get filter parameters
$course_filter = $_GET['course'] ?? 'all';
$year_filter = $_GET['year'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'recent';

// Build query to fetch graduates with their portfolio information
$query = "
    SELECT 
        u.usr_id, 
        u.usr_name, 
        u.usr_email, 
        u.usr_phone, 
        u.usr_created_at,
        u.usr_profile_photo,
        u.usr_gender,
        u.usr_birthdate,
        g.grad_degree,
        g.grad_year_graduated,
        g.grad_job_preference,
        g.grad_school_id,
        g.grad_summary
    FROM users u
    INNER JOIN graduates g ON u.usr_id = g.grad_usr_id
    WHERE u.usr_role = 'graduate' AND u.usr_is_approved = TRUE
";

$params = [];

// Apply course filter
if ($course_filter !== 'all') {
    $query .= " AND g.grad_degree LIKE :course";
    $params[':course'] = "%$course_filter%";
}

// Apply year filter
if ($year_filter !== 'all') {
    $query .= " AND g.grad_year_graduated = :year";
    $params[':year'] = $year_filter;
}

// Apply search filter
if (!empty($search_query)) {
    $query .= " AND (u.usr_name LIKE :search OR u.usr_email LIKE :search OR g.grad_degree LIKE :search)";
    $params[':search'] = "%$search_query%";
}

// Apply sorting
switch ($sort_by) {
    case 'name_asc':
        $query .= " ORDER BY u.usr_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY u.usr_name DESC";
        break;
    case 'recent':
    default:
        $query .= " ORDER BY u.usr_created_at DESC";
        break;
}

try {
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $graduates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process each graduate to get their profile photo - UPDATED PATH HANDLING
    foreach ($graduates as &$graduate) {
        $profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($graduate['usr_name']) . "&background=3498db&color=fff";
        if (!empty($graduate['usr_profile_photo'])) {
            // Check if it's a full path or just filename
            if (file_exists($graduate['usr_profile_photo'])) {
                $profile_photo = $graduate['usr_profile_photo'];
            } elseif (file_exists("uploads/profile_photos/" . $graduate['usr_profile_photo'])) {
                $profile_photo = "uploads/profile_photos/" . $graduate['usr_profile_photo'];
            }
        }
        $graduate['profile_photo'] = $profile_photo;
    }
    unset($graduate); // Unset the reference
    
} catch (PDOException $e) {
    $error = "Error fetching graduates: " . $e->getMessage();
    $graduates = [];
}

// Get portfolio details for modal if requested
$portfolio_details = null;
$portfolio_skills = [];
$portfolio_documents = [];
$portfolio_certificates = [];
$portfolio_projects = [];
$portfolio_resumes = [];
$portfolio_completeness = 0;
$job_preferences = []; // ADDED: For storing parsed job preferences

if (isset($_GET['view_portfolio'])) {
    $grad_id = $_GET['view_portfolio'];
    
    // Get graduate details
    $stmt = $conn->prepare("
        SELECT 
            u.*, 
            g.* 
        FROM users u
        INNER JOIN graduates g ON u.usr_id = g.grad_usr_id
        WHERE u.usr_id = :id
    ");
    $stmt->bindParam(':id', $grad_id);
    $stmt->execute();
    $portfolio_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($portfolio_details) {
        // Get profile photo for the graduate - UPDATED PATH HANDLING
        $profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($portfolio_details['usr_name']) . "&background=3498db&color=fff";
        if (!empty($portfolio_details['usr_profile_photo'])) {
            // Check if it's a full path or just filename
            if (file_exists($portfolio_details['usr_profile_photo'])) {
                $profile_photo = $portfolio_details['usr_profile_photo'];
            } elseif (file_exists("uploads/profile_photos/" . $portfolio_details['usr_profile_photo'])) {
                $profile_photo = "uploads/profile_photos/" . $portfolio_details['usr_profile_photo'];
            }
        }
        $portfolio_details['profile_photo'] = $profile_photo;
        
        // MODIFIED: Get job preferences from graduates table (stored as JSON) - FIXED VERSION
        if (!empty($portfolio_details['grad_job_preference'])) {
            $job_preferences_json = $portfolio_details['grad_job_preference'];
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
        
        // Store job preferences in portfolio_details for easy access in HTML
        $portfolio_details['job_preferences'] = $job_preferences;
        
        // Get skills for the graduate
        $skills_stmt = $conn->prepare("
            SELECT s.skill_name, s.skill_category, gs.skill_level 
            FROM graduate_skills gs
            JOIN skills s ON gs.skill_id = s.skill_id
            WHERE gs.grad_usr_id = :user_id
            ORDER BY gs.skill_level DESC, s.skill_name
        ");
        $skills_stmt->bindParam(':user_id', $grad_id);
        $skills_stmt->execute();
        $portfolio_skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all portfolio documents - ENHANCED QUERY TO GET ALL DOCUMENTS
        $docs_stmt = $conn->prepare("
            SELECT * 
            FROM portfolio_items 
            WHERE port_usr_id = :user_id
            ORDER BY port_item_type, port_created_at DESC
        ");
        $docs_stmt->bindParam(':user_id', $grad_id);
        $docs_stmt->execute();
        $portfolio_documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Separate documents by type - ENHANCED LOGIC
        foreach ($portfolio_documents as $doc) {
            switch ($doc['port_item_type']) {
                case 'certificate':
                    $portfolio_certificates[] = $doc;
                    break;
                case 'project':
                    $portfolio_projects[] = $doc;
                    break;
                case 'resume':
                    $portfolio_resumes[] = $doc;
                    break;
                default:
                    // Handle any other document types
                    $portfolio_documents[] = $doc;
                    break;
            }
        }
        
        // Calculate portfolio completeness
        $completeness = 20; // Base score for having an account
        
        // Check for profile completeness factors
        if (!empty($portfolio_details['usr_phone'])) $completeness += 10;
        if (!empty($portfolio_details['usr_gender'])) $completeness += 5;
        if (!empty($portfolio_details['usr_birthdate'])) $completeness += 5;
        
        // Check for resume
        if (count($portfolio_resumes) > 0) $completeness += 20;
        
        // Check for certificates
        if (count($portfolio_certificates) > 0) $completeness += 10;
        
        // Check for projects
        if (count($portfolio_projects) > 0) $completeness += 10;
        
        // Check for skills
        if (count($portfolio_skills) >= 3) $completeness += 20;
        if (count($portfolio_skills) >= 5) $completeness += 10; // Bonus for more skills
        
        // Check for job preferences
        if (count($job_preferences) > 0) $completeness += 10;
        
        // Cap at 100%
        $portfolio_completeness = min($completeness, 100);
    }
}

// MODIFIED: Fetch courses and organize them by college/department
$courses_by_college = [];
$colleges = [];

try {
    // Query to get all active courses from the database and organize by college
    $courses_stmt = $conn->query("
        SELECT course_id, course_code, course_name, course_college 
        FROM courses 
        WHERE is_active = TRUE 
        ORDER BY course_college, course_name
    ");
    $all_courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize courses by college
    foreach ($all_courses as $course) {
        $college = $course['course_college'];
        if (!isset($courses_by_college[$college])) {
            $courses_by_college[$college] = [];
            $colleges[] = $college;
        }
        $courses_by_college[$college][] = $course;
    }
    
    // If there are no courses in the database, use an empty array
    if (empty($courses_by_college)) {
        $courses_by_college = [];
        $colleges = [];
    }
} catch (PDOException $e) {
    // If there's an error, log it and use an empty array
    error_log("Error fetching courses: " . $e->getMessage());
    $courses_by_college = [];
    $colleges = [];
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

// Build current URL for filters
$current_url_params = [];
if ($course_filter !== 'all') $current_url_params['course'] = $course_filter;
if ($year_filter !== 'all') $current_url_params['year'] = $year_filter;
if (!empty($search_query)) $current_url_params['search'] = $search_query;
if ($sort_by !== 'recent') $current_url_params['sort'] = $sort_by;
$current_url = "admin_portfolio.php?" . http_build_query($current_url_params);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Graduate Portfolios</title>
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
        
        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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
        
        /* Enhanced Dashboard Cards */
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
        
        /* ENHANCED FILTER SECTION - FIXED ALIGNMENT */
        .filters {
            display: flex;
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-top: 4px solid var(--accent-color);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }
        
        .filter-label {
            font-weight: 600;
            font-size: 14px;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .filter-select, .search-filter-container input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .filter-select:focus, .search-filter-container input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .search-filter-container {
            position: relative;
            width: 100%;
        }
        
        .search-filter-container i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark-gray);
        }
        
        .search-filter-container input {
            padding-left: 45px;
        }
        
        /* Sort Filter - MOVED TO FILTERS ROW */
        .sort-filter {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }
        
        .sort-label {
            font-weight: 600;
            font-size: 14px;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .sort-select {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            width: 100%;
        }
        
        .sort-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
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
        
        /* Enhanced Action Buttons */
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
        
        .portfolio-modal {
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
        
        .modal-overlay.active .portfolio-modal {
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
        
        /* Enhanced Portfolio Content */
        .portfolio-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .profile-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .profile-header-info {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
            position: relative;
        }
        
        .profile-img {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 15px;
            display: block;
            border: 4px solid var(--primary-color);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .profile-name {
            font-size: 26px;
            margin-bottom: 5px;
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .profile-course {
            color: var(--dark-gray);
            margin-bottom: 10px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .profile-status {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, var(--green), #2ecc71);
            color: white;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .profile-details {
            margin-top: 20px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
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
            color: var(--secondary-color);
        }
        
        .detail-value {
            color: #555;
            font-weight: 500;
        }
        
        /* MODIFIED: Enhanced Job Preferences Section - Better Spacing */
        .preferences-section {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
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
        
        .view-resume {
            display: block;
            width: 100%;
            text-align: center;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 12px;
            border-radius: 8px;
            margin-top: 25px;
            text-decoration: none;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .view-resume:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .skills-section, .certificates-section, .projects-section, .documents-section, .alert-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .section-title {
            font-size: 1.3rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--secondary-color);
        }
        
        .skills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .skill {
            background: rgba(110, 3, 3, 0.1);
            color: var(--primary-color);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            border: 1px solid rgba(110, 3, 3, 0.2);
            font-weight: 500;
        }
        
        .skill-level {
            font-size: 11px;
            color: var(--dark-gray);
            margin-top: 3px;
        }
        
        .certificate, .project, .document {
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        
        .certificate:last-child, .project:last-child, .document:last-child {
            border-bottom: none;
        }
        
        .certificate-name, .project-name, .document-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .certificate-date, .project-date, .document-date {
            font-size: 0.9rem;
            color: var(--dark-gray);
        }
        
        .document-description, .certificate-description, .project-description {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
        }
        
        .document-actions {
            margin-top: 10px;
            display: flex;
            gap: 10px;
        }
        
        .document-action {
            padding: 6px 12px;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        
        .document-action:hover {
            background: #8a0404;
            transform: translateY(-2px);
        }
        
        .alert-box {
            background: linear-gradient(135deg, #fff4e6, #ffe8cc);
            border-left: 4px solid var(--secondary-color);
            padding: 20px;
            border-radius: 8px;
        }
        
        .alert-title {
            display: flex;
            align-items: center;
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .alert-title i {
            margin-right: 10px;
            font-size: 18px;
        }
        
        /* PROFESSIONAL RESUME MODAL STYLES */
        .resume-modal {
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
        
        .modal-overlay.active .resume-modal {
            transform: translateY(0);
        }
        
        .resume-content {
            padding: 40px;
            background: white;
        }
        
        .resume-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 3px solid var(--primary-color);
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 30px;
            border-radius: 10px;
            position: relative;
        }
        
        .resume-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px 10px 0 0;
        }
        
        .resume-name {
            font-size: 36px;
            color: var(--primary-color);
            margin-bottom: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .resume-title {
            font-size: 20px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .resume-contact {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .resume-contact span {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--dark-gray);
            font-weight: 500;
            font-size: 15px;
        }
        
        .resume-contact i {
            color: var(--primary-color);
            width: 16px;
        }
        
        .resume-section {
            margin-bottom: 35px;
            position: relative;
        }
        
        .resume-section::before {
            content: '';
            position: absolute;
            left: -15px;
            top: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient180deg, var(--primary-color), var(--secondary-color);
            border-radius: 2px;
        }
        
        .resume-section-title {
            font-size: 24px;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .resume-section-title i {
            color: var(--secondary-color);
            font-size: 20px;
        }
        
        .resume-item {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
            transition: all 0.3s ease;
        }
        
        .resume-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .resume-item-title {
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .resume-item-title i {
            color: var(--primary-color);
            font-size: 16px;
        }
        
        .resume-item-details {
            color: var(--dark-gray);
            margin-bottom: 5px;
            line-height: 1.6;
        }
        
        .resume-item-meta {
            display: flex;
            gap: 15px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        
        .resume-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: #666;
            background: white;
            padding: 4px 10px;
            border-radius: 15px;
            border: 1px solid #e9ecef;
        }
        
        .resume-meta-item i {
            color: var(--secondary-color);
            font-size: 12px;
        }
        
        .skills-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .skill-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .skill-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: var(--secondary-color);
        }
        
        .skill-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .skill-level {
            font-size: 12px;
            color: var(--dark-gray);
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 12px;
            display: inline-block;
        }
        
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .document-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .document-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }
        
        .document-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--secondary-color);
            border-radius: 8px 0 0 8px;
        }
        
        .document-card-title {
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .document-card-title i {
            color: var(--secondary-color);
        }
        
        .document-card-date {
            font-size: 13px;
            color: var(--dark-gray);
            margin-bottom: 10px;
        }
        
        .document-card-description {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .document-card-actions {
            display: flex;
            gap: 10px;
        }
        
        .document-card-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }
        
        .document-card-btn.primary {
            background: var(--primary-color);
            color: white;
        }
        
        .document-card-btn.secondary {
            background: #e9ecef;
            color: var(--dark-gray);
        }
        
        .document-card-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .document-card-btn.primary:hover {
            background: #8a0404;
        }
        
        .document-card-btn.secondary:hover {
            background: #dee2e6;
        }
        
        .resume-footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #e9ecef;
            color: var(--dark-gray);
            font-size: 14px;
        }
        
        .resume-footer strong {
            color: var(--primary-color);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .portfolio-content {
                grid-template-columns: 1fr;
            }
            
            .skills-grid, .documents-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 900px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .filters {
                grid-template-columns: 1fr;
            }
            
            .resume-contact {
                flex-direction: column;
                gap: 10px;
                align-items: center;
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
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .modal-content, .resume-content {
                padding: 20px;
            }
            
            th, td {
                padding: 12px 15px;
            }
            
            .resume-name {
                font-size: 28px;
            }
            
            .resume-section::before {
                left: -10px;
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
            
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .action-btn {
                width: 100%;
                min-width: auto;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .resume-item-meta {
                flex-direction: column;
                gap: 8px;
            }
            
            .document-card-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Modal Overlay -->
    <div class="modal-overlay <?= $portfolio_details ? 'active' : '' ?>" id="modalOverlay"></div>
    
    <!-- Portfolio Modal -->
    <?php if ($portfolio_details): ?>
    <div class="modal-overlay active" id="portfolioModal">
        <div class="portfolio-modal">
            <div class="modal-header">
                <h2 class="modal-title">Graduate Profile</h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <div class="portfolio-content">
                    <div class="profile-section">
                        <div class="profile-header-info">
                            <img src="<?= $portfolio_details['profile_photo'] ?>" alt="<?= htmlspecialchars($portfolio_details['usr_name']) ?>" class="profile-img">
                            <h2 class="profile-name"><?= htmlspecialchars($portfolio_details['usr_name']) ?></h2>
                            <p class="profile-course"><?= htmlspecialchars($portfolio_details['grad_degree']) ?></p>
                            <!-- REMOVED PUBLIC PORTFOLIO STATUS -->
                        </div>
                        
                        <div class="profile-details">
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-envelope"></i>
                                    Email
                                </span>
                                <span class="detail-value"><?= htmlspecialchars($portfolio_details['usr_email']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-phone"></i>
                                    Phone
                                </span>
                                <span class="detail-value"><?= !empty($portfolio_details['usr_phone']) ? htmlspecialchars($portfolio_details['usr_phone']) : 'Not provided' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-venus-mars"></i>
                                    Gender
                                </span>
                                <span class="detail-value"><?= !empty($portfolio_details['usr_gender']) ? htmlspecialchars($portfolio_details['usr_gender']) : 'Not provided' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-birthday-cake"></i>
                                    Birthdate
                                </span>
                                <span class="detail-value"><?= !empty($portfolio_details['usr_birthdate']) ? date('M j, Y', strtotime($portfolio_details['usr_birthdate'])) : 'Not provided' ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-id-card"></i>
                                    Alumni ID
                                </span>
                                <span class="detail-value"><?= htmlspecialchars($portfolio_details['grad_school_id']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-graduation-cap"></i>
                                    Year Graduated
                                </span>
                                <span class="detail-value"><?= htmlspecialchars($portfolio_details['grad_year_graduated']) ?></span>
                            </div>
                        </div>
                        
                        <!-- MODIFIED: Enhanced Job Preferences Section with Better Spacing -->
                        <div class="preferences-section">
                            <h3 class="preferences-title"><i class="fas fa-bullseye"></i> Job Preferences</h3>
                            <div class="preferences-count">
                                <i class="fas fa-list"></i> 
                                <?= count($portfolio_details['job_preferences']) ?> preference(s) set
                            </div>
                            <div class="preference-tags">
                                <?php if (!empty($portfolio_details['job_preferences'])): ?>
                                    <?php foreach ($portfolio_details['job_preferences'] as $preference): ?>
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
                        
                        <?php if (!empty($portfolio_details['grad_summary'])): ?>
                        <div class="profile-details">
                            <div class="detail-item" style="flex-direction: column; align-items: flex-start;">
                                <span class="detail-label">
                                    <i class="fas fa-file-alt"></i>
                                    Career Summary
                                </span>
                                <span class="detail-value" style="margin-top: 10px;"><?= nl2br(htmlspecialchars($portfolio_details['grad_summary'])) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <button class="view-resume" onclick="viewFullResume(<?= $portfolio_details['usr_id'] ?>, '<?= htmlspecialchars($portfolio_details['usr_name']) ?>')">
                            <i class="fas fa-eye"></i> View Full Portfolio
                        </button>
                    </div>
                    
                    <div>
                        <div class="skills-section">
                            <h3 class="section-title"><i class="fas fa-tools"></i> Skills (<?= count($portfolio_skills) ?>)</h3>
                            <div class="skills">
                                <?php if (!empty($portfolio_skills)): ?>
                                    <?php foreach ($portfolio_skills as $skill): ?>
                                        <span class="skill">
                                            <?= htmlspecialchars($skill['skill_name']) ?>
                                            <span class="skill-level"><?= ucfirst($skill['skill_level']) ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No skills added yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="certificates-section">
                            <h3 class="section-title"><i class="fas fa-certificate"></i> Certificates (<?= count($portfolio_certificates) ?>)</h3>
                            <?php if (!empty($portfolio_certificates)): ?>
                                <?php foreach ($portfolio_certificates as $cert): ?>
                                    <div class="certificate">
                                        <div class="certificate-name"><?= htmlspecialchars($cert['port_item_title']) ?></div>
                                        <div class="certificate-date"><?= !empty($cert['port_item_date']) ? date('F j, Y', strtotime($cert['port_item_date'])) : 'Date not specified' ?></div>
                                        <?php if (!empty($cert['port_item_description'])): ?>
                                            <div class="certificate-description"><?= htmlspecialchars($cert['port_item_description']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($cert['port_item_file'])): ?>
                                            <div class="document-actions">
                                                <a href="<?= htmlspecialchars($cert['port_item_file']) ?>" target="_blank" class="document-action">
                                                    <i class="fas fa-eye"></i> View Document
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No certificates added yet</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="projects-section">
                            <h3 class="section-title"><i class="fas fa-project-diagram"></i> Projects (<?= count($portfolio_projects) ?>)</h3>
                            <?php if (!empty($portfolio_projects)): ?>
                                <?php foreach ($portfolio_projects as $project): ?>
                                    <div class="project">
                                        <div class="project-name"><?= htmlspecialchars($project['port_item_title']) ?></div>
                                        <div class="project-date"><?= !empty($project['port_item_date']) ? date('F j, Y', strtotime($project['port_item_date'])) : 'Date not specified' ?></div>
                                        <?php if (!empty($project['port_item_description'])): ?>
                                            <div class="project-description"><?= htmlspecialchars($project['port_item_description']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($project['port_item_file'])): ?>
                                            <div class="document-actions">
                                                <a href="<?= htmlspecialchars($project['port_item_file']) ?>" target="_blank" class="document-action">
                                                    <i class="fas fa-eye"></i> View Document
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No projects added yet</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="documents-section">
                            <h3 class="section-title"><i class="fas fa-file-alt"></i> Resumes (<?= count($portfolio_resumes) ?>)</h3>
                            <?php if (!empty($portfolio_resumes)): ?>
                                <?php foreach ($portfolio_resumes as $resume): ?>
                                    <div class="document">
                                        <div class="document-name"><?= htmlspecialchars($resume['port_item_title']) ?></div>
                                        <div class="document-date"><?= !empty($resume['port_item_date']) ? date('F j, Y', strtotime($resume['port_item_date'])) : 'Date not specified' ?></div>
                                        <?php if (!empty($resume['port_item_description'])): ?>
                                            <div class="document-description"><?= htmlspecialchars($resume['port_item_description']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($resume['port_item_file'])): ?>
                                            <div class="document-actions">
                                                <a href="<?= htmlspecialchars($resume['port_item_file']) ?>" target="_blank" class="document-action">
                                                    <i class="fas fa-eye"></i> View Resume
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No resumes uploaded yet</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alert-section">
                            <h3 class="section-title"><i class="fas fa-exclamation-triangle"></i> System Alert</h3>
                            <div class="alert-box">
                                <div class="alert-title">
                                    <i class="fas fa-chart-pie"></i>
                                    Portfolio Completeness
                                </div>
                                <p>This graduate's portfolio is <strong><?= $portfolio_completeness ?>% complete</strong>. 
                                <?php if ($portfolio_completeness < 100): ?>
                                    Encourage them to add more information to improve their profile visibility.
                                <?php else: ?>
                                    Portfolio is complete and ready for employer review.
                                <?php endif; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Professional Resume Modal -->
    <div class="modal-overlay" id="resumeModal">
        <div class="resume-modal">
            <div class="modal-header">
                <h2 class="modal-title">Full Portfolio</h2>
                <button class="modal-close" onclick="closeResumeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="resume-content" id="resumeContent">
                <!-- Resume content will be dynamically inserted here -->
            </div>
        </div>
    </div>

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
                    <a href="admin_portfolio.php" class="active">
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
            <p>Here's the alumni portfolios management panel. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Alumni Portfolios</h1>
        </div>
        
        <!-- Alert Messages for Photo Upload -->
        <?php if ($update_success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Profile photo updated successfully!
        </div>
        <?php endif; ?>
        
        <?php if ($update_error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
        <?php endif; ?>
        
        <!-- ENHANCED FILTER SECTION - FIXED ALIGNMENT WITH SORT IN SAME ROW -->
        <div class="filters">
            <div class="filter-group">
                <label class="filter-label">Search Alumni</label>
                <div class="search-filter-container">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by name, email, or course..." value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
            <div class="filter-group">
                <label class="filter-label">Course</label>
                <select class="filter-select" id="courseFilter">
                    <option value="all" <?= $course_filter === 'all' ? 'selected' : '' ?>>All Courses</option>
                    <?php if (!empty($courses_by_college) && !empty($colleges)): ?>
                        <?php foreach ($colleges as $college): ?>
                            <?php if (isset($courses_by_college[$college]) && !empty($courses_by_college[$college])): ?>
                                <optgroup label="<?= htmlspecialchars($college) ?>">
                                    <?php foreach ($courses_by_college[$college] as $course): ?>
                                        <option value="<?= htmlspecialchars($course['course_code']) ?>" 
                                            <?= $course_filter === $course['course_code'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course['course_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- NO HARDCODED FALLBACK - Database courses only -->
                        <option value="all" selected>No courses available in database</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Graduation Year</label>
                <select class="filter-select" id="yearFilter">
                    <option value="all" <?= $year_filter === 'all' ? 'selected' : '' ?>>All Years</option>
                    <?php for ($year = 2022; $year <= 2050; $year++): ?>
                        <option value="<?= $year ?>" <?= $year_filter == $year ? 'selected' : '' ?>><?= $year ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <!-- MOVED SORT FILTER TO BE IN THE SAME ROW AS GRADUATION YEAR -->
            <div class="sort-filter">
                <label class="sort-label">Sort by:</label>
                <select class="sort-select" id="sortSelect">
                    <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Recently Added</option>
                    <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                    <option value="name_desc" <?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                </select>
            </div>
        </div>
        
        <!-- Enhanced Table Container -->
        <div class="table-container">
            <div class="table-header">
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Year Graduated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($graduates)): ?>
                        <?php foreach ($graduates as $graduate): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($graduate['usr_name']) ?></strong></td>
                                <td><?= htmlspecialchars($graduate['grad_degree']) ?></td>
                                <td><?= htmlspecialchars($graduate['grad_year_graduated']) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?= $current_url ?>&view_portfolio=<?= $graduate['usr_id'] ?>" class="action-btn view">
                                            <i class="fas fa-eye"></i> View Portfolio
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 30px; color: #999;">
                                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                                No alumni found matching your criteria
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Enhanced Dropdown Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const notification = document.getElementById('notification');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const adminProfile = document.getElementById('adminProfile');
            const profileDropdown = document.getElementById('profileDropdown');
            
            // Notification dropdown functionality
            if (notification && notificationDropdown) {
                notification.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notificationDropdown.classList.toggle('active');
                    // Close profile dropdown if open
                    if (profileDropdown) profileDropdown.classList.remove('active');
                });
            }
            
            // Profile dropdown functionality
            if (adminProfile && profileDropdown) {
                adminProfile.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('active');
                    // Close notification dropdown if open
                    if (notificationDropdown) notificationDropdown.classList.remove('active');
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                if (notificationDropdown) notificationDropdown.classList.remove('active');
                if (profileDropdown) profileDropdown.classList.remove('active');
            });
            
            // Prevent dropdowns from closing when clicking inside them
            if (notificationDropdown) {
                notificationDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
            if (profileDropdown) {
                profileDropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
            // Enhanced notification functionality
            $('.notification-link').on('click', function(e) {
                const notifId = $(this).data('notif-id');
                const notificationItem = $(this);
                
                // Only mark as read if it's unread
                if (notificationItem.hasClass('unread')) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'admin_portfolio.php',
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
            
            // Enhanced filter functionality
            const searchInput = document.getElementById('searchInput');
            const courseFilter = document.getElementById('courseFilter');
            const yearFilter = document.getElementById('yearFilter');
            const sortSelect = document.getElementById('sortSelect');
            
            let searchTimeout;
            
            // Search functionality with debounce
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        updateFilters();
                    }, 500);
                });
                
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        updateFilters();
                    }
                });
            }
            
            // Add event listeners to filter elements
            if (courseFilter) {
                courseFilter.addEventListener('change', updateFilters);
            }
            
            if (yearFilter) {
                yearFilter.addEventListener('change', updateFilters);
            }
            
            if (sortSelect) {
                sortSelect.addEventListener('change', updateFilters);
            }
        });

        // Enhanced updateFilters function
        function updateFilters() {
            const courseFilter = document.getElementById('courseFilter');
            const yearFilter = document.getElementById('yearFilter');
            const searchInput = document.getElementById('searchInput');
            const sortSelect = document.getElementById('sortSelect');
            
            if (!courseFilter || !yearFilter || !searchInput || !sortSelect) {
                console.error('Filter elements not found');
                return;
            }
            
            const courseValue = courseFilter.value;
            const yearValue = yearFilter.value;
            const searchValue = searchInput.value.trim();
            const sortValue = sortSelect.value;
            
            // Build URL with parameters
            const url = new URL(window.location.href);
            
            // Update or remove parameters
            if (courseValue && courseValue !== 'all') {
                url.searchParams.set('course', courseValue);
            } else {
                url.searchParams.delete('course');
            }
            
            if (yearValue && yearValue !== 'all') {
                url.searchParams.set('year', yearValue);
            } else {
                url.searchParams.delete('year');
            }
            
            if (searchValue) {
                url.searchParams.set('search', searchValue);
            } else {
                url.searchParams.delete('search');
            }
            
            if (sortValue && sortValue !== 'recent') {
                url.searchParams.set('sort', sortValue);
            } else {
                url.searchParams.delete('sort');
            }
            
            // Remove view_portfolio parameter if present
            url.searchParams.delete('view_portfolio');
            
            // Navigate to the new URL
            window.location.href = url.toString();
        }
        
        // Enhanced portfolio viewing function
        function viewPortfolio(userId) {
            const courseFilter = document.getElementById('courseFilter');
            const yearFilter = document.getElementById('yearFilter');
            const searchInput = document.getElementById('searchInput');
            const sortSelect = document.getElementById('sortSelect');
            
            const courseValue = courseFilter ? courseFilter.value : 'all';
            const yearValue = yearFilter ? yearFilter.value : 'all';
            const searchValue = searchInput ? searchInput.value.trim() : '';
            const sortValue = sortSelect ? sortSelect.value : 'recent';
            
            // Build URL with current filters and portfolio view
            let url = `admin_portfolio.php?view_portfolio=${userId}`;
            
            if (courseValue && courseValue !== 'all') {
                url += `&course=${encodeURIComponent(courseValue)}`;
            }
            
            if (yearValue && yearValue !== 'all') {
                url += `&year=${encodeURIComponent(yearValue)}`;
            }
            
            if (searchValue) {
                url += `&search=${encodeURIComponent(searchValue)}`;
            }
            
            if (sortValue && sortValue !== 'recent') {
                url += `&sort=${encodeURIComponent(sortValue)}`;
            }
            
            window.location.href = url;
        }
        
        function viewFullResume(userId, userName) {
            // Show the resume modal
            const resumeModal = document.getElementById('resumeModal');
            const resumeContent = document.getElementById('resumeContent');
            
            // Create professional resume content
            resumeContent.innerHTML = `
                <div class="resume-header">
                    <h1 class="resume-name">${userName}</h1>
                    <div class="resume-title"><?= $portfolio_details['grad_degree'] ?> Graduate</div>
                    <div class="resume-contact">
                        <span><i class="fas fa-envelope"></i> <?= $portfolio_details['usr_email'] ?></span>
                        <span><i class="fas fa-phone"></i> <?= !empty($portfolio_details['usr_phone']) ? $portfolio_details['usr_phone'] : 'Not provided' ?></span>
                        <span><i class="fas fa-graduation-cap"></i> Cebu Technological University</span>
                    </div>
                </div>
                
                <?php if (!empty($portfolio_details['grad_summary'])): ?>
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-bullseye"></i> Career Objective</h2>
                    <div class="resume-item">
                        <div class="resume-item-details"><?= nl2br(htmlspecialchars($portfolio_details['grad_summary'])) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-graduation-cap"></i> Education</h2>
                    <div class="resume-item">
                        <div class="resume-item-title"><i class="fas fa-university"></i> <?= $portfolio_details['grad_degree'] ?></div>
                        <div class="resume-item-details">Cebu Technological University</div>
                        <div class="resume-item-meta">
                            <span class="resume-meta-item"><i class="fas fa-calendar"></i> Graduated: <?= $portfolio_details['grad_year_graduated'] ?></span>
                            <span class="resume-meta-item"><i class="fas fa-id-card"></i> Alumni ID: <?= $portfolio_details['grad_school_id'] ?></span>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($portfolio_details['job_preferences'])): ?>
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-bullseye"></i> Job Preferences</h2>
                    <div class="skills-grid">
                        <?php foreach ($portfolio_details['job_preferences'] as $preference): ?>
                        <div class="skill-item">
                            <div class="skill-name"><?= htmlspecialchars($preference) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($portfolio_skills)): ?>
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-tools"></i> Technical Skills</h2>
                    <div class="skills-grid">
                        <?php foreach ($portfolio_skills as $skill): ?>
                        <div class="skill-item">
                            <div class="skill-name"><?= htmlspecialchars($skill['skill_name']) ?></div>
                            <div class="skill-level"><?= ucfirst($skill['skill_level']) ?> Level</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($portfolio_certificates)): ?>
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-certificate"></i> Certifications</h2>
                    <div class="documents-grid">
                        <?php foreach ($portfolio_certificates as $cert): ?>
                        <div class="document-card">
                            <div class="document-card-title"><i class="fas fa-award"></i> <?= htmlspecialchars($cert['port_item_title']) ?></div>
                            <?php if (!empty($cert['port_item_date'])): ?>
                            <div class="document-card-date"><i class="fas fa-calendar"></i> Issued: <?= date('F Y', strtotime($cert['port_item_date'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($cert['port_item_description'])): ?>
                            <div class="document-card-description"><?= htmlspecialchars($cert['port_item_description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($cert['port_item_file'])): ?>
                            <div class="document-card-actions">
                                <a href="<?= htmlspecialchars($cert['port_item_file']) ?>" target="_blank" class="document-card-btn primary">
                                    <i class="fas fa-eye"></i> View Certificate
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($portfolio_projects)): ?>
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-project-diagram"></i> Projects</h2>
                    <div class="documents-grid">
                        <?php foreach ($portfolio_projects as $project): ?>
                        <div class="document-card">
                            <div class="document-card-title"><i class="fas fa-tasks"></i> <?= htmlspecialchars($project['port_item_title']) ?></div>
                            <?php if (!empty($project['port_item_date'])): ?>
                            <div class="document-card-date"><i class="fas fa-calendar"></i> Completed: <?= date('F Y', strtotime($project['port_item_date'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($project['port_item_description'])): ?>
                            <div class="document-card-description"><?= htmlspecialchars($project['port_item_description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($project['port_item_file'])): ?>
                            <div class="document-card-actions">
                                <a href="<?= htmlspecialchars($project['port_item_file']) ?>" target="_blank" class="document-card-btn primary">
                                    <i class="fas fa-eye"></i> View Project
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($portfolio_resumes)): ?>
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-file-alt"></i> Uploaded Resumes</h2>
                    <div class="documents-grid">
                        <?php foreach ($portfolio_resumes as $resume): ?>
                        <div class="document-card">
                            <div class="document-card-title"><i class="fas fa-file-pdf"></i> <?= htmlspecialchars($resume['port_item_title']) ?></div>
                            <?php if (!empty($resume['port_item_date'])): ?>
                            <div class="document-card-date"><i class="fas fa-calendar"></i> Uploaded: <?= date('F Y', strtotime($resume['port_item_date'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($resume['port_item_description'])): ?>
                            <div class="document-card-description"><?= htmlspecialchars($resume['port_item_description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($resume['port_item_file'])): ?>
                            <div class="document-card-actions">
                                <a href="<?= htmlspecialchars($resume['port_item_file']) ?>" target="_blank" class="document-card-btn primary">
                                    <i class="fas fa-eye"></i> View Resume
                                </a>
                                <a href="<?= htmlspecialchars($resume['port_item_file']) ?>" download class="document-card-btn secondary">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="resume-section">
                    <h2 class="resume-section-title"><i class="fas fa-user-tie"></i> Additional Information</h2>
                    <?php if (!empty($portfolio_details['usr_gender'])): ?>
                    <div class="resume-item">
                        <div class="resume-item-title"><i class="fas fa-venus-mars"></i> Gender</div>
                        <div class="resume-item-details"><?= $portfolio_details['usr_gender'] ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($portfolio_details['usr_birthdate'])): ?>
                    <div class="resume-item">
                        <div class="resume-item-title"><i class="fas fa-birthday-cake"></i> Date of Birth</div>
                        <div class="resume-item-details"><?= date('F j, Y', strtotime($portfolio_details['usr_birthdate'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="resume-footer">
                    <p><em>This professional resume was generated from the CTU-PESO Digital Portfolio System</em></p>
                    <p><strong>Generated on: <?= date('F j, Y') ?></strong></p>
                </div>
            `;
            
            resumeModal.classList.add('active');
        }
        
        function closeResumeModal() {
            const resumeModal = document.getElementById('resumeModal');
            resumeModal.classList.remove('active');
        }
        
        function closeModal() {
            // Get current filter values
            const courseFilter = document.getElementById('courseFilter');
            const yearFilter = document.getElementById('yearFilter');
            const searchInput = document.getElementById('searchInput');
            const sortSelect = document.getElementById('sortSelect');
            
            const courseValue = courseFilter ? courseFilter.value : 'all';
            const yearValue = yearFilter ? yearFilter.value : 'all';
            const searchValue = searchInput ? searchInput.value.trim() : '';
            const sortValue = sortSelect ? sortSelect.value : 'recent';
            
            // Build URL without view_portfolio parameter but with current filters
            let url = 'admin_portfolio.php';
            const params = [];
            
            if (courseValue && courseValue !== 'all') {
                params.push(`course=${encodeURIComponent(courseValue)}`);
            }
            
            if (yearValue && yearValue !== 'all') {
                params.push(`year=${encodeURIComponent(yearValue)}`);
            }
            
            if (searchValue) {
                params.push(`search=${encodeURIComponent(searchValue)}`);
            }
            
            if (sortValue && sortValue !== 'recent') {
                params.push(`sort=${encodeURIComponent(sortValue)}`);
            }
            
            if (params.length > 0) {
                url += '?' + params.join('&');
            }
            
            window.location.href = url;
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            const modalOverlay = document.querySelector('.modal-overlay.active');
            if (modalOverlay && e.target === modalOverlay) {
                closeModal();
            }
            
            const resumeModal = document.getElementById('resumeModal');
            if (resumeModal && e.target === resumeModal) {
                closeResumeModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeResumeModal();
            }
        });
        
        // Function to refresh notifications
        function refreshNotifications() {
            $.ajax({
                url: 'admin_portfolio.php?ajax=get_notifications',
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