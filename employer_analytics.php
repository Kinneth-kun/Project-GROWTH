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
    
    // 5. CANDIDATE SOURCE ANALYSIS (Profile Views)
    $candidate_source_stmt = $conn->prepare("
        SELECT 
            u.usr_name as graduate_name,
            g.grad_degree,
            g.grad_year_graduated,
            g.grad_job_preference,
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

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Data Analytics</h1>
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

        <!-- Candidate Sources Tab -->
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
                            <th>Job Preference</th>
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
                            <td><?= htmlspecialchars($candidate['grad_job_preference']) ?></td>
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

    <script>
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
    </script>
</body>
</html>