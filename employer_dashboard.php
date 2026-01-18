<?php
session_start();

// Check if user is logged in and is an employer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'employer') {
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
    
    // Handle marking notifications as read via AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        $employer_id = $_SESSION['user_id'];
        
        if ($_POST['ajax_action'] === 'mark_notifications_read') {
            if (isset($_POST['mark_all']) && $_POST['mark_all'] === 'true') {
                // Mark all notifications as read for this user
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET notif_is_read = 1 
                    WHERE notif_usr_id = :employer_id 
                    AND notif_is_read = 0
                ");
                $stmt->bindParam(':employer_id', $employer_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'action' => 'mark_all']);
                exit();
            } elseif (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
                // Mark specific notifications as read
                $placeholders = str_repeat('?,', count($_POST['notification_ids']) - 1) . '?';
                $stmt = $conn->prepare("
                    UPDATE notifications 
                    SET notif_is_read = 1 
                    WHERE notif_id IN ($placeholders) 
                    AND notif_usr_id = ?
                ");
                
                $params = array_merge($_POST['notification_ids'], [$employer_id]);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'action' => 'mark_specific']);
                exit();
            }
        }
        
        // Handle sending replies to staff messages
        if ($_POST['ajax_action'] === 'send_reply') {
            $resource_id = $_POST['resource_id'];
            $reply_message = $_POST['reply_message'];
            
            // Get the staff user ID from the resource
            $get_staff_stmt = $conn->prepare("
                SELECT staff_usr_id FROM shared_resources WHERE resource_id = :resource_id
            ");
            $get_staff_stmt->bindParam(':resource_id', $resource_id);
            $get_staff_stmt->execute();
            $resource = $get_staff_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($resource) {
                // Store reply in the notifications table
                $reply_notification = "Reply to staff message: " . substr($reply_message, 0, 100) . (strlen($reply_message) > 100 ? "..." : "");
                
                $insert_reply_stmt = $conn->prepare("
                    INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at, notif_related_id)
                    VALUES (:staff_id, :message, 'employer_reply', 0, NOW(), :resource_id)
                ");
                $insert_reply_stmt->bindParam(':staff_id', $resource['staff_usr_id']);
                $insert_reply_stmt->bindParam(':message', $reply_notification);
                $insert_reply_stmt->bindParam(':resource_id', $resource_id);
                $insert_reply_stmt->execute();
                
                // Get the ID of the newly inserted reply
                $reply_id = $conn->lastInsertId();
                
                echo json_encode(['success' => true, 'message' => 'Reply sent successfully', 'reply_id' => $reply_id]);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'Resource not found']);
                exit();
            }
        }
        
        // Handle fetching replies for a resource
        if ($_POST['ajax_action'] === 'get_replies') {
            $resource_id = $_POST['resource_id'];
            
            // Get employer replies from notifications table
            $get_replies_stmt = $conn->prepare("
                SELECT 
                    n.notif_id,
                    n.notif_message,
                    n.notif_created_at as sent_at,
                    u.usr_name,
                    u.usr_profile_photo,
                    'employer' as sender_type
                FROM notifications n
                JOIN shared_resources sr ON n.notif_related_id = sr.resource_id
                JOIN users u ON sr.grad_usr_id = u.usr_id
                WHERE n.notif_related_id = :resource_id 
                AND n.notif_type = 'employer_reply'
                ORDER BY n.notif_created_at ASC
            ");
            $get_replies_stmt->bindParam(':resource_id', $resource_id);
            $get_replies_stmt->execute();
            $replies = $get_replies_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'replies' => $replies]);
            exit();
        }
        
        // Handle deleting a reply - MODIFIED TO FIX THE ISSUE
        if ($_POST['ajax_action'] === 'delete_reply') {
            $reply_id = $_POST['reply_id'];
            $employer_id = $_SESSION['user_id'];
            
            // Verify that the reply was sent by the current employer
            $verify_stmt = $conn->prepare("
                SELECT COUNT(*) as reply_count 
                FROM notifications n
                JOIN shared_resources sr ON n.notif_related_id = sr.resource_id
                WHERE n.notif_id = :reply_id 
                AND sr.grad_usr_id = :employer_id
                AND n.notif_type = 'employer_reply'
            ");
            $verify_stmt->bindParam(':reply_id', $reply_id);
            $verify_stmt->bindParam(':employer_id', $employer_id);
            $verify_stmt->execute();
            $result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['reply_count'] > 0) {
                // Delete the reply
                $delete_stmt = $conn->prepare("
                    DELETE FROM notifications 
                    WHERE notif_id = :reply_id
                ");
                $delete_stmt->bindParam(':reply_id', $reply_id);
                $delete_stmt->execute();
                
                echo json_encode(['success' => true, 'message' => 'Reply deleted successfully']);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'Reply not found or you do not have permission to delete it']);
                exit();
            }
        }
        
        // Handle marking staff communications as read
        if ($_POST['ajax_action'] === 'mark_staff_comm_read') {
            $resource_id = $_POST['resource_id'];
            $employer_id = $_SESSION['user_id'];
            
            // Mark the staff communication as read
            $mark_read_stmt = $conn->prepare("
                UPDATE shared_resources 
                SET is_read = 1 
                WHERE resource_id = :resource_id 
                AND grad_usr_id = :employer_id
            ");
            $mark_read_stmt->bindParam(':resource_id', $resource_id);
            $mark_read_stmt->bindParam(':employer_id', $employer_id);
            $mark_read_stmt->execute();
            
            echo json_encode(['success' => true]);
            exit();
        }
    }
    
    // Get employer data
    $employer_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT e.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo
        FROM employers e 
        JOIN users u ON e.emp_usr_id = u.usr_id 
        WHERE e.emp_usr_id = :employer_id
    ");
    $stmt->bindParam(':employer_id', $employer_id);
    $stmt->execute();
    $employer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employer) {
        // If no employer record found, redirect to profile setup
        header("Location: employer_profile.php?setup=1");
        exit();
    }
    
    // Get job statistics
    $jobs_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN j.job_status = 'active' THEN 1 ELSE 0 END) as active_jobs,
            SUM(CASE WHEN j.job_status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
            SUM(CASE WHEN j.job_status = 'closed' THEN 1 ELSE 0 END) as closed_jobs,
            SUM(CASE WHEN j.job_status = 'rejected' THEN 1 ELSE 0 END) as rejected_jobs
        FROM jobs j
        WHERE j.job_emp_usr_id = :employer_id
    ");
    $jobs_stmt->bindParam(':employer_id', $employer_id);
    $jobs_stmt->execute();
    $job_stats = $jobs_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get application statistics
    $apps_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN a.app_status = 'pending' THEN 1 ELSE 0 END) as pending_applications,
            SUM(CASE WHEN a.app_status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_applications,
            SUM(CASE WHEN a.app_status = 'qualified' THEN 1 ELSE 0 END) as qualified_applications,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_applications
        FROM applications a
        JOIN jobs j ON a.app_job_id = j.job_id
        WHERE j.job_emp_usr_id = :employer_id
    ");
    $apps_stmt->bindParam(':employer_id', $employer_id);
    $apps_stmt->execute();
    $app_stats = $apps_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get profile views
    $views_stmt = $conn->prepare("
        SELECT COUNT(*) as profile_views 
        FROM employer_profile_views 
        WHERE view_emp_usr_id = :employer_id
    ");
    $views_stmt->bindParam(':employer_id', $employer_id);
    $views_stmt->execute();
    $profile_views = $views_stmt->fetchColumn();
    
    // CHANGED: Get profile views by day for chart (Last 30 days)
    $views_by_day_stmt = $conn->prepare("
        SELECT 
            DATE(view_viewed_at) as view_date, 
            COUNT(*) as count
        FROM employer_profile_views 
        WHERE view_emp_usr_id = :employer_id
        AND view_viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(view_viewed_at)
        ORDER BY view_date ASC
    ");
    $views_by_day_stmt->bindParam(':employer_id', $employer_id);
    $views_by_day_stmt->execute();
    $views_by_day = $views_by_day_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CHANGED: Get applications by day for chart (Last 30 days)
    $apps_by_day_stmt = $conn->prepare("
        SELECT 
            DATE(app_applied_at) as app_date, 
            COUNT(*) as count
        FROM applications a
        JOIN jobs j ON a.app_job_id = j.job_id
        WHERE j.job_emp_usr_id = :employer_id
        AND app_applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(app_applied_at)
        ORDER BY app_date ASC
    ");
    $apps_by_day_stmt->bindParam(':employer_id', $employer_id);
    $apps_by_day_stmt->execute();
    $apps_by_day = $apps_by_day_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // MODIFIED: Get top ACTIVE jobs by applications - NOW SHOWING ONLY ACTIVE JOBS
    $top_jobs_stmt = $conn->prepare("
        SELECT 
            j.job_title,
            COUNT(a.app_id) as application_count
        FROM jobs j
        LEFT JOIN applications a ON j.job_id = a.app_job_id
        WHERE j.job_emp_usr_id = :employer_id
        AND j.job_status = 'active'  -- ADDED THIS CONDITION TO SHOW ONLY ACTIVE JOBS
        GROUP BY j.job_id, j.job_title
        ORDER BY application_count DESC
        LIMIT 5
    ");
    $top_jobs_stmt->bindParam(':employer_id', $employer_id);
    $top_jobs_stmt->execute();
    $top_jobs = $top_jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ========== ENHANCED NOTIFICATIONS SYSTEM ==========
    
    // 1. Create application notifications for recent applications
    $recent_apps_stmt = $conn->prepare("
        SELECT 
            a.app_id,
            a.app_applied_at,
            u.usr_name,
            j.job_title,
            j.job_id,
            e.emp_company_name
        FROM applications a
        JOIN jobs j ON a.app_job_id = j.job_id
        JOIN users u ON a.app_grad_usr_id = u.usr_id
        JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
        WHERE j.job_emp_usr_id = :employer_id
        AND a.app_applied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY a.app_applied_at DESC
    ");
    $recent_apps_stmt->bindParam(':employer_id', $employer_id);
    $recent_apps_stmt->execute();
    $recent_applications = $recent_apps_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create notifications for recent applications that don't have notifications yet
    foreach ($recent_applications as $app) {
        $notification_message = 'New application received for "' . $app['job_title'] . '" from ' . $app['usr_name'];
        
        // Check if notification already exists
        $check_notif_stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE notif_usr_id = :employer_id 
            AND notif_message = :message
            AND notif_type = 'application'
        ");
        $check_notif_stmt->bindParam(':employer_id', $employer_id);
        $check_notif_stmt->bindParam(':message', $notification_message);
        $check_notif_stmt->execute();
        
        if ($check_notif_stmt->fetchColumn() == 0) {
            // Insert new notification
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'application', 0, :created_at)
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->bindParam(':created_at', $app['app_applied_at']);
            $insert_notif_stmt->execute();
        }
    }
    
    // 2. Create job status notifications for recently reviewed jobs
    $recent_jobs_stmt = $conn->prepare("
        SELECT 
            j.job_id,
            j.job_title,
            j.job_status,
            j.job_reviewed_at,
            u.usr_name as reviewer_name
        FROM jobs j
        LEFT JOIN users u ON j.job_reviewed_by = u.usr_id
        WHERE j.job_emp_usr_id = :employer_id
        AND j.job_reviewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY j.job_reviewed_at DESC
    ");
    $recent_jobs_stmt->bindParam(':employer_id', $employer_id);
    $recent_jobs_stmt->execute();
    $recent_jobs = $recent_jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($recent_jobs as $job) {
        if ($job['job_status'] === 'active') {
            $notification_message = 'Your job posting "' . $job['job_title'] . '" has been approved and is now active';
        } elseif ($job['job_status'] === 'rejected') {
            $notification_message = 'Your job posting "' . $job['job_title'] . '" has been rejected by admin';
        } else {
            continue;
        }
        
        // Check if notification already exists
        $check_notif_stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE notif_usr_id = :employer_id 
            AND notif_message = :message
            AND notif_type = 'job_status'
        ");
        $check_notif_stmt->bindParam(':employer_id', $employer_id);
        $check_notif_stmt->bindParam(':message', $notification_message);
        $check_notif_stmt->execute();
        
        if ($check_notif_stmt->fetchColumn() == 0) {
            // Insert new notification
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'job_status', 0, :created_at)
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->bindParam(':created_at', $job['job_reviewed_at']);
            $insert_notif_stmt->execute();
        }
    }
    
    // 3. Create profile view notifications for recent profile views - FIXED QUERY
    $recent_views_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as view_count,
            DATE(view_viewed_at) as view_date
        FROM employer_profile_views 
        WHERE view_emp_usr_id = :employer_id
        AND view_viewed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        GROUP BY DATE(view_viewed_at)
        ORDER BY view_date DESC
        LIMIT 1
    ");
    $recent_views_stmt->bindParam(':employer_id', $employer_id);
    $recent_views_stmt->execute();
    $recent_views = $recent_views_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recent_views && $recent_views['view_count'] >= 3) {
        $notification_message = 'Your company profile was viewed by ' . $recent_views['view_count'] . ' graduates today';
        
        // Check if notification already exists
        $check_notif_stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE notif_usr_id = :employer_id 
            AND notif_message LIKE 'Your company profile was viewed by%'
            AND notif_type = 'profile_views'
            AND DATE(notif_created_at) = CURDATE()
        ");
        $check_notif_stmt->bindParam(':employer_id', $employer_id);
        $check_notif_stmt->execute();
        
        if ($check_notif_stmt->fetchColumn() == 0) {
            // Insert new notification
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'profile_views', 0, NOW())
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->execute();
        }
    }
    
    // 4. Create system notifications (example: weekly summary)
    $last_system_notif_stmt = $conn->prepare("
        SELECT COUNT(*) FROM notifications 
        WHERE notif_usr_id = :employer_id 
        AND notif_type = 'system'
        AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $last_system_notif_stmt->bindParam(':employer_id', $employer_id);
    $last_system_notif_stmt->execute();
    
    if ($last_system_notif_stmt->fetchColumn() == 0) {
        // Create weekly summary notification
        $total_active_jobs = $job_stats['active_jobs'] ?? 0;
        $total_pending_apps = $app_stats['pending_applications'] ?? 0;
        
        $system_message = "Weekly Summary: You have {$total_active_jobs} active jobs and {$total_pending_apps} pending applications";
        
        $insert_system_notif_stmt = $conn->prepare("
            INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
            VALUES (:employer_id, :message, 'system', 0, NOW())
        ");
        $insert_system_notif_stmt->bindParam(':employer_id', $employer_id);
        $insert_system_notif_stmt->bindParam(':message', $system_message);
        $insert_system_notif_stmt->execute();
    }
    
    // 5. Get ALL notifications (removed LIMIT 8)
    $notif_stmt = $conn->prepare("
        SELECT 
            n.notif_id,
            n.notif_usr_id,
            n.notif_message,
            n.notif_type,
            n.notif_is_read,
            n.notif_created_at,
            CASE 
                WHEN n.notif_type = 'application' THEN 'fas fa-file-alt'
                WHEN n.notif_type = 'job_status' THEN 'fas fa-briefcase'
                WHEN n.notif_type = 'profile_views' THEN 'fas fa-eye'
                WHEN n.notif_type = 'system' THEN 'fas fa-cog'
                WHEN n.notif_type = 'application_update' THEN 'fas fa-sync-alt'
                WHEN n.notif_type = 'employer_reply' THEN 'fas fa-reply'
                ELSE 'fas fa-bell'
            END as notif_icon,
            CASE 
                WHEN n.notif_type = 'application' THEN '#0044ff'
                WHEN n.notif_type = 'job_status' THEN '#1f7a11'
                WHEN n.notif_type = 'profile_views' THEN '#6a0dad'
                WHEN n.notif_type = 'system' THEN '#ffa700'
                WHEN n.notif_type = 'application_update' THEN '#d32f2f'
                WHEN n.notif_type = 'employer_reply' THEN '#1f7a11'
                ELSE '#6e0303'
            END as notif_color
        FROM notifications n
        WHERE n.notif_usr_id = :employer_id 
        ORDER BY n.notif_created_at DESC
    ");
    $notif_stmt->bindParam(':employer_id', $employer_id);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread notifications
    $unread_notif_count = 0;
    foreach ($notifications as $notif) {
        if (!$notif['notif_is_read']) $unread_notif_count++;
    }
    
    // ========== GET STAFF NOTES AND MESSAGES ==========
    $staff_communications_stmt = $conn->prepare("
        SELECT 
            sr.resource_id,
            sr.resource_type,
            sr.resource_title,
            sr.resource_description,
            sr.resource_url,
            sr.is_read,
            sr.shared_at,
            u.usr_name as staff_name,
            u.usr_profile_photo as staff_photo,
            sr.staff_usr_id
        FROM shared_resources sr
        JOIN users u ON sr.staff_usr_id = u.usr_id
        WHERE sr.grad_usr_id = :employer_id
        AND sr.resource_type IN ('career_advice', 'skill_development', 'interview_guide')
        ORDER BY sr.shared_at DESC
        LIMIT 10
    ");
    $staff_communications_stmt->bindParam(':employer_id', $employer_id);
    $staff_communications_stmt->execute();
    $staff_communications = $staff_communications_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread staff communications
    $unread_staff_comms = 0;
    foreach ($staff_communications as $comm) {
        if (!$comm['is_read']) $unread_staff_comms++;
    }
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Prepare data for charts
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// CHANGED: Prepare profile views data for last 30 days
$profile_views_data = [];
$profile_views_labels = [];

// Generate dates for last 30 days
$last_30_days = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last_30_days[$date] = 0;
}

// Fill with actual data
foreach ($views_by_day as $view) {
    $last_30_days[$view['view_date']] = $view['count'];
}

// Prepare for chart
foreach ($last_30_days as $date => $count) {
    $profile_views_data[] = $count;
    $profile_views_labels[] = date('M j', strtotime($date));
}

// CHANGED: Prepare applications data for last 30 days
$applications_data = [];
$applications_labels = [];

// Generate dates for last 30 days
$last_30_days_apps = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last_30_days_apps[$date] = 0;
}

// Fill with actual data
foreach ($apps_by_day as $app) {
    $last_30_days_apps[$app['app_date']] = $app['count'];
}

// Prepare for chart
foreach ($last_30_days_apps as $date => $count) {
    $applications_data[] = $count;
    $applications_labels[] = date('M j', strtotime($date));
}

// Prepare job status data
$job_status_data = [
    $job_stats['active_jobs'] ?? 0,
    $job_stats['pending_jobs'] ?? 0,
    $job_stats['closed_jobs'] ?? 0,
    $job_stats['rejected_jobs'] ?? 0
];

// Prepare application status data
$application_status_data = [
    $app_stats['pending_applications'] ?? 0,
    $app_stats['reviewed_applications'] ?? 0,
    $app_stats['qualified_applications'] ?? 0,
    $app_stats['hired_applications'] ?? 0
];

// MODIFIED: Prepare top ACTIVE jobs data - NOW ONLY SHOWING ACTIVE JOBS
$top_jobs_labels = [];
$top_jobs_data = [];
$top_jobs_percentages = [];
$total_apps_for_top_jobs = 0;

// Calculate total applications for active top jobs
foreach ($top_jobs as $job) {
    $total_apps_for_top_jobs += $job['application_count'];
}

foreach ($top_jobs as $job) {
    $top_jobs_labels[] = $job['job_title'];
    $top_jobs_data[] = $job['application_count'];
    $percentage = $total_apps_for_top_jobs > 0 ? round(($job['application_count'] / $total_apps_for_top_jobs) * 100, 1) : 0;
    $top_jobs_percentages[] = $percentage;
}

// IMPROVED: Calculate percentages for tooltips with better humanization
$total_jobs = $job_stats['total_jobs'] ?? 0;
$job_percentages = [];

if ($total_jobs > 0) {
    $job_percentages = [
        'active' => round(($job_stats['active_jobs'] ?? 0) / $total_jobs * 100, 1),
        'pending' => round(($job_stats['pending_jobs'] ?? 0) / $total_jobs * 100, 1),
        'closed' => round(($job_stats['closed_jobs'] ?? 0) / $total_jobs * 100, 1),
        'rejected' => round(($job_stats['rejected_jobs'] ?? 0) / $total_jobs * 100, 1),
    ];
} else {
    $job_percentages = [
        'active' => 0,
        'pending' => 0,
        'closed' => 0,
        'rejected' => 0,
    ];
}

$total_applications = $app_stats['total_applications'] ?? 0;
$application_percentages = [];

if ($total_applications > 0) {
    $application_percentages = [
        'pending' => round(($app_stats['pending_applications'] ?? 0) / $total_applications * 100, 1),
        'reviewed' => round(($app_stats['reviewed_applications'] ?? 0) / $total_applications * 100, 1),
        'qualified' => round(($app_stats['qualified_applications'] ?? 0) / $total_applications * 100, 1),
        'hired' => round(($app_stats['hired_applications'] ?? 0) / $total_applications * 100, 1),
    ];
} else {
    $application_percentages = [
        'pending' => 0,
        'reviewed' => 0,
        'qualified' => 0,
        'hired' => 0,
    ];
}

// IMPROVED: Calculate application rate percentage with better humanization
$active_jobs = $job_stats['active_jobs'] ?? 0;
$total_apps = $app_stats['total_applications'] ?? 0;

// Calculate average applications per active job
if ($active_jobs > 0) {
    $avg_apps_per_job = $total_apps / $active_jobs;
    // Humanize the application rate: cap at 100%, show as percentage
    $application_rate = min(round($avg_apps_per_job * 20), 100); // Adjusted multiplier for better visualization
} else {
    $avg_apps_per_job = 0;
    $application_rate = 0;
}

// IMPROVED: Calculate hire rate percentage with better humanization
$hire_rate = 0;
if (($app_stats['total_applications'] ?? 0) > 0) {
    $hire_rate = round(($app_stats['hired_applications'] ?? 0) / $app_stats['total_applications'] * 100, 1);
}

// Function to get icon for staff communication type
function getStaffCommIcon($type) {
    switch ($type) {
        case 'career_advice':
            return 'fas fa-comments';
        case 'skill_development':
            return 'fas fa-chart-line';
        case 'interview_guide':
            return 'fas fa-file-alt';
        default:
            return 'fas fa-envelope';
    }
}

// Function to get color for staff communication type
function getStaffCommColor($type) {
    switch ($type) {
        case 'career_advice':
            return '#6e0303';
        case 'skill_development':
            return '#1f7a11';
        case 'interview_guide':
            return '#0044ff';
        default:
            return '#6a0dad';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Employer Dashboard</title>
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
        
        .company-name {
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
        
        /* Enhanced Notification Items - Following Admin Dashboard Style */
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
        
        /* Staff Communications Styles */
        .staff-comms {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-top: 30px;
        }
        
        .staff-comms h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
            font-weight: 600;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .comms-count {
            background: var(--secondary-color);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        /* Scrollable messages container */
        .comms-container {
            max-height: 450px;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        /* Custom scrollbar for comms container */
        .comms-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .comms-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .comms-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
        }
        
        .comms-container::-webkit-scrollbar-thumb:hover {
            background: #8a0404;
        }
        
        .comm-item {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .comm-item:hover {
            background-color: #f8f9fa;
            padding-left: 25px;
            border-radius: 5px;
            border-left-color: var(--secondary-color);
        }
        
        .comm-item:last-child {
            border-bottom: none;
        }
        
        .comm-item.unread {
            background-color: #fff5e6;
            border-left-color: var(--primary-color);
        }
        
        .comm-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        
        .comm-staff-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .comm-staff-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        .comm-staff-details h4 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .comm-type {
            display: none; /* Hide the type labels */
        }
        
        .comm-time {
            font-size: 0.8rem;
            color: #999;
            min-width: 100px;
            text-align: right;
        }
        
        .comm-content {
            margin-left: 52px;
        }
        
        .comm-title {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 8px;
            font-size: 1.1rem;
        }
        
        .comm-description {
            color: #666;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        
        .comm-resource {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--blue);
            text-decoration: none;
            font-weight: 500;
            padding: 6px 12px;
            background: rgba(0, 68, 255, 0.1);
            border-radius: 6px;
            transition: all 0.3s;
        }
        
        .comm-resource:hover {
            background: rgba(0, 68, 255, 0.2);
            transform: translateY(-2px);
        }
        
        /* Reply Section Styles */
        .reply-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #eee;
        }
        
        .reply-toggle {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-color);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .reply-toggle:hover {
            background: rgba(110, 3, 3, 0.1);
        }
        
        .reply-form {
            margin-top: 15px;
            display: none;
        }
        
        .reply-textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            resize: vertical;
            font-family: inherit;
            font-size: 0.9rem;
            margin-bottom: 10px;
            transition: border-color 0.3s;
        }
        
        .reply-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
        }
        
        .reply-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .reply-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .reply-submit {
            background: var(--primary-color);
            color: white;
        }
        
        .reply-submit:hover {
            background: #8a0404;
        }
        
        .reply-cancel {
            background: #f0f0f0;
            color: #666;
        }
        
        .reply-cancel:hover {
            background: #e0e0e0;
        }
        
        .replies-container {
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            padding: 10px;
            background: #f9f9f9;
        }
        
        .reply-item {
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        
        .reply-item:last-child {
            margin-bottom: 0;
        }
        
        .reply-item.employer {
            background: #fff5e6;
            border-left: 3px solid var(--secondary-color);
        }
        
        .reply-item.staff {
            background: #e6f7ff;
            border-left: 3px solid var(--blue);
        }
        
        .reply-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .reply-sender {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .reply-time {
            font-size: 0.8rem;
            color: #999;
        }
        
        .reply-message {
            color: #333;
            line-height: 1.4;
        }
        
        .reply-actions {
            margin-top: 8px;
            display: flex;
            justify-content: flex-end;
        }
        
        .delete-reply-btn {
            background: none;
            border: none;
            color: var(--red);
            cursor: pointer;
            font-size: 0.8rem;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .delete-reply-btn:hover {
            background: rgba(211, 47, 47, 0.1);
        }
        
        .no-replies {
            text-align: center;
            padding: 20px;
            color: #999;
            font-style: italic;
        }
        
        .no-comms {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .no-comms i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
        }
        
        .no-comms p {
            font-size: 1.1rem;
        }
        
        /* Custom Success Message */
        .custom-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-left: 4px solid var(--green);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            border-radius: 8px;
            padding: 15px 20px;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 400px;
            transform: translateX(150%);
            transition: transform 0.4s ease;
        }
        
        .custom-alert.show {
            transform: translateX(0);
        }
        
        .custom-alert.error {
            border-left-color: var(--red);
        }
        
        .custom-alert.error .custom-alert-icon {
            background: var(--red);
        }
        
        .custom-alert-icon {
            width: 24px;
            height: 24px;
            background: var(--green);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        
        .custom-alert-content {
            flex: 1;
        }
        
        .custom-alert-title {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 4px;
        }
        
        .custom-alert-message {
            color: #666;
            font-size: 0.9rem;
        }
        
        .custom-alert-close {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .custom-alert-close:hover {
            color: var(--red);
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
            
            .sidebar-header h3, .sidebar-menu span, .company-name, .menu-label {
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
            
            .comm-header {
                flex-direction: column;
                gap: 10px;
            }
            
            .comm-time {
                text-align: left;
                min-width: auto;
            }
            
            .comm-content {
                margin-left: 0;
                margin-top: 10px;
            }
            
            .comms-container {
                max-height: 400px;
            }
            
            .custom-alert {
                right: 10px;
                left: 10px;
                max-width: none;
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
            
            .comms-container {
                max-height: 350px;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= !empty($employer['usr_profile_photo']) ? htmlspecialchars($employer['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($employer['emp_company_name']) . '&background=random' ?>" alt="Company Logo">
            <div class="sidebar-header-text">
                <h3><?= htmlspecialchars($employer['usr_name']) ?></h3>
                <div class="company-name"><?= htmlspecialchars($employer['emp_company_name']) ?></div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <ul>
                <li>
                    <a href="employer_dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="employer_jobs.php?section=manage">
                        <i class="fas fa-briefcase"></i>
                        <span>Manage Jobs</span>
                    </a>
                </li>
                <li>
                    <a href="employer_portfolio.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Alumni Portfolios</span>
                    </a>
                </li>
                <li>
                    <a href="employer_candidates.php">
                        <i class="fas fa-list-alt"></i>
                        <span>Shortlist Candidates</span>
                    </a>
                </li>
                <li>
                    <a href="employer_analytics.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Data Analytics</span>
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
                            <button class="mark-all-read" id="markAllRead">Mark all as read</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notif): 
                                // Determine priority and icon based on notification type
                                $priority_class = 'priority-medium';
                                $notification_icon = $notif['notif_icon'];
                                
                                if ($notif['notif_type'] === 'application') {
                                    $priority_class = 'priority-high';
                                } elseif ($notif['notif_type'] === 'job_status') {
                                    $priority_class = 'priority-medium';
                                } elseif ($notif['notif_type'] === 'profile_views') {
                                    $priority_class = 'priority-low';
                                } elseif ($notif['notif_type'] === 'system') {
                                    $priority_class = 'priority-medium';
                                } elseif ($notif['notif_type'] === 'application_update') {
                                    $priority_class = 'priority-high';
                                } elseif ($notif['notif_type'] === 'employer_reply') {
                                    $priority_class = 'priority-low';
                                }
                                
                                // Determine the URL for the notification
                                $notification_url = 'employer_jobs.php?section=manage';
                                if (strpos($notif['notif_type'], 'application') !== false) {
                                    $notification_url = 'employer_jobs.php?section=manage';
                                } elseif (strpos($notif['notif_type'], 'job') !== false) {
                                    $notification_url = 'employer_jobs.php?section=manage';
                                } elseif (strpos($notif['notif_type'], 'profile') !== false) {
                                    $notification_url = 'employer_analytics.php';
                                } else {
                                    $notification_url = 'employer_dashboard.php';
                                }
                            ?>
                            <a href="<?= $notification_url ?>" class="notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?>">
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="<?= $notification_icon ?>" style="color: <?= $notif['notif_color'] ?>"></i>
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
                <div class="admin-profile" id="adminProfile">
                    <img src="<?= !empty($employer['usr_profile_photo']) ? htmlspecialchars($employer['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($employer['usr_name']) . '&background=random' ?>" alt="Employer">
                    <span class="admin-name"><?= htmlspecialchars($employer['usr_name']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                    <div class="dropdown profile-dropdown" id="profileDropdown">
                        <a href="employer_profile.php" class="dropdown-item"><i class="fas fa-building"></i> Company Profile</a>
                        <a href="employer_settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <div class="dropdown-item" style="border-top: 1px solid #eee; margin-top: 5px; padding-top: 15px;">
                            <i class="fas fa-user-circle"></i> Logged in as: <strong><?= htmlspecialchars($employer['usr_name']) ?></strong>
                        </div>
                        <a href="index.php" class="dropdown-item" style="color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?= htmlspecialchars($employer['usr_name']) ?>!</h2>
            <p>Here's what's happening with your hiring activities today.</p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Employer Dashboard</h1>
        </div>
        
        <!-- Enhanced Dashboard Cards -->
        <div class="dashboard-cards">
            <!-- Job Status Card with Enhanced Doughnut Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">JOB STATUS</h3>
                    <i class="fas fa-briefcase card-icon"></i>
                </div>
                <div class="card-value"><?= $job_stats['total_jobs'] ?? 0 ?></div>
                <div class="card-footer">Total Jobs Posted</div>
                <div class="chart-container">
                    <canvas id="jobStatusChart"></canvas>
                </div>
            </div>
            
            <!-- Applications Card with Enhanced Doughnut Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPLICATIONS</h3>
                    <i class="fas fa-file-alt card-icon"></i>
                </div>
                <div class="card-value"><?= $app_stats['total_applications'] ?? 0 ?></div>
                <!-- MODIFIED: Removed the apps per job percentage line -->
                <div class="card-footer">Total Applications Received</div>
                <div class="chart-container">
                    <canvas id="applicationStatusChart"></canvas>
                </div>
            </div>
            
            <!-- Profile Views Card with Enhanced Line Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PROFILE VIEWS</h3>
                    <i class="fas fa-eye card-icon"></i>
                </div>
                <div class="card-value"><?= $profile_views ?></div>
                <div class="card-percentage positive-percentage">
                    <i class="fas fa-trending-up"></i> Last 30 Days
                </div>
                <div class="card-footer">Company Profile Views by Alumni</div>
                <div class="chart-container">
                    <canvas id="profileViewsChart"></canvas>
                </div>
            </div>
            
            <!-- Hiring Success Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">HIRING SUCCESS</h3>
                    <i class="fas fa-user-check card-icon"></i>
                </div>
                <div class="card-value"><?= $app_stats['hired_applications'] ?? 0 ?></div>
                <div class="card-percentage positive-percentage">
                    <i class="fas fa-percentage"></i> <?= $hire_rate ?>% Hire Rate
                </div>
                <div class="card-footer">Successful Hires</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $app_stats['qualified_applications'] ?? 0 ?></div>
                        <div class="stat-label">Qualified</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $app_stats['reviewed_applications'] ?? 0 ?></div>
                        <div class="stat-label">Reviewed</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $app_stats['pending_applications'] ?? 0 ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $app_stats['hired_applications'] ?? 0 ?></div>
                        <div class="stat-label">Hired</div>
                    </div>
                </div>
            </div>
            
            <!-- Applications Over Time Card with Enhanced Line Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPLICATIONS OVER TIME</h3>
                    <i class="fas fa-chart-line card-icon"></i>
                </div>
                <div class="card-value">Last 30 Days</div>
                <div class="card-footer">Daily Application Trends</div>
                <div class="chart-container">
                    <canvas id="applicationsChart"></canvas>
                </div>
            </div>
            
            <!-- MODIFIED: Top ACTIVE Jobs Card with Enhanced Horizontal Bar Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">TOP ACTIVE JOBS BY APPLICATIONS</h3>
                    <i class="fas fa-star card-icon"></i>
                </div>
                <div class="card-value"><?= $total_apps_for_top_jobs ?></div>
                <div class="card-footer">Applications for Top 5 Active Jobs</div>
                <div class="chart-container">
                    <canvas id="topJobsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Notes and Messages from Staff -->
        <div class="staff-comms">
            <h3>
                Messages from CTU-PESO
                <?php if ($unread_staff_comms > 0): ?>
                <span class="comms-count"><?= $unread_staff_comms ?> unread</span>
                <?php endif; ?>
            </h3>
            <div class="comms-container">
                <?php if (!empty($staff_communications)): ?>
                    <?php foreach ($staff_communications as $comm): ?>
                    <div class="comm-item <?= $comm['is_read'] ? '' : 'unread' ?>" data-comm-id="<?= $comm['resource_id'] ?>">
                        <div class="comm-header">
                            <div class="comm-staff-info">
                                <img src="<?= !empty($comm['staff_photo']) ? htmlspecialchars($comm['staff_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($comm['staff_name']) . '&background=random' ?>" 
                                     alt="Staff Photo" class="comm-staff-photo">
                                <div class="comm-staff-details">
                                    <h4><?= htmlspecialchars($comm['staff_name']) ?></h4>
                                </div>
                            </div>
                            <div class="comm-time">
                                <?= date('M j, Y g:i A', strtotime($comm['shared_at'])) ?>
                            </div>
                        </div>
                        <div class="comm-content">
                            <div class="comm-title"><?= htmlspecialchars($comm['resource_title']) ?></div>
                            <?php if (!empty($comm['resource_description'])): ?>
                            <div class="comm-description"><?= htmlspecialchars($comm['resource_description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($comm['resource_url'])): ?>
                            <a href="<?= htmlspecialchars($comm['resource_url']) ?>" target="_blank" class="comm-resource">
                                <i class="fas fa-external-link-alt"></i>
                                View Resource
                            </a>
                            <?php endif; ?>
                            
                            <!-- Reply Section -->
                            <div class="reply-section">
                                <button class="reply-toggle" data-resource-id="<?= $comm['resource_id'] ?>">
                                    <i class="fas fa-reply"></i> Reply to this message
                                </button>
                                
                                <div class="reply-form" id="replyForm-<?= $comm['resource_id'] ?>">
                                    <textarea class="reply-textarea" placeholder="Type your reply here..." id="replyMessage-<?= $comm['resource_id'] ?>"></textarea>
                                    <div class="reply-actions">
                                        <button class="reply-btn reply-cancel" data-resource-id="<?= $comm['resource_id'] ?>">Cancel</button>
                                        <button class="reply-btn reply-submit" data-resource-id="<?= $comm['resource_id'] ?>">Send Reply</button>
                                    </div>
                                </div>
                                
                                <div class="replies-container" id="repliesContainer-<?= $comm['resource_id'] ?>">
                                    <!-- Replies will be loaded here via AJAX -->
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-comms">
                        <i class="fas fa-envelope-open"></i>
                        <p>No messages from staff yet</p>
                        <p style="font-size: 0.9rem; margin-top: 10px;">Staff members will contact you here with important updates and resources.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Custom Success Message -->
    <div class="custom-alert" id="customAlert">
        <div class="custom-alert-icon">
            <i class="fas fa-check"></i>
        </div>
        <div class="custom-alert-content">
            <div class="custom-alert-title" id="customAlertTitle">Success</div>
            <div class="custom-alert-message" id="customAlertMessage">Reply sent successfully!</div>
        </div>
        <button class="custom-alert-close" id="customAlertClose">
            <i class="fas fa-times"></i>
        </button>
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
        
        // Custom Alert Functionality
        const customAlert = document.getElementById('customAlert');
        const customAlertTitle = document.getElementById('customAlertTitle');
        const customAlertMessage = document.getElementById('customAlertMessage');
        const customAlertClose = document.getElementById('customAlertClose');
        
        function showCustomAlert(message, type = 'success') {
            customAlertTitle.textContent = type === 'success' ? 'Success' : 'Error';
            customAlertMessage.textContent = message;
            
            if (type === 'error') {
                customAlert.classList.add('error');
            } else {
                customAlert.classList.remove('error');
            }
            
            customAlert.classList.add('show');
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                hideCustomAlert();
            }, 5000);
        }
        
        function hideCustomAlert() {
            customAlert.classList.remove('show');
        }
        
        customAlertClose.addEventListener('click', hideCustomAlert);
        
        // Mark notifications as read functionality
        document.addEventListener('DOMContentLoaded', function() {
            const markAllReadBtn = document.getElementById('markAllRead');
            const notificationItems = document.querySelectorAll('.notification-link.unread');
            
            // Mark all as read
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Get all unread notification IDs
                    const unreadIds = Array.from(notificationItems).map(item => 
                        item.getAttribute('data-notif-id')
                    );
                    
                    if (unreadIds.length > 0) {
                        // Send AJAX request to mark all as read
                        const formData = new FormData();
                        formData.append('ajax_action', 'mark_notifications_read');
                        formData.append('mark_all', 'true');
                        
                        fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Update UI
                                notificationItems.forEach(item => {
                                    item.classList.remove('unread');
                                });
                                
                                // Update notification count
                                const notificationCount = document.querySelector('.notification-count');
                                if (notificationCount) {
                                    notificationCount.remove();
                                }
                                
                                // Remove mark all button
                                markAllBtn.remove();
                            }
                        })
                        .catch(error => {
                            console.error('Error marking notifications as read:', error);
                        });
                    }
                });
            }
            
            // Mark single notification as read when clicked
            notificationItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (!this.classList.contains('unread')) return;
                    
                    const notifId = this.getAttribute('data-notif-id');
                    
                    // Send AJAX request to mark as read
                    const formData = new FormData();
                    formData.append('ajax_action', 'mark_notifications_read');
                    formData.append('notification_ids[]', notifId);
                    
                    fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI
                            this.classList.remove('unread');
                            
                            // Update notification count
                            const notificationCount = document.querySelector('.notification-count');
                            if (notificationCount) {
                                const newCount = parseInt(notificationCount.textContent) - 1;
                                if (newCount > 0) {
                                    notificationCount.textContent = newCount;
                                } else {
                                    notificationCount.remove();
                                    const markAllBtn = document.getElementById('markAllRead');
                                    if (markAllBtn) markAllBtn.remove();
                                }
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notification as read:', error);
                    });
                });
            });

            // Mark staff communications as read when clicked
            const commItems = document.querySelectorAll('.comm-item.unread');
            commItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (!this.classList.contains('unread')) return;
                    
                    const commId = this.getAttribute('data-comm-id');
                    
                    // Send AJAX request to mark as read
                    const formData = new FormData();
                    formData.append('ajax_action', 'mark_staff_comm_read');
                    formData.append('resource_id', commId);
                    
                    fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI
                            this.classList.remove('unread');
                            
                            // Update unread count
                            const commsCount = document.querySelector('.comms-count');
                            if (commsCount) {
                                const newCount = parseInt(commsCount.textContent) - 1;
                                if (newCount > 0) {
                                    commsCount.textContent = newCount + ' unread';
                                } else {
                                    commsCount.remove();
                                }
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error marking staff communication as read:', error);
                    });
                });
            });
            
            // Reply functionality
            const replyToggles = document.querySelectorAll('.reply-toggle');
            const replyCancelBtns = document.querySelectorAll('.reply-cancel');
            const replySubmitBtns = document.querySelectorAll('.reply-submit');
            
            // Toggle reply form
            replyToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const resourceId = this.getAttribute('data-resource-id');
                    const replyForm = document.getElementById(`replyForm-${resourceId}`);
                    const repliesContainer = document.getElementById(`repliesContainer-${resourceId}`);
                    
                    // Toggle form visibility
                    if (replyForm.style.display === 'block') {
                        replyForm.style.display = 'none';
                    } else {
                        replyForm.style.display = 'block';
                        
                        // Load replies if not already loaded
                        if (repliesContainer.innerHTML.trim() === '') {
                            loadReplies(resourceId);
                        }
                    }
                });
            });
            
            // Cancel reply
            replyCancelBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const resourceId = this.getAttribute('data-resource-id');
                    const replyForm = document.getElementById(`replyForm-${resourceId}`);
                    replyForm.style.display = 'none';
                });
            });
            
            // Submit reply
            replySubmitBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const resourceId = this.getAttribute('data-resource-id');
                    const replyMessage = document.getElementById(`replyMessage-${resourceId}`).value.trim();
                    
                    if (replyMessage === '') {
                        showCustomAlert('Please enter a reply message', 'error');
                        return;
                    }
                    
                    sendReply(resourceId, replyMessage);
                });
            });
            
            // Function to load replies for a resource
            function loadReplies(resourceId) {
                const formData = new FormData();
                formData.append('ajax_action', 'get_replies');
                formData.append('resource_id', resourceId);
                
                fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const repliesContainer = document.getElementById(`repliesContainer-${resourceId}`);
                        
                        if (data.replies.length > 0) {
                            let repliesHtml = '';
                            
                            data.replies.forEach(reply => {
                                const replyTime = new Date(reply.sent_at).toLocaleString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                    year: 'numeric',
                                    hour: 'numeric',
                                    minute: '2-digit',
                                    hour12: true
                                });
                                
                                // Extract just the message content (remove the "Reply to staff message:" prefix)
                                const messageContent = reply.notif_message.replace('Reply to staff message: ', '');
                                
                                repliesHtml += `
                                    <div class="reply-item ${reply.sender_type}" data-reply-id="${reply.notif_id}">
                                        <div class="reply-header">
                                            <div class="reply-sender">${reply.usr_name}</div>
                                            <div class="reply-time">${replyTime}</div>
                                        </div>
                                        <div class="reply-message">${messageContent}</div>
                                        <div class="reply-actions">
                                            <button class="delete-reply-btn" data-reply-id="${reply.notif_id}">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                `;
                            });
                            
                            repliesContainer.innerHTML = repliesHtml;
                            
                            // Add event listeners to delete buttons - MODIFIED FOR AUTO DELETE
                            const deleteButtons = repliesContainer.querySelectorAll('.delete-reply-btn');
                            deleteButtons.forEach(btn => {
                                btn.addEventListener('click', function() {
                                    const replyId = this.getAttribute('data-reply-id');
                                    deleteReply(replyId);
                                });
                            });
                        } else {
                            repliesContainer.innerHTML = '<div class="no-replies">No replies yet</div>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading replies:', error);
                });
            }
            
            // Function to send a reply
            function sendReply(resourceId, message) {
                const formData = new FormData();
                formData.append('ajax_action', 'send_reply');
                formData.append('resource_id', resourceId);
                formData.append('reply_message', message);
                
                fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Clear the textarea
                        document.getElementById(`replyMessage-${resourceId}`).value = '';
                        
                        // Hide the form
                        document.getElementById(`replyForm-${resourceId}`).style.display = 'none';
                        
                        // Reload replies to show the new one
                        loadReplies(resourceId);
                        
                        // Show success message using custom alert
                        showCustomAlert('Reply sent successfully!');
                    } else {
                        showCustomAlert('Error sending reply: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error sending reply:', error);
                    showCustomAlert('Error sending reply. Please try again.', 'error');
                });
            }
            
            // Function to delete a reply - MODIFIED FOR AUTO DELETE
            function deleteReply(replyId) {
                const formData = new FormData();
                formData.append('ajax_action', 'delete_reply');
                formData.append('reply_id', replyId);
                
                fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove the reply from the UI
                        const replyElement = document.querySelector(`[data-reply-id="${replyId}"]`);
                        if (replyElement) {
                            replyElement.remove();
                        }
                        
                        // Show success message
                        showCustomAlert('Reply deleted successfully!');
                        
                        // If no replies left, show the no replies message
                        const repliesContainer = document.querySelector('.replies-container');
                        if (repliesContainer && repliesContainer.querySelectorAll('.reply-item').length === 0) {
                            repliesContainer.innerHTML = '<div class="no-replies">No replies yet</div>';
                        }
                    } else {
                        showCustomAlert('Error deleting reply: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error deleting reply:', error);
                    showCustomAlert('Error deleting reply. Please try again.', 'error');
                });
            }
        });
        
        // Enhanced Charts initialization with professional styling
        document.addEventListener('DOMContentLoaded', function() {
            // Enhanced Job Status Chart (Doughnut)
            const jobStatusCtx = document.getElementById('jobStatusChart').getContext('2d');
            new Chart(jobStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Active', 'Pending', 'Closed', 'Rejected'],
                    datasets: [{
                        data: <?= json_encode($job_status_data) ?>,
                        backgroundColor: [
                            'rgba(31, 122, 17, 0.9)',
                            'rgba(255, 167, 0, 0.9)',
                            'rgba(100, 100, 100, 0.9)',
                            'rgba(211, 47, 47, 0.9)'
                        ],
                        borderColor: [
                            'rgba(31, 122, 17, 1)',
                            'rgba(255, 167, 0, 1)',
                            'rgba(100, 100, 100, 1)',
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
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const percentage = <?= $total_jobs ?> > 0 ? Math.round((value / <?= $total_jobs ?>) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
            
            // Enhanced Application Status Chart (Doughnut)
            const applicationStatusCtx = document.getElementById('applicationStatusChart').getContext('2d');
            new Chart(applicationStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pending', 'Reviewed', 'Qualified', 'Hired'],
                    datasets: [{
                        data: <?= json_encode($application_status_data) ?>,
                        backgroundColor: [
                            'rgba(255, 167, 0, 0.9)',
                            'rgba(23, 162, 184, 0.9)',
                            'rgba(0, 123, 255, 0.9)',
                            'rgba(31, 122, 17, 0.9)'
                        ],
                        borderColor: [
                            'rgba(255, 167, 0, 1)',
                            'rgba(23, 162, 184, 1)',
                            'rgba(0, 123, 255, 1)',
                            'rgba(31, 122, 17, 1)'
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
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const percentage = <?= $total_applications ?> > 0 ? Math.round((value / <?= $total_applications ?>) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
            
            // Enhanced Profile Views Chart (Line) - CHANGED TO 30 DAYS
            const profileViewsCtx = document.getElementById('profileViewsChart').getContext('2d');
            new Chart(profileViewsCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($profile_views_labels) ?>,
                    datasets: [{
                        label: 'Profile Views',
                        data: <?= json_encode($profile_views_data) ?>,
                        backgroundColor: 'rgba(110, 3, 3, 0.1)',
                        borderColor: 'rgba(110, 3, 3, 1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(255, 167, 0, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(110, 3, 3, 1)',
                        pointHoverBorderWidth: 3
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
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    return `Profile Views: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                precision: 0,
                                font: {
                                    size: 10
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 9
                                },
                                maxTicksLimit: 15,
                                callback: function(value, index, values) {
                                    // Show every 3rd label for better readability
                                    return index % 3 === 0 ? this.getLabelForValue(value) : '';
                                }
                            }
                        }
                    }
                }
            });
            
            // Enhanced Applications Over Time Chart (Line) - CHANGED TO 30 DAYS
            const applicationsCtx = document.getElementById('applicationsChart').getContext('2d');
            new Chart(applicationsCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($applications_labels) ?>,
                    datasets: [{
                        label: 'Applications',
                        data: <?= json_encode($applications_data) ?>,
                        backgroundColor: 'rgba(255, 167, 0, 0.1)',
                        borderColor: 'rgba(255, 167, 0, 1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(110, 3, 3, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(255, 167, 0, 1)',
                        pointHoverBorderWidth: 3
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
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    return `Applications: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                precision: 0,
                                font: {
                                    size: 10
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 9
                                },
                                maxTicksLimit: 15,
                                callback: function(value, index, values) {
                                    // Show every 3rd label for better readability
                                    return index % 3 === 0 ? this.getLabelForValue(value) : '';
                                }
                            }
                        }
                    }
                }
            });
            
            // MODIFIED: Enhanced Top ACTIVE Jobs Chart (Horizontal Bar)
            const topJobsCtx = document.getElementById('topJobsChart').getContext('2d');
            
            // Generate professional gradient colors
            const generateGradientColors = (ctx, count) => {
                const gradients = [];
                for (let i = 0; i < count; i++) {
                    const gradient = ctx.createLinearGradient(0, 0, 400, 0);
                    const opacity = 0.7 + (i * 0.3 / count);
                    gradient.addColorStop(0, `rgba(110, 3, 3, ${opacity})`);
                    gradient.addColorStop(1, `rgba(255, 167, 0, ${opacity})`);
                    gradients.push(gradient);
                }
                return gradients.reverse();
            };
            
            new Chart(topJobsCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($top_jobs_labels) ?>,
                    datasets: [{
                        label: 'Applications',
                        data: <?= json_encode($top_jobs_data) ?>,
                        backgroundColor: generateGradientColors(topJobsCtx, <?= count($top_jobs_labels) ?>),
                        borderColor: 'rgba(110, 3, 3, 1)',
                        borderWidth: 1,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    indexAxis: 'y',
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
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    const percentage = <?= json_encode($top_jobs_percentages) ?>[context.dataIndex];
                                    return `Applications: ${context.raw} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                precision: 0,
                                font: {
                                    size: 10
                                }
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });
        });
    </script>
</body>
</html>