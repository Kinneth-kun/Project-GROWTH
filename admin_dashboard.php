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
// COURSE MANAGEMENT FUNCTIONALITY - MODIFIED (WITH IN-MODAL MESSAGES)
// ============================================================================

// Initialize message variables
$course_success_message = '';
$course_error_message = '';

// Handle course management actions
if (isset($_POST['add_course'])) {
    $course_code = trim($_POST['course_code']);
    $course_name = trim($_POST['course_name']);
    $course_college = trim($_POST['course_college']);
    $course_description = trim($_POST['course_description']);
    
    // Check if new college was entered
    $new_college = '';
    if (isset($_POST['new_college']) && !empty(trim($_POST['new_college']))) {
        $new_college = trim($_POST['new_college']);
        // If new college is provided, use it instead of the dropdown value
        if (!empty($new_college)) {
            $course_college = $new_college;
        }
    }
    
    // Validate inputs
    $errors = [];
    
    if (empty($course_code)) {
        $errors[] = "Course code is required";
    } elseif (strlen($course_code) > 20) {
        $errors[] = "Course code must not exceed 20 characters";
    }
    
    if (empty($course_name)) {
        $errors[] = "Course name is required";
    } elseif (strlen($course_name) > 255) {
        $errors[] = "Course name must not exceed 255 characters";
    }
    
    if (empty($course_college) || $course_college === '_new') {
        $errors[] = "College/department is required";
    } elseif (strlen($course_college) > 100) {
        $errors[] = "College/department must not exceed 100 characters";
    }
    
    if (strlen($course_description) > 1000) {
        $errors[] = "Description must not exceed 1000 characters";
    }
    
    if (empty($errors)) {
        // Check for duplicate course code
        $checkStmt = $conn->prepare("SELECT course_id FROM courses WHERE course_code = ?");
        $checkStmt->execute([$course_code]);
        
        if ($checkStmt->rowCount() > 0) {
            $course_error_message = "Course code already exists. Please use a different code.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO courses (course_code, course_name, course_college, course_description, created_at) 
                                       VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$course_code, $course_name, $course_college, $course_description]);
                
                $course_success_message = "Course added successfully!";
                
                // Clear form fields after successful submission
                $_POST['course_code'] = '';
                $_POST['course_name'] = '';
                $_POST['course_college'] = '';
                $_POST['new_college'] = '';
                $_POST['course_description'] = '';
                
                // Create notification for action
                $message = "New course added: $course_code - $course_name ($course_college)";
                createAdminNotification($conn, 'course_management', $message);
                
                // Redirect to clear POST data and show success message
                header("Location: admin_dashboard.php?course_success=1");
                exit();
                
            } catch (PDOException $e) {
                $course_error_message = "Database error: " . $e->getMessage();
            }
        }
    } else {
        $course_error_message = implode("<br>", $errors);
    }
}

// Handle delete course
if (isset($_GET['delete_course'])) {
    $course_id = intval($_GET['delete_course']);
    
    // First get course details for message
    $courseDetailsStmt = $conn->prepare("SELECT course_code, course_name, course_college FROM courses WHERE course_id = ?");
    $courseDetailsStmt->execute([$course_id]);
    $course = $courseDetailsStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($course) {
        // Check if any graduates are enrolled in this course
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM graduates WHERE grad_degree LIKE ?");
        $courseCode = $course['course_code'];
        $checkStmt->execute(["%$courseCode%"]);
        
        if ($checkStmt->fetchColumn() > 0) {
            $course_error_message = "Cannot delete '{$course['course_code']} - {$course['course_name']}' because graduates are enrolled in it.";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM courses WHERE course_id = ?");
                $stmt->execute([$course_id]);
                
                $course_success_message = "Course '{$course['course_code']} - {$course['course_name']}' deleted successfully!";
                
                // Create notification for action
                $message = "Course '{$course['course_code']} - {$course['course_name']}' was deleted";
                createAdminNotification($conn, 'course_management', $message);
                
                // Redirect to clear GET data and show success message
                header("Location: admin_dashboard.php?course_delete_success=1");
                exit();
                
            } catch (PDOException $e) {
                $course_error_message = "Database error: " . $e->getMessage();
            }
        }
    } else {
        $course_error_message = "Course not found.";
    }
}

// Check for success messages from redirects
if (isset($_GET['course_success'])) {
    $course_success_message = "Course added successfully!";
}

if (isset($_GET['course_delete_success'])) {
    $course_success_message = "Course deleted successfully!";
}

// Get course data for dashboard
try {
    $courses_stmt = $conn->query("
        SELECT c.*, 
               COUNT(g.grad_id) as graduate_count
        FROM courses c
        LEFT JOIN graduates g ON c.course_code = g.grad_degree
        GROUP BY c.course_id
        ORDER BY c.course_college, c.course_name
    ");
    $courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique colleges/departments
    $colleges_stmt = $conn->query("
        SELECT DISTINCT course_college 
        FROM courses 
        WHERE course_college IS NOT NULL AND course_college != ''
        ORDER BY course_college
    ");
    $colleges = $colleges_stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    $courses = [];
    $colleges = [];
}

// Get course counts
$total_courses = count($courses);

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
// EXISTING DASHBOARD FUNCTIONALITY - WITH ENHANCEMENTS
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_dashboard.php");
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

// Get counts for dashboard with error handling
try {
    // Total users count
    $users_count = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role != 'admin'")->fetchColumn();
    
    // Graduates count
    $graduates_count = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'graduate'")->fetchColumn();
    
    // Employers count
    $employers_count = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'employer' AND usr_is_approved = TRUE")->fetchColumn();
    
    // Pending employers count
    $pending_employers = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'employer' AND usr_is_approved = FALSE")->fetchColumn();
    
    // Total employers (approved + pending)
    $total_employers = $employers_count + $pending_employers;
    
    // Staff count
    $staff_count = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'staff'")->fetchColumn();
    
    // Active jobs count
    $jobs_count = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'active'")->fetchColumn();
    
    // Pending jobs count
    $pending_jobs = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'pending'")->fetchColumn();
    
    // Rejected jobs count
    $rejected_jobs = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'rejected'")->fetchColumn();
    
    // Closed jobs count
    $closed_jobs = $conn->query("SELECT COUNT(*) FROM jobs WHERE job_status = 'closed'")->fetchColumn();
    
    $pending_approvals = $pending_employers + $pending_jobs;
    
    // ============================================================================
    // PORTFOLIO ISSUES CALCULATION - USING THE SAME LOGIC FROM PORTFOLIO CODE
    // ============================================================================
    
    // Portfolio issues (users without complete profile) - Using the same query from portfolio code
    $portfolio_issues_stmt = $conn->query("
        SELECT COUNT(*) as count FROM users u 
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id 
        WHERE u.usr_role = 'graduate' 
        AND (g.grad_id IS NULL OR g.grad_school_id = '' OR g.grad_degree = '' OR g.grad_job_preference = '')
    ");
    $portfolio_issues = $portfolio_issues_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get detailed portfolio completeness data - Enhanced calculation from portfolio code
    $portfolio_completeness_data_stmt = $conn->query("
        SELECT 
            COUNT(*) as total_graduates,
            SUM(CASE WHEN g.grad_school_id IS NOT NULL AND g.grad_school_id != '' THEN 1 ELSE 0 END) as has_school_id,
            SUM(CASE WHEN g.grad_degree IS NOT NULL AND g.grad_degree != '' THEN 1 ELSE 0 END) as has_degree,
            SUM(CASE WHEN g.grad_job_preference IS NOT NULL AND g.grad_job_preference != '' THEN 1 ELSE 0 END) as has_job_preference,
            SUM(CASE WHEN g.grad_summary IS NOT NULL AND g.grad_summary != '' THEN 1 ELSE 0 END) as has_summary,
            SUM(CASE WHEN u.usr_phone IS NOT NULL AND u.usr_phone != '' THEN 1 ELSE 0 END) as has_phone,
            SUM(CASE WHEN u.usr_gender IS NOT NULL AND u.usr_gender != '' THEN 1 ELSE 0 END) as has_gender,
            SUM(CASE WHEN u.usr_birthdate IS NOT NULL THEN 1 ELSE 0 END) as has_birthdate,
            SUM(CASE WHEN u.usr_profile_photo IS NOT NULL AND u.usr_profile_photo != '' THEN 1 ELSE 0 END) as has_photo
        FROM users u
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id
        WHERE u.usr_role = 'graduate'
    ");
    $portfolio_completeness_data = $portfolio_completeness_data_stmt->fetch(PDO::FETCH_ASSOC);
    
    // FIXED: Check if there are any graduates before calculating percentages
    $portfolio_completeness = 0;
    $portfolio_breakdown = [
        'basic_info' => 0,
        'education' => 0,
        'career' => 0,
        'skills' => 0,
        'documents' => 0
    ];
    
    if ($portfolio_completeness_data['total_graduates'] > 0) {
        // Calculate portfolio completeness percentage using the same logic from portfolio code
        $completeness = 20; // Base score for having an account
        
        // Check for profile completeness factors - Same calculation as in portfolio code
        if ($portfolio_completeness_data['has_phone'] > 0) {
            $phone_percentage = ($portfolio_completeness_data['has_phone'] / $portfolio_completeness_data['total_graduates']) * 10;
            $completeness += $phone_percentage;
        }
        
        if ($portfolio_completeness_data['has_gender'] > 0) {
            $gender_percentage = ($portfolio_completeness_data['has_gender'] / $portfolio_completeness_data['total_graduates']) * 5;
            $completeness += $gender_percentage;
        }
        
        if ($portfolio_completeness_data['has_birthdate'] > 0) {
            $birthdate_percentage = ($portfolio_completeness_data['has_birthdate'] / $portfolio_completeness_data['total_graduates']) * 5;
            $completeness += $birthdate_percentage;
        }
        
        // Check for required graduate fields
        if ($portfolio_completeness_data['has_school_id'] > 0) {
            $school_id_percentage = ($portfolio_completeness_data['has_school_id'] / $portfolio_completeness_data['total_graduates']) * 15;
            $completeness += $school_id_percentage;
        }
        
        if ($portfolio_completeness_data['has_degree'] > 0) {
            $degree_percentage = ($portfolio_completeness_data['has_degree'] / $portfolio_completeness_data['total_graduates']) * 15;
            $completeness += $degree_percentage;
        }
        
        if ($portfolio_completeness_data['has_job_preference'] > 0) {
            $job_pref_percentage = ($portfolio_completeness_data['has_job_preference'] / $portfolio_completeness_data['total_graduates']) * 15;
            $completeness += $job_pref_percentage;
        }
        
        if ($portfolio_completeness_data['has_summary'] > 0) {
            $summary_percentage = ($portfolio_completeness_data['has_summary'] / $portfolio_completeness_data['total_graduates']) * 10;
            $completeness += $summary_percentage;
        }
        
        if ($portfolio_completeness_data['has_photo'] > 0) {
            $photo_percentage = ($portfolio_completeness_data['has_photo'] / $portfolio_completeness_data['total_graduates']) * 5;
            $completeness += $photo_percentage;
        }
        
        // Cap at 100%
        $portfolio_completeness = min(round($completeness), 100);
        
        // Calculate portfolio completeness breakdown
        $portfolio_breakdown = [
            'basic_info' => round(($portfolio_completeness_data['has_phone'] + $portfolio_completeness_data['has_gender'] + $portfolio_completeness_data['has_birthdate']) / ($portfolio_completeness_data['total_graduates'] * 3) * 100),
            'education' => round(($portfolio_completeness_data['has_school_id'] + $portfolio_completeness_data['has_degree']) / ($portfolio_completeness_data['total_graduates'] * 2) * 100),
            'career' => round($portfolio_completeness_data['has_job_preference'] / $portfolio_completeness_data['total_graduates'] * 100),
            'skills' => 0, // Will be calculated below
            'documents' => 0 // Will be calculated below
        ];
    }
    
    // Get skills data for graduates
    $skills_data_stmt = $conn->query("
        SELECT 
            COUNT(DISTINCT gs.grad_usr_id) as graduates_with_skills,
            AVG(skill_count) as avg_skills_per_graduate
        FROM (
            SELECT grad_usr_id, COUNT(*) as skill_count
            FROM graduate_skills
            GROUP BY grad_usr_id
        ) gs
    ");
    $skills_data = $skills_data_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update skills breakdown if there are graduates
    if ($portfolio_completeness_data['total_graduates'] > 0) {
        $portfolio_breakdown['skills'] = round($skills_data['graduates_with_skills'] / $portfolio_completeness_data['total_graduates'] * 100);
    }
    
    // Get portfolio documents data
    $documents_data_stmt = $conn->query("
        SELECT 
            COUNT(DISTINCT port_usr_id) as graduates_with_documents,
            SUM(CASE WHEN port_item_type = 'resume' THEN 1 ELSE 0 END) as resume_count,
            SUM(CASE WHEN port_item_type = 'certificate' THEN 1 ELSE 0 END) as certificate_count,
            SUM(CASE WHEN port_item_type = 'project' THEN 1 ELSE 0 END) as project_count
        FROM portfolio_items
    ");
    $documents_data = $documents_data_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update documents breakdown if there are graduates
    if ($portfolio_completeness_data['total_graduates'] > 0) {
        $portfolio_breakdown['documents'] = round($documents_data['graduates_with_documents'] / $portfolio_completeness_data['total_graduates'] * 100);
    }
    
    // Matching summary (applications count)
    $matching_count = $conn->query("SELECT COUNT(*) FROM applications")->fetchColumn();
    
    // Calculate matching rate (applications per job)
    $matching_rate = $jobs_count > 0 ? min(round(($matching_count / $jobs_count) * 100), 100) : 0;
    
    // Calculate employer approval percentage
    $employer_approval_rate = $total_employers > 0 ? round(($employers_count / $total_employers) * 100) : 0;
    
    // Employment rate calculation
    $employment_data_stmt = $conn->query("
        SELECT 
            COUNT(*) as total_graduates,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_graduates
        FROM graduates g
        LEFT JOIN users u ON g.grad_usr_id = u.usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
    ");
    $employment_data = $employment_data_stmt->fetch(PDO::FETCH_ASSOC);
    
    $employment_rate = $employment_data['total_graduates'] > 0 ? 
        round(($employment_data['hired_graduates'] / $employment_data['total_graduates']) * 100) : 0;
    
    // Get user distribution data for chart
    $user_distribution = [
        'Graduates' => $graduates_count,
        'Employers' => $employers_count,
        'Staff' => $staff_count
    ];
    
    // Get employment rate by course data
    $employment_rates_stmt = $conn->query("
        SELECT g.grad_degree, 
               COUNT(*) as total_graduates,
               SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_graduates
        FROM graduates g
        LEFT JOIN users u ON g.grad_usr_id = u.usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
        WHERE g.grad_degree IS NOT NULL AND g.grad_degree != ''
        GROUP BY g.grad_degree
        ORDER BY total_graduates DESC
        LIMIT 5
    ");
    $employment_rates = $employment_rates_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $employment_rate_data = [];
    foreach ($employment_rates as $rate) {
        if ($rate['total_graduates'] > 0) {
            $employment_percentage = round(($rate['hired_graduates'] / $rate['total_graduates']) * 100);
        } else {
            $employment_percentage = 0;
        }
        $employment_rate_data[$rate['grad_degree']] = $employment_percentage;
    }
    
    // Get job status distribution
    $job_status_data_stmt = $conn->query("
        SELECT job_status, COUNT(*) as count 
        FROM jobs 
        GROUP BY job_status
    ");
    $job_status_data_result = $job_status_data_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $job_status_data = [];
    foreach ($job_status_data_result as $row) {
        $job_status_data[$row['job_status']] = $row['count'];
    }
    
    // Ensure all statuses are represented
    $all_statuses = ['active', 'pending', 'rejected', 'closed'];
    foreach ($all_statuses as $status) {
        if (!isset($job_status_data[$status])) {
            $job_status_data[$status] = 0;
        }
    }
    
    // FIXED: Get application trends for last 30 days - CORRECTED QUERY WITH CONSISTENT DATA
    $application_trends_stmt = $conn->query("
        SELECT DATE(app_applied_at) as application_date, COUNT(*) as count
        FROM applications
        WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(app_applied_at)
        ORDER BY application_date
    ");
    $application_trends = $application_trends_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no application trends, create empty data for chart (NOT random data)
    if (empty($application_trends)) {
        // Create empty data for the last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $application_trends[] = [
                'application_date' => date('Y-m-d', strtotime("-$i days")),
                'count' => 0
            ];
        }
    }
    
    // FIXED: Get top skills from graduate profiles - CORRECTED QUERY WITH CONSISTENT DATA
    $top_skills_stmt = $conn->query("
        SELECT s.skill_name, COUNT(gs.skill_id) as count
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        GROUP BY s.skill_name
        ORDER BY count DESC
        LIMIT 10
    ");
    $top_skills = $top_skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format for chart - Use actual data only, no sample data
    $top_skills_chart = [];
    foreach ($top_skills as $skill) {
        $top_skills_chart[$skill['skill_name']] = $skill['count'];
    }
    
    // If no skills data, create empty array (NOT random data)
    if (empty($top_skills_chart)) {
        $top_skills_chart = [];
    }
    
    // FIXED: Get skills breakdown by category - CORRECTED QUERY
    $skills_by_category_stmt = $conn->query("
        SELECT s.skill_category, COUNT(gs.skill_id) as count
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        WHERE s.skill_category IS NOT NULL
        GROUP BY s.skill_category
        ORDER BY count DESC
        LIMIT 5
    ");
    $skills_by_category = $skills_by_category_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get top job domains
    $top_domains_stmt = $conn->query("
        SELECT job_domain, COUNT(*) as count
        FROM jobs
        WHERE job_status = 'active' AND job_domain IS NOT NULL AND job_domain != ''
        GROUP BY job_domain
        ORDER BY count DESC
        LIMIT 5
    ");
    $top_domains = $top_domains_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Set default values if there's an error
    $users_count = $graduates_count = $employers_count = $staff_count = $jobs_count = 
    $pending_employers = $pending_approvals = $portfolio_issues = $matching_count = 0;
    $total_employers = $portfolio_completeness = $matching_rate = $employer_approval_rate = $employment_rate = 0;
    $user_distribution = [];
    $employment_rate_data = [];
    $job_status_data = ['active' => 0, 'pending' => 0, 'rejected' => 0, 'closed' => 0];
    $application_trends = [];
    $top_skills = [];
    $top_skills_chart = [];
    $skills_by_category = [];
    $top_domains = [];
    $portfolio_breakdown = [
        'basic_info' => 0,
        'education' => 0,
        'career' => 0,
        'skills' => 0,
        'documents' => 0
    ];
    $documents_data = [
        'resume_count' => 0,
        'certificate_count' => 0,
        'project_count' => 0
    ];
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
    } elseif (strpos($type, 'course') !== false || strpos($message, 'course') !== false) {
        return 'admin_dashboard.php#course-management';
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
    
    if (strpos($type, 'course') !== false) {
        return 'fas fa-graduation-cap';
    } elseif (strpos($type, 'user') !== false) {
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Admin Dashboard</title>
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
        
        /* MODIFIED: Button Styles - Made Courses button consistent with Archived button from first code */
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
        
        /* Enhanced Dashboard Cards - Mobile Responsive Grid */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Make the last two cards span 2 columns each for better layout */
        .dashboard-cards .card:nth-last-child(-n+2) {
            grid-column: span 2;
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
        
        .card-percentage {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .positive-percentage {
            color: var(--green);
        }
        
        .negative-percentage {
            color: var(--red);
        }
        
        .card-footer {
            font-size: 0.9rem;
            color: #666;
            margin-top: 15px;
        }
        
        /* Enhanced Chart Containers */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
            margin-top: 15px;
        }
        
        .mini-chart-container {
            position: relative;
            height: 180px;
            width: 100%;
            margin-top: 10px;
        }
        
        /* Enhanced Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 15px;
        }
        
        .stat-item {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }
        
        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .stat-value {
            font-weight: bold;
            color: var(--primary-color);
            font-size: 1.3rem;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }
        
        /* Enhanced Skills and Lists */
        .skills-list, .courses-list, .trends-list {
            list-style: none;
            margin-top: 10px;
        }
        
        .skills-list li, .courses-list li, .trends-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .skills_list li:hover, .courses-list li:hover, .trends-list li:hover {
            background-color: #f8f9fa;
            padding-left: 10px;
            border-radius: 5px;
        }
        
        .skills-list li:last-child, .courses-list li:last-child, .trends-list li:last-child {
            border-bottom: none;
        }
        
        .course-percentage {
            font-weight: 600;
            color: var(--primary-color);
            background: rgba(110, 3, 3, 0.1);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        /* Enhanced Skills Breakdown */
        .skills-breakdown {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        
        .breakdown-item {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }
        
        .breakdown-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .breakdown-value {
            font-weight: bold;
            color: var(--primary-color);
            font-size: 1.2rem;
            margin-bottom: 5px;
        }
        
        .breakdown-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }
        
        .category-tag {
            display: inline-block;
            background: rgba(255, 167, 0, 0.2);
            color: var(--primary-color);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin: 2px;
            font-weight: 500;
            border: 1px solid rgba(255, 167, 0, 0.3);
        }
        
        /* Portfolio Breakdown Styles */
        .portfolio-breakdown {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 15px;
        }
        
        .portfolio-breakdown-item {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        
        .portfolio-breakdown-value {
            font-weight: bold;
            color: var(--primary-color);
            font-size: 1rem;
            margin-bottom: 3px;
        }
        
        .portfolio-breakdown-label {
            font-size: 0.7rem;
            color: #666;
            font-weight: 500;
        }
        
        .documents-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 10px;
        }
        
        .document-stat {
            background: rgba(110, 3, 3, 0.05);
            padding: 8px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid rgba(110, 3, 3, 0.1);
        }
        
        .document-stat-value {
            font-weight: bold;
            color: var(--primary-color);
            font-size: 0.9rem;
        }
        
        .document-stat-label {
            font-size: 0.65rem;
            color: #666;
        }
        
        /* Course Management Modal Styles - MODIFIED (REMOVED STATUS ELEMENTS) */
        .course-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            animation: fadeInOverlay 0.3s ease;
        }
        
        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .course-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 1200px;
            max-height: 90vh;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            z-index: 1001;
            display: none;
            animation: fadeInModal 0.3s ease;
            overflow: hidden;
        }
        
        @keyframes fadeInModal {
            from { opacity: 0; transform: translate(-50%, -48%); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }
        
        .course-modal.active {
            display: block;
        }
        
        .course-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .course-modal-title {
            font-size: 1.6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .course-modal-title i {
            color: var(--secondary-color);
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }
        
        .close-modal:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }
        
        .course-modal-content {
            padding: 30px;
            max-height: calc(90vh - 80px);
            overflow-y: auto;
        }
        
        .course-management-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        @media (max-width: 1200px) {
            .course-management-content {
                grid-template-columns: 1fr;
            }
        }
        
        .form-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .table-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .form-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-title i {
            color: var(--secondary-color);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
        }
        
        .form-textarea {
            height: 100px;
            resize: vertical;
        }
        
        .btn-small {
            padding: 8px 12px;
            font-size: 0.85rem;
        }
        
        .btn-success {
            background: linear-gradient(to right, var(--green), #2c9c1a);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(to right, #ff9800, #f57c00);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(to right, var(--red), #c62828);
            color: white;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        .alert i {
            font-size: 1.1rem;
        }
        
        .courses-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .courses-table th,
        .courses-table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .courses-table th {
            background-color: #e9ecef;
            font-weight: 600;
            color: var(--primary-color);
            position: sticky;
            top: 0;
        }
        
        .courses-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .search-filter {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .search-input {
            flex: 1;
        }
        
        .filter-select {
            width: 150px;
        }
        
        /* Confirmation Dialog Styles */
        .confirmation-dialog {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .confirmation-dialog.active {
            display: flex;
        }
        
        .confirmation-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            overflow: hidden;
            animation: fadeInModal 0.3s ease;
        }
        
        .confirmation-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .confirmation-body {
            padding: 25px;
            color: #333;
            font-size: 1rem;
            line-height: 1.5;
        }
        
        .confirmation-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 12px 15px;
            border-radius: 5px;
            margin: 15px 0;
            color: #856404;
            font-size: 0.9rem;
        }
        
        .confirmation-footer {
            padding: 15px 25px;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-top: 1px solid #dee2e6;
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
        
        /* Responsive Design - Mobile First Approach */
        @media (max-width: 1400px) {
            .dashboard-cards {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .dashboard-cards .card:nth-last-child(-n+2) {
                grid-column: span 1;
            }
            
            .dashboard-cards .card:nth-last-child(1) {
                grid-column: span 3;
            }
        }
        
        @media (max-width: 1200px) {
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-cards .card:nth-last-child(1) {
                grid-column: span 2;
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
            
            .dashboard-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
            
            .chart-container {
                height: 200px;
            }
            
            .mini-chart-container {
                height: 150px;
            }
            
            .top-nav {
                padding: 12px 20px;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-cards {
                grid-template-columns: 1fr;
            }
            
            .dashboard-cards .card:nth-last-child(1) {
                grid-column: span 1;
            }
            
            .main-content {
                padding: 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-value {
                font-size: 1.8rem;
            }
            
            .form-section {
                padding: 1rem;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -80px;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .course-modal {
                width: 95%;
                max-height: 95vh;
            }
            
            .course-modal-content {
                padding: 15px;
            }
            
            .course-management-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .search-filter {
                flex-direction: column;
            }
            
            .filter-select {
                width: 100%;
            }
            
            .courses-table {
                display: block;
                overflow-x: auto;
            }
            
            .confirmation-content {
                width: 95%;
            }
            
            .confirmation-footer {
                flex-direction: column;
            }
            
            .confirmation-footer .btn {
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
            
            .card-value {
                font-size: 1.6rem;
            }
            
            .card-title {
                font-size: 0.9rem;
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
            
            .chart-container {
                height: 180px;
            }
            
            .mini-chart-container {
                height: 130px;
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
            
            .course-modal-header {
                padding: 15px 20px;
            }
            
            .course-modal-title {
                font-size: 1.4rem;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-cards {
                gap: 15px;
            }
            
            .card {
                padding: 12px;
            }
            
            .card-header {
                margin-bottom: 12px;
            }
            
            .card-value {
                font-size: 1.5rem;
            }
            
            .card-percentage {
                font-size: 1rem;
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
            
            .chart-container {
                height: 160px;
            }
            
            .mini-chart-container {
                height: 120px;
            }
            
            .stats-grid {
                gap: 8px;
            }
            
            .stat-item {
                padding: 10px;
            }
            
            .stat-value {
                font-size: 1.1rem;
            }
            
            .course-modal-header {
                padding: 12px 15px;
            }
            
            .course-modal-title {
                font-size: 1.2rem;
            }
            
            .close-modal {
                width: 35px;
                height: 35px;
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 8px;
            }
            
            .top-nav {
                padding: 8px 12px;
            }
            
            .card {
                padding: 10px;
            }
            
            .card-value {
                font-size: 1.4rem;
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
            
            .course-modal-title {
                font-size: 1.1rem;
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
            
            .card-value {
                font-size: 1.3rem;
            }
            
            .chart-container {
                height: 140px;
            }
            
            .mini-chart-container {
                height: 100px;
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
        .btn, .form-control, .user-type-card {
            min-height: 44px;
        }
        
        /* Prevent zoom on input focus for mobile */
        @media (max-width: 768px) {
            input, select, textarea {
                font-size: 16px; /* Prevents zoom on iOS */
            }
        }
        
        /* No Data Message Styles */
        .no-data-message {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .no-data-message i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .no-data-message h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: #777;
        }
        
        .no-data-message p {
            font-size: 0.9rem;
            color: #999;
        }
        
        /* Success Message Animation */
        .success-flash {
            animation: successFlash 2s ease;
        }
        
        @keyframes successFlash {
            0% { background-color: #d4edda; }
            50% { background-color: #c3e6cb; }
            100% { background-color: #d4edda; }
        }
        
        /* Auto-hide message animation */
        .auto-hide {
            animation: autoHide 5s forwards;
        }
        
        @keyframes autoHide {
            0% { opacity: 1; }
            80% { opacity: 1; }
            100% { opacity: 0; display: none; }
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
                    <a href="admin_dashboard.php" class="active">
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
            <p>Here's what's happening with the system today. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Home Dashboard</h1>
            <div>
                <!-- MODIFIED: Made Courses button consistent with Archived button from first code -->
                <button id="openCourseModal" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-graduation-cap"></i>Courses
                </button>
            </div>
        </div>
        
        <!-- Confirmation Dialog for Delete -->
        <div class="confirmation-dialog" id="confirmationDialog">
            <div class="confirmation-content">
                <div class="confirmation-header">
                    <i class="fas fa-exclamation-triangle"></i> Confirm Deletion
                </div>
                <div class="confirmation-body">
                    <p id="confirmationMessage">Are you sure you want to delete this course?</p>
                    <div class="confirmation-warning" id="confirmationWarning">
                        <i class="fas fa-info-circle"></i> Courses with enrolled graduates cannot be deleted.
                    </div>
                    <p><strong id="courseDetails"></strong></p>
                </div>
                <div class="confirmation-footer">
                    <button class="btn" id="cancelDelete">Cancel</button>
                    <a href="#" class="btn btn-danger" id="confirmDelete">Delete</a>
                </div>
            </div>
        </div>
        
        <!-- Course Management Modal - MODIFIED (REMOVED STATUS ELEMENTS) -->
        <div class="course-modal-overlay" id="courseModalOverlay"></div>
        <div class="course-modal" id="courseModal">
            <div class="course-modal-header">
                <h2 class="course-modal-title">
                    <i class="fas fa-graduation-cap"></i> Course Management
                </h2>
                <button class="close-modal" id="closeCourseModal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="course-modal-content">
                <!-- Course Stats - SIMPLIFIED (ONLY SHOWING TOTAL COURSES) -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                    <div style="font-size: 2rem; font-weight: bold; color: var(--primary-color);"><?= $total_courses ?></div>
                    <div style="font-size: 1rem; color: #666;">Total Courses in System</div>
                </div>
                
                <!-- Display Course Messages INSIDE THE MODAL -->
                <?php if (!empty($course_success_message)): ?>
                    <div class="alert alert-success success-flash" id="successMessage">
                        <i class="fas fa-check-circle"></i>
                        <?= $course_success_message ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($course_error_message)): ?>
                    <div class="alert alert-error" id="errorMessage">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= $course_error_message ?>
                    </div>
                <?php endif; ?>
                
                <div class="course-management-content">
                    <!-- Add Course Form -->
                    <div class="form-card">
                        <h3 class="form-title">
                            <i class="fas fa-plus-circle"></i> Add New Course
                        </h3>
                        
                        <form method="POST" action="" id="addCourseForm">
                            <div class="form-group">
                                <label class="form-label" for="course_code">Course Code *</label>
                                <input type="text" id="course_code" name="course_code" class="form-input" 
                                       value="<?= isset($_POST['course_code']) ? htmlspecialchars($_POST['course_code']) : '' ?>"
                                       placeholder="e.g., BSCS, BSIT, BEEd" required maxlength="20">
                                <small style="font-size: 0.8rem; color: #666;">Unique identifier for the course (max 20 characters)</small>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="course_name">Course Name *</label>
                                <input type="text" id="course_name" name="course_name" class="form-input" 
                                       value="<?= isset($_POST['course_name']) ? htmlspecialchars($_POST['course_name']) : '' ?>"
                                       placeholder="e.g., Bachelor of Science in Computer Science" required maxlength="255">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="course_college">College/Department *</label>
                                <select id="course_college" name="course_college" class="form-select" required>
                                    <option value="">Select College/Department</option>
                                    <?php if (!empty($colleges)): ?>
                                        <?php foreach ($colleges as $college): ?>
                                            <option value="<?= htmlspecialchars($college) ?>" <?= (isset($_POST['course_college']) && $_POST['course_college'] == $college) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($college) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <option value="_new" <?= (isset($_POST['course_college']) && $_POST['course_college'] == '_new') ? 'selected' : '' ?>>Add New College/Department</option>
                                </select>
                                <input type="text" id="new_college" name="new_college" class="form-input" 
                                       value="<?= isset($_POST['new_college']) ? htmlspecialchars($_POST['new_college']) : '' ?>"
                                       placeholder="Enter new college/department name" style="display: <?= (isset($_POST['course_college']) && $_POST['course_college'] == '_new') ? 'block' : 'none'; ?>; margin-top: 8px;" maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="course_description">Course Description</label>
                                <textarea id="course_description" name="course_description" class="form-textarea" 
                                          placeholder="Brief description of the course..." maxlength="1000"><?= isset($_POST['course_description']) ? htmlspecialchars($_POST['course_description']) : '' ?></textarea>
                                <small style="font-size: 0.8rem; color: #666;">Optional (max 1000 characters)</small>
                            </div>
                            
                            <button type="submit" name="add_course" class="btn btn-primary">
                                <i class="fas fa-save"></i> Add Course
                            </button>
                        </form>
                    </div>

                    <!-- Courses List Table - MODIFIED (REMOVED STATUS COLUMN AND BUTTONS) -->
                    <div class="table-card">
                        <h3 class="form-title">
                            <i class="fas fa-list"></i> Available Courses
                        </h3>
                        
                        <!-- Search and Filter -->
                        <div class="search-filter">
                            <input type="text" id="searchCourses" class="form-input search-input" 
                                   placeholder="Search courses...">
                            <select id="filterCollege" class="form-select filter-select">
                                <option value="">All Colleges</option>
                                <?php if (!empty($colleges)): ?>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= htmlspecialchars($college) ?>"><?= htmlspecialchars($college) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <?php if (!empty($courses)): ?>
                            <div style="overflow-x: auto; max-height: 400px;">
                                <table class="courses-table" id="coursesTable">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Name</th>
                                            <th>College</th>
                                            <th>Graduates</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($courses as $course): ?>
                                        <tr data-college="<?= htmlspecialchars($course['course_college']) ?>">
                                            <td><strong><?= htmlspecialchars($course['course_code']) ?></strong></td>
                                            <td>
                                                <div><?= htmlspecialchars($course['course_name']) ?></div>
                                                <?php if (!empty($course['course_description'])): ?>
                                                    <div style="font-size: 0.8rem; color: #666; margin-top: 3px;">
                                                        <?= htmlspecialchars(substr($course['course_description'], 0, 50)) ?>...
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($course['course_college']) ?></td>
                                            <td style="text-align: center;">
                                                <span style="font-weight: bold; color: var(--primary-color);">
                                                    <?= $course['graduate_count'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-small btn-danger delete-course-btn"
                                                            data-course-id="<?= $course['course_id'] ?>"
                                                            data-course-code="<?= htmlspecialchars($course['course_code']) ?>"
                                                            data-course-name="<?= htmlspecialchars($course['course_name']) ?>"
                                                            data-course-college="<?= htmlspecialchars($course['course_college']) ?>"
                                                            data-graduate-count="<?= $course['graduate_count'] ?>"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="margin-top: 15px; text-align: center; color: #666; font-size: 0.9rem;">
                                Showing <?= count($courses) ?> course(s)
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: #999;">
                                <i class="fas fa-graduation-cap" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                <h3>No Courses Found</h3>
                                <p>Add your first course using the form on the left.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Dashboard Cards - Mobile Responsive Grid -->
        <div class="dashboard-cards">
            <!-- Users Card with Pie Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">USER DISTRIBUTION</h3>
                    <i class="fas fa-users card-icon"></i>
                </div>
                <div class="card-value"><?= $users_count ?></div>
                <div class="card-footer">Total Users</div>
                <div class="mini-chart-container">
                    <canvas id="userDistributionChart"></canvas>
                </div>
            </div>
            
            <!-- Employers Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">VERIFIED EMPLOYERS</h3>
                    <i class="fas fa-building card-icon"></i>
                </div>
                <div class="card-value"><?= $employers_count ?></div>
                <div class="card-percentage <?= $employer_approval_rate >= 50 ? 'positive-percentage' : 'negative-percentage' ?>">
                    <i class="fas fa-chart-line"></i> <?= $employer_approval_rate ?>% Approved
                </div>
                <div class="card-footer">Out of <?= $total_employers ?> total employers</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $pending_employers ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $employers_count ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
            </div>
            
            <!-- Active Jobs Card with Doughnut Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">JOB STATUS</h3>
                    <i class="fas fa-briefcase card-icon"></i>
                </div>
                <div class="card-value"><?= $jobs_count ?></div>
                <div class="card-footer">Active Jobs</div>
                <div class="mini-chart-container">
                    <canvas id="jobStatusChart"></canvas>
                </div>
            </div>
            
            <!-- Portfolio Issues Card - ENHANCED WITH PORTFOLIO DATA -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PORTFOLIO ISSUES</h3>
                    <i class="fas fa-file-alt card-icon"></i>
                </div>
                <div class="card-value"><?= $portfolio_issues ?></div>
                <div class="card-percentage <?= $portfolio_completeness >= 70 ? 'positive-percentage' : 'negative-percentage' ?>">
                    <i class="fas fa-chart-pie"></i> <?= $portfolio_completeness ?>% Complete
                </div>
                <div class="card-footer">Alumni Profile Completeness</div>
                
                <!-- Portfolio Breakdown -->
                <div class="portfolio-breakdown">
                    <div class="portfolio-breakdown-item">
                        <div class="portfolio-breakdown-value"><?= $portfolio_breakdown['basic_info'] ?>%</div>
                        <div class="portfolio-breakdown-label">Basic Info</div>
                    </div>
                    <div class="portfolio-breakdown-item">
                        <div class="portfolio-breakdown-value"><?= $portfolio_breakdown['education'] ?>%</div>
                        <div class="portfolio-breakdown-label">Education</div>
                    </div>
                    <div class="portfolio-breakdown-item">
                        <div class="portfolio-breakdown-value"><?= $portfolio_breakdown['career'] ?>%</div>
                        <div class="portfolio-breakdown-label">Career</div>
                    </div>
                    <div class="portfolio-breakdown-item">
                        <div class="portfolio-breakdown-value"><?= $portfolio_breakdown['skills'] ?>%</div>
                        <div class="portfolio-breakdown-label">Skills</div>
                    </div>
                    <div class="portfolio-breakdown-item">
                        <div class="portfolio-breakdown-value"><?= $portfolio_breakdown['documents'] ?>%</div>
                        <div class="portfolio-breakdown-label">Documents</div>
                    </div>
                </div>
                
                <!-- Documents Stats -->
                <div class="documents-stats">
                    <div class="document-stat">
                        <div class="document-stat-value"><?= $documents_data['resume_count'] ?></div>
                        <div class="document-stat-label">Resumes</div>
                    </div>
                    <div class="document-stat">
                        <div class="document-stat-value"><?= $documents_data['certificate_count'] ?></div>
                        <div class="document-stat-label">Certificates</div>
                    </div>
                    <div class="document-stat">
                        <div class="document-stat-value"><?= $documents_data['project_count'] ?></div>
                        <div class="document-stat-label">Projects</div>
                    </div>
                </div>
            </div>
            
            <!-- Matching Summary Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPLICATION MATCHING</h3>
                    <i class="fas fa-handshake card-icon"></i>
                </div>
                <div class="card-value"><?= $matching_count ?></div>
                <div class="card-percentage <?= $matching_rate >= 50 ? 'positive-percentage' : 'negative-percentage' ?>">
                    <i class="fas fa-percentage"></i> <?= $matching_rate ?>% Match Rate
                </div>
                <div class="card-footer">Applications per Job</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $jobs_count ?></div>
                        <div class="stat-label">Active Jobs</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $graduates_count ?></div>
                        <div class="stat-label">Graduates</div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Approvals Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PENDING APPROVALS</h3>
                    <i class="fas fa-clock card-icon"></i>
                </div>
                <div class="card-value"><?= $pending_approvals ?></div>
                <div class="card-footer">
                    <div><?= $pending_employers ?> Employers, <?= $pending_jobs ?> Job posts</div>
                </div>
                <div class="mini-chart-container">
                    <canvas id="pendingApprovalsChart"></canvas>
                </div>
            </div>
            
            <!-- Employment Rate Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">EMPLOYMENT RATE</h3>
                    <i class="fas fa-graduation-cap card-icon"></i>
                </div>
                <div class="card-value"><?= $employment_rate ?>%</div>
                <div class="card-percentage <?= $employment_rate >= 50 ? 'positive-percentage' : 'negative-percentage' ?>">
                    <i class="fas fa-chart-line"></i> Overall Employment
                </div>
                <div class="card-footer">Based on <?= $employment_data['total_graduates'] ?> alumni</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $employment_data['hired_graduates'] ?></div>
                        <div class="stat-label">Hired</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $employment_data['total_graduates'] - $employment_data['hired_graduates'] ?></div>
                        <div class="stat-label">Seeking</div>
                    </div>
                </div>
            </div>
            
            <!-- Employment Rate by Course Card with Bar Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">EMPLOYMENT RATE BY COURSE</h3>
                    <i class="fas fa-graduation-cap card-icon"></i>
                </div>
                <?php if (!empty($employment_rate_data)): ?>
                    <div class="chart-container">
                        <canvas id="employmentRateChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="no-data-message">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No Employment Data</h3>
                        <p>Employment rate data will appear here when graduates are hired.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Top Required Skills Card with Horizontal Bar Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">TOP ALUMNI SKILLS</h3>
                    <i class="fas fa-tools card-icon"></i>
                </div>
                <?php if (!empty($top_skills_chart)): ?>
                    <div class="chart-container">
                        <canvas id="topSkillsChart"></canvas>
                    </div>
                    <div class="skills-breakdown">
                        <div class="breakdown-item">
                            <div class="breakdown-value"><?= count($top_skills_chart) ?></div>
                            <div class="breakdown-label">Top Skills</div>
                        </div>
                        <div class="breakdown-item">
                            <div class="breakdown-value"><?= array_sum($top_skills_chart) ?></div>
                            <div class="breakdown-label">Total Mentions</div>
                        </div>
                    </div>
                    <?php if (!empty($skills_by_category)): ?>
                    <div class="card-footer">
                        <strong>Top Categories:</strong>
                        <?php 
                        $top_categories = array_slice($skills_by_category, 0, 3);
                        foreach ($top_categories as $category): 
                        ?>
                            <span class="category-tag"><?= htmlspecialchars($category['skill_category']) ?> (<?= $category['count'] ?>)</span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-data-message">
                        <i class="fas fa-tools"></i>
                        <h3>No Skills Data</h3>
                        <p>Skills data will appear here when graduates add skills to their profiles.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Application Trends Card with Line Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPLICATIONS (LAST 30 DAYS)</h3>
                    <i class="fas fa-chart-line card-icon"></i>
                </div>
                <?php if (!empty($application_trends) && array_sum(array_column($application_trends, 'count')) > 0): ?>
                    <div class="chart-container">
                        <canvas id="applicationTrendsChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="no-data-message">
                        <i class="fas fa-file-alt"></i>
                        <h3>No Applications Yet</h3>
                        <p>Application trends will appear here when graduates start applying for jobs.</p>
                    </div>
                <?php endif; ?>
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
            // Handle notification click and mark as read
            $('.notification-link').on('click', function(e) {
                const notifId = $(this).data('notif-id');
                const notificationItem = $(this);
                
                // Only mark as read if it's unread
                if (notificationItem.hasClass('unread')) {
                    // Send AJAX request to mark as read
                    $.ajax({
                        url: 'admin_dashboard.php',
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
            
            // Auto-open modal if there are success or error messages
            const successMessage = document.getElementById('successMessage');
            const errorMessage = document.getElementById('errorMessage');
            
            if (successMessage || errorMessage) {
                setTimeout(() => {
                    courseModalOverlay.style.display = 'block';
                    courseModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }, 300);
            }
        });
        
        // Course Management Modal Functionality
        const openCourseModalBtn = document.getElementById('openCourseModal');
        const closeCourseModalBtn = document.getElementById('closeCourseModal');
        const courseModalOverlay = document.getElementById('courseModalOverlay');
        const courseModal = document.getElementById('courseModal');
        
        // Open modal when "Courses" button is clicked
        if (openCourseModalBtn) {
            openCourseModalBtn.addEventListener('click', function() {
                courseModalOverlay.style.display = 'block';
                courseModal.classList.add('active');
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            });
        }
        
        // Close modal when close button is clicked
        if (closeCourseModalBtn) {
            closeCourseModalBtn.addEventListener('click', function() {
                courseModalOverlay.style.display = 'none';
                courseModal.classList.remove('active');
                document.body.style.overflow = 'auto'; // Restore scrolling
            });
        }
        
        // Close modal when overlay is clicked
        if (courseModalOverlay) {
            courseModalOverlay.addEventListener('click', function() {
                courseModalOverlay.style.display = 'none';
                courseModal.classList.remove('active');
                document.body.style.overflow = 'auto'; // Restore scrolling
            });
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && courseModal.classList.contains('active')) {
                courseModalOverlay.style.display = 'none';
                courseModal.classList.remove('active');
                document.body.style.overflow = 'auto'; // Restore scrolling
            }
        });
        
        // Prevent modal from closing when clicking inside modal content
        courseModal.addEventListener('click', function(event) {
            event.stopPropagation();
        });

        // Course Management Functionality
        // New college/department input toggle - FIXED
        const courseCollegeSelect = document.getElementById('course_college');
        const newCollegeInput = document.getElementById('new_college');
        
        if (courseCollegeSelect && newCollegeInput) {
            courseCollegeSelect.addEventListener('change', function() {
                if (this.value === '_new') {
                    newCollegeInput.style.display = 'block';
                    newCollegeInput.required = true;
                    // Clear the select name and set the input name
                    this.name = '';
                    newCollegeInput.name = 'course_college';
                } else {
                    newCollegeInput.style.display = 'none';
                    newCollegeInput.required = false;
                    // Restore the select name and clear the input name
                    this.name = 'course_college';
                    newCollegeInput.name = 'new_college';
                    // Clear the new college input value
                    newCollegeInput.value = '';
                }
            });
        }

        // Course search and filter functionality
        const searchInput = document.getElementById('searchCourses');
        const filterCollege = document.getElementById('filterCollege');
        const coursesTable = document.getElementById('coursesTable');
        
        function filterCourses() {
            if (coursesTable) {
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const selectedCollege = filterCollege ? filterCollege.value : '';
                
                const rows = coursesTable.querySelectorAll('tbody tr');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    const code = row.cells[0].textContent.toLowerCase();
                    const name = row.cells[1].textContent.toLowerCase();
                    const college = row.getAttribute('data-college');
                    
                    const matchesSearch = code.includes(searchTerm) || name.includes(searchTerm);
                    const matchesCollege = !selectedCollege || college === selectedCollege;
                    
                    if (matchesSearch && matchesCollege) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        }
        
        if (searchInput) searchInput.addEventListener('input', filterCourses);
        if (filterCollege) filterCollege.addEventListener('change', filterCourses);

        // Form validation
        const addCourseForm = document.getElementById('addCourseForm');
        if (addCourseForm) {
            addCourseForm.addEventListener('submit', function(e) {
                const courseCode = document.getElementById('course_code')?.value.trim() || '';
                const courseName = document.getElementById('course_name')?.value.trim() || '';
                const courseCollegeSelect = document.getElementById('course_college');
                const courseCollege = courseCollegeSelect ? courseCollegeSelect.value : '';
                const newCollege = document.getElementById('new_college')?.value.trim() || '';
                
                let isValid = true;
                
                // Clear previous error messages
                document.querySelectorAll('.error-message').forEach(el => el.remove());
                
                // Validate course code
                if (!courseCode) {
                    showError('course_code', 'Course code is required');
                    isValid = false;
                } else if (courseCode.length > 20) {
                    showError('course_code', 'Course code must not exceed 20 characters');
                    isValid = false;
                }
                
                // Validate course name
                if (!courseName) {
                    showError('course_name', 'Course name is required');
                    isValid = false;
                } else if (courseName.length > 255) {
                    showError('course_name', 'Course name must not exceed 255 characters');
                    isValid = false;
                }
                
                // Validate college
                if (courseCollege === '_new') {
                    if (!newCollege) {
                        showError('new_college', 'New college/department name is required');
                        isValid = false;
                    } else if (newCollege.length > 100) {
                        showError('new_college', 'College/department must not exceed 100 characters');
                        isValid = false;
                    }
                } else if (!courseCollege) {
                    showError('course_college', 'College/department is required');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                }
            });
        }
        
        function showError(fieldId, message) {
            const field = document.getElementById(fieldId);
            if (field) {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                errorDiv.style.color = 'var(--red)';
                errorDiv.style.fontSize = '0.85rem';
                errorDiv.style.marginTop = '5px';
                errorDiv.textContent = message;
                
                field.parentNode.appendChild(errorDiv);
                field.style.borderColor = 'var(--red)';
            }
        }
        
        // Confirmation Dialog for Delete
        const confirmationDialog = document.getElementById('confirmationDialog');
        const confirmationMessage = document.getElementById('confirmationMessage');
        const confirmationWarning = document.getElementById('confirmationWarning');
        const courseDetails = document.getElementById('courseDetails');
        const cancelDeleteBtn = document.getElementById('cancelDelete');
        const confirmDeleteBtn = document.getElementById('confirmDelete');
        
        // Handle delete course button clicks
        document.querySelectorAll('.delete-course-btn').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                const courseId = this.getAttribute('data-course-id');
                const courseCode = this.getAttribute('data-course-code');
                const courseName = this.getAttribute('data-course-name');
                const courseCollege = this.getAttribute('data-course-college');
                const graduateCount = parseInt(this.getAttribute('data-graduate-count'));
                
                // Update confirmation dialog content
                courseDetails.textContent = `${courseCode} - ${courseName} (${courseCollege})`;
                
                // Show warning if there are graduates
                if (graduateCount > 0) {
                    confirmationWarning.innerHTML = `<i class="fas fa-info-circle"></i> This course has ${graduateCount} enrolled graduate(s) and cannot be deleted.`;
                    confirmationWarning.style.display = 'block';
                    confirmDeleteBtn.style.display = 'none';
                    confirmationMessage.textContent = "Cannot delete course with enrolled graduates";
                } else {
                    confirmationWarning.innerHTML = `<i class="fas fa-info-circle"></i> Are you sure you want to delete this course? This action cannot be undone.`;
                    confirmationWarning.style.display = 'block';
                    confirmDeleteBtn.style.display = 'inline-flex';
                    confirmationMessage.textContent = "Are you sure you want to delete this course?";
                }
                
                // Set delete URL
                confirmDeleteBtn.href = `admin_dashboard.php?delete_course=${courseId}`;
                
                // Show confirmation dialog
                confirmationDialog.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        });
        
        // Cancel delete
        if (cancelDeleteBtn) {
            cancelDeleteBtn.addEventListener('click', function(e) {
                e.preventDefault();
                confirmationDialog.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        }
        
        // Close confirmation dialog when clicking outside
        confirmationDialog.addEventListener('click', function(e) {
            if (e.target === confirmationDialog) {
                confirmationDialog.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Close confirmation dialog with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && confirmationDialog.classList.contains('active')) {
                confirmationDialog.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Auto-hide success message after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.opacity = '0';
                    setTimeout(() => {
                        successMessage.style.display = 'none';
                    }, 300);
                }, 5000);
            }
            
            // Auto-hide error message after 10 seconds
            const errorMessage = document.getElementById('errorMessage');
            if (errorMessage) {
                setTimeout(() => {
                    errorMessage.style.opacity = '0';
                    setTimeout(() => {
                        errorMessage.style.display = 'none';
                    }, 300);
                }, 10000);
            }
        });
        
        // Chart.js Configuration with Percentage Display
        document.addEventListener('DOMContentLoaded', function() {
            // User Distribution Chart (Pie)
            const userDistributionCtx = document.getElementById('userDistributionChart')?.getContext('2d');
            if (userDistributionCtx) {
                new Chart(userDistributionCtx, {
                    type: 'pie',
                    data: {
                        labels: ['Alumni', 'Employers', 'Staff'],
                        datasets: [{
                            data: [<?= $graduates_count ?>, <?= $employers_count ?>, <?= $staff_count ?>],
                            backgroundColor: [
                                'rgba(110, 3, 3, 0.8)',
                                'rgba(255, 167, 0, 0.8)',
                                'rgba(106, 13, 173, 0.8)'
                            ],
                            borderColor: [
                                'rgba(110, 3, 3, 1)',
                                'rgba(255, 167, 0, 1)',
                                'rgba(106, 13, 173, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: {
                                        size: 10
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = <?= $users_count ?>;
                                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Job Status Chart (Doughnut)
            const jobStatusCtx = document.getElementById('jobStatusChart')?.getContext('2d');
            const totalJobs = <?= $job_status_data['active'] + $job_status_data['pending'] + $job_status_data['rejected'] + $job_status_data['closed'] ?>;
            if (jobStatusCtx) {
                new Chart(jobStatusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Active', 'Pending', 'Rejected', 'Closed'],
                        datasets: [{
                            data: [
                                <?= $job_status_data['active'] ?? 0 ?>,
                                <?= $job_status_data['pending'] ?? 0 ?>,
                                <?= $job_status_data['rejected'] ?? 0 ?>,
                                <?= $job_status_data['closed'] ?? 0 ?>
                            ],
                            backgroundColor: [
                                'rgba(31, 122, 17, 0.8)',
                                'rgba(255, 167, 0, 0.8)',
                                'rgba(211, 47, 47, 0.8)',
                                'rgba(100, 100, 100, 0.8)'
                            ],
                            borderColor: [
                                'rgba(31, 122, 17, 1)',
                                'rgba(255, 167, 0, 1)',
                                'rgba(211, 47, 47, 1)',
                                'rgba(100, 100, 100, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: {
                                        size: 9
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const percentage = totalJobs > 0 ? Math.round((value / totalJobs) * 100) : 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Pending Approvals Chart (Doughnut)
            const pendingApprovalsCtx = document.getElementById('pendingApprovalsChart')?.getContext('2d');
            const totalPending = <?= $pending_approvals ?>;
            if (pendingApprovalsCtx) {
                new Chart(pendingApprovalsCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Employers', 'Job Posts'],
                        datasets: [{
                            data: [<?= $pending_employers ?>, <?= $pending_jobs ?>],
                            backgroundColor: [
                                'rgba(255, 167, 0, 0.8)',
                                'rgba(110, 3, 3, 0.8)'
                            ],
                            borderColor: [
                                'rgba(255, 167, 0, 1)',
                                'rgba(110, 3, 3, 1)'
                            ],
                            borderWidth: 1
                        }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 9
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const percentage = totalPending > 0 ? Math.round((value / totalPending) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Employment Rate Chart (Bar) - ONLY IF DATA EXISTS
        <?php if (!empty($employment_rate_data)): ?>
        const employmentRateCtx = document.getElementById('employmentRateChart')?.getContext('2d');
        if (employmentRateCtx) {
            new Chart(employmentRateCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_keys($employment_rate_data)) ?>,
                    datasets: [{
                        label: 'Employment Rate (%)',
                        data: <?= json_encode(array_values($employment_rate_data)) ?>,
                        backgroundColor: 'rgba(110, 3, 3, 0.8)',
                        borderColor: 'rgba(110, 3, 3, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Employment Rate: ${context.raw}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Top Skills Chart (Horizontal Bar) - ONLY IF DATA EXISTS
        <?php if (!empty($top_skills_chart)): ?>
        const topSkillsCtx = document.getElementById('topSkillsChart')?.getContext('2d');
        if (topSkillsCtx) {
            // Prepare data for the chart
            const skillNames = <?= json_encode(array_keys($top_skills_chart)) ?>;
            const skillCounts = <?= json_encode(array_values($top_skills_chart)) ?>;
            
            // Generate colors for the chart
            const generateColors = (count) => {
                const colors = [];
                const baseColor = [255, 167, 0]; // RGB for --frame-orange
                
                for (let i = 0; i < count; i++) {
                    const opacity = 0.7 + (i * 0.3 / count); // Gradual increase in opacity
                    colors.push(`rgba(${baseColor[0]}, ${baseColor[1]}, ${baseColor[2]}, ${opacity})`);
                }
                
                return colors.reverse(); // Reverse so highest values have highest opacity
            };
            
            new Chart(topSkillsCtx, {
                type: 'bar',
                data: {
                    labels: skillNames,
                    datasets: [{
                        label: 'Number of Alumni with This Skill',
                        data: skillCounts,
                        backgroundColor: generateColors(skillNames.length),
                        borderColor: 'rgba(255, 167, 0, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const totalMentions = <?= array_sum($top_skills_chart) ?>;
                                    const percentage = totalMentions > 0 ? Math.round((context.raw / totalMentions) * 100) : 0;
                                    return `${context.raw} graduate(s) have this skill (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Number of Graduates'
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Application Trends Chart (Line) - ONLY IF DATA EXISTS
        <?php if (!empty($application_trends) && array_sum(array_column($application_trends, 'count')) > 0): ?>
        const applicationTrendsCtx = document.getElementById('applicationTrendsChart')?.getContext('2d');
        if (applicationTrendsCtx) {
            // Prepare data for the line chart
            const trendDates = <?= json_encode(array_column($application_trends, 'application_date')) ?>;
            const trendCounts = <?= json_encode(array_column($application_trends, 'count')) ?>;
            
            new Chart(applicationTrendsCtx, {
                type: 'line',
                data: {
                    labels: trendDates,
                    datasets: [{
                        label: 'Applications per Day',
                        data: trendCounts,
                        fill: false,
                        backgroundColor: 'rgba(110, 3, 3, 0.8)',
                        borderColor: 'rgba(110, 3, 3, 1)',
                        tension: 0.1,
                        pointBackgroundColor: 'rgba(255, 167, 0, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(110, 3, 3, 1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Applications: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        <?php endif; ?>
    }); 
    </script>
</body>
</html>