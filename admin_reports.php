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
    // Check for incomplete alumni profiles
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
                "{$incompleteProfiles['count']} alumni profile(s) are incomplete. Review portfolio issues.");
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
    
    // Check employment rate trends
    $employmentRate = $conn->query("
        SELECT 
            COUNT(*) as total_alumni,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_alumni
        FROM graduates g
        LEFT JOIN users u ON g.grad_usr_id = u.usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($employmentRate['total_alumni'] > 0) {
        $employmentPercentage = round(($employmentRate['hired_alumni'] / $employmentRate['total_alumni']) * 100);
        
        if ($employmentPercentage >= 70) {
            $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                            WHERE notif_type = 'employment_success' 
                                            AND DATE(notif_created_at) = CURDATE()");
            $existingNotif->execute();
            
            if ($existingNotif->fetchColumn() == 0) {
                createAdminNotification($conn, 'employment_success', 
                    "Great news! Employment rate is at {$employmentPercentage}% among alumni.");
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
    
    // Check top skills in demand - FIXED: Using job_skills column from jobs table instead of non-existent job_skills table
    $topSkillsQuery = $conn->query("
        SELECT job_skills, COUNT(*) as job_count
        FROM jobs 
        WHERE job_status = 'active' 
        AND job_skills IS NOT NULL 
        AND job_skills != ''
        GROUP BY job_skills
        ORDER BY job_count DESC
        LIMIT 1
    ");
    
    $topSkills = $topSkillsQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($topSkills && $topSkills['job_count'] >= 3) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'skill_demand' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'skill_demand', 
                "High demand for '{$topSkills['job_skills']}' skill ({$topSkills['job_count']} job postings).");
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
// EXISTING REPORTS FUNCTIONALITY - WITH ENHANCED NOTIFICATIONS
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_reports.php");
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

// NEW: Check if a specific course is selected for filtering
$selected_course = isset($_GET['course']) ? $_GET['course'] : null;

// Fetch statistics data from database
// Total users count (EXCLUDING ADMIN)
$total_users_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role != 'admin'");
$total_users = $total_users_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total alumni count
$total_alumni_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role = 'graduate'");
$total_alumni = $total_alumni_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total employers count
$total_employers_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role = 'employer'");
$total_employers = $total_employers_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total staff count
$total_staff_stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role = 'staff'");
$total_staff = $total_staff_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total jobs count
$total_jobs_stmt = $conn->query("SELECT COUNT(*) as count FROM jobs");
$total_jobs = $total_jobs_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total applications count
$total_applications_stmt = $conn->query("SELECT COUNT(*) as count FROM applications");
$total_applications = $total_applications_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// FIXED: Employment statistics by course - Corrected query to properly count employed alumni
$employment_stats_stmt = $conn->query("
    SELECT 
        g.grad_degree as course, 
        COUNT(*) as total_alumni,
        COUNT(CASE WHEN a.app_status = 'hired' THEN 1 END) as employed
    FROM graduates g
    INNER JOIN users u ON g.grad_usr_id = u.usr_id
    LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id AND a.app_status = 'hired'
    GROUP BY g.grad_degree
");
$employment_stats = $employment_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// NEW: Get employment data for selected course if any
$selected_course_data = null;
if ($selected_course) {
    $selected_course_stmt = $conn->prepare("
        SELECT 
            g.grad_degree as course, 
            COUNT(*) as total_alumni,
            COUNT(CASE WHEN a.app_status = 'hired' THEN 1 END) as employed
        FROM graduates g
        INNER JOIN users u ON g.grad_usr_id = u.usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id AND a.app_status = 'hired'
        WHERE g.grad_degree = ?
        GROUP BY g.grad_degree
    ");
    $selected_course_stmt->execute([$selected_course]);
    $selected_course_data = $selected_course_stmt->fetch(PDO::FETCH_ASSOC);
}

// NEW: Get application statistics for selected course
$application_stats_by_course = [];
if ($selected_course) {
    $application_stats_stmt = $conn->prepare("
        SELECT a.app_status as status, COUNT(*) as count 
        FROM applications a
        INNER JOIN users u ON a.app_grad_usr_id = u.usr_id
        INNER JOIN graduates g ON u.usr_id = g.grad_usr_id
        WHERE g.grad_degree = ?
        GROUP BY a.app_status
    ");
    $application_stats_stmt->execute([$selected_course]);
    $application_stats_by_course = $application_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Get overall application statistics
    $application_stats_stmt = $conn->query("
        SELECT app_status as status, COUNT(*) as count 
        FROM applications 
        GROUP BY app_status
    ");
    $application_stats_by_course = $application_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate overall employment statistics
$total_employed = 0;
$total_unemployed = 0;

foreach ($employment_stats as $stat) {
    $total_employed += $stat['employed'];
    $total_unemployed += ($stat['total_alumni'] - $stat['employed']);
}

// NEW: Calculate employment statistics for selected course
$selected_employed = 0;
$selected_unemployed = 0;
if ($selected_course_data) {
    $selected_employed = $selected_course_data['employed'];
    $selected_unemployed = $selected_course_data['total_alumni'] - $selected_course_data['employed'];
}

// Job trends by month
$job_trends_stmt = $conn->query("
    SELECT DATE_FORMAT(job_created_at, '%b') as month, 
           COUNT(*) as job_count
    FROM jobs 
    WHERE job_created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(job_created_at, '%Y-%m'), DATE_FORMAT(job_created_at, '%b')
    ORDER BY MIN(job_created_at)
");
$job_trends = $job_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// NEW: Get job trends by industry
$industry_trends_stmt = $conn->query("
    SELECT e.emp_industry as industry, COUNT(j.job_id) as job_count
    FROM jobs j
    INNER JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
    GROUP BY e.emp_industry
    ORDER BY job_count DESC
    LIMIT 5
");
$industry_trends = $industry_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// NEW: Get job type distribution
$job_type_stmt = $conn->query("
    SELECT job_type, COUNT(*) as count 
    FROM jobs 
    GROUP BY job_type
");
$job_type_data = $job_type_stmt->fetchAll(PDO::FETCH_ASSOC);

// NEW: Get top job locations
$job_location_stmt = $conn->query("
    SELECT job_location, COUNT(*) as count 
    FROM jobs 
    WHERE job_location IS NOT NULL AND job_location != ''
    GROUP BY job_location
    ORDER BY count DESC
    LIMIT 5
");
$job_location_data = $job_location_stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for charts
$employment_rate_data = [];
$course_employment_data = [];
$job_trends_data = [];
$application_status_data = [];
$industry_trends_data = [];
$job_type_chart_data = [];

// Define consistent color scheme for application statuses
$application_status_colors = [
    'pending' => '#f7a100',    // Orange
    'reviewed' => '#0044ff',   // Blue
    'shortlisted' => '#ff6b00', // Dark Orange
    'qualified' => '#6a0dad',  // Purple
    'hired' => '#1f7a11',      // Green
    'rejected' => '#d32f2f'    // Red
];

// NEW: Prepare employment data based on selected course
if ($selected_course_data) {
    // Use selected course data
    $employment_rate = $selected_course_data['total_alumni'] > 0 ? round(($selected_course_data['employed'] / $selected_course_data['total_alumni']) * 100) : 0;
    $employment_rate_data[] = $employment_rate;
    $course_employment_data[] = [
        'course' => $selected_course_data['course'],
        'employed' => $selected_course_data['employed'],
        'unemployed' => $selected_course_data['total_alumni'] - $selected_course_data['employed'],
        'total' => $selected_course_data['total_alumni']
    ];
} else {
    // Use overall data
    foreach ($employment_stats as $stat) {
        $employment_rate = $stat['total_alumni'] > 0 ? round(($stat['employed'] / $stat['total_alumni']) * 100) : 0;
        $employment_rate_data[] = $employment_rate;
        $course_employment_data[] = [
            'course' => $stat['course'],
            'employed' => $stat['employed'],
            'unemployed' => $stat['total_alumni'] - $stat['employed'],
            'total' => $stat['total_alumni']
        ];
    }
}

foreach ($job_trends as $trend) {
    $job_trends_data['labels'][] = $trend['month'];
    $job_trends_data['counts'][] = $trend['job_count'];
}

// NEW: Prepare application status data with consistent colors based on selected course
$total_applications_for_selected = $selected_course ? array_sum(array_column($application_stats_by_course, 'count')) : $total_applications;

foreach ($application_stats_by_course as $stat) {
    $application_status_data['labels'][] = ucfirst($stat['status']);
    $application_status_data['counts'][] = $stat['count'];
    $application_status_data['percentages'][] = $total_applications_for_selected > 0 ? round(($stat['count'] / $total_applications_for_selected) * 100) : 0;
    $application_status_data['colors'][] = $application_status_colors[strtolower($stat['status'])] ?? '#6e0303';
}

// Prepare industry trends data
foreach ($industry_trends as $trend) {
    $industry_trends_data['labels'][] = $trend['industry'];
    $industry_trends_data['counts'][] = $trend['job_count'];
    $industry_trends_data['percentages'][] = $total_jobs > 0 ? round(($trend['job_count'] / $total_jobs) * 100) : 0;
}

// Prepare job type data
foreach ($job_type_data as $type) {
    $job_type_chart_data['labels'][] = ucfirst(str_replace('-', ' ', $type['job_type']));
    $job_type_chart_data['counts'][] = $type['count'];
    $job_type_chart_data['percentages'][] = $total_jobs > 0 ? round(($type['count'] / $total_jobs) * 100) : 0;
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
    <title>CTU-PESO - Reports & Analytics</title>
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
        
        /* Course Filter Indicator */
        .course-indicator {
            display: <?= $selected_course ? 'flex' : 'none' ?>;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .indicator-label {
            font-weight: 600;
            margin-right: 15px;
            color: var(--primary-color);
        }
        
        .selected-course {
            background: var(--primary-color);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
            margin-right: 15px;
        }
        
        .clear-filter {
            margin-left: auto;
            padding: 8px 15px;
            border: 1px solid var(--secondary-color);
            border-radius: 20px;
            background: white;
            color: var(--secondary-color);
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .clear-filter:hover {
            background: rgba(247, 161, 0, 0.1);
        }
        
        /* FIXED: Single Row Dashboard Cards - No horizontal scrolling */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 30px;
            overflow-x: hidden;
        }
        
        .card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            padding: 18px;
            transition: all 0.3s;
            border-top: 3px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .card-title {
            font-size: 0.75rem;
            color: var(--primary-color);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        
        .card-icon {
            font-size: 1rem;
            color: var(--secondary-color);
            opacity: 0.8;
            margin-left: 8px;
        }
        
        .card-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .card-footer {
            font-size: 0.7rem;
            color: #666;
            line-height: 1.2;
        }
        
        /* Reports Page Specific Styles */
        .chart-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .chart-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            height: 450px; /* Increased height for larger graphs */
            display: flex;
            flex-direction: column;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }
        
        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
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
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #8a0404;
        }
        
        .btn-danger {
            background-color: var(--red);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b71c1c;
        }
        
        .chart-canvas {
            flex: 1;
            width: 100%;
            min-height: 350px; /* Minimum height for charts */
        }
        
        /* Report Tabs */
        .report-tabs {
            display: flex;
            margin: 0 0 25px 0;
            background-color: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .tab {
            padding: 18px 25px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            flex: 1;
            border-bottom: 3px solid transparent;
            font-weight: 500;
        }
        
        .tab:hover {
            background-color: #f9f9f9;
        }
        
        .tab.active {
            border-bottom: 3px solid var(--primary-color);
            background-color: #fff5e6;
            font-weight: 600;
        }
        
        /* Report Views */
        .report-view {
            display: none;
            margin: 0;
        }
        
        .report-view.active {
            display: block;
        }
        
        /* Employment Rate View Styles */
        .employment-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .employment-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            height: 450px; /* Increased height */
            display: flex;
            flex-direction: column;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
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
            cursor: pointer;
        }
        
        .course-item:hover {
            background-color: #f8f9fa;
            padding-left: 10px;
            border-radius: 5px;
        }
        
        .course-item.active {
            background-color: #fff5e6;
            border-left: 4px solid var(--secondary-color);
            padding-left: 10px;
        }
        
        .course-name {
            font-weight: 500;
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
            padding: 3px 8px;
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
        
        /* Alumni Success View Styles - MODIFIED: Now uses consistent colors between chart and distribution */
        .skills-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
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
            padding-left: 10px;
            border-radius: 5px;
        }
        
        .skill-name {
            flex: 1;
            font-weight: 500;
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
            width: 50px;
            text-align: right;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        /* NEW: Percentage badge styling */
        .percentage-badge {
            background-color: var(--primary-color);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* Market Trends View Styles - ENHANCED */
        .trends-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .trends-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            height: 450px; /* Increased height */
            display: flex;
            flex-direction: column;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }
        
        .trends-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .trends-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        /* NEW: Market insights cards */
        .insights-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .insight-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            height: 200px; /* Fixed height for consistency */
            display: flex;
            flex-direction: column;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }
        
        .insight-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .insight-title {
            font-size: 1rem;
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .insight-list {
            list-style: none;
            flex: 1;
            overflow-y: auto;
        }
        
        .insight-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            transition: all 0.3s;
        }
        
        .insight-item:hover {
            background-color: #f8f9fa;
            padding-left: 10px;
            border-radius: 5px;
        }
        
        .insight-name {
            font-weight: 500;
        }
        
        .insight-value {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        /* Chart percentage labels */
        .chart-percentage {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-weight: bold;
            font-size: 16px;
            color: var(--primary-color);
        }
        
        .chart-container-relative {
            position: relative;
            width: 100%;
            height: 100%;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .trends-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 1024px) {
            .chart-container,
            .employment-container,
            .skills-container,
            .trends-container {
                grid-template-columns: 1fr;
            }
            
            .insights-container {
                grid-template-columns: 1fr 1fr;
            }
            
            .dashboard-cards {
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }
            
            .card {
                padding: 15px;
                min-height: 110px;
            }
            
            .card-value {
                font-size: 1.4rem;
            }
            
            .chart-card,
            .employment-card,
            .trends-card {
                height: 400px;
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
            
            .report-tabs {
                flex-direction: column;
            }
            
            .page-title {
                margin: 0 0 20px 0;
            }
            
            .report-view {
                margin: 0;
            }
            
            .chart-card,
            .employment-card,
            .trends-card {
                height: auto;
                min-height: 350px;
            }
            
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .skill-bar {
                width: 100px;
            }
            
            .insights-container {
                grid-template-columns: 1fr;
            }
            
            .trends-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .card {
                padding: 12px;
                min-height: 100px;
            }
            
            .card-value {
                font-size: 1.3rem;
            }
            
            .card-title {
                font-size: 0.7rem;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .skill-bar {
                width: 80px;
                margin: 0 8px;
            }
            
            .skill-percentage {
                width: 40px;
            }
            
            .chart-card,
            .employment-card,
            .trends-card {
                height: auto;
                min-height: 300px;
            }
            
            .dashboard-cards {
                grid-template-columns: 1fr;
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
                    <a href="admin_reports.php" class="active">
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
            <p>Here's the latest reports and analytics for the system. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Reports & Analytics</h1>
        </div>
        
        <!-- NEW: Course Filter Indicator -->
        <?php if ($selected_course): ?>
        <div class="course-indicator" id="courseIndicator">
            <div class="indicator-label">Currently viewing:</div>
            <div class="selected-course"><?= htmlspecialchars($selected_course) ?></div>
            <button class="clear-filter" onclick="clearCourseFilter()">View All Courses</button>
        </div>
        <?php endif; ?>
        
        <!-- Enhanced Dashboard Cards - FIXED: Single row layout -->
        <div class="dashboard-cards">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Total Users</h3>
                    <i class="fas fa-users card-icon"></i>
                </div>
                <div class="card-value"><?= $total_users ?></div>
                <div class="card-footer">All registered users</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Job Posts</h3>
                    <i class="fas fa-briefcase card-icon"></i>
                </div>
                <div class="card-value"><?= $total_jobs ?></div>
                <div class="card-footer">Total job listings posted</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Alumni</h3>
                    <i class="fas fa-graduation-cap card-icon"></i>
                </div>
                <div class="card-value"><?= $total_alumni ?></div>
                <div class="card-footer">Registered alumni users</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Employers</h3>
                    <i class="fas fa-building card-icon"></i>
                </div>
                <div class="card-value"><?= $total_employers ?></div>
                <div class="card-footer">Registered employer accounts</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Staffs</h3>
                    <i class="fas fa-user-tie card-icon"></i>
                </div>
                <div class="card-value"><?= $total_staff ?></div>
                <div class="card-footer">Registered staff accounts</div>
            </div>
        </div>
        
        <!-- Report Tabs -->
        <div class="report-tabs">
            <div class="tab active" data-view="employment-rate">Employment Rate</div>
            <div class="tab" data-view="alumni-success">Alumni Success</div>
            <div class="tab" data-view="market-trends">Market Trends</div>
        </div>
        
        <!-- Employment Rate View -->
        <div class="report-view active" id="employment-rate-view">
            <div class="employment-container">
                <div class="chart-card">
                    <h3 class="chart-title">
                        <?= $selected_course ? "Employment Rate - " . htmlspecialchars($selected_course) : "Employment Rate" ?>
                    </h3>
                    <div class="chart-container-relative">
                        <canvas id="employmentRateChart" class="chart-canvas"></canvas>
                        <div class="chart-percentage" id="employmentRatePercentage">
                            <?php 
                                if ($selected_course_data) {
                                    $employment_rate = $selected_course_data['total_alumni'] > 0 ? round(($selected_course_data['employed'] / $selected_course_data['total_alumni']) * 100) : 0;
                                } else {
                                    $total_alumni_count = $total_employed + $total_unemployed;
                                    $employment_rate = $total_alumni_count > 0 ? round(($total_employed / $total_alumni_count) * 100) : 0;
                                }
                                echo $employment_rate . '%';
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="employment-card">
                    <h3 class="chart-title">Employed vs. Unemployed per Course</h3>
                    <div class="course-employment">
                        <?php if (!empty($course_employment_data)): ?>
                            <?php foreach ($course_employment_data as $course): 
                                $employed_percentage = $course['total'] > 0 ? round(($course['employed'] / $course['total']) * 100) : 0;
                                $unemployed_percentage = $course['total'] > 0 ? round(($course['unemployed'] / $course['total']) * 100) : 0;
                            ?>
                            <div class="course-item <?= ($selected_course == $course['course']) ? 'active' : '' ?>" data-course="<?= htmlspecialchars($course['course']) ?>">
                                <div class="course-name"><?= htmlspecialchars($course['course']) ?></div>
                                <div class="course-stats">
                                    <div class="employed">
                                        <span class="stat-badge"><?= $course['employed'] ?></span>
                                        <span>Employed (<?= $employed_percentage ?>%)</span>
                                    </div>
                                    <div class="unemployed">
                                        <span class="stat-badge"><?= $course['unemployed'] ?></span>
                                        <span>Unemployed (<?= $unemployed_percentage ?>%)</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="course-item">
                                <div class="course-name">No data available</div>
                                <div class="course-stats">
                                    <div class="employed">
                                        <span class="stat-badge">0</span>
                                        <span>Employed (0%)</span>
                                    </div>
                                    <div class="unemployed">
                                        <span class="stat-badge">0</span>
                                        <span>Unemployed (0%)</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Alumni Success View - MODIFIED: Now uses consistent colors between chart and distribution -->
        <div class="report-view" id="alumni-success-view">
            <div class="skills-container">
                <div class="chart-card">
                    <h3 class="chart-title">
                        <?= $selected_course ? "Application Status - " . htmlspecialchars($selected_course) : "Application Status" ?>
                    </h3>
                    <div class="chart-container-relative">
                        <canvas id="applicationStatusChart" class="chart-canvas"></canvas>
                    </div>
                </div>
                
                <div class="employment-card">
                    <h3 class="chart-title">
                        <?= $selected_course ? "Application Status Distribution - " . htmlspecialchars($selected_course) : "Application Status Distribution" ?>
                    </h3>
                    <ul class="skills-list">
                        <?php if (!empty($application_stats_by_course) && $total_applications_for_selected > 0): ?>
                            <?php foreach ($application_stats_by_course as $index => $stat): 
                                $percentage = $application_status_data['percentages'][$index];
                                $status_color = $application_status_data['colors'][$index];
                            ?>
                            <li class="skill-item">
                                <div class="skill-name"><?= ucfirst($stat['status']) ?></div>
                                <div class="skill-bar">
                                    <div class="skill-progress" style="width: <?= $percentage ?>%; background-color: <?= $status_color ?>;"></div>
                                </div>
                                <div class="skill-percentage"><?= $percentage ?>%</div>
                                <span class="percentage-badge"><?= $stat['count'] ?> applications</span>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="skill-item">
                                <div class="skill-name">No application data</div>
                                <div class="skill-bar">
                                    <div class="skill-progress" style="width: 0%; background-color: var(--primary-color);"></div>
                                </div>
                                <div class="skill-percentage">0%</div>
                                <span class="percentage-badge">0 applications</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Market Trends View - ENHANCED -->
        <div class="report-view" id="market-trends-view">
            <!-- Market Insights Cards - MODIFIED: Changed to two cards in one row -->
            <div class="insights-container">
                <div class="insight-card">
                    <h3 class="insight-title">Top Industries</h3>
                    <ul class="insight-list">
                        <?php if (!empty($industry_trends)): ?>
                            <?php foreach ($industry_trends as $index => $industry): 
                                $percentage = $industry_trends_data['percentages'][$index] ?? 0;
                            ?>
                            <li class="insight-item">
                                <span class="insight-name"><?= htmlspecialchars($industry['industry']) ?></span>
                                <span class="insight-value"><?= $industry['job_count'] ?> jobs (<?= $percentage ?>%)</span>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="insight-item">
                                <span class="insight-name">No industry data</span>
                                <span class="insight-value">0 jobs (0%)</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <div class="insight-card">
                    <h3 class="insight-title">Top Job Locations</h3>
                    <ul class="insight-list">
                        <?php if (!empty($job_location_data)): ?>
                            <?php foreach ($job_location_data as $location): 
                                $percentage = $total_jobs > 0 ? round(($location['count'] / $total_jobs) * 100) : 0;
                            ?>
                            <li class="insight-item">
                                <span class="insight-name"><?= htmlspecialchars($location['job_location']) ?></span>
                                <span class="insight-value"><?= $location['count'] ?> jobs (<?= $percentage ?>%)</span>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="insight-item">
                                <span class="insight-name">No location data</span>
                                <span class="insight-value">0 jobs (0%)</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            
            <!-- Charts Section - MODIFIED: Changed to three charts in one row -->
            <div class="trends-container">
                <div class="trends-card">
                    <div class="trends-header">
                        <h3 class="chart-title">Job Post Frequency Over Months</h3>
                        <div class="export-buttons">
                            <button class="btn btn-primary"><i class="fas fa-file-pdf"></i> PDF</button>
                            <button class="btn btn-danger"><i class="fas fa-file-excel"></i> Excel</button>
                        </div>
                    </div>
                    <canvas id="marketTrendsChart" class="chart-canvas"></canvas>
                </div>
                
                <div class="trends-card">
                    <div class="trends-header">
                        <h3 class="chart-title">Job Type Distribution</h3>
                        <div class="export-buttons">
                            <button class="btn btn-primary"><i class="fas fa-file-pdf"></i> PDF</button>
                            <button class="btn btn-danger"><i class="fas fa-file-excel"></i> Excel</button>
                        </div>
                    </div>
                    <div class="chart-container-relative">
                        <canvas id="jobTypeChart" class="chart-canvas"></canvas>
                        <div class="chart-percentage" id="jobTypePercentage">
                            <?php 
                                $most_common_type = max($job_type_chart_data['counts'] ?? [0]);
                                $most_common_percentage = $total_jobs > 0 ? round(($most_common_type / $total_jobs) * 100) : 0;
                                echo $most_common_percentage . '%';
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="trends-card">
                    <div class="trends-header">
                        <h3 class="chart-title">Industry Distribution</h3>
                        <div class="export-buttons">
                            <button class="btn btn-primary"><i class="fas fa-file-pdf"></i> PDF</button>
                            <button class="btn btn-danger"><i class="fas fa-file-excel"></i> Excel</button>
                        </div>
                    </div>
                    <div class="chart-container-relative">
                        <canvas id="industryChart" class="chart-canvas"></canvas>
                        <div class="chart-percentage" id="industryPercentage">
                            <?php 
                                $most_common_industry = max($industry_trends_data['counts'] ?? [0]);
                                $most_common_percentage = $total_jobs > 0 ? round(($most_common_industry / $total_jobs) * 100) : 0;
                                echo $most_common_percentage . '%';
                            ?>
                        </div>
                    </div>
                </div>
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
                        url: 'admin_reports.php',
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
                url: 'admin_reports.php?ajax=get_notifications',
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
        
        // NEW: Course Filtering Functionality
        function filterByCourse(course) {
            // Update URL with course parameter
            const url = new URL(window.location.href);
            url.searchParams.set('course', course);
            window.location.href = url.toString();
        }
        
        function clearCourseFilter() {
            // Remove course parameter from URL
            const url = new URL(window.location.href);
            url.searchParams.delete('course');
            window.location.href = url.toString();
        }
        
        // Add event listeners to course items
        document.querySelectorAll('.course-item').forEach(item => {
            item.addEventListener('click', function() {
                const course = this.getAttribute('data-course');
                filterByCourse(course);
            });
        });
        
        // Tab Switching Functionality
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
        
        // Enhanced color palette for charts
        const systemColors = {
            maroon: '#6e0303',
            orange: '#f7a100',
            green: '#1f7a11',
            blue: '#0044ff',
            purple: '#6a0dad',
            red: '#d32f2f',
            darkOrange: '#ff6b00'
        };
        
        // Enhanced color combinations for different chart types
        const chartColorSchemes = {
            employment: [systemColors.green, systemColors.red],
            applications: [
                systemColors.orange,    // pending
                systemColors.blue,      // reviewed
                systemColors.darkOrange, // shortlisted
                systemColors.purple,    // qualified
                systemColors.green,     // hired
                systemColors.red        // rejected
            ],
            jobTypes: [
                systemColors.maroon,
                systemColors.orange,
                systemColors.green,
                systemColors.blue,
                systemColors.purple
            ],
            industries: [
                systemColors.maroon,
                systemColors.green,
                systemColors.orange,
                systemColors.blue,
                systemColors.purple
            ],
            trends: [systemColors.maroon]
        };
        
        // Charts initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Employment Rate Chart - ENHANCED COLORS
            const employmentRateCtx = document.getElementById('employmentRateChart').getContext('2d');
            const employmentRateChart = new Chart(employmentRateCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Employed', 'Unemployed'],
                    datasets: [{
                        data: [
                            <?= $selected_course_data ? $selected_course_data['employed'] : $total_employed ?>, 
                            <?= $selected_course_data ? ($selected_course_data['total_alumni'] - $selected_course_data['employed']) : $total_unemployed ?>
                        ],
                        backgroundColor: chartColorSchemes.employment,
                        borderColor: ['#fff', '#fff'],
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = <?= $selected_course_data ? $selected_course_data['total_alumni'] : ($total_employed + $total_unemployed) ?>;
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Application Status Chart - ENHANCED COLORS
            const applicationStatusCtx = document.getElementById('applicationStatusChart').getContext('2d');
            const applicationStatusChart = new Chart(applicationStatusCtx, {
                type: 'pie',
                data: {
                    labels: <?= json_encode($application_status_data['labels'] ?? []) ?>,
                    datasets: [{
                        data: <?= json_encode($application_status_data['counts'] ?? []) ?>,
                        backgroundColor: chartColorSchemes.applications,
                        borderColor: '#fff',
                        borderWidth: 2,
                        hoverOffset: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = <?= $selected_course ? $total_applications_for_selected : $total_applications ?>;
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Market Trends Chart - ENHANCED COLORS
            const marketTrendsCtx = document.getElementById('marketTrendsChart').getContext('2d');
            new Chart(marketTrendsCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($job_trends_data['labels'] ?? []) ?>,
                    datasets: [{
                        label: 'Job Posts',
                        data: <?= json_encode($job_trends_data['counts'] ?? []) ?>,
                        borderColor: systemColors.maroon,
                        backgroundColor: 'rgba(110, 3, 3, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: systemColors.maroon,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                pointStyle: 'line'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        }
                    }
                }
            });
            
            // Job Type Chart - ENHANCED COLORS
            const jobTypeCtx = document.getElementById('jobTypeChart').getContext('2d');
            new Chart(jobTypeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($job_type_chart_data['labels'] ?? []) ?>,
                    datasets: [{
                        data: <?= json_encode($job_type_chart_data['counts'] ?? []) ?>,
                        backgroundColor: chartColorSchemes.jobTypes,
                        borderColor: '#fff',
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = <?= $total_jobs ?>;
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Industry Chart - ENHANCED COLORS
            const industryCtx = document.getElementById('industryChart').getContext('2d');
            new Chart(industryCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($industry_trends_data['labels'] ?? []) ?>,
                    datasets: [{
                        data: <?= json_encode($industry_trends_data['counts'] ?? []) ?>,
                        backgroundColor: chartColorSchemes.industries,
                        borderColor: '#fff',
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = <?= $total_jobs ?>;
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>