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
    
    $employer_id = $_SESSION['user_id'];
    
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
        
        // Handle monthly report generation
        if ($_POST['ajax_action'] === 'generate_monthly_report') {
            $month = $_POST['month'];
            $year = $_POST['year'];
            
            // Get month name
            $month_name = date('F', mktime(0, 0, 0, $month, 1));
            
            // Calculate start and end dates
            $start_date = "$year-$month-01";
            $end_date = "$year-$month-" . date('t', strtotime($start_date));
            
            // Get total jobs posted in this month
            $jobs_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_jobs,
                    SUM(CASE WHEN job_status = 'active' THEN 1 ELSE 0 END) as active_jobs,
                    SUM(CASE WHEN job_status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                    SUM(CASE WHEN job_status = 'closed' THEN 1 ELSE 0 END) as closed_jobs
                FROM jobs 
                WHERE job_emp_usr_id = :employer_id
                AND DATE(job_created_at) BETWEEN :start_date AND :end_date
            ");
            $jobs_stmt->bindParam(':employer_id', $employer_id);
            $jobs_stmt->bindParam(':start_date', $start_date);
            $jobs_stmt->bindParam(':end_date', $end_date);
            $jobs_stmt->execute();
            $job_stats = $jobs_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get application statistics for this month
            $applications_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_applications,
                    SUM(CASE WHEN app_status = 'pending' THEN 1 ELSE 0 END) as pending_applications,
                    SUM(CASE WHEN app_status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_applications,
                    SUM(CASE WHEN app_status = 'qualified' THEN 1 ELSE 0 END) as qualified_applications,
                    SUM(CASE WHEN app_status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted_applications,
                    SUM(CASE WHEN app_status = 'hired' THEN 1 ELSE 0 END) as hired_applications,
                    SUM(CASE WHEN app_status = 'rejected' THEN 1 ELSE 0 END) as rejected_applications
                FROM applications a
                JOIN jobs j ON a.app_job_id = j.job_id
                WHERE j.job_emp_usr_id = :employer_id
                AND DATE(a.app_applied_at) BETWEEN :start_date AND :end_date
            ");
            $applications_stmt->bindParam(':employer_id', $employer_id);
            $applications_stmt->bindParam(':start_date', $start_date);
            $applications_stmt->bindParam(':end_date', $end_date);
            $applications_stmt->execute();
            $application_stats = $applications_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get top 5 jobs by applications
            $top_jobs_stmt = $conn->prepare("
                SELECT 
                    j.job_title,
                    COUNT(a.app_id) as application_count,
                    SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hires
                FROM jobs j
                LEFT JOIN applications a ON j.job_id = a.app_job_id
                WHERE j.job_emp_usr_id = :employer_id
                AND DATE(j.job_created_at) BETWEEN :start_date AND :end_date
                GROUP BY j.job_id
                ORDER BY application_count DESC
                LIMIT 5
            ");
            $top_jobs_stmt->bindParam(':employer_id', $employer_id);
            $top_jobs_stmt->bindParam(':start_date', $start_date);
            $top_jobs_stmt->bindParam(':end_date', $end_date);
            $top_jobs_stmt->execute();
            $top_jobs = $top_jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate hire rates for top jobs
            foreach ($top_jobs as &$job) {
                $job['hire_rate'] = $job['application_count'] > 0 ? 
                    min(round(($job['hires'] / $job['application_count']) * 100, 1), 100) : 0;
            }
            
            // Get monthly trends (last 6 months)
            $trends_stmt = $conn->prepare("
                SELECT 
                    DATE_FORMAT(j.job_created_at, '%Y-%m') as month,
                    COUNT(DISTINCT j.job_id) as jobs_posted,
                    COUNT(DISTINCT a.app_id) as applications_received
                FROM jobs j
                LEFT JOIN applications a ON j.job_id = a.app_job_id
                WHERE j.job_emp_usr_id = :employer_id
                AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(j.job_created_at, '%Y-%m')
                ORDER BY month DESC
            ");
            $trends_stmt->bindParam(':employer_id', $employer_id);
            $trends_stmt->execute();
            $monthly_trends = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Prepare the report data
            $report_data = [
                'month' => $month_name,
                'year' => $year,
                'job_stats' => $job_stats,
                'application_stats' => $application_stats,
                'top_jobs' => $top_jobs,
                'monthly_trends' => $monthly_trends
            ];
            
            echo json_encode([
                'success' => true,
                'report' => $report_data
            ]);
            exit();
        }
        
        // Handle PDF download notification
        if ($_POST['ajax_action'] === 'create_pdf_notification') {
            $month = $_POST['month'];
            $year = $_POST['year'];
            $file_name = $_POST['file_name'];
            
            // Get month name
            $month_name = date('F', mktime(0, 0, 0, $month, 1));
            
            // Create notification message
            $notification_message = "PDF report '" . $file_name . "' has been downloaded successfully!";
            
            // Insert notification into database
            $insert_notif_stmt = $conn->prepare("
                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                VALUES (:employer_id, :message, 'system', 0, NOW())
            ");
            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
            $insert_notif_stmt->bindParam(':message', $notification_message);
            $insert_notif_stmt->execute();
            
            // Get the inserted notification for response
            $notif_id = $conn->lastInsertId();
            
            // Get notification details for response
            $notif_stmt = $conn->prepare("
                SELECT 
                    n.notif_id,
                    n.notif_usr_id,
                    n.notif_message,
                    n.notif_type,
                    n.notif_is_read,
                    n.notif_created_at,
                    'fas fa-file-pdf' as notif_icon,
                    '#d32f2f' as notif_color
                FROM notifications n
                WHERE n.notif_id = :notif_id
            ");
            $notif_stmt->bindParam(':notif_id', $notif_id);
            $notif_stmt->execute();
            $notification = $notif_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'notification' => $notification,
                'message' => 'Notification created successfully'
            ]);
            exit();
        }
    }
    
    // Get employer data
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
        header("Location: employer_profile.php?setup=1");
        exit();
    }
    
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
        // Get job statistics for weekly summary
        $jobs_stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN j.job_status = 'active' THEN 1 ELSE 0 END) as active_jobs,
                SUM(CASE WHEN j.job_status = 'pending' THEN 1 ELSE 0 END) as pending_jobs
            FROM jobs j
            WHERE j.job_emp_usr_id = :employer_id
        ");
        $jobs_stmt->bindParam(':employer_id', $employer_id);
        $jobs_stmt->execute();
        $job_stats = $jobs_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get application statistics for weekly summary
        $apps_stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_applications,
                SUM(CASE WHEN a.app_status = 'pending' THEN 1 ELSE 0 END) as pending_applications
            FROM applications a
            JOIN jobs j ON a.app_job_id = j.job_id
            WHERE j.job_emp_usr_id = :employer_id
        ");
        $apps_stmt->bindParam(':employer_id', $employer_id);
        $apps_stmt->execute();
        $app_stats = $apps_stmt->fetch(PDO::FETCH_ASSOC);
        
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
                ELSE 'fas fa-bell'
            END as notif_icon,
            CASE 
                WHEN n.notif_type = 'application' THEN '#0044ff'
                WHEN n.notif_type = 'job_status' THEN '#1f7a11'
                WHEN n.notif_type = 'profile_views' THEN '#6a0dad'
                WHEN n.notif_type = 'system' THEN '#ffa700'
                WHEN n.notif_type = 'application_update' THEN '#d32f2f'
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
    
    // ===== PROFESSIONAL HR ANALYTICS METRICS =====
    
    // 1. RECRUITMENT FUNNEL METRICS
    $funnel_metrics_stmt = $conn->prepare("
        SELECT 
            -- Total views from graduates
            (SELECT COUNT(*) FROM employer_profile_views WHERE view_emp_usr_id = :employer_id) as total_profile_views,
            
            -- Total applications
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id) as total_applications,
            
            -- Applications by status
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id AND a.app_status = 'pending') as pending_applications,
            
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id AND a.app_status = 'reviewed') as reviewed_applications,
            
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id AND a.app_status = 'shortlisted') as shortlisted_applications,
            
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id AND a.app_status = 'qualified') as qualified_applications,
            
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id AND a.app_status = 'hired') as hired_applications,
            
            (SELECT COUNT(*) FROM applications a 
             JOIN jobs j ON a.app_job_id = j.job_id 
             WHERE j.job_emp_usr_id = :employer_id AND a.app_status = 'rejected') as rejected_applications,
            
            -- Active job postings
            (SELECT COUNT(*) FROM jobs WHERE job_emp_usr_id = :employer_id AND job_status = 'active') as active_jobs,
            
            -- Total unique graduate viewers
            (SELECT COUNT(DISTINCT view_grad_usr_id) FROM employer_profile_views WHERE view_emp_usr_id = :employer_id) as unique_viewers
    ");
    $funnel_metrics_stmt->bindParam(':employer_id', $employer_id);
    $funnel_metrics_stmt->execute();
    $funnel_metrics = $funnel_metrics_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate conversion rates - FIXED: Ensure percentages don't exceed 100%
    $view_to_apply_rate = $funnel_metrics['unique_viewers'] > 0 ? 
        min(round(($funnel_metrics['total_applications'] / $funnel_metrics['unique_viewers']) * 100, 1), 100) : 0;
    
    $apply_to_hire_rate = $funnel_metrics['total_applications'] > 0 ? 
        min(round(($funnel_metrics['hired_applications'] / $funnel_metrics['total_applications']) * 100, 1), 100) : 0;
    
    // 2. JOB PERFORMANCE ANALYTICS
    $job_performance_stmt = $conn->prepare("
        SELECT 
            j.job_id,
            j.job_title,
            j.job_type,
            j.job_location,
            j.job_created_at,
            COUNT(a.app_id) as total_applications,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hires,
            SUM(CASE WHEN a.app_status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
            SUM(CASE WHEN a.app_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            (SELECT COUNT(*) FROM employer_profile_views WHERE view_emp_usr_id = :employer_id) as profile_views
        FROM jobs j
        LEFT JOIN applications a ON j.job_id = a.app_job_id
        WHERE j.job_emp_usr_id = :employer_id
        GROUP BY j.job_id
        ORDER BY total_applications DESC
    ");
    $job_performance_stmt->bindParam(':employer_id', $employer_id);
    $job_performance_stmt->execute();
    $job_performance = $job_performance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate hire rates per job - FIXED: Ensure percentages don't exceed 100%
    foreach ($job_performance as &$job) {
        $job['hire_rate'] = $job['total_applications'] > 0 ? 
            min(round(($job['hires'] / $job['total_applications']) * 100, 1), 100) : 0;
    }
    
    // 3. APPLICATION TRENDS (Last 30 days)
    $application_trends_stmt = $conn->prepare("
        SELECT 
            DATE(a.app_applied_at) as application_date,
            COUNT(*) as daily_applications,
            j.job_title
        FROM applications a
        JOIN jobs j ON a.app_job_id = j.job_id
        WHERE j.job_emp_usr_id = :employer_id
        AND a.app_applied_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY application_date, j.job_id
        ORDER BY application_date DESC
    ");
    $application_trends_stmt->bindParam(':employer_id', $employer_id);
    $application_trends_stmt->execute();
    $application_trends = $application_trends_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. SKILLS DEMAND ANALYSIS
    $skills_analysis_stmt = $conn->prepare("
        SELECT 
            j.job_skills,
            COUNT(a.app_id) as application_count,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as successful_hires
        FROM jobs j
        LEFT JOIN applications a ON j.job_id = a.app_job_id
        WHERE j.job_emp_usr_id = :employer_id
        AND j.job_skills IS NOT NULL
        AND j.job_skills != ''
        GROUP BY j.job_skills
        ORDER BY application_count DESC
        LIMIT 10
    ");
    $skills_analysis_stmt->bindParam(':employer_id', $employer_id);
    $skills_analysis_stmt->execute();
    $skills_analysis = $skills_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process skills data
    $skills_data = [];
    foreach ($skills_analysis as $skill_group) {
        $skills = explode(',', $skill_group['job_skills']);
        foreach ($skills as $skill) {
            $skill = trim($skill);
            if (!empty($skill)) {
                if (!isset($skills_data[$skill])) {
                    $skills_data[$skill] = [
                        'application_count' => 0,
                        'successful_hires' => 0
                    ];
                }
                $skills_data[$skill]['application_count'] += $skill_group['application_count'];
                $skills_data[$skill]['successful_hires'] += $skill_group['successful_hires'];
            }
        }
    }
    
    // Sort by application count and take top 8
    uasort($skills_data, function($a, $b) {
        return $b['application_count'] - $a['application_count'];
    });
    $top_skills = array_slice($skills_data, 0, 8, true);
    
    // 5. CANDIDATE SOURCE ANALYSIS (Profile Views) - MODIFIED: Removed grad_job_preference
    $candidate_source_stmt = $conn->prepare("
        SELECT 
            u.usr_name as graduate_name,
            g.grad_degree,
            g.grad_year_graduated,
            v.view_viewed_at,
            (SELECT COUNT(*) FROM applications a 
             WHERE a.app_grad_usr_id = v.view_grad_usr_id 
             AND EXISTS (SELECT 1 FROM jobs j WHERE j.job_id = a.app_job_id AND j.job_emp_usr_id = :employer_id)) as has_applied
        FROM employer_profile_views v
        JOIN users u ON v.view_grad_usr_id = u.usr_id
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id
        WHERE v.view_emp_usr_id = :employer_id
        ORDER BY v.view_viewed_at DESC
        LIMIT 15
    ");
    $candidate_source_stmt->bindParam(':employer_id', $employer_id);
    $candidate_source_stmt->execute();
    $candidate_sources = $candidate_source_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare data for charts
    $job_titles = [];
    $job_applications = [];
    $job_hire_rates = [];
    
    foreach ($job_performance as $job) {
        $job_titles[] = $job['job_title'];
        $job_applications[] = $job['total_applications'];
        $job_hire_rates[] = $job['hire_rate'];
    }
    
    $skill_names = [];
    $skill_applications = [];
    $skill_success_rates = [];
    
    foreach ($top_skills as $skill => $data) {
        $skill_names[] = $skill;
        $skill_applications[] = $data['application_count'];
        $success_rate = $data['application_count'] > 0 ? 
            min(round(($data['successful_hires'] / $data['application_count']) * 100, 1), 100) : 0;
        $skill_success_rates[] = $success_rate;
    }
    
    // Application status distribution
    $application_status_data = [
        $funnel_metrics['pending_applications'],
        $funnel_metrics['reviewed_applications'], 
        $funnel_metrics['shortlisted_applications'],
        $funnel_metrics['qualified_applications'],
        $funnel_metrics['hired_applications'],
        $funnel_metrics['rejected_applications']
    ];
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Employer Data Analytics</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Add jsPDF library for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
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

        /* Monthly Report Button */
        .monthly-report-btn {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 12px 25px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 10px rgba(110, 3, 3, 0.2);
        }
        
        .monthly-report-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(110, 3, 3, 0.3);
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
            height: 300px;
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
        
        /* KPI Cards - Enhanced Single row layout */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .kpi-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            transition: all 0.3s;
            border-top: 4px solid var(--secondary-color);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .kpi-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .kpi-label {
            font-size: 1rem;
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-trend {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
        }
        
        /* Chart Containers */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Job Performance in one row */
        .job-performance-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            transition: all 0.3s;
            border-top: 4px solid var(--secondary-color);
        }
        
        .chart-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .data-table th,
        .data-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .data-table th {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
        }
        
        .data-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-reviewed { background: #cce7ff; color: #004085; }
        .status-shortlisted { background: #d1ecf1; color: #0c5460; }
        .status-qualified { background: #d4edda; color: #155724; }
        .status-hired { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        /* Enhanced Tabs */
        .analytics-tabs {
            display: flex;
            margin-bottom: 25px;
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
            font-size: 0.9rem;
            font-weight: 500;
            color: #666;
        }
        
        .tab:hover {
            background-color: #f9f9f9;
            color: var(--primary-color);
        }
        
        .tab.active {
            border-bottom: 3px solid var(--primary-color);
            background-color: #fff5f5;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Monthly Report Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .report-modal {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .modal-overlay.active .report-modal {
            transform: translateY(0);
        }
        
        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .modal-header h3 {
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
            flex: 1;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .close-modal:hover {
            transform: scale(1.1);
        }
        
        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .btn-modal {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-modal-cancel {
            background: linear-gradient(135deg, #e0e0e0, #d0d0d0);
            color: #333;
        }
        
        .btn-modal-cancel:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-modal-confirm {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
        }
        
        .btn-modal-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 68, 255, 0.3);
        }
        
        .btn-modal-download {
            background: linear-gradient(135deg, var(--green), #1a6b0a);
            color: white;
        }
        
        .btn-modal-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(31, 122, 17, 0.3);
        }
        
        /* Report Content Styles */
        .report-content {
            background: white;
            padding: 20px;
            border-radius: 10px;
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
        }
        
        .report-title {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .report-subtitle {
            color: #666;
            font-size: 1rem;
        }
        
        .report-section {
            margin-bottom: 25px;
        }
        
        .section-title {
            color: var(--primary-color);
            font-size: 1.2rem;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }
        
        .stats-grid-report {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card-report {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid var(--secondary-color);
        }
        
        .stat-value-report {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label-report {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }
        
        .application-status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .status-card-report {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #eee;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        
        .status-value-report {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        
        .status-label-report {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .status-description-report {
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Loading Spinner for PDF Generation */
        .pdf-loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: white;
        }
        
        .pdf-loading.active {
            display: flex;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--secondary-color);
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .job-performance-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid-report {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .application-status-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .job-performance-grid {
                grid-template-columns: 1fr;
            }
            
            .dropdown {
                width: 90%;
                right: 5%;
            }
            
            .notification-dropdown {
                width: 350px;
                right: -100px;
            }
            
            .analytics-tabs {
                flex-direction: column;
            }
            
            .tab {
                padding: 15px;
            }
            
            .stats-grid-report {
                grid-template-columns: 1fr;
            }
            
            .application-status-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -140px;
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
                    <a href="employer_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="employer_jobs.php">
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
                    <a href="employer_analytics.php" class="active">
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
            <p>Comprehensive insights into your hiring performance and candidate pipeline</p>
        </div>

        <!-- Page Header with Monthly Report Button -->
        <div class="page-header">
            <h1 class="page-title">Data Analytics</h1>
            <button class="monthly-report-btn" id="monthlyReportBtn">
                <i class="fas fa-file-alt"></i> Monthly Reports
            </button>
        </div>
        
        <!-- Key Performance Indicators - Enhanced Single row layout -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-value"><?= $funnel_metrics['total_applications'] ?></div>
                <div class="kpi-label">TOTAL APPLICATIONS</div>
                <div class="kpi-trend">+12% this month</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?= $funnel_metrics['hired_applications'] ?></div>
                <div class="kpi-label">SUCCESSFUL HIRES</div>
                <div class="kpi-trend">Hire Rate: <?= $apply_to_hire_rate ?>%</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?= $funnel_metrics['unique_viewers'] ?></div>
                <div class="kpi-label">ALUMNI VIEWERS</div>
                <div class="kpi-trend">Conversion: <?= $view_to_apply_rate ?>%</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?= $funnel_metrics['active_jobs'] ?></div>
                <div class="kpi-label">ACTIVE JOB POSTINGS</div>
                <div class="kpi-trend"><?= count($job_performance) ?> total positions</div>
            </div>
        </div>

        <!-- Enhanced Analytics Tabs -->
        <div class="analytics-tabs">
            <div class="tab active" data-tab="recruitment-funnel">Recruitment Funnel</div>
            <div class="tab" data-tab="job-performance">Job Performance</div>
            <div class="tab" data-tab="skills-analysis">Skills Analysis</div>
            <div class="tab" data-tab="candidate-sources">Candidate Sources</div>
        </div>

        <!-- Recruitment Funnel Tab -->
        <div class="tab-content active" id="recruitment-funnel">
            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">APPLICATION PIPELINE</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="pipelineChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">APPLICATION STATUS DISTRIBUTION</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Job Performance Tab - Enhanced Single row layout -->
        <div class="tab-content" id="job-performance">
            <div class="job-performance-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">JOB PERFORMANCE OVERVIEW</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="jobPerformanceChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">TOP PERFORMING JOBS</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Job Title</th>
                                <th>Applications</th>
                                <th>Hires</th>
                                <th>Hire Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($job_performance, 0, 5) as $job): ?>
                            <tr>
                                <td><?= htmlspecialchars($job['job_title']) ?></td>
                                <td><?= $job['total_applications'] ?></td>
                                <td><?= $job['hires'] ?></td>
                                <td><strong><?= $job['hire_rate'] ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Skills Analysis Tab -->
        <div class="tab-content" id="skills-analysis">
            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">TOP IN-DEMAND SKILLS</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="skillsChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">SKILL SUCCESS RATES</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="skillsSuccessChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Candidate Sources Tab - MODIFIED: Removed Job Preference column -->
        <div class="tab-content" id="candidate-sources">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">RECENT PROFILE VISITORS</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Alumni Name</th>
                            <th>Degree</th>
                            <th>Graduation Year</th>
                            <th>Viewed On</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidate_sources as $candidate): ?>
                        <tr>
                            <td><?= htmlspecialchars($candidate['graduate_name']) ?></td>
                            <td><?= htmlspecialchars($candidate['grad_degree']) ?></td>
                            <td><?= htmlspecialchars($candidate['grad_year_graduated']) ?></td>
                            <td><?= date('M j, Y', strtotime($candidate['view_viewed_at'])) ?></td>
                            <td>
                                <span class="status-badge <?= $candidate['has_applied'] ? 'status-qualified' : 'status-pending' ?>">
                                    <?= $candidate['has_applied'] ? 'Applied' : 'Viewed' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PDF Loading Spinner -->
    <div class="pdf-loading" id="pdfLoading">
        <div class="spinner"></div>
        <p>Generating PDF Report...</p>
        <p>Please wait a moment</p>
    </div>

    <!-- Monthly Report Modal -->
    <div class="modal-overlay" id="monthlyReportModal">
        <div class="report-modal">
            <div class="modal-header">
                <i class="fas fa-file-alt"></i>
                <h3>Monthly Recruitment Report</h3>
                <button class="close-modal" onclick="hideModal('monthlyReportModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="report-content" id="reportContent">
                    <!-- Report content will be loaded here via AJAX -->
                    <div class="report-header">
                        <h2 class="report-title">Select a Month to Generate Report</h2>
                        <p class="report-subtitle">Choose a month and year to view detailed recruitment statistics</p>
                    </div>
                    
                    <div class="report-section">
                        <div class="section-title">Select Report Period</div>
                        <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <label for="reportMonth" style="display: block; margin-bottom: 8px; font-weight: 500;">Month</label>
                                <select id="reportMonth" class="form-select" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                </select>
                            </div>
                            <div style="flex: 1;">
                                <label for="reportYear" style="display: block; margin-bottom: 8px; font-weight: 500;">Year</label>
                                <select id="reportYear" class="form-select" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                    <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <button id="generateReportBtn" class="btn-modal btn-modal-confirm" style="width: 100%;">
                            <i class="fas fa-chart-bar"></i> Generate Monthly Report
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-cancel" onclick="hideModal('monthlyReportModal')">Close</button>
                <button class="btn-modal btn-modal-download" id="downloadReportBtn" style="display: none;">
                    <i class="fas fa-download"></i> Download PDF
                </button>
            </div>
        </div>
    </div>

    <script>
        // Store employer data in JavaScript for PDF generation
        const employerData = {
            name: "<?= addslashes($employer['usr_name']) ?>",
            company: "<?= addslashes($employer['emp_company_name']) ?>",
            email: "<?= addslashes($employer['usr_email']) ?>"
        };

        // Enhanced Charts Configuration with professional styling
        const pipelineChart = new Chart(document.getElementById('pipelineChart'), {
            type: 'bar',
            data: {
                labels: ['Profile Views', 'Applications', 'Shortlisted', 'Hired'],
                datasets: [{
                    label: 'Recruitment Pipeline',
                    data: [
                        <?= $funnel_metrics['total_profile_views'] ?>,
                        <?= $funnel_metrics['total_applications'] ?>,
                        <?= $funnel_metrics['shortlisted_applications'] ?>,
                        <?= $funnel_metrics['hired_applications'] ?>
                    ],
                    backgroundColor: [
                        'rgba(110, 3, 3, 0.8)',
                        'rgba(255, 167, 0, 0.8)',
                        'rgba(0, 123, 255, 0.8)',
                        'rgba(40, 167, 69, 0.8)'
                    ],
                    borderColor: [
                        'rgba(110, 3, 3, 1)',
                        'rgba(255, 167, 0, 1)',
                        'rgba(0, 123, 255, 1)',
                        'rgba(40, 167, 69, 1)'
                    ],
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Candidates',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
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
                                const value = context.raw;
                                const total = <?= $funnel_metrics['total_profile_views'] ?>;
                                const percentage = total > 0 ? Math.min((value / total) * 100, 100).toFixed(1) : 0;
                                return `${context.label}: ${value} (${percentage}%)`;
                            }
                        }
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });

        const statusChart = new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Reviewed', 'Shortlisted', 'Qualified', 'Hired', 'Rejected'],
                datasets: [{
                    data: <?= json_encode($application_status_data) ?>,
                    backgroundColor: [
                        'rgba(255, 193, 7, 0.9)',
                        'rgba(23, 162, 184, 0.9)',
                        'rgba(111, 66, 193, 0.9)',
                        'rgba(0, 123, 255, 0.9)',
                        'rgba(40, 167, 69, 0.9)',
                        'rgba(220, 53, 69, 0.9)'
                    ],
                    borderColor: [
                        'rgba(255, 193, 7, 1)',
                        'rgba(23, 162, 184, 1)',
                        'rgba(111, 66, 193, 1)',
                        'rgba(0, 123, 255, 1)',
                        'rgba(40, 167, 69, 1)',
                        'rgba(220, 53, 69, 1)'
                    ],
                    borderWidth: 2,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
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
                                const value = context.raw;
                                const total = <?= $funnel_metrics['total_applications'] ?>;
                                const percentage = total > 0 ? Math.min((value / total) * 100, 100).toFixed(1) : 0;
                                return `${context.label}: ${value} (${percentage}%)`;
                            }
                        }
                    },
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
                    }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });

        const jobPerformanceChart = new Chart(document.getElementById('jobPerformanceChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($job_titles) ?>,
                datasets: [
                    {
                        label: 'Applications',
                        data: <?= json_encode($job_applications) ?>,
                        backgroundColor: 'rgba(110, 3, 3, 0.8)',
                        borderColor: 'rgba(110, 3, 3, 1)',
                        borderWidth: 2,
                        borderRadius: 4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Hire Rate %',
                        data: <?= json_encode($job_hire_rates) ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.8)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2,
                        type: 'line',
                        yAxisID: 'y1',
                        tension: 0.4,
                        pointBackgroundColor: 'rgba(40, 167, 69, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Applications',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Hire Rate (%)',
                            font: {
                                weight: 'bold'
                            }
                        },
                        max: 100,
                        grid: {
                            drawOnChartArea: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
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
                                if (context.dataset.label === 'Hire Rate %') {
                                    return `Hire Rate: ${context.raw}%`;
                                }
                                return `${context.dataset.label}: ${context.raw}`;
                            }
                        }
                    }
                }
            }
        });

        const skillsChart = new Chart(document.getElementById('skillsChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($skill_names) ?>,
                datasets: [{
                    label: 'Applications per Skill',
                    data: <?= json_encode($skill_applications) ?>,
                    backgroundColor: 'rgba(255, 167, 0, 0.8)',
                    borderColor: 'rgba(255, 167, 0, 1)',
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Applications',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
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
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });

        const skillsSuccessChart = new Chart(document.getElementById('skillsSuccessChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($skill_names) ?>,
                datasets: [{
                    label: 'Success Rate %',
                    data: <?= json_encode($skill_success_rates) ?>,
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Success Rate (%)',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
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
                                return `Success Rate: ${context.raw}%`;
                            }
                        }
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Enhanced Tab Functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab and corresponding content
                this.classList.add('active');
                document.getElementById(this.dataset.tab).classList.add('active');
            });
        });

        // Enhanced Dropdown Functionality
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
                                markAllReadBtn.remove();
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
        });
        
        // Monthly Report Functionality
        const monthlyReportBtn = document.getElementById('monthlyReportBtn');
        const monthlyReportModal = document.getElementById('monthlyReportModal');
        const generateReportBtn = document.getElementById('generateReportBtn');
        const downloadReportBtn = document.getElementById('downloadReportBtn');
        const pdfLoading = document.getElementById('pdfLoading');
        const reportMonth = document.getElementById('reportMonth');
        const reportYear = document.getElementById('reportYear');
        
        // Store current report data for PDF generation
        let currentReportData = null;
        
        // Show monthly report modal
        monthlyReportBtn.addEventListener('click', function() {
            showModal('monthlyReportModal');
        });
        
        // Generate monthly report
        generateReportBtn.addEventListener('click', function() {
            const month = reportMonth.value;
            const year = reportYear.value;
            
            // Show loading state
            generateReportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating Report...';
            generateReportBtn.disabled = true;
            
            // Send AJAX request to generate report
            const formData = new FormData();
            formData.append('ajax_action', 'generate_monthly_report');
            formData.append('month', month);
            formData.append('year', year);
            
            fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentReportData = data.report;
                    displayMonthlyReport(data.report);
                    downloadReportBtn.style.display = 'inline-block';
                } else {
                    alert('Error generating report. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error generating report:', error);
                alert('Error generating report. Please try again.');
            })
            .finally(() => {
                // Reset button state
                generateReportBtn.innerHTML = '<i class="fas fa-chart-bar"></i> Generate Monthly Report';
                generateReportBtn.disabled = false;
            });
        });
        
        // Download PDF report
        downloadReportBtn.addEventListener('click', function() {
            if (currentReportData) {
                showPdfLoading();
                setTimeout(() => {
                    generatePDFReport(currentReportData);
                }, 500);
            }
        });
        
        // Display monthly report
        function displayMonthlyReport(report) {
            const monthName = report.month;
            const year = report.year;
            const jobStats = report.job_stats;
            const appStats = report.application_stats;
            const topJobs = report.top_jobs;
            const monthlyTrends = report.monthly_trends;
            
            // Format numbers with commas
            function formatNumber(num) {
                return num ? num.toLocaleString() : '0';
            }
            
            // Calculate percentages
            const pendingRate = appStats.total_applications > 0 ? 
                Math.min((appStats.pending_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
            const reviewedRate = appStats.total_applications > 0 ? 
                Math.min((appStats.reviewed_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
            const qualifiedRate = appStats.total_applications > 0 ? 
                Math.min((appStats.qualified_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
            const shortlistedRate = appStats.total_applications > 0 ? 
                Math.min((appStats.shortlisted_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
            const hiredRate = appStats.total_applications > 0 ? 
                Math.min((appStats.hired_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
            const rejectedRate = appStats.total_applications > 0 ? 
                Math.min((appStats.rejected_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
            
            // Generate report HTML
            const reportHTML = `
                <div class="report-header">
                    <h2 class="report-title">${monthName} ${year} Recruitment Report</h2>
                    <p class="report-subtitle">Comprehensive analysis of job postings and applicant statistics</p>
                    <p><strong>Generated:</strong> ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
                </div>
                
                <div class="report-section">
                    <div class="section-title">Job Posting Statistics</div>
                    <div class="stats-grid-report">
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(jobStats.total_jobs)}</div>
                            <div class="stat-label-report">Total Jobs Posted</div>
                        </div>
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(jobStats.active_jobs)}</div>
                            <div class="stat-label-report">Active Jobs</div>
                        </div>
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(jobStats.pending_jobs)}</div>
                            <div class="stat-label-report">Pending Approval</div>
                        </div>
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(jobStats.closed_jobs)}</div>
                            <div class="stat-label-report">Closed Jobs</div>
                        </div>
                    </div>
                </div>
                
                <div class="report-section">
                    <div class="section-title">Application Statistics</div>
                    <div class="stats-grid-report">
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(appStats.total_applications)}</div>
                            <div class="stat-label-report">Total Applications</div>
                        </div>
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(appStats.hired_applications)}</div>
                            <div class="stat-label-report">Successful Hires</div>
                        </div>
                        <div class="stat-card-report">
                            <div class="stat-value-report">${hiredRate}%</div>
                            <div class="stat-label-report">Hire Rate</div>
                        </div>
                        <div class="stat-card-report">
                            <div class="stat-value-report">${formatNumber(appStats.rejected_applications)}</div>
                            <div class="stat-label-report">Rejected Applications</div>
                        </div>
                    </div>
                </div>
                
                <div class="report-section">
                    <div class="section-title">Application Status Breakdown</div>
                    <div class="application-status-grid">
                        <div class="status-card-report">
                            <div class="status-value-report">${formatNumber(appStats.pending_applications)}</div>
                            <div class="status-label-report">Pending Graduates</div>
                            <div class="status-description-report">${pendingRate}% of total applications</div>
                        </div>
                        <div class="status-card-report">
                            <div class="status-value-report">${formatNumber(appStats.reviewed_applications)}</div>
                            <div class="status-label-report">Under Review</div>
                            <div class="status-description-report">${reviewedRate}% of total applications</div>
                        </div>
                        <div class="status-card-report">
                            <div class="status-value-report">${formatNumber(appStats.qualified_applications)}</div>
                            <div class="status-label-report">Qualified Candidates</div>
                            <div class="status-description-report">${qualifiedRate}% of total applications</div>
                        </div>
                        <div class="status-card-report">
                            <div class="status-value-report">${formatNumber(appStats.shortlisted_applications)}</div>
                            <div class="status-label-report">Shortlisted</div>
                            <div class="status-description-report">${shortlistedRate}% of total applications</div>
                        </div>
                        <div class="status-card-report">
                            <div class="status-value-report">${formatNumber(appStats.hired_applications)}</div>
                            <div class="status-label-report">Hired</div>
                            <div class="status-description-report">${hiredRate}% of total applications</div>
                        </div>
                        <div class="status-card-report">
                            <div class="status-value-report">${formatNumber(appStats.rejected_applications)}</div>
                            <div class="status-label-report">Rejected</div>
                            <div class="status-description-report">${rejectedRate}% of total applications</div>
                        </div>
                    </div>
                </div>
                
                <div class="report-section">
                    <div class="section-title">Top 5 Performing Jobs</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Job Title</th>
                                <th>Applications</th>
                                <th>Hires</th>
                                <th>Hire Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${topJobs.map(job => `
                                <tr>
                                    <td>${job.job_title}</td>
                                    <td>${formatNumber(job.application_count)}</td>
                                    <td>${formatNumber(job.hires)}</td>
                                    <td><strong>${job.hire_rate}%</strong></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                
                ${monthlyTrends.length > 0 ? `
                <div class="report-section">
                    <div class="section-title">Monthly Trends (Last 6 Months)</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Jobs Posted</th>
                                <th>Applications Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${monthlyTrends.map(trend => `
                                <tr>
                                    <td>${trend.month}</td>
                                    <td>${formatNumber(trend.jobs_posted)}</td>
                                    <td>${formatNumber(trend.applications_received)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ` : ''}
                
                <div class="report-section" style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 30px;">
                    <div class="section-title">Key Insights</div>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                            <strong>📊 Recruitment Performance:</strong> 
                            ${appStats.hired_applications > 0 ? 
                                `Successfully hired ${appStats.hired_applications} candidates with a ${hiredRate}% hire rate.` : 
                                'No hires recorded for this period.'}
                        </li>
                        <li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                            <strong>📈 Pipeline Health:</strong> 
                            ${appStats.pending_applications > 0 ? 
                                `${appStats.pending_applications} applications are pending review (${pendingRate}% of total).` : 
                                'All applications have been processed.'}
                        </li>
                        <li style="padding: 8px 0; border-bottom: 1px solid #eee;">
                            <strong>🎯 Quality Candidates:</strong> 
                            ${appStats.qualified_applications > 0 ? 
                                `${appStats.qualified_applications} candidates qualified for positions (${qualifiedRate}% of total).` : 
                                'No qualified candidates identified.'}
                        </li>
                        <li style="padding: 8px 0;">
                            <strong>📅 Monthly Activity:</strong> 
                            Posted ${jobStats.total_jobs} jobs and received ${appStats.total_applications} applications in ${monthName} ${year}.
                        </li>
                    </ul>
                </div>
            `;
            
            document.getElementById('reportContent').innerHTML = reportHTML;
        }
        
        // PDF Loading Functions
        function showPdfLoading() {
            pdfLoading.classList.add('active');
        }
        
        function hidePdfLoading() {
            setTimeout(() => {
                pdfLoading.classList.remove('active');
            }, 500);
        }
        
        // Create system notification for PDF download
        function createPdfNotification(fileName, month, year) {
            const formData = new FormData();
            formData.append('ajax_action', 'create_pdf_notification');
            formData.append('file_name', fileName);
            formData.append('month', month);
            formData.append('year', year);
            
            fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.notification) {
                    // Add the new notification to the notification dropdown
                    addNotificationToDropdown(data.notification);
                    
                    // Update notification count
                    updateNotificationCount(1);
                }
            })
            .catch(error => {
                console.error('Error creating notification:', error);
            });
        }
        
        // Add new notification to the dropdown
        function addNotificationToDropdown(notification) {
            const notificationDropdown = document.getElementById('notificationDropdown');
            const noNotifications = notificationDropdown.querySelector('.no-notifications');
            
            // Remove "no notifications" message if it exists
            if (noNotifications) {
                noNotifications.remove();
            }
            
            // Create notification HTML
            const notificationHTML = `
                <a href="employer_analytics.php" class="notification-link unread" data-notif-id="${notification.notif_id}">
                    <div class="notification-item">
                        <div class="notification-icon">
                            <i class="${notification.notif_icon}" style="color: ${notification.notif_color}"></i>
                        </div>
                        <div class="notification-content">
                            <div>
                                <span class="notification-priority priority-medium"></span>
                                ${notification.notif_message}
                            </div>
                            <div class="notification-time">
                                Just now
                            </div>
                        </div>
                    </div>
                </a>
            `;
            
            // Add notification to the top of the dropdown
            const dropdownHeader = notificationDropdown.querySelector('.dropdown-header');
            dropdownHeader.insertAdjacentHTML('afterend', notificationHTML);
            
            // Update notification count in header
            const notificationCountSpan = notificationDropdown.querySelector('.dropdown-header').firstChild;
            const currentCount = parseInt(notificationCountSpan.textContent.match(/\d+/)[0]);
            notificationCountSpan.textContent = `Notifications (${currentCount + 1})`;
            
            // Add event listener for the new notification
            const newNotificationLink = notificationDropdown.querySelector(`[data-notif-id="${notification.notif_id}"]`);
            newNotificationLink.addEventListener('click', function(e) {
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
                        this.classList.remove('unread');
                        updateNotificationCount(-1);
                    }
                })
                .catch(error => {
                    console.error('Error marking notification as read:', error);
                });
            });
        }
        
        // Update notification count badge
        function updateNotificationCount(change) {
            const notificationCount = document.getElementById('notificationCount');
            if (notificationCount) {
                const currentCount = parseInt(notificationCount.textContent);
                const newCount = currentCount + change;
                if (newCount > 0) {
                    notificationCount.textContent = newCount;
                } else {
                    notificationCount.remove();
                    const markAllBtn = document.getElementById('markAllRead');
                    if (markAllBtn) markAllBtn.remove();
                }
            } else if (change > 0) {
                // Create notification count badge if it doesn't exist
                const notificationIcon = document.querySelector('.notification i');
                const notificationCount = document.createElement('span');
                notificationCount.className = 'notification-count';
                notificationCount.id = 'notificationCount';
                notificationCount.textContent = change;
                notificationIcon.parentNode.appendChild(notificationCount);
                
                // Add "Mark all as read" button if it doesn't exist
                const dropdownHeader = document.querySelector('.notification-dropdown .dropdown-header');
                if (!dropdownHeader.querySelector('.mark-all-read')) {
                    const markAllBtn = document.createElement('button');
                    markAllBtn.className = 'mark-all-read';
                    markAllBtn.id = 'markAllRead';
                    markAllBtn.textContent = 'Mark all as read';
                    markAllBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const unreadNotifications = document.querySelectorAll('.notification-link.unread');
                        const unreadIds = Array.from(unreadNotifications).map(item => 
                            item.getAttribute('data-notif-id')
                        );
                        
                        if (unreadIds.length > 0) {
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
                                    unreadNotifications.forEach(item => {
                                        item.classList.remove('unread');
                                    });
                                    
                                    notificationCount.remove();
                                    markAllBtn.remove();
                                }
                            })
                            .catch(error => {
                                console.error('Error marking notifications as read:', error);
                            });
                        }
                    });
                    dropdownHeader.appendChild(markAllBtn);
                }
            }
        }
        
        // Generate PDF Report
        function generatePDFReport(report) {
            try {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });
                
                // Get report data
                const monthName = report.month;
                const year = report.year;
                const jobStats = report.job_stats;
                const appStats = report.application_stats;
                const topJobs = report.top_jobs;
                const monthlyTrends = report.monthly_trends;
                
                // Format numbers with commas
                function formatNumber(num) {
                    return num ? num.toLocaleString() : '0';
                }
                
                // Calculate percentages
                const pendingRate = appStats.total_applications > 0 ? 
                    Math.min((appStats.pending_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
                const reviewedRate = appStats.total_applications > 0 ? 
                    Math.min((appStats.reviewed_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
                const qualifiedRate = appStats.total_applications > 0 ? 
                    Math.min((appStats.qualified_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
                const shortlistedRate = appStats.total_applications > 0 ? 
                    Math.min((appStats.shortlisted_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
                const hiredRate = appStats.total_applications > 0 ? 
                    Math.min((appStats.hired_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
                const rejectedRate = appStats.total_applications > 0 ? 
                    Math.min((appStats.rejected_applications / appStats.total_applications) * 100, 100).toFixed(1) : 0;
                
                // Set document properties
                doc.setProperties({
                    title: `${monthName} ${year} Recruitment Report - ${employerData.company}`,
                    subject: 'Monthly Recruitment Analytics',
                    author: 'CTU-PESO System',
                    keywords: 'recruitment, analytics, report, hiring, applications',
                    creator: 'CTU-PESO Analytics Dashboard'
                });
                
                // Add header
                doc.setFontSize(24);
                doc.setTextColor(110, 3, 3);
                doc.setFont('helvetica', 'bold');
                doc.text('Monthly Recruitment Report', 105, 20, { align: 'center' });
                
                doc.setFontSize(14);
                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'normal');
                doc.text(`${monthName} ${year}`, 105, 30, { align: 'center' });
                
                doc.setFontSize(12);
                doc.text(employerData.company, 105, 38, { align: 'center' });
                
                // Add generated date
                doc.setFontSize(10);
                doc.setTextColor(100, 100, 100);
                doc.text(`Generated: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}`, 105, 45, { align: 'center' });
                
                // Add CTU-PESO Logo/Text
                doc.setFontSize(10);
                doc.setTextColor(110, 3, 3);
                doc.text('CTU-PESO Recruitment Analytics System', 105, 55, { align: 'center' });
                
                let yPos = 65;
                
                // Executive Summary Section
                doc.setFontSize(16);
                doc.setTextColor(110, 3, 3);
                doc.setFont('helvetica', 'bold');
                doc.text('Executive Summary', 20, yPos);
                yPos += 10;
                
                doc.setFontSize(11);
                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'normal');
                
                // Summary stats in two columns
                const summaryData = [
                    [`Total Jobs Posted: ${formatNumber(jobStats.total_jobs)}`, `Active Jobs: ${formatNumber(jobStats.active_jobs)}`],
                    [`Total Applications: ${formatNumber(appStats.total_applications)}`, `Successful Hires: ${formatNumber(appStats.hired_applications)}`],
                    [`Overall Hire Rate: ${hiredRate}%`, `Pending Applications: ${formatNumber(appStats.pending_applications)}`]
                ];
                
                summaryData.forEach(row => {
                    doc.text(row[0], 20, yPos);
                    doc.text(row[1], 110, yPos);
                    yPos += 7;
                });
                
                yPos += 5;
                
                // Job Posting Statistics
                if (yPos > 250) {
                    doc.addPage();
                    yPos = 20;
                }
                
                doc.setFontSize(14);
                doc.setTextColor(110, 3, 3);
                doc.setFont('helvetica', 'bold');
                doc.text('Job Posting Statistics', 20, yPos);
                yPos += 10;
                
                // Create job stats table
                const jobStatsTable = {
                    head: [['Metric', 'Count']],
                    body: [
                        ['Total Jobs Posted', formatNumber(jobStats.total_jobs)],
                        ['Active Job Postings', formatNumber(jobStats.active_jobs)],
                        ['Pending Approval', formatNumber(jobStats.pending_jobs)],
                        ['Closed Jobs', formatNumber(jobStats.closed_jobs)]
                    ]
                };
                
                doc.autoTable({
                    startY: yPos,
                    head: jobStatsTable.head,
                    body: jobStatsTable.body,
                    theme: 'grid',
                    headStyles: { fillColor: [110, 3, 3], textColor: [255, 255, 255], fontStyle: 'bold' },
                    alternateRowStyles: { fillColor: [245, 245, 245] },
                    margin: { left: 20, right: 20 }
                });
                
                yPos = doc.lastAutoTable.finalY + 10;
                
                // Application Statistics
                if (yPos > 250) {
                    doc.addPage();
                    yPos = 20;
                }
                
                doc.setFontSize(14);
                doc.setTextColor(110, 3, 3);
                doc.setFont('helvetica', 'bold');
                doc.text('Application Statistics', 20, yPos);
                yPos += 10;
                
                // Create application stats table
                const appStatsTable = {
                    head: [['Status', 'Count', 'Percentage']],
                    body: [
                        ['Total Applications', formatNumber(appStats.total_applications), '100%'],
                        ['Pending Review', formatNumber(appStats.pending_applications), `${pendingRate}%`],
                        ['Under Review', formatNumber(appStats.reviewed_applications), `${reviewedRate}%`],
                        ['Shortlisted', formatNumber(appStats.shortlisted_applications), `${shortlistedRate}%`],
                        ['Qualified', formatNumber(appStats.qualified_applications), `${qualifiedRate}%`],
                        ['Hired', formatNumber(appStats.hired_applications), `${hiredRate}%`],
                        ['Rejected', formatNumber(appStats.rejected_applications), `${rejectedRate}%`]
                    ]
                };
                
                doc.autoTable({
                    startY: yPos,
                    head: appStatsTable.head,
                    body: appStatsTable.body,
                    theme: 'grid',
                    headStyles: { fillColor: [110, 3, 3], textColor: [255, 255, 255], fontStyle: 'bold' },
                    alternateRowStyles: { fillColor: [245, 245, 245] },
                    margin: { left: 20, right: 20 },
                    columnStyles: {
                        0: { cellWidth: 70 },
                        1: { cellWidth: 40 },
                        2: { cellWidth: 40 }
                    }
                });
                
                yPos = doc.lastAutoTable.finalY + 10;
                
                // Top Performing Jobs
                if (topJobs.length > 0) {
                    if (yPos > 250) {
                        doc.addPage();
                        yPos = 20;
                    }
                    
                    doc.setFontSize(14);
                    doc.setTextColor(110, 3, 3);
                    doc.setFont('helvetica', 'bold');
                    doc.text('Top Performing Jobs', 20, yPos);
                    yPos += 10;
                    
                    // Create top jobs table
                    const topJobsTable = {
                        head: [['Job Title', 'Applications', 'Hires', 'Hire Rate']],
                        body: topJobs.map(job => [
                            job.job_title.length > 40 ? job.job_title.substring(0, 37) + '...' : job.job_title,
                            formatNumber(job.application_count),
                            formatNumber(job.hires),
                            `${job.hire_rate}%`
                        ])
                    };
                    
                    doc.autoTable({
                        startY: yPos,
                        head: topJobsTable.head,
                        body: topJobsTable.body,
                        theme: 'grid',
                        headStyles: { fillColor: [110, 3, 3], textColor: [255, 255, 255], fontStyle: 'bold' },
                        alternateRowStyles: { fillColor: [245, 245, 245] },
                        margin: { left: 20, right: 20 },
                        columnStyles: {
                            0: { cellWidth: 80 },
                            1: { cellWidth: 30 },
                            2: { cellWidth: 25 },
                            3: { cellWidth: 25 }
                        }
                    });
                    
                    yPos = doc.lastAutoTable.finalY + 10;
                }
                
                // Monthly Trends
                if (monthlyTrends.length > 0) {
                    if (yPos > 250) {
                        doc.addPage();
                        yPos = 20;
                    }
                    
                    doc.setFontSize(14);
                    doc.setTextColor(110, 3, 3);
                    doc.setFont('helvetica', 'bold');
                    doc.text('Monthly Trends (Last 6 Months)', 20, yPos);
                    yPos += 10;
                    
                    // Create trends table
                    const trendsTable = {
                        head: [['Month', 'Jobs Posted', 'Applications Received']],
                        body: monthlyTrends.map(trend => [
                            trend.month,
                            formatNumber(trend.jobs_posted),
                            formatNumber(trend.applications_received)
                        ])
                    };
                    
                    doc.autoTable({
                        startY: yPos,
                        head: trendsTable.head,
                        body: trendsTable.body,
                        theme: 'grid',
                        headStyles: { fillColor: [110, 3, 3], textColor: [255, 255, 255], fontStyle: 'bold' },
                        alternateRowStyles: { fillColor: [245, 245, 245] },
                        margin: { left: 20, right: 20 }
                    });
                    
                    yPos = doc.lastAutoTable.finalY + 10;
                }
                
                // Key Insights
                if (yPos > 250) {
                    doc.addPage();
                    yPos = 20;
                }
                
                doc.setFontSize(14);
                doc.setTextColor(110, 3, 3);
                doc.setFont('helvetica', 'bold');
                doc.text('Key Insights & Recommendations', 20, yPos);
                yPos += 10;
                
                doc.setFontSize(11);
                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'normal');
                
                const insights = [
                    `• Posted ${jobStats.total_jobs} jobs and received ${appStats.total_applications} applications in ${monthName} ${year}`,
                    `• Successfully hired ${appStats.hired_applications} candidates with a ${hiredRate}% hire rate`,
                    `• ${appStats.pending_applications} applications pending review (${pendingRate}% of total)`,
                    appStats.qualified_applications > 0 ? 
                        `• ${appStats.qualified_applications} candidates qualified for positions (${qualifiedRate}% of total)` : 
                        '• No qualified candidates identified this period',
                    `• Active job postings: ${jobStats.active_jobs} | Pending approval: ${jobStats.pending_jobs} | Closed: ${jobStats.closed_jobs}`
                ];
                
                insights.forEach(insight => {
                    if (yPos > 280) {
                        doc.addPage();
                        yPos = 20;
                    }
                    doc.text(insight, 25, yPos);
                    yPos += 7;
                });
                
                // Add footer on each page
                const pageCount = doc.internal.getNumberOfPages();
                for (let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(9);
                    doc.setTextColor(100, 100, 100);
                    doc.text(`Page ${i} of ${pageCount}`, 105, doc.internal.pageSize.height - 10, { align: 'center' });
                    doc.text('CTU-PESO Recruitment Analytics System', 20, doc.internal.pageSize.height - 10);
                    doc.text(`© ${new Date().getFullYear()} Cebu Technological University`, doc.internal.pageSize.width - 20, doc.internal.pageSize.height - 10, { align: 'right' });
                }
                
                // Save the PDF
                const fileName = `CTU_PESO_Recruitment_Report_${monthName}_${year}_${employerData.company.replace(/[^a-z0-9]/gi, '_')}.pdf`;
                doc.save(fileName);
                
                // Hide loading spinner
                hidePdfLoading();
                
                // Create system notification instead of alert
                createPdfNotification(fileName, reportMonth.value, reportYear.value);
                
            } catch (error) {
                console.error('Error generating PDF:', error);
                hidePdfLoading();
                // Fallback to alert if notification system fails
                alert('Error generating PDF report. Please try again.');
            }
        }
        
        // Modal Functions
        function showModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    hideModal(this.id);
                }
            });
        });
        
        // Set current month as default
        const currentMonth = new Date().getMonth() + 1;
        reportMonth.value = currentMonth;
    </script>
</body>
</html>