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
    
    // Check if shortlists table exists, if not create it
    $check_table = $conn->query("SHOW TABLES LIKE 'shortlists'");
    $table_exists = $check_table->rowCount() > 0;
    
    if (!$table_exists) {
        // Create shortlists table with proper prefix
        $create_shortlists = "CREATE TABLE shortlists (
            short_id INT AUTO_INCREMENT PRIMARY KEY,
            short_emp_usr_id INT NOT NULL,
            short_grad_usr_id INT NOT NULL,
            short_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (short_emp_usr_id) REFERENCES users(usr_id) ON DELETE CASCADE,
            FOREIGN KEY (short_grad_usr_id) REFERENCES users(usr_id) ON DELETE CASCADE,
            UNIQUE KEY unique_shortlist (short_emp_usr_id, short_grad_usr_id)
        )";
        
        $conn->exec($create_shortlists);
    }
    
    // Handle remove from shortlist action
    if (isset($_GET['action']) && $_GET['action'] == 'remove' && isset($_GET['grad_id'])) {
        $grad_id = $_GET['grad_id'];
        
        $remove_query = "DELETE FROM shortlists WHERE short_emp_usr_id = :employer_id AND short_grad_usr_id = :grad_id";
        $stmt = $conn->prepare($remove_query);
        $stmt->bindParam(':employer_id', $employer_id);
        $stmt->bindParam(':grad_id', $grad_id);
        
        if ($stmt->execute()) {
            $success_message = "Graduate removed from your shortlist!";
        } else {
            $error_message = "Error removing from shortlist";
        }
    }
    
    // Initialize shortlisted graduates array
    $shortlisted_graduates = [];
    
    // Only try to fetch shortlisted graduates if the table exists or was just created
    $check_table_after = $conn->query("SHOW TABLES LIKE 'shortlists'");
    if ($check_table_after->rowCount() > 0) {
        // Fetch shortlisted graduates - FIXED QUERY
        $shortlist_query = "
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
                g.grad_school_id,
                g.grad_summary,
                s.short_created_at
            FROM 
                users u
            JOIN 
                graduates g ON u.usr_id = g.grad_usr_id
            JOIN
                shortlists s ON u.usr_id = s.short_grad_usr_id
            WHERE 
                s.short_emp_usr_id = :employer_id
            ORDER BY 
                s.short_created_at DESC
        ";
    
        $stmt = $conn->prepare($shortlist_query);
        $stmt->bindParam(':employer_id', $employer_id);
        $stmt->execute();
        $shortlisted_graduates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Handle view graduate details
    if (isset($_GET['action']) && $_GET['action'] == 'view' && isset($_GET['grad_id'])) {
        $grad_id = $_GET['grad_id'];
        
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
                u.usr_id = :grad_id
        ";
        
        $stmt = $conn->prepare($grad_detail_query);
        $stmt->bindParam(':grad_id', $grad_id);
        $stmt->execute();
        $grad_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($grad_details) {
            // Fetch graduate skills
            $skills_query = "
                SELECT s.skill_name, s.skill_category, gs.skill_level 
                FROM graduate_skills gs
                JOIN skills s ON gs.skill_id = s.skill_id
                WHERE gs.grad_usr_id = :grad_id
                ORDER BY gs.skill_level DESC, s.skill_name
            ";
            $stmt = $conn->prepare($skills_query);
            $stmt->bindParam(':grad_id', $grad_id);
            $stmt->execute();
            $grad_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch portfolio items
            $portfolio_query = "
                SELECT port_item_type, port_item_title, port_item_description, port_item_file, port_created_at
                FROM portfolio_items 
                WHERE port_usr_id = :grad_id
                ORDER BY port_item_type, port_created_at DESC
            ";
            $stmt = $conn->prepare($portfolio_query);
            $stmt->bindParam(':grad_id', $grad_id);
            $stmt->execute();
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
            
            // Format the details for display
            $view_message = "Viewing profile of: " . htmlspecialchars($grad_details['usr_name']);
            
            // Get profile photo URL
            $grad_profile_photo = !empty($grad_details['usr_profile_photo']) 
                ? htmlspecialchars($grad_details['usr_profile_photo']) 
                : 'https://ui-avatars.com/api/?name=' . urlencode($grad_details['usr_name']) . '&background=random';
            
            // Get job status display text and color
            $job_status_display = [
                'employed' => ['text' => 'Employed', 'color' => '#28a745', 'icon' => 'fas fa-briefcase'],
                'actively_looking' => ['text' => 'Actively Looking', 'color' => '#ffc107', 'icon' => 'fas fa-search'],
                'available' => ['text' => 'Available', 'color' => '#17a2b8', 'icon' => 'fas fa-user-check']
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
                            <img src="' . $grad_profile_photo . '" alt="Profile Photo" class="profile-photo-large">
                            <div class="profile-basic-info">
                                <h2>' . htmlspecialchars($grad_details['usr_name']) . '</h2>
                                <div class="profile-status">
                                    <span class="status-badge-large" style="background-color: ' . $current_status['color'] . ';">
                                        <i class="' . $current_status['icon'] . '"></i> ' . $current_status['text'] . '
                                    </span>
                                </div>
                                <div class="profile-meta">
                                    <span><i class="fas fa-graduation-cap"></i> ' . htmlspecialchars($grad_details['grad_degree']) . '</span>
                                    <span><i class="fas fa-calendar-alt"></i> Graduated: ' . htmlspecialchars($grad_details['grad_year_graduated']) . '</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grad-detail-sections">
                        <!-- Quick Stats -->
                        <div class="quick-stats-grid">
                            <div class="quick-stat">
                                <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                                <div class="stat-info">
                                    <div class="stat-number">' . $resume_count . '</div>
                                    <div class="stat-label">Resumes</div>
                                </div>
                            </div>
                            <div class="quick-stat">
                                <div class="stat-icon"><i class="fas fa-certificate"></i></div>
                                <div class="stat-info">
                                    <div class="stat-number">' . $certificate_count . '</div>
                                    <div class="stat-label">Certificates</div>
                                </div>
                            </div>
                            <div class="quick-stat">
                                <div class="stat-icon"><i class="fas fa-project-diagram"></i></div>
                                <div class="stat-info">
                                    <div class="stat-number">' . $project_count . '</div>
                                    <div class="stat-label">Projects</div>
                                </div>
                            </div>
                            <div class="quick-stat">
                                <div class="stat-icon"><i class="fas fa-code"></i></div>
                                <div class="stat-info">
                                    <div class="stat-number">' . count($grad_skills) . '</div>
                                    <div class="stat-label">Skills</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Information -->
                        <div class="grad-detail-section">
                            <h3><i class="fas fa-user"></i> Personal Information</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Email</div>
                                    <div class="info-value">' . htmlspecialchars($grad_details['usr_email']) . '</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Phone</div>
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
                            <h3><i class="fas fa-graduation-cap"></i> Education & Career</h3>
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
                                <div class="info-label">Career Summary</div>
                                <div class="info-value">' . nl2br(htmlspecialchars($grad_details['grad_summary'])) . '</div>
                            </div>' : '') . '
                        </div>
                        
                        <!-- Skills -->
                        <div class="grad-detail-section">
                            <h3><i class="fas fa-code"></i> Skills & Competencies</h3>
                            ' . (count($grad_skills) > 0 ? '
                            <div class="skills-display">
                                ' . implode('', array_map(function($category, $skills) {
                                    return '
                                    <div class="skill-category">
                                        <h4>' . htmlspecialchars($category) . '</h4>
                                        <div class="skills-list">
                                            ' . implode('', array_map(function($skill) {
                                                $level_class = $skill['skill_level'] ?? 'intermediate';
                                                return '
                                                <div class="skill-tag-detail">
                                                    <span class="skill-name">' . htmlspecialchars($skill['skill_name']) . '</span>
                                                    <span class="skill-level-badge ' . $level_class . '">' . ucfirst($level_class) . '</span>
                                                </div>';
                                            }, $skills)) . '
                                        </div>
                                    </div>';
                                }, array_keys($skills_by_category), array_values($skills_by_category))) . '
                            </div>' : '
                            <div class="empty-skills">
                                <i class="fas fa-code"></i>
                                <p>No skills added yet</p>
                            </div>') . '
                        </div>
                        
                        <!-- Portfolio Items -->
                        <div class="grad-detail-section">
                            <h3><i class="fas fa-briefcase"></i> Portfolio</h3>
                            ' . (count($portfolio_items) > 0 ? '
                            <div class="portfolio-items">
                                ' . implode('', array_map(function($item) {
                                    $icon = '';
                                    $type_class = '';
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
                                        <div class="portfolio-icon">
                                            <i class="fas ' . $icon . '"></i>
                                        </div>
                                        <div class="portfolio-info">
                                            <div class="portfolio-title">' . htmlspecialchars($item['port_item_title']) . '</div>
                                            ' . (!empty($item['port_item_description']) ? '<div class="portfolio-desc">' . htmlspecialchars($item['port_item_description']) . '</div>' : '') . '
                                            <div class="portfolio-date">Uploaded: ' . date('M j, Y', strtotime($item['port_created_at'])) . '</div>
                                        </div>
                                        <div class="portfolio-action">
                                            <a href="' . htmlspecialchars($item['port_item_file']) . '" target="_blank" class="btn-view-file">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </div>';
                                }, $portfolio_items)) . '
                            </div>' : '
                            <div class="empty-portfolio">
                                <i class="fas fa-briefcase"></i>
                                <p>No portfolio items uploaded yet</p>
                            </div>') . '
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button class="btn-remove" onclick="showRemoveConfirmation(' . $grad_details['usr_id'] . ', \'' . htmlspecialchars(addslashes($grad_details['usr_name'])) . '\')">Remove From Shortlist</button>
                    </div>
                </div>
            </div>
            <script>
                // Display the modal
                document.getElementById("gradDetailModal").style.display = "block";
                
                // Close modal functionality
                document.querySelector(".close-modal").addEventListener("click", function() {
                    document.getElementById("gradDetailModal").style.display = "none";
                });
                
                // Close modal when clicking outside
                window.addEventListener("click", function(event) {
                    if (event.target == document.getElementById("gradDetailModal")) {
                        document.getElementById("gradDetailModal").style.display = "none";
                    }
                });
            </script>';
        }
    }
    
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Shortlisted Candidates</title>
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
        
        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }
        
        /* Section Styles */
        .section {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            margin-bottom: 30px;
            border-top: 4px solid var(--secondary-color);
        }
        
        .section-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .section-header h2 {
            color: var(--primary-color);
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .section-header p {
            color: #666;
            margin-top: 5px;
        }
        
        /* Stats Section */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            text-align: center;
            border-top: 4px solid var(--secondary-color);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Table Styles */
        .graduates-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .graduates-table th, .graduates-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .graduates-table th {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
        }
        
        .graduates-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--blue), #0039e6);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 68, 255, 0.3);
            text-decoration: none;
            color: white;
        }
        
        .btn-remove {
            background: linear-gradient(135deg, var(--red), #c62828);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-remove:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(211, 47, 47, 0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ddd;
        }
        
        .empty-state h3 {
            margin-bottom: 15px;
            color: #555;
            font-weight: 600;
        }
        
        .empty-state p {
            margin-bottom: 25px;
            font-size: 1rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(110, 3, 3, 0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Enhanced Modal Styles */
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
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            border: none;
        }
        
        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 15px;
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
        }
        
        .close-modal:hover {
            color: #000;
            background: rgba(255,255,255,1);
        }
        
        /* Enhanced Profile Header */
        .grad-profile-header {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 30px;
            border-radius: 12px 12px 0 0;
        }
        
        .profile-main-info {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .profile-photo-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.2);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .profile-basic-info h2 {
            font-size: 2rem;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .profile-status {
            margin-bottom: 15px;
        }
        
        .status-badge-large {
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .profile-meta span {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            opacity: 0.9;
        }
        
        /* Quick Stats Grid */
        .quick-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
        }
        
        .quick-stat {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .quick-stat .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }
        
        .quick-stat .stat-icon:nth-child(1) { background: linear-gradient(45deg, var(--primary-color), #8a0404); }
        .quick-stat .stat-icon:nth-child(2) { background: linear-gradient(45deg, var(--green), #1e7b34); }
        .quick-stat .stat-icon:nth-child(3) { background: linear-gradient(45deg, var(--blue), #0056b3); }
        .quick-stat .stat-icon:nth-child(4) { background: linear-gradient(45deg, var(--purple), #5a32a3); }
        
        .quick-stat .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 2px;
        }
        
        .quick-stat .stat-label {
            color: #666;
            font-size: 0.8rem;
        }
        
        /* Enhanced Detail Sections */
        .grad-detail-sections {
            padding: 0;
        }
        
        .grad-detail-section {
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
        }
        
        .grad-detail-section:last-child {
            border-bottom: none;
        }
        
        .grad-detail-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .grad-detail-section h3 i {
            color: var(--secondary-color);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
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
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
        }
        
        /* Skills Display */
        .skills-display {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .skill-category h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-size: 1.1rem;
            padding-bottom: 5px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .skill-tag-detail {
            background: white;
            padding: 10px 15px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
            transition: transform 0.2s;
        }
        
        .skill-tag-detail:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .skill-name {
            font-weight: 500;
        }
        
        .skill-level-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .skill-level-badge.beginner {
            background: #e3f2fd;
            color: #1565c0;
        }
        
        .skill-level-badge.intermediate {
            background: #e8f5e8;
            color: #2e7d32;
        }
        
        .skill-level-badge.advanced {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .skill-level-badge.expert {
            background: #fce4ec;
            color: #c2185b;
        }
        
        .empty-skills, .empty-portfolio {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-skills i, .empty-portfolio i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Portfolio Items */
        .portfolio-items {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .portfolio-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
            transition: background-color 0.2s;
        }
        
        .portfolio-item:hover {
            background: #e9ecef;
        }
        
        .portfolio-item.resume {
            border-left-color: var(--primary-color);
        }
        
        .portfolio-item.certificate {
            border-left-color: var(--green);
        }
        
        .portfolio-item.project {
            border-left-color: var(--blue);
        }
        
        .portfolio-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .portfolio-item.resume .portfolio-icon {
            background: linear-gradient(45deg, var(--primary-color), #8a0404);
        }
        
        .portfolio-item.certificate .portfolio-icon {
            background: linear-gradient(45deg, var(--green), #1e7b34);
        }
        
        .portfolio-item.project .portfolio-icon {
            background: linear-gradient(45deg, var(--blue), #0056b3);
        }
        
        .portfolio-info {
            flex: 1;
        }
        
        .portfolio-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .portfolio-desc {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        
        .portfolio-date {
            color: #999;
            font-size: 0.8rem;
        }
        
        .btn-view-file {
            background: var(--blue);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-view-file:hover {
            background: #0056b3;
            color: white;
            text-decoration: none;
        }
        
        .modal-actions {
            padding: 20px 30px;
            background: #f8f9fa;
            border-radius: 0 0 12px 12px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        
        .confirmation-content {
            text-align: center;
            padding: 20px;
        }
        
        .confirmation-content h3 {
            margin-bottom: 15px;
            color: var(--primary-color);
            text-align: center;
        }
        
        .confirmation-content p {
            margin-bottom: 20px;
            font-size: 1rem;
            text-align: center;
        }
        
        .confirmation-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        .btn-confirm {
            background-color: var(--red);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.2s;
        }
        
        .btn-cancel {
            background-color: #666;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.2s;
        }
        
        .btn-confirm:hover {
            background-color: #c82333;
        }
        
        .btn-cancel:hover {
            background-color: #555;
        }
        
        /* Graduate photo in table */
        .grad-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
            vertical-align: middle;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .quick-stats-grid {
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
            
            .graduates-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .dropdown {
                width: 90%;
                right: 5%;
            }
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .profile-main-info {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
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
            }
            
            .notification-dropdown {
                width: 350px;
                right: -100px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .confirmation-buttons {
                flex-direction: column;
            }
            
            .grad-detail-section {
                padding: 15px 20px;
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
                    <a href="employer_candidates.php" class="active">
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
                                }
                                
                                // Determine the URL for the notification
                                $notification_url = 'employer_jobs.php';
                                if (strpos($notif['notif_type'], 'application') !== false) {
                                    $notification_url = 'employer_jobs.php';
                                } elseif (strpos($notif['notif_type'], 'job') !== false) {
                                    $notification_url = 'employer_jobs.php';
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
            <p>Manage your shortlisted candidates here.</p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Shortlisted Candidates</h1>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?= $success_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><?= $error_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($view_message)): ?>
            <div class="alert alert-info"><?= $view_message ?></div>
        <?php endif; ?>
        
        <!-- Stats Section -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?= count($shortlisted_graduates) ?></div>
                <div class="stat-label">Shortlisted Candidates</div>
            </div>
        </div>
        
        <!-- Main Section -->
        <div class="section">
            <div class="section-header">
                <h2>Your Shortlisted Alumni</h2>
                <p>Showing <?= count($shortlisted_graduates) ?> alumni in your shortlist</p>
            </div>
            
            <?php if (!empty($shortlisted_graduates)): ?>
                <!-- Graduates Table -->
                <table class="graduates-table">
                    <thead>
                        <tr>
                            <th>Profile</th>
                            <th>Name</th>
                            <th>Degree</th>
                            <th>Year Graduated</th>
                            <th>Job Preference</th>
                            <th>Shortlisted On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shortlisted_graduates as $graduate): ?>
                        <tr>
                            <td>
                                <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" alt="Graduate Photo" class="grad-photo">
                            </td>
                            <td>
                                <div><strong><?= htmlspecialchars($graduate['usr_name']) ?></strong></div>
                                <div style="font-size: 0.8rem; color: #666;"><?= htmlspecialchars($graduate['usr_email']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($graduate['grad_degree']) ?></td>
                            <td><?= htmlspecialchars($graduate['grad_year_graduated']) ?></td>
                            <td><?= htmlspecialchars($graduate['grad_job_preference']) ?></td>
                            <td><?= date('M j, Y', strtotime($graduate['short_created_at'])) ?></td>
                            <td class="action-buttons">
                                <a href="?action=view&grad_id=<?= $graduate['usr_id'] ?>" class="btn-view"><i class="fas fa-eye"></i> View</a>
                                <button class="btn-remove" onclick="showRemoveConfirmation(<?= $graduate['usr_id'] ?>, '<?= htmlspecialchars(addslashes($graduate['usr_name'])) ?>')"><i class="fas fa-trash"></i> Remove</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <i class="fas fa-user-plus"></i>
                    <h3>No shortlisted candidates yet</h3>
                    <p>You haven't added any alumni to your shortlist yet.</p>
                    <a href="employer_portfolio.php" class="btn-primary">Browse Alumni</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($grad_details_html)) echo $grad_details_html; ?>

    <!-- Confirmation Modal -->
    <div class="confirmation-modal" id="confirmationModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeConfirmationModal()">&times;</span>
            <div class="confirmation-content">
                <h3>Confirm Removal</h3>
                <p id="confirmationMessage">Are you sure you want to remove this alumni from your shortlist?</p>
                <div class="confirmation-buttons">
                    <button class="btn-confirm" id="confirmRemove">Yes, Remove</button>
                    <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
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
        
        // Confirmation modal functionality
        let currentGradId = null;
        
        function showRemoveConfirmation(gradId, gradName) {
            currentGradId = gradId;
            const message = `Are you sure you want to remove <strong>${gradName}</strong> from your shortlist?`;
            document.getElementById('confirmationMessage').innerHTML = message;
            document.getElementById('confirmationModal').style.display = 'block';
        }
        
        function closeConfirmationModal() {
            document.getElementById('confirmationModal').style.display = 'none';
            currentGradId = null;
        }
        
        // Set up the confirmation button event
        document.getElementById('confirmRemove').addEventListener('click', function() {
            if (currentGradId) {
                window.location.href = '?action=remove&grad_id=' + currentGradId;
            }
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const confirmationModal = document.getElementById('confirmationModal');
            if (event.target == confirmationModal) {
                closeConfirmationModal();
            }
        });
    </script>
</body>
</html>