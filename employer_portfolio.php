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
        // If no employer record found, redirect to profile setup
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
    
    // 3. Create profile view notifications for recent profile views
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
        // Get job statistics for summary
        $jobs_stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN j.job_status = 'active' THEN 1 ELSE 0 END) as active_jobs
            FROM jobs j
            WHERE j.job_emp_usr_id = :employer_id
        ");
        $jobs_stmt->bindParam(':employer_id', $employer_id);
        $jobs_stmt->execute();
        $job_stats = $jobs_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get application statistics for summary
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
    
    // Check if shortlists table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'shortlists'");
    $table_exists = $check_table->rowCount() > 0;
    
    // Get filter parameters
    $course_filter = $_GET['course'] ?? 'all';
    $year_filter = $_GET['year'] ?? 'all';
    $search_query = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? 'all';
    
    // Build query to fetch all graduates - FIXED TO MATCH SCHEMA
    $graduates_query = "
        SELECT 
            u.usr_id, 
            u.usr_name, 
            u.usr_email,
            u.usr_phone,
            u.usr_gender,
            u.usr_birthdate,
            u.usr_profile_photo,
            g.grad_degree, 
            g.grad_year_graduated,
            g.grad_job_preference,
            g.grad_summary,
            g.grad_school_id,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM applications a 
                    JOIN jobs j ON a.app_job_id = j.job_id 
                    WHERE a.app_grad_usr_id = u.usr_id 
                    AND a.app_status = 'hired'
                ) THEN 'employed'
                WHEN EXISTS (
                    SELECT 1 FROM applications a 
                    WHERE a.app_grad_usr_id = u.usr_id 
                    AND a.app_status IN ('reviewed', 'shortlisted', 'qualified')
                ) THEN 'actively_looking'
                ELSE 'available'
            END as job_status
        FROM 
            users u
        JOIN 
            graduates g ON u.usr_id = g.grad_usr_id
        WHERE 
            u.usr_role = 'graduate' AND u.usr_account_status = 'active'
    ";
    
    // Add filters to query
    $params = [];
    $param_types = "";
    
    if ($course_filter !== 'all') {
        $graduates_query .= " AND g.grad_degree LIKE ?";
        $params[] = "%$course_filter%";
        $param_types .= "s";
    }
    
    if ($year_filter !== 'all') {
        $graduates_query .= " AND g.grad_year_graduated = ?";
        $params[] = $year_filter;
        $param_types .= "s";
    }
    
    if ($status_filter !== 'all') {
        if ($status_filter === 'employed') {
            $graduates_query .= " AND EXISTS (
                SELECT 1 FROM applications a 
                JOIN jobs j ON a.app_job_id = j.job_id 
                WHERE a.app_grad_usr_id = u.usr_id 
                AND a.app_status = 'hired'
            )";
        } elseif ($status_filter === 'actively_looking') {
            $graduates_query .= " AND EXISTS (
                SELECT 1 FROM applications a 
                WHERE a.app_grad_usr_id = u.usr_id 
                AND a.app_status IN ('reviewed', 'shortlisted', 'qualified')
            )";
        } elseif ($status_filter === 'available') {
            $graduates_query .= " AND NOT EXISTS (
                SELECT 1 FROM applications a 
                WHERE a.app_grad_usr_id = u.usr_id 
                AND a.app_status = 'hired'
            ) AND NOT EXISTS (
                SELECT 1 FROM applications a 
                WHERE a.app_grad_usr_id = u.usr_id 
                AND a.app_status IN ('reviewed', 'shortlisted', 'qualified')
            )";
        }
    }
    
    if (!empty($search_query)) {
        $graduates_query .= " AND (u.usr_name LIKE ? OR u.usr_email LIKE ? OR g.grad_degree LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $param_types .= "sss";
    }
    
    $graduates_query .= " ORDER BY u.usr_created_at DESC";
    
    // Prepare and execute query with filters
    $stmt = $conn->prepare($graduates_query);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $graduates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each graduate, check if they're already in the employer's shortlist
    if ($table_exists) {
        foreach ($graduates as &$graduate) {
            $shortlist_check_query = "SELECT COUNT(*) as count FROM shortlists 
                                     WHERE short_emp_usr_id = ? AND short_grad_usr_id = ?";
            $stmt = $conn->prepare($shortlist_check_query);
            $stmt->execute([$employer_id, $graduate['usr_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $graduate['is_shortlisted'] = $result['count'] > 0;
        }
        unset($graduate); // Unset reference
    } else {
        // If table doesn't exist, mark all as not shortlisted
        foreach ($graduates as &$graduate) {
            $graduate['is_shortlisted'] = false;
        }
        unset($graduate);
    }
    
    // Handle actions
    if (isset($_GET['action']) && isset($_GET['grad_id'])) {
        $grad_id = $_GET['grad_id'];
        
        if ($_GET['action'] == 'view') {
            // Fetch detailed graduate information - ENHANCED WITH PORTFOLIO DATA
            $grad_detail_query = "
                SELECT 
                    u.usr_id, 
                    u.usr_name, 
                    u.usr_email,
                    u.usr_phone,
                    u.usr_gender,
                    u.usr_birthdate,
                    u.usr_profile_photo,
                    u.usr_created_at,
                    g.grad_degree, 
                    g.grad_year_graduated,
                    g.grad_job_preference,
                    g.grad_summary,
                    g.grad_school_id,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM applications a 
                            JOIN jobs j ON a.app_job_id = j.job_id 
                            WHERE a.app_grad_usr_id = u.usr_id 
                            AND a.app_status = 'hired'
                        ) THEN 'employed'
                        WHEN EXISTS (
                            SELECT 1 FROM applications a 
                            WHERE a.app_grad_usr_id = u.usr_id 
                            AND a.app_status IN ('reviewed', 'shortlisted', 'qualified')
                        ) THEN 'actively_looking'
                        ELSE 'available'
                    END as job_status
                FROM 
                    users u
                JOIN 
                    graduates g ON u.usr_id = g.grad_usr_id
                WHERE 
                    u.usr_id = ?
            ";
            
            $stmt = $conn->prepare($grad_detail_query);
            $stmt->execute([$grad_id]);
            $grad_details = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($grad_details) {
                // Fetch graduate skills
                $skills_query = "
                    SELECT s.skill_name, s.skill_category, gs.skill_level 
                    FROM graduate_skills gs
                    JOIN skills s ON gs.skill_id = s.skill_id
                    WHERE gs.grad_usr_id = ?
                    ORDER BY gs.skill_level DESC, s.skill_name
                ";
                $stmt = $conn->prepare($skills_query);
                $stmt->execute([$grad_id]);
                $grad_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch portfolio items
                $portfolio_query = "
                    SELECT port_item_type, port_item_title, port_item_description, port_item_file, port_created_at
                    FROM portfolio_items 
                    WHERE port_usr_id = ?
                    ORDER BY port_item_type, port_created_at DESC
                ";
                $stmt = $conn->prepare($portfolio_query);
                $stmt->execute([$grad_id]);
                $portfolio_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Count portfolio items by type
                $resume_count = 0;
                $certificate_count = 0;
                $project_count = 0;
                foreach ($portfolio_items as $item) {
                    if ($item['port_item_type'] === 'resume') $resume_count++;
                    if ($item['port_item_type'] === 'certificate') $certificate_count++;
                    if ($item['port_item_type'] === 'project') $project_count++;
                }
                
                // Check if this graduate is already shortlisted
                $is_shortlisted = false;
                if ($table_exists) {
                    $shortlist_check_query = "SELECT COUNT(*) as count FROM shortlists 
                                             WHERE short_emp_usr_id = ? AND short_grad_usr_id = ?";
                    $stmt = $conn->prepare($shortlist_check_query);
                    $stmt->execute([$employer_id, $grad_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $is_shortlisted = $result['count'] > 0;
                }
                
                // Format the details for display
                $view_message = "Viewing profile of: " . htmlspecialchars($grad_details['usr_name']);
                
                // Determine if shortlisting is allowed based on job status
                $can_shortlist = !$is_shortlisted && $grad_details['job_status'] !== 'employed';
                $shortlist_button = $is_shortlisted 
                    ? '<button class="btn-action btn-shortlisted" disabled><i class="fas fa-check-circle"></i> Already Shortlisted</button>'
                    : ($grad_details['job_status'] === 'employed' 
                        ? '<button class="btn-action btn-employed" disabled><i class="fas fa-briefcase"></i> Currently Employed</button>'
                        : '<button class="btn-action btn-shortlist" onclick="showShortlistConfirmation(' . $grad_details['usr_id'] . ', \'' . htmlspecialchars(addslashes($grad_details['usr_name'])) . '\')"><i class="fas fa-star"></i> Shortlist Graduate</button>');
                
                // Get profile photo URL
                $grad_profile_photo = !empty($grad_details['usr_profile_photo']) 
                    ? htmlspecialchars($grad_details['usr_profile_photo']) 
                    : 'https://ui-avatars.com/api/?name=' . urlencode($grad_details['usr_name']) . '&background=random';
                
                // Get job status display text and color
                $job_status_display = [
                    'employed' => ['text' => 'Employed', 'color' => '#28a745', 'icon' => 'fas fa-briefcase', 'bg_color' => 'rgba(40, 167, 69, 0.1)'],
                    'actively_looking' => ['text' => 'Actively Looking', 'color' => '#ffc107', 'icon' => 'fas fa-search', 'bg_color' => 'rgba(255, 193, 7, 0.1)'],
                    'available' => ['text' => 'Available', 'color' => '#17a2b8', 'icon' => 'fas fa-user-check', 'bg_color' => 'rgba(23, 162, 184, 0.1)']
                ];
                
                $current_status = $job_status_display[$grad_details['job_status']];
                
                // Calculate age from birthdate
                $age = '';
                if (!empty($grad_details['usr_birthdate'])) {
                    $birthdate = new DateTime($grad_details['usr_birthdate']);
                    $today = new DateTime();
                    $age = $today->diff($birthdate)->y;
                }
                
                // Format member since date
                $member_since = date('F j, Y', strtotime($grad_details['usr_created_at']));
                
                // Group skills by category
                $skills_by_category = [];
                foreach ($grad_skills as $skill) {
                    $category = $skill['skill_category'] ?? 'Other';
                    if (!isset($skills_by_category[$category])) {
                        $skills_by_category[$category] = [];
                    }
                    $skills_by_category[$category][] = $skill;
                }
                
                $grad_details_html = '
                <div class="grad-detail-modal" id="gradDetailModal">
                    <div class="modal-content">
                        <span class="close-modal">&times;</span>
                        <div class="grad-profile-header">
                            <div class="profile-main-info">
                                <div class="profile-photo-container">
                                    <img src="' . $grad_profile_photo . '" alt="Profile Photo" class="profile-photo-large">
                                    <div class="profile-status-badge" style="background-color: ' . $current_status['bg_color'] . '; border-color: ' . $current_status['color'] . ';">
                                        <i class="' . $current_status['icon'] . '" style="color: ' . $current_status['color'] . ';"></i>
                                        <span style="color: ' . $current_status['color'] . ';">' . $current_status['text'] . '</span>
                                    </div>
                                </div>
                                <div class="profile-basic-info">
                                    <h2>' . htmlspecialchars($grad_details['usr_name']) . '</h2>
                                    <div class="profile-meta">
                                        <span class="meta-item">
                                            <i class="fas fa-graduation-cap"></i>
                                            <strong>' . htmlspecialchars($grad_details['grad_degree']) . '</strong>
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            Graduated ' . htmlspecialchars($grad_details['grad_year_graduated']) . '
                                        </span>
                                        <span class="meta-item">
                                            <i class="fas fa-id-card"></i>
                                            ' . htmlspecialchars($grad_details['grad_school_id']) . '
                                        </span>
                                    </div>
                                    <div class="profile-contact">
                                        <span class="contact-item">
                                            <i class="fas fa-envelope"></i>
                                            ' . htmlspecialchars($grad_details['usr_email']) . '
                                        </span>
                                        ' . (!empty($grad_details['usr_phone']) ? '
                                        <span class="contact-item">
                                            <i class="fas fa-phone"></i>
                                            ' . htmlspecialchars($grad_details['usr_phone']) . '
                                        </span>' : '') . '
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grad-detail-sections">
                            <!-- Quick Stats -->
                            <div class="quick-stats-grid">
                                <div class="quick-stat">
                                    <div class="stat-icon resume">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="stat-info">
                                        <div class="stat-number">' . $resume_count . '</div>
                                        <div class="stat-label">Resumes</div>
                                    </div>
                                </div>
                                <div class="quick-stat">
                                    <div class="stat-icon certificate">
                                        <i class="fas fa-certificate"></i>
                                    </div>
                                    <div class="stat-info">
                                        <div class="stat-number">' . $certificate_count . '</div>
                                        <div class="stat-label">Certificates</div>
                                    </div>
                                </div>
                                <div class="quick-stat">
                                    <div class="stat-icon project">
                                        <i class="fas fa-project-diagram"></i>
                                    </div>
                                    <div class="stat-info">
                                        <div class="stat-number">' . $project_count . '</div>
                                        <div class="stat-label">Projects</div>
                                    </div>
                                </div>
                                <div class="quick-stat">
                                    <div class="stat-icon skills">
                                        <i class="fas fa-code"></i>
                                    </div>
                                    <div class="stat-info">
                                        <div class="stat-number">' . count($grad_skills) . '</div>
                                        <div class="stat-label">Skills</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Personal Information -->
                            <div class="grad-detail-section">
                                <div class="section-header">
                                    <i class="fas fa-user"></i>
                                    <h3>Personal Information</h3>
                                </div>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Full Name</div>
                                        <div class="info-value">' . htmlspecialchars($grad_details['usr_name']) . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Email Address</div>
                                        <div class="info-value">' . htmlspecialchars($grad_details['usr_email']) . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Phone Number</div>
                                        <div class="info-value">' . (!empty($grad_details['usr_phone']) ? htmlspecialchars($grad_details['usr_phone']) : '<span class="not-provided">Not provided</span>') . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Gender</div>
                                        <div class="info-value">' . (!empty($grad_details['usr_gender']) ? htmlspecialchars($grad_details['usr_gender']) : '<span class="not-provided">Not provided</span>') . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Age</div>
                                        <div class="info-value">' . (!empty($age) ? $age . ' years' : '<span class="not-provided">Not provided</span>') . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Member Since</div>
                                        <div class="info-value">' . $member_since . '</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Education & Career -->
                            <div class="grad-detail-section">
                                <div class="section-header">
                                    <i class="fas fa-graduation-cap"></i>
                                    <h3>Education & Career</h3>
                                </div>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Alumni ID</div>
                                        <div class="info-value">' . htmlspecialchars($grad_details['grad_school_id']) . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Degree/Course</div>
                                        <div class="info-value">' . htmlspecialchars($grad_details['grad_degree']) . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Year Graduated</div>
                                        <div class="info-value">' . htmlspecialchars($grad_details['grad_year_graduated']) . '</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Job Preference</div>
                                        <div class="info-value">' . htmlspecialchars($grad_details['grad_job_preference']) . '</div>
                                    </div>
                                </div>
                                ' . (!empty($grad_details['grad_summary']) ? '
                                <div class="career-summary">
                                    <div class="summary-header">
                                        <i class="fas fa-file-alt"></i>
                                        <h4>Career Summary</h4>
                                    </div>
                                    <div class="summary-content">' . nl2br(htmlspecialchars($grad_details['grad_summary'])) . '</div>
                                </div>' : '') . '
                            </div>
                            
                            <!-- Skills -->
                            <div class="grad-detail-section">
                                <div class="section-header">
                                    <i class="fas fa-code"></i>
                                    <h3>Skills & Competencies</h3>
                                </div>
                                ' . (count($grad_skills) > 0 ? '
                                <div class="skills-display">
                                    ' . implode('', array_map(function($category, $skills) {
                                        return '
                                        <div class="skill-category">
                                            <h4>' . htmlspecialchars($category) . '</h4>
                                            <div class="skills-list">
                                                ' . implode('', array_map(function($skill) {
                                                    $level_class = $skill['skill_level'] ?? 'intermediate';
                                                    $level_colors = [
                                                        'beginner' => '#17a2b8',
                                                        'intermediate' => '#28a745',
                                                        'advanced' => '#ffc107',
                                                        'expert' => '#dc3545'
                                                    ];
                                                    return '
                                                    <div class="skill-tag-detail">
                                                        <span class="skill-name">' . htmlspecialchars($skill['skill_name']) . '</span>
                                                        <span class="skill-level-badge ' . $level_class . '" style="background-color: ' . $level_colors[$level_class] . '">' . ucfirst($level_class) . '</span>
                                                    </div>';
                                                }, $skills)) . '
                                            </div>
                                        </div>';
                                    }, array_keys($skills_by_category), array_values($skills_by_category))) . '
                                </div>' : '
                                <div class="empty-state">
                                    <i class="fas fa-code"></i>
                                    <p>No skills added yet</p>
                                </div>') . '
                            </div>
                            
                            <!-- Portfolio Items -->
                            <div class="grad-detail-section">
                                <div class="section-header">
                                    <i class="fas fa-briefcase"></i>
                                    <h3>Portfolio</h3>
                                </div>
                                ' . (count($portfolio_items) > 0 ? '
                                <div class="portfolio-items">
                                    ' . implode('', array_map(function($item) {
                                        $icon = '';
                                        $type_class = '';
                                        $type_colors = [
                                            'resume' => ['bg' => 'rgba(110, 3, 3, 0.1)', 'color' => '#6e0303'],
                                            'certificate' => ['bg' => 'rgba(31, 122, 17, 0.1)', 'color' => '#1f7a11'],
                                            'project' => ['bg' => 'rgba(0, 68, 255, 0.1)', 'color' => '#0044ff']
                                        ];
                                        switch($item['port_item_type']) {
                                            case 'resume':
                                                $icon = 'fa-file-alt';
                                                $type_class = 'resume';
                                                break;
                                            case 'certificate':
                                                $icon = 'fa-certificate';
                                                $type_class = 'certificate';
                                                break;
                                            case 'project':
                                                $icon = 'fa-project-diagram';
                                                $type_class = 'project';
                                                break;
                                            default:
                                                $icon = 'fa-file';
                                                $type_class = 'other';
                                        }
                                        return '
                                        <div class="portfolio-item ' . $type_class . '">
                                            <div class="portfolio-icon" style="background-color: ' . $type_colors[$type_class]['bg'] . '; color: ' . $type_colors[$type_class]['color'] . ';">
                                                <i class="fas ' . $icon . '"></i>
                                            </div>
                                            <div class="portfolio-info">
                                                <div class="portfolio-title">' . htmlspecialchars($item['port_item_title']) . '</div>
                                                ' . (!empty($item['port_item_description']) ? '<div class="portfolio-desc">' . htmlspecialchars($item['port_item_description']) . '</div>' : '') . '
                                                <div class="portfolio-date">Uploaded: ' . date('M j, Y', strtotime($item['port_created_at'])) . '</div>
                                            </div>
                                            <div class="portfolio-action">
                                                <a href="' . htmlspecialchars($item['port_item_file']) . '" target="_blank" class="btn-view-file">
                                                    <i class="fas fa-eye"></i> View Document
                                                </a>
                                            </div>
                                        </div>';
                                    }, $portfolio_items)) . '
                                </div>' : '
                                <div class="empty-state">
                                    <i class="fas fa-briefcase"></i>
                                    <p>No portfolio items uploaded yet</p>
                                </div>') . '
                            </div>
                        </div>
                        
                        <div class="modal-actions">
                            ' . ($is_shortlisted ? '<div class="alert alert-info"><i class="fas fa-info-circle"></i> This graduate is already in your shortlist.</div>' : '') .
                            ($grad_details['job_status'] === 'employed' ? '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> This graduate is currently employed.</div>' : '') . '
                            <div class="action-buttons">
                                ' . $shortlist_button . '
                                <button class="btn-action btn-secondary" onclick="closeGradModal()">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    // Display the modal
                    document.getElementById("gradDetailModal").style.display = "block";
                    
                    function closeGradModal() {
                        document.getElementById("gradDetailModal").style.display = "none";
                    }
                    
                    // Close modal functionality
                    document.querySelector(".close-modal").addEventListener("click", closeGradModal);
                    
                    // Close modal when clicking outside
                    window.addEventListener("click", function(event) {
                        if (event.target == document.getElementById("gradDetailModal")) {
                            closeGradModal();
                        }
                    });
                </script>';
            }
        }
        
        if ($_GET['action'] == 'shortlist') {
            // First check if shortlists table exists, if not create it
            if (!$table_exists) {
                // Create shortlists table
                $create_shortlists = "CREATE TABLE shortlists (
                    short_id INT AUTO_INCREMENT PRIMARY KEY,
                    short_emp_usr_id INT NOT NULL,
                    short_grad_usr_id INT NOT NULL,
                    short_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (short_emp_usr_id) REFERENCES users(usr_id) ON DELETE CASCADE,
                    FOREIGN KEY (short_grad_usr_id) REFERENCES users(usr_id) ON DELETE CASCADE,
                    UNIQUE KEY unique_shortlist (short_emp_usr_id, short_grad_usr_id)
                )";
                
                if ($conn->exec($create_shortlists)) {
                    $table_exists = true;
                    $success_message = "Shortlists table created successfully.";
                } else {
                    $error_message = "Error creating shortlists table";
                }
            }
            
            // Check if already shortlisted
            $shortlist_check_query = "SELECT COUNT(*) as count FROM shortlists 
                                     WHERE short_emp_usr_id = ? AND short_grad_usr_id = ?";
            $stmt = $conn->prepare($shortlist_check_query);
            $stmt->execute([$employer_id, $grad_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                $error_message = "This graduate is already in your shortlist.";
            } else {
                // Check if graduate is employed
                $employment_check_query = "
                    SELECT COUNT(*) as count FROM applications a 
                    JOIN jobs j ON a.app_job_id = j.job_id 
                    WHERE a.app_grad_usr_id = ? AND a.app_status = 'hired'
                ";
                $stmt = $conn->prepare($employment_check_query);
                $stmt->execute([$grad_id]);
                $employment_result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($employment_result['count'] > 0) {
                    $error_message = "This graduate is currently employed and cannot be shortlisted.";
                } else {
                    // Add to shortlist functionality - FIXED COLUMN NAMES
                    $shortlist_query = "INSERT INTO shortlists (short_emp_usr_id, short_grad_usr_id) VALUES (?, ?)";
                    $stmt = $conn->prepare($shortlist_query);
                    
                    if ($stmt->execute([$employer_id, $grad_id])) {
                        $success_message = "Graduate added to your shortlist!";
                        
                        // ========== ANALYTICS TRACKING FOR SHORTLIST ==========
                        
                        // Get graduate details for analytics
                        $grad_details_query = "SELECT usr_name FROM users WHERE usr_id = ?";
                        $stmt = $conn->prepare($grad_details_query);
                        $stmt->execute([$grad_id]);
                        $grad_details = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($grad_details) {
                            // Create notification for shortlisting
                            $notification_message = 'You shortlisted ' . $grad_details['usr_name'] . ' for potential hiring';
                            
                            $insert_notif_stmt = $conn->prepare("
                                INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at)
                                VALUES (:employer_id, :message, 'shortlist', 0, NOW())
                            ");
                            $insert_notif_stmt->bindParam(':employer_id', $employer_id);
                            $insert_notif_stmt->bindParam(':message', $notification_message);
                            $insert_notif_stmt->execute();
                            
                            // Record shortlist activity for analytics
                            $activity_details = json_encode([
                                'graduate_id' => $grad_id,
                                'graduate_name' => $grad_details['usr_name'],
                                'action' => 'shortlist_added',
                                'employer_id' => $employer_id,
                                'employer_name' => $employer['usr_name']
                            ]);
                            
                            $activity_stmt = $conn->prepare("
                                INSERT INTO user_activities (activity_usr_id, activity_type, activity_details, activity_date)
                                VALUES (:employer_id, 'shortlist_added', :details, NOW())
                            ");
                            $activity_stmt->bindParam(':employer_id', $employer_id);
                            $activity_stmt->bindParam(':details', $activity_details);
                            $activity_stmt->execute();
                        }
                        
                        // Update the graduate's shortlisted status
                        foreach ($graduates as &$graduate) {
                            if ($graduate['usr_id'] == $grad_id) {
                                $graduate['is_shortlisted'] = true;
                                break;
                            }
                        }
                        unset($graduate);
                    } else {
                        $error_message = "Error adding to shortlist";
                    }
                }
            }
        }
    }
    
    // Define course options
    $course_options = [
        "BEEd",
        "BECEd",
        "BSNEd",
        "BSEd",
        "BSEd-Math",
        "BSEd-Sci",
        "BSEd-Eng",
        "BSEd-Fil",
        "BSEd-VE",
        "BTLEd",
        "BTLEd-IA",
        "BTLEd-HE",
        "BTLEd-ICT",
        "BTVTEd",
        "BTVTEd-AD",
        "BTVTEd-AT",
        "BTVTEd-FSMT",
        "BTVTEd-ET",
        "BTVTEd-ELXT",
        "BTVTEd-GFDT",
        "BTVTEd-WFT",
        "BSCE",
        "BSCpE",
        "BSECE",
        "BSEE",
        "BSIE",
        "BSME",
        "BSMx",
        "BSGD",
        "BSTechM",
        "BIT",
        "BIT-AT",
        "BIT-CvT",
        "BIT-CosT",
        "BIT-DT",
        "BIT-ET",
        "BIT-ELXT",
        "BIT-FPST",
        "BIT-FCM",
        "BIT-GT",
        "BIT-IDT",
        "BIT-MST",
        "BIT-PPT",
        "BIT-RAC",
        "BIT-WFT",
        "BPA",
        "BSHM",
        "BSBA-MM",
        "BSTM",
        "BSIT",
        "BSIS",
        "BIT-CT",
        "BAEL",
        "BAL",
        "BAF",
        "BS Math",
        "BS Stat",
        "BS DevCom",
        "BSPsy",
        "BSN"
    ];
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Get employer profile photo
$employer_profile_photo = !empty($employer['usr_profile_photo']) 
    ? htmlspecialchars($employer['usr_profile_photo']) 
    : 'https://ui-avatars.com/api/?name=' . urlencode($employer['usr_name']) . '&background=random';

// Get company logo/photo
$company_logo = !empty($employer['usr_profile_photo']) 
    ? htmlspecialchars($employer['usr_profile_photo']) 
    : 'https://ui-avatars.com/api/?name=' . urlencode($employer['emp_company_name']) . '&background=random';
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
        
        /* Section Styles */
        .section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-bottom: 30px;
            border-top: 4px solid var(--secondary-color);
        }
        
        .section-header {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .section-header h2 {
            color: var(--primary-color);
            font-size: 1.7rem;
            font-weight: 600;
        }
        
        .section-header p {
            color: #666;
            margin-top: 8px;
            font-size: 1rem;
        }
        
        /* Filters Section - MODIFIED: All filters in one row */
        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            border: 1px solid #e9ecef;
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
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
            background-color: white;
            transition: all 0.3s;
            font-size: 0.95rem;
            width: 100%;
        }
        
        .filter-select:focus {
            border-color: var(--primary-color);
            outline: none;
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
            width: 100%;
        }
        
        .search-filter-container input {
            padding: 12px 15px 12px 45px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
            width: 100%;
            font-size: 0.95rem;
            transition: all 0.3s;
            background-color: white;
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
        }
        
        /* Status badges */
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-employed {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .status-actively-looking {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
        }
        
        .status-available {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
        }
        
        /* Table Styles */
        .graduates-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .graduates-table th, .graduates-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .graduates-table th {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
        }
        
        .graduates-table tr {
            transition: background-color 0.3s;
        }
        
        .graduates-table tr:hover {
            background-color: #f9f9f9;
        }
        
        /* ENHANCED: Action buttons with consistent sizing */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }
        
        .btn-action {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            font-size: 0.85rem;
            font-weight: 500;
            min-width: 120px;
            justify-content: center;
            height: 38px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
        }
        
        .btn-view:hover {
            background: linear-gradient(135deg, #0033cc, #002299);
            color: white;
        }
        
        .btn-shortlist {
            background: linear-gradient(135deg, var(--green), #1a6b0a);
            color: white;
        }
        
        .btn-shortlist:hover {
            background: linear-gradient(135deg, #1a6b0a, #145908);
            color: white;
        }
        
        .btn-shortlisted {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            cursor: not-allowed;
        }
        
        .btn-employed {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            color: white;
        }
        
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .skill-tag {
            background-color: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        /* Profile photo in table */
        .grad-photo {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 12px;
            vertical-align: middle;
            border: 2px solid #e9ecef;
        }
        
        /* ENHANCED: Professional Modal Styles */
        .grad-detail-modal, .confirmation-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 0;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 1100px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            border: none;
            animation: slideUp 0.4s ease;
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(30px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 20px;
            right: 25px;
            cursor: pointer;
            z-index: 1001;
            background: rgba(255,255,255,0.9);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .close-modal:hover {
            color: #000;
            background: rgba(255,255,255,1);
            border-color: #eee;
            transform: rotate(90deg);
        }
        
        /* ENHANCED: Professional Profile Header */
        .grad-profile-header {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 40px;
            border-radius: 16px 16px 0 0;
            position: relative;
            overflow: hidden;
        }
        
        .grad-profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .profile-main-info {
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            z-index: 2;
        }
        
        .profile-photo-container {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }
        
        .profile-photo-large {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.3);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        
        .profile-status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
            border: 2px solid;
        }
        
        .profile-basic-info {
            flex: 1;
        }
        
        .profile-basic-info h2 {
            font-size: 2.2rem;
            margin-bottom: 15px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            opacity: 0.95;
            background: rgba(255,255,255,0.1);
            padding: 8px 15px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }
        
        .meta-item strong {
            font-weight: 600;
        }
        
        .profile-contact {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            opacity: 0.9;
        }
        
        /* ENHANCED: Quick Stats Grid */
        .quick-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            padding: 30px 40px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: 1px solid #e9ecef;
        }
        
        .quick-stat {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 1px solid #f0f0f0;
        }
        
        .quick-stat:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .quick-stat .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .quick-stat .stat-icon.resume {
            background: linear-gradient(45deg, var(--primary-color), #8a0404);
        }
        
        .quick-stat .stat-icon.certificate {
            background: linear-gradient(45deg, var(--green), #1e7b34);
        }
        
        .quick-stat .stat-icon.project {
            background: linear-gradient(45deg, var(--blue), #0056b3);
        }
        
        .quick-stat .stat-icon.skills {
            background: linear-gradient(45deg, var(--purple), #5a32a3);
        }
        
        .quick-stat .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .quick-stat .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* ENHANCED: Professional Detail Sections */
        .grad-detail-sections {
            padding: 0;
        }
        
        .grad-detail-section {
            padding: 30px 40px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .grad-detail-section:last-child {
            border-bottom: none;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .section-header h3 {
            color: var(--primary-color);
            margin-bottom: 0;
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        .section-header i {
            color: var(--secondary-color);
            font-size: 1.3rem;
            width: 30px;
            text-align: center;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }
        
        .info-item {
            margin-bottom: 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            color: #333;
            font-size: 1rem;
            font-weight: 500;
        }
        
        .not-provided {
            color: #6c757d;
            font-style: italic;
        }
        
        .career-summary {
            margin-top: 25px;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            border-left: 4px solid var(--secondary-color);
        }
        
        .summary-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .summary-header h4 {
            color: var(--primary-color);
            margin: 0;
            font-size: 1.2rem;
        }
        
        .summary-content {
            color: #333;
            line-height: 1.6;
            font-size: 1rem;
        }
        
        /* ENHANCED: Skills Display */
        .skills-display {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .skill-category h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.1rem;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f0f0;
            font-weight: 600;
        }
        
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .skill-tag-detail {
            background: white;
            padding: 12px 18px;
            border-radius: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .skill-tag-detail:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .skill-name {
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .skill-level-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 1.1rem;
            margin: 0;
        }
        
        /* ENHANCED: Portfolio Items */
        .portfolio-items {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .portfolio-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            border-left: 4px solid var(--secondary-color);
            transition: all 0.3s;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
        }
        
        .portfolio-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            background: #f8f9fa;
        }
        
        .portfolio-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        
        .portfolio-info {
            flex: 1;
        }
        
        .portfolio-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }
        
        .portfolio-desc {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 8px;
            line-height: 1.5;
        }
        
        .portfolio-date {
            color: #999;
            font-size: 0.85rem;
        }
        
        .btn-view-file {
            background: linear-gradient(135deg, var(--blue), #0056b3);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .btn-view-file:hover {
            background: linear-gradient(135deg, #0056b3, #004494);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .modal-actions {
            padding: 25px 40px;
            background: #f8f9fa;
            border-radius: 0 0 16px 16px;
            border-top: 1px solid #e9ecef;
        }
        
        .modal-actions .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .modal-actions .btn-action {
            min-width: 180px;
            height: 48px;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .confirmation-content {
            text-align: center;
            padding: 40px;
        }
        
        .confirmation-content h3 {
            margin-bottom: 20px;
            color: var(--primary-color);
            text-align: center;
            font-size: 1.5rem;
        }
        
        .confirmation-content p {
            margin-bottom: 30px;
            font-size: 1.1rem;
            text-align: center;
            line-height: 1.6;
        }
        
        .confirmation-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .btn-confirm {
            background: linear-gradient(135deg, var(--green), #1a6b0a);
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(31, 122, 17, 0.3);
        }
        
        .btn-cancel {
            background: linear-gradient(135deg, var(--red), #c0392b);
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.3s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-cancel:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(211, 47, 47, 0.3);
        }
        
        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: none;
            animation: slideIn 0.5s ease;
            font-weight: 500;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .filters {
                grid-template-columns: 1fr 1fr;
            }
            
            .quick-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 900px) {
            .filters {
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
            
            .filters {
                grid-template-columns: 1fr;
            }
            
            .search-filter-container {
                width: 100%;
            }
            
            .graduates-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                min-width: 100%;
                justify-content: center;
            }
            
            .notification-dropdown {
                width: 350px;
                right: -100px;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .profile-main-info {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .profile-meta, .profile-contact {
                justify-content: center;
            }
            
            .quick-stats-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .portfolio-item {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .modal-actions .action-buttons {
                flex-direction: column;
            }
            
            .modal-actions .btn-action {
                min-width: 100%;
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
            
            .notification-dropdown {
                width: 300px;
                right: -140px;
            }
            
            .section {
                padding: 20px;
            }
            
            .section-header h2 {
                font-size: 1.4rem;
            }
            
            .confirmation-buttons {
                flex-direction: column;
            }
            
            .grad-detail-section {
                padding: 20px 25px;
            }
            
            .grad-profile-header {
                padding: 30px 25px;
            }
            
            .profile-basic-info h2 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= $company_logo ?>" alt="Company Logo">
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
                    <a href="employer_portfolio.php" class="active">
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
                                } elseif ($notif['notif_type'] === 'shortlist') {
                                    $priority_class = 'priority-medium';
                                }
                                
                                // Determine the URL for the notification
                                $notification_url = 'employer_jobs.php';
                                if (strpos($notif['notif_type'], 'application') !== false) {
                                    $notification_url = 'employer_jobs.php';
                                } elseif (strpos($notif['notif_type'], 'job') !== false) {
                                    $notification_url = 'employer_jobs.php';
                                } elseif (strpos($notif['notif_type'], 'profile') !== false) {
                                    $notification_url = 'employer_analytics.php';
                                } elseif (strpos($notif['notif_type'], 'shortlist') !== false) {
                                    $notification_url = 'employer_candidates.php';
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
                    <img src="<?= $employer_profile_photo ?>" alt="Employer">
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
            <p>Browse through all alumni registered in the system.</p>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Alumni Portfolios</h1>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success" id="successAlert"><?= $success_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error" id="errorAlert"><?= $error_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($view_message)): ?>
            <div class="alert alert-info" id="infoAlert"><?= $view_message ?></div>
        <?php endif; ?>
        
        <!-- Main Section -->
        <div class="section">
            <div class="section-header">
                <h2>All Registered Alumni</h2>
                <p>Showing <?= count($graduates) ?> alumni in the system</p>
            </div>
            
            <!-- Filters Section - MODIFIED: All filters in one row -->
            <div class="filters">
                <!-- Search Filter -->
                <div class="search-filter-group">
                    <label class="filter-label">Search</label>
                    <div class="search-filter-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search alumni..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                </div>
                
                <!-- Course Filter -->
                <div class="filter-group">
                    <label class="filter-label">Course</label>
                    <select class="filter-select" id="courseFilter" onchange="updateFilters()">
                        <option value="all" <?= $course_filter === 'all' ? 'selected' : '' ?>>All Courses</option>
                        <?php foreach ($course_options as $course): ?>
                            <option value="<?= $course ?>" <?= $course_filter === $course ? 'selected' : '' ?>><?= $course ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Year Filter -->
                <div class="filter-group">
                    <label class="filter-label">Year</label>
                    <select class="filter-select" id="yearFilter" onchange="updateFilters()">
                        <option value="all" <?= $year_filter === 'all' ? 'selected' : '' ?>>All Years</option>
                        <?php for ($year = 2022; $year <= 2050; $year++): ?>
                            <option value="<?= $year ?>" <?= $year_filter == $year ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <!-- Status Filter -->
                <div class="filter-group">
                    <label class="filter-label">Job Status</label>
                    <select class="filter-select" id="statusFilter" onchange="updateFilters()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="employed" <?= $status_filter === 'employed' ? 'selected' : '' ?>>Employed</option>
                        <option value="actively_looking" <?= $status_filter === 'actively_looking' ? 'selected' : '' ?>>Actively Looking</option>
                        <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Available</option>
                    </select>
                </div>
            </div>
            
            <!-- Graduates Table - MODIFIED: Enhanced action buttons -->
            <table class="graduates-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Degree</th>
                        <th>Year Graduated</th>
                        <th>Job Status</th>
                        <th>Shortlist Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($graduates)): ?>
                        <?php foreach ($graduates as $graduate): 
                            // Get graduate profile photo
                            $grad_photo = !empty($graduate['usr_profile_photo']) 
                                ? htmlspecialchars($graduate['usr_profile_photo']) 
                                : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random';
                            
                            // Determine status badge class and text
                            $status_classes = [
                                'employed' => 'status-employed',
                                'actively_looking' => 'status-actively-looking',
                                'available' => 'status-available'
                            ];
                            $status_text = [
                                'employed' => 'Employed',
                                'actively_looking' => 'Actively Looking',
                                'available' => 'Available'
                            ];
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center;">
                                    <img src="<?= $grad_photo ?>" alt="Profile" class="grad-photo">
                                    <div>
                                        <strong><?= htmlspecialchars($graduate['usr_name']) ?></strong>
                                        <div style="font-size: 0.8rem; color: #666;"><?= htmlspecialchars($graduate['usr_email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($graduate['grad_degree']) ?></td>
                            <td><?= htmlspecialchars($graduate['grad_year_graduated']) ?></td>
                            <td>
                                <span class="status-badge <?= $status_classes[$graduate['job_status']] ?>">
                                    <?= $status_text[$graduate['job_status']] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($graduate['is_shortlisted']): ?>
                                    <span style="color: var(--green); font-weight: bold;">Shortlisted</span>
                                <?php else: ?>
                                    <span style="color: #666;">Not Shortlisted</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <a href="?action=view&grad_id=<?= $graduate['usr_id'] ?>" class="btn-action btn-view">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                                <?php if ($graduate['is_shortlisted']): ?>
                                    <button class="btn-action btn-shortlisted" disabled>
                                        <i class="fas fa-check-circle"></i> Shortlisted
                                    </button>
                                <?php elseif ($graduate['job_status'] === 'employed'): ?>
                                    <button class="btn-action btn-employed" disabled>
                                        <i class="fas fa-briefcase"></i> Employed
                                    </button>
                                <?php else: ?>
                                    <button class="btn-action btn-shortlist" onclick="showShortlistConfirmation(<?= $graduate['usr_id'] ?>, '<?= htmlspecialchars(addslashes($graduate['usr_name'])) ?>')">
                                        <i class="fas fa-star"></i> Shortlist
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center;">No graduates found matching your criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (isset($grad_details_html)) echo $grad_details_html; ?>

    <!-- Confirmation Modal -->
    <div class="confirmation-modal" id="confirmationModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeConfirmationModal()">&times;</span>
            <div class="confirmation-content">
                <h3>Confirm Shortlist</h3>
                <p id="confirmationMessage">Are you sure you want to shortlist this alumni?</p>
                <div class="confirmation-buttons">
                    <button class="btn-confirm" id="confirmShortlist">
                        <i class="fas fa-check"></i> Yes, Shortlist
                    </button>
                    <button class="btn-cancel" onclick="closeConfirmationModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
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
        });
        
        // Confirmation modal functionality
        let currentGradId = null;
        
        function showShortlistConfirmation(gradId, gradName) {
            currentGradId = gradId;
            const message = `Are you sure you want to shortlist <strong>${gradName}</strong>?`;
            document.getElementById('confirmationMessage').innerHTML = message;
            document.getElementById('confirmationModal').style.display = 'block';
        }
        
        function closeConfirmationModal() {
            document.getElementById('confirmationModal').style.display = 'none';
            currentGradId = null;
        }
        
        // Set up the confirmation button event
        document.getElementById('confirmShortlist').addEventListener('click', function() {
            if (currentGradId) {
                window.location.href = '?action=shortlist&grad_id=' + currentGradId;
            }
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const confirmationModal = document.getElementById('confirmationModal');
            if (event.target == confirmationModal) {
                closeConfirmationModal();
            }
            
            const gradModal = document.getElementById('gradDetailModal');
            if (event.target == gradModal) {
                closeGradModal();
            }
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
            const courseFilter = document.getElementById('courseFilter').value;
            const yearFilter = document.getElementById('yearFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const searchQuery = searchInput.value;
            
            // Build URL with parameters
            const url = new URL(window.location.href);
            url.searchParams.set('course', courseFilter);
            url.searchParams.set('year', yearFilter);
            url.searchParams.set('status', statusFilter);
            url.searchParams.set('search', searchQuery);
            
            // Remove action parameters if present
            url.searchParams.delete('action');
            url.searchParams.delete('grad_id');
            
            // Navigate to the new URL
            window.location.href = url.toString();
        }
        
        // Show alerts if they exist
        window.onload = function() {
            <?php if (isset($success_message)): ?>
                document.getElementById('successAlert').style.display = 'block';
                setTimeout(function() {
                    document.getElementById('successAlert').style.display = 'none';
                }, 5000);
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                document.getElementById('errorAlert').style.display = 'block';
                setTimeout(function() {
                    document.getElementById('errorAlert').style.display = 'none';
                }, 5000);
            <?php endif; ?>
            
            <?php if (isset($view_message)): ?>
                document.getElementById('infoAlert').style.display = 'block';
                setTimeout(function() {
                    document.getElementById('infoAlert').style.display = 'none';
                }, 5000);
            <?php endif; ?>
        };
    </script>
</body>
</html>