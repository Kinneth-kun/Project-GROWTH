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
    
    // Get staff data with proper joins
    $staff_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT u.*, s.staff_department, s.staff_position, s.staff_employee_id
        FROM users u 
        JOIN staff s ON u.usr_id = s.staff_usr_id
        WHERE u.usr_id = :staff_id AND u.usr_role = 'staff'
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
    
    // Get statistics for dashboard
    
    // MODIFIED: Count graduates assisted based on shared resources and feedback
    $grads_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT grad_usr_id) as graduates_assisted 
        FROM shared_resources 
        WHERE staff_usr_id = :staff_id
        UNION
        SELECT COUNT(DISTINCT activity_usr_id) as graduates_assisted 
        FROM user_activities 
        WHERE activity_type = 'staff_feedback'
    ");
    $grads_stmt->bindParam(':staff_id', $staff_id);
    $grads_stmt->execute();
    $graduates_assisted = $grads_stmt->fetchColumn() ?: 0;
    
    // Pending job posts
    $jobs_stmt = $conn->prepare("
        SELECT COUNT(*) as pending_jobs 
        FROM jobs 
        WHERE job_status = 'pending'
    ");
    $jobs_stmt->execute();
    $pending_jobs = $jobs_stmt->fetchColumn() ?: 0;
    
    // Unmatched graduates (graduates with no applications)
    $unmatched_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT u.usr_id) as unmatched_graduates
        FROM users u
        JOIN graduates g ON u.usr_id = g.grad_usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
        WHERE a.app_id IS NULL 
        AND u.usr_role = 'graduate'
        AND u.usr_account_status = 'active'
    ");
    $unmatched_stmt->execute();
    $unmatched_graduates = $unmatched_stmt->fetchColumn() ?: 0;
    
    // Portfolios needing attention (graduates with no portfolio items)
    $portfolios_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT u.usr_id) as incomplete_portfolios
        FROM users u
        JOIN graduates g ON u.usr_id = g.grad_usr_id
        LEFT JOIN portfolio_items p ON u.usr_id = p.port_usr_id
        WHERE p.port_id IS NULL 
        AND u.usr_role = 'graduate'
        AND u.usr_account_status = 'active'
    ");
    $portfolios_stmt->execute();
    $incomplete_portfolios = $portfolios_stmt->fetchColumn() ?: 0;
    
    // Recently approved employers (last 7 days)
    $employers_stmt = $conn->prepare("
        SELECT COUNT(*) as recent_employers 
        FROM users u
        JOIN employers e ON u.usr_id = e.emp_usr_id
        WHERE u.usr_is_approved = TRUE 
        AND u.usr_account_status = 'active'
        AND u.usr_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $employers_stmt->execute();
    $recent_employers = $employers_stmt->fetchColumn() ?: 0;
    
    // Graduate activity (users logged in last 7 days)
    $activity_stmt = $conn->prepare("
        SELECT COUNT(*) as recent_activity 
        FROM users 
        WHERE usr_last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND usr_role = 'graduate'
        AND usr_account_status = 'active'
    ");
    $activity_stmt->execute();
    $recent_activity = $activity_stmt->fetchColumn() ?: 0;
    
    // Total graduates count
    $total_grads_stmt = $conn->prepare("
        SELECT COUNT(*) as total_graduates
        FROM users 
        WHERE usr_role = 'graduate'
        AND usr_account_status = 'active'
    ");
    $total_grads_stmt->execute();
    $total_graduates = $total_grads_stmt->fetchColumn() ?: 1; // Avoid division by zero
    
    // Total employers count
    $total_emp_stmt = $conn->prepare("
        SELECT COUNT(*) as total_employers
        FROM users 
        WHERE usr_role = 'employer'
        AND usr_account_status = 'active'
    ");
    $total_emp_stmt->execute();
    $total_employers = $total_emp_stmt->fetchColumn() ?: 1;
    
    // Total jobs count
    $total_jobs_stmt = $conn->prepare("
        SELECT COUNT(*) as total_jobs
        FROM jobs 
        WHERE job_status = 'active'
    ");
    $total_jobs_stmt->execute();
    $total_jobs = $total_jobs_stmt->fetchColumn() ?: 1;
    
    // MODIFIED: Get shared resources statistics
    $shared_resources_stmt = $conn->prepare("
        SELECT COUNT(*) as total_resources 
        FROM shared_resources 
        WHERE staff_usr_id = :staff_id
    ");
    $shared_resources_stmt->bindParam(':staff_id', $staff_id);
    $shared_resources_stmt->execute();
    $total_resources = $shared_resources_stmt->fetchColumn() ?: 0;
    
    $read_resources_stmt = $conn->prepare("
        SELECT COUNT(*) as read_resources 
        FROM shared_resources 
        WHERE staff_usr_id = :staff_id AND is_read = TRUE
    ");
    $read_resources_stmt->bindParam(':staff_id', $staff_id);
    $read_resources_stmt->execute();
    $read_resources = $read_resources_stmt->fetchColumn() ?: 0;
    
    $unread_resources = $total_resources - $read_resources;
    
    // MODIFIED: Get feedback statistics
    $feedback_stmt = $conn->prepare("
        SELECT COUNT(*) as total_feedback 
        FROM user_activities 
        WHERE activity_type = 'staff_feedback'
    ");
    $feedback_stmt->execute();
    $total_feedback = $feedback_stmt->fetchColumn() ?: 0;
    
    // Get data for charts
    // Monthly graduate registrations
    $monthly_grads_stmt = $conn->prepare("
        SELECT 
            YEAR(usr_created_at) as year, 
            MONTH(usr_created_at) as month, 
            COUNT(*) as count
        FROM users 
        WHERE usr_role = 'graduate'
        AND usr_created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(usr_created_at), MONTH(usr_created_at)
        ORDER BY year, month
    ");
    $monthly_grads_stmt->execute();
    $monthly_grads = $monthly_grads_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Application status distribution
    $app_status_stmt = $conn->prepare("
        SELECT 
            app_status,
            COUNT(*) as count
        FROM applications
        GROUP BY app_status
    ");
    $app_status_stmt->execute();
    $app_status_data = $app_status_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Job posts by status
    $job_status_stmt = $conn->prepare("
        SELECT 
            job_status,
            COUNT(*) as count
        FROM jobs
        GROUP BY job_status
    ");
    $job_status_stmt->execute();
    $job_status_data = $job_status_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate percentages for progress bars
    $grads_assisted_percent = min(round(($graduates_assisted / $total_graduates) * 100), 100);
    $pending_jobs_percent = min(round(($pending_jobs / $total_jobs) * 100), 100);
    $unmatched_grads_percent = min(round(($unmatched_graduates / $total_graduates) * 100), 100);
    $incomplete_portfolios_percent = min(round(($incomplete_portfolios / $total_graduates) * 100), 100);
    $recent_employers_percent = min(round(($recent_employers / $total_employers) * 100), 100);
    $recent_activity_percent = min(round(($recent_activity / $total_graduates) * 100), 100);
    
    // MODIFIED: Calculate resource statistics percentages
    $resources_shared_percent = $total_graduates > 0 ? min(round(($total_resources / $total_graduates) * 100), 100) : 0;
    $resources_read_percent = $total_resources > 0 ? min(round(($read_resources / $total_resources) * 100), 100) : 0;
    
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
            $notifications = generateDefaultStaffNotifications($conn, $staff_id, $pending_jobs, $unmatched_graduates, $incomplete_portfolios);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Staff notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultStaffNotifications($conn, $staff_id, $pending_jobs, $unmatched_graduates, $incomplete_portfolios);
        $unread_notif_count = count($notifications);
    }
    
    // Get recent activities for the activity log - FIXED QUERY
    try {
        $recent_activities_stmt = $conn->prepare("
            SELECT 
                ua.activity_id,
                ua.activity_usr_id,
                ua.activity_type,
                ua.activity_details,
                ua.activity_date,
                u.usr_name,
                u.usr_role
            FROM user_activities ua
            LEFT JOIN users u ON ua.activity_usr_id = u.usr_id
            WHERE ua.activity_type IN ('job_applied', 'profile_updated', 'job_posted', 'account_approved', 'user_registered')
            OR ua.activity_details LIKE '%applied%'
            OR ua.activity_details LIKE '%profile%'
            OR ua.activity_details LIKE '%job%'
            OR ua.activity_details LIKE '%registered%'
            ORDER BY ua.activity_date DESC 
            LIMIT 10
        ");
        $recent_activities_stmt->execute();
        $recent_activities = $recent_activities_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no activities found, generate sample data from other tables
        if (empty($recent_activities)) {
            $recent_activities = generateRecentActivitiesFromOtherTables($conn);
        }
    } catch (PDOException $e) {
        error_log("Recent activities query error: " . $e->getMessage());
        // Generate sample activities from other tables as fallback
        $recent_activities = generateRecentActivitiesFromOtherTables($conn);
    }
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

/**
 * Generate default notifications for staff based on system status
 */
function generateDefaultStaffNotifications($conn, $staff_id, $pending_jobs, $unmatched_graduates, $incomplete_portfolios) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Staff Dashboard! Monitor system activities and assist graduates.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Pending jobs notification
    if ($pending_jobs > 0) {
        $default_notifications[] = [
            'notif_message' => "You have {$pending_jobs} job posts awaiting validation.",
            'notif_type' => 'pending_jobs',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
        ];
    }
    
    // Unmatched graduates notification
    if ($unmatched_graduates > 5) {
        $default_notifications[] = [
            'notif_message' => "{$unmatched_graduates} graduates have no job applications and may need assistance.",
            'notif_type' => 'unmatched_graduates',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
        ];
    }
    
    // Incomplete portfolios notification
    if ($incomplete_portfolios > 5) {
        $default_notifications[] = [
            'notif_message' => "{$incomplete_portfolios} graduates have incomplete portfolios.",
            'notif_type' => 'incomplete_portfolios',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))
        ];
    }
    
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
 * Generate recent activities from other tables if user_activities is empty
 */
function generateRecentActivitiesFromOtherTables($conn) {
    $activities = [];
    
    try {
        // Get recent applications
        $apps_stmt = $conn->prepare("
            SELECT 
                a.app_id,
                a.app_grad_usr_id,
                a.app_created_at as activity_date,
                j.job_title,
                u.usr_name
            FROM applications a
            JOIN jobs j ON a.app_job_id = j.job_id
            JOIN users u ON a.app_grad_usr_id = u.usr_id
            ORDER BY a.app_created_at DESC 
            LIMIT 5
        ");
        $apps_stmt->execute();
        $recent_apps = $apps_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recent_apps as $app) {
            $activities[] = [
                'activity_id' => $app['app_id'],
                'activity_usr_id' => $app['app_grad_usr_id'],
                'activity_type' => 'job_applied',
                'activity_details' => "{$app['usr_name']} applied for job: {$app['job_title']}",
                'activity_date' => $app['activity_date'],
                'usr_name' => $app['usr_name'],
                'usr_role' => 'graduate'
            ];
        }
        
        // Get recent job posts
        $jobs_stmt = $conn->prepare("
            SELECT 
                j.job_id,
                j.job_emp_usr_id,
                j.job_created_at as activity_date,
                j.job_title,
                u.usr_name,
                e.emp_company_name
            FROM jobs j
            JOIN users u ON j.job_emp_usr_id = u.usr_id
            JOIN employers e ON u.usr_id = e.emp_usr_id
            WHERE j.job_status = 'active'
            ORDER BY j.job_created_at DESC 
            LIMIT 3
        ");
        $jobs_stmt->execute();
        $recent_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recent_jobs as $job) {
            $activities[] = [
                'activity_id' => $job['job_id'],
                'activity_usr_id' => $job['job_emp_usr_id'],
                'activity_type' => 'job_posted',
                'activity_details' => "{$job['emp_company_name']} posted a new job: {$job['job_title']}",
                'activity_date' => $job['activity_date'],
                'usr_name' => $job['emp_company_name'],
                'usr_role' => 'employer'
            ];
        }
        
        // Get recent user registrations
        $users_stmt = $conn->prepare("
            SELECT 
                usr_id as activity_usr_id,
                usr_name,
                usr_role,
                usr_created_at as activity_date
            FROM users
            WHERE usr_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY usr_created_at DESC 
            LIMIT 2
        ");
        $users_stmt->execute();
        $recent_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recent_users as $user) {
            $activities[] = [
                'activity_id' => $user['activity_usr_id'],
                'activity_usr_id' => $user['activity_usr_id'],
                'activity_type' => 'user_registered',
                'activity_details' => "{$user['usr_name']} registered as {$user['usr_role']}",
                'activity_date' => $user['activity_date'],
                'usr_name' => $user['usr_name'],
                'usr_role' => $user['usr_role']
            ];
        }
        
        // Sort activities by date
        usort($activities, function($a, $b) {
            return strtotime($b['activity_date']) - strtotime($a['activity_date']);
        });
        
        // Return only the 10 most recent
        return array_slice($activities, 0, 10);
        
    } catch (PDOException $e) {
        error_log("Generate activities from tables error: " . $e->getMessage());
        return [];
    }
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
        header("Location: staff_dashboard.php");
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

// Prepare data for charts
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Prepare graduate registrations data
$grad_registrations_data = [];
$grad_registrations_labels = [];
foreach ($monthly_grads as $grad) {
    $grad_registrations_data[] = $grad['count'];
    $grad_registrations_labels[] = $month_names[$grad['month'] - 1] . ' ' . $grad['year'];
}

// Application status distribution
$application_status_labels = [];
$application_status_counts = [];
foreach ($app_status_data as $status) {
    $application_status_labels[] = ucfirst($status['app_status']);
    $application_status_counts[] = $status['count'];
}

// Job posts by status
$job_status_labels = [];
$job_status_counts = [];
foreach ($job_status_data as $status) {
    $job_status_labels[] = ucfirst($status['job_status']);
    $job_status_counts[] = $status['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Staff Dashboard</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    
    /* Enhanced Dashboard Cards */
    .dashboard-cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
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
    
    /* Progress Bar Styles */
    .progress-bar {
      height: 8px;
      background: #e0e0e0;
      border-radius: 4px;
      margin: 10px 0;
      overflow: hidden;
    }
    
    .progress {
      height: 100%;
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      border-radius: 4px;
    }
    
    /* Activity Log Styles */
    .activity-log {
      background-color: white;
      border-radius: 12px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
      padding: 25px;
      margin-top: 30px;
    }
    
    .activity-log h3 {
      color: var(--primary-color);
      margin-bottom: 20px;
      font-size: 1.3rem;
      font-weight: 600;
      border-bottom: 2px solid #f0f0f0;
      padding-bottom: 10px;
    }
    
    .activity-item {
      padding: 15px 0;
      border-bottom: 1px solid #f0f0f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: all 0.3s;
    }
    
    .activity-item:hover {
      background-color: #f8f9fa;
      padding-left: 10px;
      border-radius: 5px;
    }
    
    .activity-item:last-child {
      border-bottom: none;
    }
    
    .activity-details {
      flex: 1;
    }
    
    .activity-time {
      font-size: 0.8rem;
      color: #999;
      min-width: 100px;
      text-align: right;
    }
    
    /* Tip Box Styles */
    .tip-box {
      background-color: #fff8e1;
      padding: 15px;
      border-radius: 8px;
      margin-top: 15px;
      border-left: 4px solid #ffc107;
    }
    
    .tip-title {
      font-weight: 600;
      margin-bottom: 8px;
      color: #7d6608;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .tip-title i {
      color: #ffc107;
    }
    
    /* Animation for new notifications */
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.1); }
      100% { transform: scale(1); }
    }
    
    .notification-pulse {
      animation: pulse 1s infinite;
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .dashboard-cards {
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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
      
      .dashboard-cards {
        grid-template-columns: 1fr;
      }
      
      .notification-dropdown {
        width: 350px;
        right: -100px;
      }
      
      .chart-container {
        height: 200px;
      }
      
      .mini-chart-container {
        height: 150px;
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
      
      .stats-grid {
        grid-template-columns: 1fr;
      }
      
      .notification-dropdown {
        width: 300px;
        right: -140px;
      }
      
      .activity-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
      }
      
      .activity-time {
        text-align: left;
        min-width: auto;
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
          <a href="staff_dashboard.php" class="active">
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
          <a href="staff_manage_emp.php">
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
      <p>Here's what's happening with your account today. You are logged in as <?= htmlspecialchars($staff['staff_position']) ?> in <?= htmlspecialchars($staff['staff_department']) ?> department. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Staff Dashboard</h1>
    </div>
    
    <!-- Enhanced Dashboard Cards -->
    <div class="dashboard-cards">
      <!-- MODIFIED: Alumni Assisted Card - Updated to match previous code functionality -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">ALUMNI ASSISTED</h3>
          <i class="fas fa-hands-helping card-icon"></i>
        </div>
        <div class="card-value"><?= $graduates_assisted ?> Alumni</div>
        <div class="card-percentage positive-percentage">
          <i class="fas fa-percentage"></i> <?= $grads_assisted_percent ?>% of total alumni
        </div>
        <div class="card-footer">Based on shared resources and feedback provided</div>
        <div class="progress-bar">
          <div class="progress" style="width: <?= $grads_assisted_percent ?>%;"></div>
        </div>
        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-value"><?= $total_resources ?></div>
            <div class="stat-label">Resources Shared</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $read_resources ?></div>
            <div class="stat-label">Resources Read</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $total_feedback ?></div>
            <div class="stat-label">Feedback Given</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $unread_resources ?></div>
            <div class="stat-label">Resources Unread</div>
          </div>
        </div>
      </div>
      
      <!-- Pending Job Posts Card with Chart -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">JOB POSTS STATUS</h3>
          <i class="fas fa-file-alt card-icon"></i>
        </div>
        <div class="card-value"><?= $pending_jobs ?> Pending</div>
        <div class="card-percentage <?= $pending_jobs > 0 ? 'negative-percentage' : 'positive-percentage' ?>">
          <?php if ($pending_jobs > 0): ?>
          <i class="fas fa-exclamation-circle"></i> Needs Attention
          <?php else: ?>
          <i class="fas fa-check-circle"></i> All Clear
          <?php endif; ?>
        </div>
        <div class="card-footer">Jobs awaiting validation</div>
        <div class="mini-chart-container">
          <canvas id="jobStatusChart"></canvas>
        </div>
      </div>
      
      <!-- Graduate Statistics Card -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">ALUMNI STATISTICS</h3>
          <i class="fas fa-user-graduate card-icon"></i>
        </div>
        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-value"><?= $unmatched_graduates ?></div>
            <div class="stat-label">Unmatched</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $incomplete_portfolios ?></div>
            <div class="stat-label">Incomplete Portfolios</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $recent_activity ?></div>
            <div class="stat-label">Active (7 days)</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $total_graduates ?></div>
            <div class="stat-label">Total alumni</div>
          </div>
        </div>
        <div class="tip-box">
          <div class="tip-title">
            <i class="fas fa-lightbulb"></i>
            Alumni Support
          </div>
          <div>
            <?php if ($unmatched_graduates > 0): ?>
            • <?= $unmatched_graduates ?> alumni need job matches<br>
            <?php endif; ?>
            <?php if ($incomplete_portfolios > 0): ?>
            • <?= $incomplete_portfolios ?> portfolios need completion
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <!-- Application Status Card with Chart -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">APPLICATION STATUS</h3>
          <i class="fas fa-chart-pie card-icon"></i>
        </div>
        <div class="card-value"><?= array_sum($application_status_counts) ?> Total</div>
        <div class="card-footer">All applications status distribution</div>
        <div class="chart-container">
          <canvas id="applicationStatusChart"></canvas>
        </div>
      </div>
      
      <!-- Graduate Registration Trends Card -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">ALUMNI REGISTRATIONS</h3>
          <i class="fas fa-chart-line card-icon"></i>
        </div>
        <div class="card-value"><?= $total_graduates ?> Total</div>
        <div class="card-footer">Monthly registration</div>
        <div class="chart-container">
          <canvas id="gradRegistrationsChart"></canvas>
        </div>
      </div>
      
      <!-- Platform Overview Card -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">PLATFORM OVERVIEW</h3>
          <i class="fas fa-globe card-icon"></i>
        </div>
        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-value"><?= $total_graduates ?></div>
            <div class="stat-label">Alumni</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $total_employers ?></div>
            <div class="stat-label">Employers</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= $total_jobs ?></div>
            <div class="stat-label">Active Jobs</div>
          </div>
          <div class="stat-item">
            <div class="stat-value"><?= array_sum($application_status_counts) ?></div>
            <div class="stat-label">Applications</div>
          </div>
        </div>
        <div class="tip-box" style="background-color: #e3f2fd; border-left: 4px solid #2196f3;">
          <div class="tip-title" style="color: #1565c0;">
            <i class="fas fa-info-circle"></i>
            Platform Health
          </div>
          <div>
            Activity Rate: <strong><?= $recent_activity_percent ?>%</strong> of alumni active this week
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Common Dropdown Functionality
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
    
    // Enhanced notification functionality
    $(document).ready(function() {
      // Handle notification click and mark as read
      $('.notification-link').on('click', function(e) {
        const notifId = $(this).data('notif-id');
        const notificationItem = $(this);
        
        // Only mark as read if it's unread and has a valid ID
        if (notificationItem.hasClass('unread') && notifId) {
          // Send AJAX request to mark as read
          $.ajax({
            url: 'staff_dashboard.php',
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
      $('.card').hover(
        function() {
          $(this).css('transform', 'translateY(-5px)');
        },
        function() {
          $(this).css('transform', 'translateY(0)');
        }
      );
    });

    // Enhanced Charts initialization with professional styling
    document.addEventListener('DOMContentLoaded', function() {
      // Graduate Registrations Chart (Line)
      const gradRegistrationsCtx = document.getElementById('gradRegistrationsChart').getContext('2d');
      new Chart(gradRegistrationsCtx, {
        type: 'line',
        data: {
          labels: <?= json_encode($grad_registrations_labels) ?>,
          datasets: [{
            label: 'Alumni Registrations',
            data: <?= json_encode($grad_registrations_data) ?>,
            backgroundColor: 'rgba(110, 3, 3, 0.1)',
            borderColor: 'rgba(110, 3, 3, 0.9)',
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: 'rgba(110, 3, 3, 1)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.8)',
              titleFont: {
                size: 12,
                weight: 'bold'
              },
              bodyFont: {
                size: 11
              },
              padding: 12,
              cornerRadius: 6
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: {
                color: 'rgba(0, 0, 0, 0.05)'
              },
              ticks: {
                font: {
                  size: 11
                }
              }
            },
            x: {
              grid: {
                display: false
              },
              ticks: {
                font: {
                  size: 11
                }
              }
            }
          }
        }
      });

      // Application Status Chart (Doughnut)
      const applicationStatusCtx = document.getElementById('applicationStatusChart').getContext('2d');
      new Chart(applicationStatusCtx, {
        type: 'doughnut',
        data: {
          labels: <?= json_encode($application_status_labels) ?>,
          datasets: [{
            data: <?= json_encode($application_status_counts) ?>,
            backgroundColor: [
              'rgba(255, 167, 0, 0.9)',
              'rgba(23, 162, 184, 0.9)',
              'rgba(0, 123, 255, 0.9)',
              'rgba(31, 122, 17, 0.9)',
              'rgba(211, 47, 47, 0.9)'
            ],
            borderColor: [
              'rgba(255, 167, 0, 1)',
              'rgba(23, 162, 184, 1)',
              'rgba(0, 123, 255, 1)',
              'rgba(31, 122, 17, 1)',
              'rgba(211, 47, 47, 1)'
            ],
            borderWidth: 2,
            hoverOffset: 15
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
                padding: 20,
                usePointStyle: true,
                pointStyle: 'circle',
                font: {
                  size: 11,
                  weight: '500'
                }
              }
            },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.8)',
              titleFont: {
                size: 12,
                weight: 'bold'
              },
              bodyFont: {
                size: 11
              },
              padding: 12,
              cornerRadius: 6
            }
          },
          animation: {
            animateScale: true,
            animateRotate: true
          }
        }
      });

      // Job Status Chart (Bar)
      const jobStatusCtx = document.getElementById('jobStatusChart').getContext('2d');
      new Chart(jobStatusCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode($job_status_labels) ?>,
          datasets: [{
            label: 'Job Count',
            data: <?= json_encode($job_status_counts) ?>,
            backgroundColor: [
              'rgba(31, 122, 17, 0.8)',
              'rgba(255, 167, 0, 0.8)',
              'rgba(211, 47, 47, 0.8)'
            ],
            borderColor: [
              'rgba(255, 167, 0, 1)',
              'rgba(31, 122, 17, 1)',
              'rgba(211, 47, 47, 1)'
            ],
            borderWidth: 1,
            borderRadius: 6,
            borderSkipped: false,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              backgroundColor: 'rgba(0, 0, 0, 0.8)',
              titleFont: {
                size: 12,
                weight: 'bold'
              },
              bodyFont: {
                size: 11
              },
              padding: 12,
              cornerRadius: 6
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: {
                color: 'rgba(0, 0, 0, 0.05)'
              },
              ticks: {
                font: {
                  size: 11
                }
              }
            },
            x: {
              grid: {
                display: false
              },
              ticks: {
                font: {
                  size: 11
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