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
    
    // Get staff data
    $staff_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT u.*
        FROM users u 
        WHERE u.usr_id = :staff_id
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
    
    // Handle AJAX request for graduate resource history
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_graduate_resources' && isset($_GET['grad_id'])) {
        $grad_id = $_GET['grad_id'];
        
        // Get resource history for this specific graduate
        $resources_stmt = $conn->prepare("
            SELECT sr.*, u.usr_name as graduate_name
            FROM shared_resources sr
            JOIN users u ON sr.grad_usr_id = u.usr_id
            WHERE sr.grad_usr_id = :grad_id AND sr.staff_usr_id = :staff_id
            ORDER BY sr.shared_at DESC
        ");
        $resources_stmt->bindParam(':grad_id', $grad_id);
        $resources_stmt->bindParam(':staff_id', $staff_id);
        $resources_stmt->execute();
        $resources = $resources_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get statistics
        $total_stmt = $conn->prepare("
            SELECT COUNT(*) as total FROM shared_resources 
            WHERE grad_usr_id = :grad_id AND staff_usr_id = :staff_id
        ");
        $total_stmt->bindParam(':grad_id', $grad_id);
        $total_stmt->bindParam(':staff_id', $staff_id);
        $total_stmt->execute();
        $total = $total_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $read_stmt = $conn->prepare("
            SELECT COUNT(*) as read_count FROM shared_resources 
            WHERE grad_usr_id = :grad_id AND staff_usr_id = :staff_id AND is_read = TRUE
        ");
        $read_stmt->bindParam(':grad_id', $grad_id);
        $read_stmt->bindParam(':staff_id', $staff_id);
        $read_stmt->execute();
        $read = $read_stmt->fetch(PDO::FETCH_ASSOC)['read_count'];
        
        $unread = $total - $read;
        
        // Format resource types for display
        foreach ($resources as &$resource) {
            $resource['resource_type'] = ucfirst(str_replace('_', ' ', $resource['resource_type']));
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'resources' => $resources,
            'total_resources' => $total,
            'read_resources' => $read,
            'unread_resources' => $unread
        ]);
        exit();
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
            header("Location: staff_assist_grads.php");
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
            $notifications = generateDefaultStaffNotifications($conn, $staff_id);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Staff notifications query error: " . $e->getMessage());
        // Generate default notifications
        $notifications = generateDefaultStaffNotifications($conn, $staff_id);
        $unread_notif_count = count($notifications);
    }
    
    // Get filter parameters
    $course_filter = $_GET['course'] ?? 'all';
    $year_filter = $_GET['year'] ?? 'all';
    $search_query = $_GET['search'] ?? '';
    
    // MODIFIED: Build query to fetch ALL graduates (not just those needing assistance)
    $query = "
        SELECT u.usr_id, u.usr_name, u.usr_email, g.grad_degree, g.grad_year_graduated,
               COUNT(p.port_id) as portfolio_items,
               COUNT(a.app_id) as applications
        FROM users u
        JOIN graduates g ON u.usr_id = g.grad_usr_id
        LEFT JOIN portfolio_items p ON u.usr_id = p.port_usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
        WHERE u.usr_role = 'graduate' AND u.usr_account_status = 'active'
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
    
    // MODIFIED: Remove filtering condition to show ALL graduates
    $query .= " GROUP BY u.usr_id
                ORDER BY portfolio_items ASC, applications ASC";
    
    // Prepare and execute query
    $grads_stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $grads_stmt->bindValue($key, $value);
    }
    $grads_stmt->execute();
    $graduates = $grads_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process feedback submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
        $grad_id = $_POST['grad_id'];
        $feedback_type = $_POST['feedback_type'];
        $feedback_content = $_POST['feedback_content'];
        
        // Insert feedback into database
        $feedback_stmt = $conn->prepare("
            INSERT INTO user_activities (activity_usr_id, activity_type, activity_details, activity_date)
            VALUES (:grad_id, 'staff_feedback', :feedback_content, NOW())
        ");
        $feedback_stmt->bindParam(':grad_id', $grad_id);
        $feedback_stmt->bindParam(':feedback_content', $feedback_content);
        $feedback_stmt->execute();
        
        // Create notification for graduate
        $notif_message = "Staff member " . $staff['usr_name'] . " provided feedback on your " . $feedback_type;
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at)
            VALUES (:grad_id, :message, 'feedback', NOW())
        ");
        $notif_stmt->bindParam(':grad_id', $grad_id);
        $notif_stmt->bindParam(':message', $notif_message);
        $notif_stmt->execute();
        
        $feedback_success = "Feedback submitted successfully!";
    }
    
    // Process resource sharing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_resource'])) {
        $grad_id = $_POST['grad_id'];
        $resource_type = $_POST['resource_type'];
        $resource_title = $_POST['resource_title'];
        $resource_description = $_POST['resource_description'];
        $resource_url = $_POST['resource_url'];
        
        // Insert resource into shared_resources table
        $resource_stmt = $conn->prepare("
            INSERT INTO shared_resources (grad_usr_id, staff_usr_id, resource_type, resource_title, resource_description, resource_url, shared_at)
            VALUES (:grad_id, :staff_id, :resource_type, :resource_title, :resource_description, :resource_url, NOW())
        ");
        $resource_stmt->bindParam(':grad_id', $grad_id);
        $resource_stmt->bindParam(':staff_id', $staff_id);
        $resource_stmt->bindParam(':resource_type', $resource_type);
        $resource_stmt->bindParam(':resource_title', $resource_title);
        $resource_stmt->bindParam(':resource_description', $resource_description);
        $resource_stmt->bindParam(':resource_url', $resource_url);
        $resource_stmt->execute();
        
        // Create notification for graduate
        $notif_message = "Staff member " . $staff['usr_name'] . " shared a " . $resource_type . " resource with you: " . $resource_title;
        $notif_stmt = $conn->prepare("
            INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at)
            VALUES (:grad_id, :message, 'resource', NOW())
        ");
        $notif_stmt->bindParam(':grad_id', $grad_id);
        $notif_stmt->bindParam(':message', $notif_message);
        $notif_stmt->execute();
        
        $resource_success = "Resource shared successfully!";
    }
    
    // Handle portfolio view action - ONLY when explicitly requested
    $grad_details_html = '';
    if (isset($_GET['action']) && $_GET['action'] == 'view' && isset($_GET['grad_id']) && !isset($_GET['course']) && !isset($_GET['year']) && !isset($_GET['search'])) {
        $grad_id = $_GET['grad_id'];
        
        // Fetch detailed graduate information
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
            
            // Format the details for display
            $view_message = "Viewing profile of: " . htmlspecialchars($grad_details['usr_name']);
            
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
                    <span class="close-modal" onclick="closeGradModal()">&times;</span>
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
                        <div class="action-buttons">
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
                    // Remove the action parameter from URL without refreshing
                    const url = new URL(window.location.href);
                    url.searchParams.delete("action");
                    url.searchParams.delete("grad_id");
                    window.history.replaceState({}, "", url.toString());
                }
                
                // Close modal when clicking outside
                window.addEventListener("click", function(event) {
                    if (event.target == document.getElementById("gradDetailModal")) {
                        closeGradModal();
                    }
                });
            </script>';
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

/**
 * Generate default notifications for staff based on system status
 */
function generateDefaultStaffNotifications($conn, $staff_id) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Staff Dashboard! Monitor system activities and assist graduates.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Assist Alumni</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--secondary-color);
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
      display: flex;
      align-items: center;
      gap: 6px;
    }
    
    .filter-label i {
      color: var(--secondary-color);
    }
    
    .filter-select {
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #ddd;
      min-width: 180px;
      background: white;
      transition: all 0.3s;
      font-size: 14px;
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
      width: 320px;
    }
    
    .search-filter-container input {
      padding: 10px 15px 10px 40px;
      border: 1px solid #ddd;
      border-radius: 8px;
      outline: none;
      width: 100%;
      font-size: 14px;
      transition: all 0.3s;
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
    
    /* Enhanced Graduates Section */
    .graduates-section {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 30px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--accent-color);
    }
    
    .graduates-section h2 {
      color: var(--primary-color);
      margin-bottom: 20px;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      gap: 10px;
      padding-bottom: 12px;
      border-bottom: 2px solid var(--primary-color);
    }
    
    .graduates-section h2 i {
      color: var(--accent-color);
    }
    
    .graduates {
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      overflow: hidden;
      margin-bottom: 20px;
    }
    
    .graduate-header {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
      padding: 18px 25px;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      font-weight: bold;
      color: white;
      border-bottom: 2px solid #ddd;
    }
    
    .graduate-item {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
      padding: 18px 25px;
      border-bottom: 1px solid #eee;
      align-items: center;
      transition: all 0.3s;
    }
    
    .graduate-item:hover {
      background: #f9f9f9;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    /* MODIFIED: Action buttons with same size */
    .action-buttons {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: flex-start;
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
      min-width: 160px; /* MODIFIED: Same width for both buttons */
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
    
    .assist-btn {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.85rem;
      min-width: 160px; /* MODIFIED: Same width for both buttons */
      justify-content: center;
      height: 38px;
      text-decoration: none;
    }
    
    .assist-btn:hover {
      background: linear-gradient(135deg, #8a0404, #6e0303);
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
      color: white;
    }
    
    /* Enhanced Modal Overlay */
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
    
    .assistance-modal {
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
    
    .modal-overlay.active .assistance-modal {
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
    
    /* Task Selection Screen */
    .task-selection {
      text-align: center;
      padding: 20px;
    }
    
    .task-selection h2 {
      color: var(--primary-color);
      margin-bottom: 10px;
      font-size: 1.8rem;
    }
    
    .task-selection p {
      color: #666;
      margin-bottom: 30px;
      font-size: 1rem;
    }
    
    .task-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
      margin-bottom: 30px;
    }
    
    .task-card {
      background: white;
      border-radius: 12px;
      padding: 25px 20px;
      text-align: center;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
      border-top: 5px solid var(--primary-color);
      cursor: pointer;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      height: 100%;
    }
    
    .task-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
    }
    
    .task-card.resources {
      border-top-color: var(--blue);
    }
    
    .task-card.feedback {
      border-top-color: var(--green);
    }
    
    .task-card.history {
      border-top-color: var(--purple);
    }
    
    .task-icon {
      width: 70px;
      height: 70px;
      margin: 0 auto 15px;
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      color: white;
    }
    
    .task-card.resources .task-icon {
      background: linear-gradient(135deg, var(--blue), #0066ff);
    }
    
    .task-card.feedback .task-icon {
      background: linear-gradient(135deg, var(--green), #2ecc71);
    }
    
    .task-card.history .task-icon {
      background: linear-gradient(135deg, var(--purple), #8e44ad);
    }
    
    .task-card h3 {
      color: var(--primary-color);
      margin-bottom: 12px;
      font-size: 1.3rem;
    }
    
    .task-card p {
      color: #666;
      margin-bottom: 15px;
      line-height: 1.5;
      font-size: 0.9rem;
      flex-grow: 1;
    }
    
    .task-btn {
      background: var(--primary-color);
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.9rem;
      margin-top: auto;
    }
    
    .task-card.resources .task-btn {
      background: var(--blue);
    }
    
    .task-card.feedback .task-btn {
      background: var(--green);
    }
    
    .task-card.history .task-btn {
      background: var(--purple);
    }
    
    .task-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }
    
    /* Enhanced Resource Sections */
    .resource-section {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--accent-color);
      display: none;
    }
    
    .resource-section.active {
      display: block;
    }
    
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid var(--primary-color);
    }
    
    .section-title {
      color: var(--primary-color);
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .section-title i {
      color: var(--accent-color);
    }
    
    /* Enhanced Resource Sharing Form */
    .resource-form {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      padding: 25px;
      border-radius: 10px;
      margin-bottom: 20px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-left: 5px solid var(--primary-color);
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--primary-color);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .form-label i {
      width: 20px;
      text-align: center;
    }
    
    .form-input, .form-select, .form-textarea {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
      transition: all 0.3s;
      background: white;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
    }
    
    .form-textarea {
      min-height: 120px;
      resize: vertical;
    }
    
    .resource-type {
      display: flex;
      gap: 20px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    
    .resource-type label {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 10px 15px;
      background: white;
      border-radius: 6px;
      border: 2px solid #e0e0e0;
      transition: all 0.3s;
    }
    
    .resource-type label:hover {
      border-color: var(--primary-color);
      background: #f9f9f9;
    }
    
    .resource-type input[type="radio"]:checked + span {
      color: var(--primary-color);
      font-weight: 600;
    }
    
    .resource-type input[type="radio"] {
      display: none;
    }
    
    .resource-type input[type="radio"]:checked + span:before {
      content: "✓ ";
      color: var(--primary-color);
    }
    
    .submit-btn {
      background: linear-gradient(135deg, var(--primary-color), #8a0404);
      color: white;
      padding: 12px 25px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    
    .submit-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }
    
    /* Success Messages */
    .notification-message {
      background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
      color: #2e7d32;
      padding: 15px 20px;
      border-radius: 8px;
      margin-bottom: 20px;
      display: <?= isset($feedback_success) || isset($resource_success) ? 'block' : 'none' ?>;
      border-left: 4px solid #2e7d32;
      font-weight: 500;
    }
    
    /* Enhanced Feedback Module */
    .feedback-module {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      border-radius: 10px;
      padding: 25px;
      margin-bottom: 20px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-left: 5px solid var(--blue);
    }
    
    .feedback-item {
      margin-bottom: 25px;
    }
    
    .feedback-item h3 {
      margin-bottom: 15px;
      color: var(--primary-color);
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.3rem;
    }
    
    .feedback-item h3 i {
      color: var(--accent-color);
    }
    
    textarea {
      width: 100%;
      padding: 15px;
      border: 1px solid #ddd;
      border-radius: 6px;
      resize: vertical;
      min-height: 120px;
      font-family: inherit;
      transition: all 0.3s;
    }
    
    textarea:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
    }
    
    .feedback-type {
      display: flex;
      gap: 20px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    
    .feedback-type label {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 10px 15px;
      background: white;
      border-radius: 6px;
      border: 2px solid #e0e0e0;
      transition: all 0.3s;
    }
    
    .feedback-type label:hover {
      border-color: var(--primary-color);
      background: #f9f9f9;
    }
    
    .feedback-type input[type="radio"]:checked + span {
      color: var(--primary-color);
      font-weight: 600;
    }
    
    .feedback-type input[type="radio"] {
      display: none;
    }
    
    .feedback-type input[type="radio"]:checked + span:before {
      content: "✓ ";
      color: var(--primary-color);
    }
    
    /* Enhanced Resource History */
    .resource-history {
      background: white;
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 25px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-top: 4px solid var(--purple);
    }
    
    .resource-history h3 {
      color: var(--primary-color);
      margin-bottom: 20px;
      font-size: 1.3rem;
      display: flex;
      align-items: center;
      gap: 10px;
      padding-bottom: 12px;
      border-bottom: 2px solid var(--primary-color);
    }
    
    .resource-history h3 i {
      color: var(--accent-color);
    }
    
    .history-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 20px;
      margin-bottom: 25px;
    }
    
    .history-stat {
      background: linear-gradient(135deg, #f8f9fa, #e9ecef);
      padding: 20px;
      border-radius: 10px;
      text-align: center;
      border-left: 5px solid var(--primary-color);
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
      transition: all 0.3s;
    }
    
    .history-stat:hover {
      transform: translateY(-5px);
      box-shadow: 0 6px 15px rgba(0, 0, 0, 0.12);
    }
    
    .history-number {
      font-size: 2rem;
      font-weight: bold;
      color: var(--primary-color);
      margin-bottom: 8px;
    }
    
    .history-label {
      color: #666;
      font-size: 0.9rem;
      font-weight: 500;
    }
    
    .history-list {
      max-height: 350px;
      overflow-y: auto;
      border: 1px solid #eee;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }
    
    .history-item {
      padding: 18px;
      border-bottom: 1px solid #eee;
      display: grid;
      grid-template-columns: 2fr 1fr 1fr;
      gap: 15px;
      align-items: center;
      transition: all 0.3s;
    }
    
    .history-item:hover {
      background: #f9f9f9;
      transform: translateX(5px);
    }
    
    .history-item:last-child {
      border-bottom: none;
    }
    
    .resource-link {
      color: var(--blue);
      text-decoration: none;
      font-weight: 500;
      transition: all 0.3s;
    }
    
    .resource-link:hover {
      color: var(--primary-color);
      text-decoration: underline;
    }
    
    .status-badge {
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    
    .status-read {
      background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
      color: #2e7d32;
    }
    
    .status-unread {
      background: linear-gradient(135deg, #fff3e0, #ffe0b2);
      color: #ef6c00;
    }
    
    .no-history, .no-data {
      text-align: center;
      padding: 40px;
      color: #666;
      background: #f9f9f9;
      border-radius: 8px;
      font-style: italic;
    }
    
    .loading-spinner {
      text-align: center;
      padding: 30px;
      color: #666;
    }
    
    .loading-spinner i {
      font-size: 1.8rem;
      margin-bottom: 15px;
      color: var(--primary-color);
    }
    
    /* Back Button - Moved to bottom */
    .back-btn {
      background: #6c757d;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 20px;
    }
    
    .back-btn:hover {
      background: #5a6268;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }
    
    /* Section Footer for Back Button */
    .section-footer {
      display: flex;
      justify-content: flex-end;
      margin-top: 25px;
      padding-top: 20px;
      border-top: 1px solid #eee;
    }
    
    /* ENHANCED: Professional Modal Styles */
    .grad-detail-modal {
      display: none;
      position: fixed;
      z-index: 3000;
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
    
    .btn-secondary {
      background: linear-gradient(135deg, #6c757d, #5a6268);
      color: white;
    }
    
    .btn-secondary:hover {
      background: linear-gradient(135deg, #5a6268, #495057);
      color: white;
    }
    
    /* Responsive Design */
    @media (max-width: 1200px) {
      .graduate-header, .graduate-item {
        grid-template-columns: 2fr 1fr 1fr 1fr;
      }
      
      .last-update {
        display: none;
      }
      
      .history-item {
        grid-template-columns: 2fr 1fr;
      }
      
      .quick-stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }
    
    @media (max-width: 900px) {
      .graduate-header, .graduate-item {
        grid-template-columns: 2fr 1fr 1fr;
      }
      
      .course {
        display: none;
      }
      
      .assistance-modal {
        width: 95%;
        max-width: 95%;
      }
      
      .history-item {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .task-grid {
        grid-template-columns: 1fr;
      }
      
      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
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
      
      .graduate-header, .graduate-item {
        grid-template-columns: 1fr;
        gap: 10px;
        text-align: center;
      }
      
      .email, .course, .last-update, .portfolio-items {
        display: none;
      }
      
      .action-buttons {
        flex-direction: column;
        align-items: center;
        gap: 10px;
      }
      
      .btn-action, .assist-btn {
        min-width: 100%;
        justify-content: center;
      }
      
      .dropdown {
        width: 90%;
        right: 5%;
      }
      
      .assistance-modal {
        width: 98%;
        max-width: 98%;
        margin: 10px;
      }
      
      .filters {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
      
      .search-filter-container {
        width: 100%;
      }
      
      .resource-type, .feedback-type {
        flex-direction: column;
        gap: 10px;
      }
      
      .history-stats {
        grid-template-columns: 1fr;
      }
      
      .modal-header {
        padding: 20px;
      }
      
      .modal-title {
        font-size: 1.4rem;
      }
      
      .modal-content {
        padding: 20px;
      }
      
      .task-selection {
        padding: 20px 10px;
      }
      
      .task-card {
        padding: 20px;
      }
      
      .section-footer {
        justify-content: center;
      }
      
      .quick-stats-grid {
        grid-template-columns: 1fr;
      }
      
      .profile-main-info {
        flex-direction: column;
        text-align: center;
        gap: 20px;
      }
      
      .profile-meta, .profile-contact {
        justify-content: center;
      }
      
      .info-grid {
        grid-template-columns: 1fr;
      }
      
      .portfolio-item {
        flex-direction: column;
        text-align: center;
        gap: 15px;
      }
    }
  </style>
</head>
<body>
  <!-- Modal Overlay -->
  <div class="modal-overlay" id="assistanceModal">
    <div class="assistance-modal">
      <div class="modal-header">
        <h2 class="modal-title" id="modalGradName">Provide Assistance</h2>
        <button class="modal-close" onclick="closeAssistanceModal()">&times;</button>
      </div>
      <div class="modal-content">
        <!-- Task Selection Screen -->
        <div class="task-selection" id="taskSelection">
          <h2>How would you like to assist?</h2>
          <p>Select an assistance option for <span id="selectedGradName" style="font-weight: 600; color: var(--primary-color);">[Alumni Name]</span></p>
          
          <div class="task-grid">
            <div class="task-card resources" onclick="selectTask('resources')">
              <div class="task-icon">
                <i class="fas fa-share-alt"></i>
              </div>
              <h3>Share Resources</h3>
              <p>Share helpful resources like resume templates, interview guides, portfolio samples, career advice, and skill development materials.</p>
              <button class="task-btn">
                <i class="fas fa-arrow-right"></i> Select
              </button>
            </div>
            
            <div class="task-card feedback" onclick="selectTask('feedback')">
              <div class="task-icon">
                <i class="fas fa-comment-alt"></i>
              </div>
              <h3>Portfolio Feedback</h3>
              <p>Provide constructive feedback on resumes, certificates, skills, or overall portfolio to help alumni improve their job applications.</p>
              <button class="task-btn">
                <i class="fas fa-arrow-right"></i> Select
              </button>
            </div>
            
            <div class="task-card history" onclick="selectTask('history')">
              <div class="task-icon">
                <i class="fas fa-history"></i>
              </div>
              <h3>Resource History</h3>
              <p>View previously shared resources, track which ones have been read, and monitor engagement with your shared materials.</p>
              <button class="task-btn">
                <i class="fas fa-arrow-right"></i> Select
              </button>
            </div>
          </div>
        </div>

        <!-- Resource Sharing Section -->
        <div class="resource-section" id="resourcesSection">
          <div class="section-header">
            <h2 class="section-title"><i class="fas fa-share-alt"></i> Share Resources</h2>
          </div>
          
          <div class="resource-form">
            <form method="POST" action="" id="resourceForm">
              <input type="hidden" name="grad_id" id="resource_grad_id">
              <input type="hidden" name="share_resource" value="1">
              
              <div class="form-group">
                <label class="form-label"><i class="fas fa-tag"></i> Resource Type</label>
                <div class="resource-type">
                  <label>
                    <input type="radio" name="resource_type" value="resume_template" checked>
                    <span>Resume Template</span>
                  </label>
                  <label>
                    <input type="radio" name="resource_type" value="interview_guide">
                    <span>Interview Guide</span>
                  </label>
                  <label>
                    <input type="radio" name="resource_type" value="portfolio_sample">
                    <span>Portfolio Sample</span>
                  </label>
                  <label>
                    <input type="radio" name="resource_type" value="career_advice">
                    <span>Career Advice</span>
                  </label>
                  <label>
                    <input type="radio" name="resource_type" value="skill_development">
                    <span>Skill Development</span>
                  </label>
                </div>
              </div>
              
              <div class="form-group">
                <label class="form-label"><i class="fas fa-heading"></i> Resource Title *</label>
                <input type="text" name="resource_title" class="form-input" placeholder="Enter resource title" required>
              </div>
              
              <div class="form-group">
                <label class="form-label"><i class="fas fa-align-left"></i> Resource Description</label>
                <textarea name="resource_description" class="form-textarea" placeholder="Describe the resource..."></textarea>
              </div>
              
              <div class="form-group">
                <label class="form-label"><i class="fas fa-link"></i> Resource URL/Link *</label>
                <input type="url" name="resource_url" class="form-input" placeholder="https://example.com/resource" required>
              </div>
              
              <button type="submit" class="submit-btn">
                <i class="fas fa-share"></i> Share Resource
              </button>
            </form>
          </div>
          
          <div class="section-footer">
            <button class="back-btn" onclick="backToTaskSelection()">
              <i class="fas fa-arrow-left"></i> Back to Options
            </button>
          </div>
        </div>

        <!-- Portfolio Feedback Module -->
        <div class="resource-section" id="feedbackSection">
          <div class="section-header">
            <h2 class="section-title"><i class="fas fa-comment-alt"></i> Portfolio Feedback Module</h2>
          </div>
          
          <div class="feedback-module">
            <form method="POST" action="" id="feedbackForm">
              <input type="hidden" name="grad_id" id="feedback_grad_id">
              <div class="feedback-item">
                <h3 id="feedback_grad_name"><i class="fas fa-user-graduate"></i> Feedback for Alumni</h3>
                <div class="feedback-type">
                  <label>
                    <input type="radio" name="feedback_type" value="resume" checked>
                    <span>Resume</span>
                  </label>
                  <label>
                    <input type="radio" name="feedback_type" value="certificates">
                    <span>Certificates</span>
                  </label>
                  <label>
                    <input type="radio" name="feedback_type" value="skills">
                    <span>Skills</span>
                  </label>
                  <label>
                    <input type="radio" name="feedback_type" value="portfolio">
                    <span>Overall Portfolio</span>
                  </label>
                </div>
                <textarea name="feedback_content" placeholder="Add your constructive feedback for the alumni..." required></textarea>
              </div>
              <button type="submit" name="submit_feedback" class="submit-btn">
                <i class="fas fa-paper-plane"></i> Submit Feedback
              </button>
            </form>
          </div>
          
          <div class="section-footer">
            <button class="back-btn" onclick="backToTaskSelection()">
              <i class="fas fa-arrow-left"></i> Back to Options
            </button>
          </div>
        </div>

        <!-- Resource Sharing History for this Alumni -->
        <div class="resource-section" id="historySection">
          <div class="section-header">
            <h2 class="section-title"><i class="fas fa-history"></i> Resource Sharing History</h2>
          </div>
          
          <div class="resource-history">
            <!-- Statistics for this alumni -->
            <div class="history-stats" id="gradStats">
              <div class="history-stat">
                <div class="history-number">0</div>
                <div class="history-label">Total Shared</div>
              </div>
              <div class="history-stat">
                <div class="history-number">0</div>
                <div class="history-label">Resources Read</div>
              </div>
              <div class="history-stat">
                <div class="history-number">0</div>
                <div class="history-label">Resources Unread</div>
              </div>
            </div>
            
            <!-- Resource history list -->
            <div class="history-list" id="gradHistoryList">
              <div class="no-history">
                Select an alumni to view resource sharing history.
              </div>
            </div>
          </div>
          
          <div class="section-footer">
            <button class="back-btn" onclick="backToTaskSelection()">
              <i class="fas fa-arrow-left"></i> Back to Options
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
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
          <a href="staff_dashboard.php">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
          </a>
        </li>
        <li>
          <a href="staff_assist_grads.php" class="active">
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
      <p>Here's what's happening with alumni assistance today. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <h1 class="page-title">Assist Alumni</h1>
    </div>

    <?php if (isset($feedback_success) || isset($resource_success)): ?>
    <div class="notification-message" id="notificationMessage">
      <?= $feedback_success ?? $resource_success ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($view_message)): ?>
    <div class="notification-message" id="infoAlert"><?= $view_message ?></div>
    <?php endif; ?>

    <!-- Enhanced Filter Container -->
    <div class="filters">
      <!-- Search Filter -->
      <div class="search-filter-group">
        <label class="filter-label"><i class="fas fa-search"></i> Search</label>
        <div class="search-filter-container">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search alumni..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
      </div>
      
      <!-- Course Filter -->
      <div class="filter-group">
        <label class="filter-label"><i class="fas fa-graduation-cap"></i> Course</label>
        <select class="filter-select" id="courseFilter" onchange="updateFilters()">
          <option value="all" <?= $course_filter === 'all' ? 'selected' : '' ?>>All Courses</option>
          <?php foreach ($course_options as $course): ?>
            <option value="<?= $course ?>" <?= $course_filter === $course ? 'selected' : '' ?>><?= $course ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <!-- Year Filter -->
      <div class="filter-group">
        <label class="filter-label"><i class="fas fa-calendar-alt"></i> Year</label>
        <select class="filter-select" id="yearFilter" onchange="updateFilters()">
          <option value="all" <?= $year_filter === 'all' ? 'selected' : '' ?>>All Years</option>
          <?php for ($year = 2022; $year <= 2050; $year++): ?>
            <option value="<?= $year ?>" <?= $year_filter == $year ? 'selected' : '' ?>><?= $year ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>

    <!-- MODIFIED: Alumni Section (showing ALL alumni) -->
    <div class="graduates-section">
      <h2><i class="fas fa-users"></i> All Alumni</h2>
      <div class="graduates">
        <div class="graduate-header">
          <div>Name</div>
          <div>Course</div>
          <div>Year Graduated</div>
          <div>Portfolio Items</div>
          <div>Actions</div>
        </div>
        
        <?php if (!empty($graduates)): ?>
          <?php foreach ($graduates as $graduate): ?>
            <div class="graduate-item">
              <div><strong><?= htmlspecialchars($graduate['usr_name']) ?></strong></div>
              <div><?= htmlspecialchars($graduate['grad_degree']) ?></div>
              <div><?= htmlspecialchars($graduate['grad_year_graduated']) ?></div>
              <div><?= $graduate['portfolio_items'] ?></div>
              <div class="action-buttons">
                <a href="?action=view&grad_id=<?= $graduate['usr_id'] ?>" class="btn-action btn-view">
                  <i class="fas fa-eye"></i> View Portfolio
                </a>
                <button class="assist-btn" onclick="provideAssistance(<?= $graduate['usr_id'] ?>, '<?= htmlspecialchars($graduate['usr_name']) ?>')">
                  <i class="fas fa-hands-helping"></i> Provide Assistance
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="graduate-item">
            <div style="text-align: center; padding: 20px; grid-column: 1 / -1;">
              No alumni found matching your criteria.
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (isset($grad_details_html)) echo $grad_details_html; ?>

  <script>
    // ===== DROPDOWN FUNCTIONALITY =====
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
    
    // ===== ENHANCED NOTIFICATION FUNCTIONALITY =====
    $(document).ready(function() {
      // Handle notification click and mark as read
      $('.notification-link').on('click', function(e) {
        const notifId = $(this).data('notif-id');
        const notificationItem = $(this);
        
        // Only mark as read if it's unread and has a valid ID
        if (notificationItem.hasClass('unread') && notifId) {
          // Send AJAX request to mark as read
          $.ajax({
            url: 'staff_assist_grads.php',
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
    });
    
    // ===== FILTER FUNCTIONALITY =====
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
    
    // Update filters function - FIXED: Remove action parameter to prevent portfolio popup
    function updateFilters() {
        const courseFilter = document.getElementById('courseFilter').value;
        const yearFilter = document.getElementById('yearFilter').value;
        const searchQuery = searchInput.value;
        
        // Build URL with parameters - EXCLUDE action parameter
        const url = new URL(window.location.href.split('?')[0]); // Start with base URL
        url.searchParams.set('course', courseFilter);
        url.searchParams.set('year', yearFilter);
        url.searchParams.set('search', searchQuery);
        
        // Navigate to the new URL
        window.location.href = url.toString();
    }
    
    // ===== ASSISTANCE MODAL FUNCTIONALITY =====
    let currentGradId = null;
    let currentGradName = null;
    
    function provideAssistance(gradId, gradName) {
        currentGradId = gradId;
        currentGradName = gradName;
        
        // Set the alumni name in the modal
        document.getElementById('modalGradName').textContent = 'Provide Assistance to ' + gradName;
        document.getElementById('selectedGradName').textContent = gradName;
        
        // Show the task selection screen
        showTaskSelection();
        
        // Show the modal
        document.getElementById('assistanceModal').classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }
    
    function closeAssistanceModal() {
        document.getElementById('assistanceModal').classList.remove('active');
        document.body.style.overflow = 'auto'; // Re-enable scrolling
        // Reset to task selection when modal is closed
        showTaskSelection();
    }
    
    function showTaskSelection() {
        // Hide all sections
        document.getElementById('taskSelection').style.display = 'block';
        document.getElementById('resourcesSection').classList.remove('active');
        document.getElementById('feedbackSection').classList.remove('active');
        document.getElementById('historySection').classList.remove('active');
    }
    
    function selectTask(task) {
        // Hide task selection
        document.getElementById('taskSelection').style.display = 'none';
        
        // Show the selected task section
        if (task === 'resources') {
            document.getElementById('resourcesSection').classList.add('active');
            // Set the alumni ID in the resource form
            document.getElementById('resource_grad_id').value = currentGradId;
        } else if (task === 'feedback') {
            document.getElementById('feedbackSection').classList.add('active');
            // Set the alumni ID and name in the feedback form
            document.getElementById('feedback_grad_id').value = currentGradId;
            document.getElementById('feedback_grad_name').textContent = 'Feedback for ' + currentGradName;
        } else if (task === 'history') {
            document.getElementById('historySection').classList.add('active');
            // Load resource history for this alumni
            loadGraduateResourceHistory(currentGradId);
        }
    }
    
    function backToTaskSelection() {
        showTaskSelection();
    }
    
    function submitFeedback() {
        // You can add any client-side validation here if needed
        // The form will submit normally via PHP
        setTimeout(() => {
            closeAssistanceModal();
        }, 1000);
    }
    
    // Load resource history for specific alumni
    function loadGraduateResourceHistory(gradId) {
        // Show loading state
        document.getElementById('gradHistoryList').innerHTML = `
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
                <div>Loading resource history...</div>
            </div>
        `;
        
        // Reset stats to zero while loading
        const statNumbers = document.querySelectorAll('.history-number');
        statNumbers.forEach(stat => stat.textContent = '0');
        
        // Fetch resource history from the same file
        fetch(`${window.location.href.split('?')[0]}?ajax=get_graduate_resources&grad_id=${gradId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                updateResourceHistoryUI(data);
            })
            .catch(error => {
                console.error('Error loading resource history:', error);
                document.getElementById('gradHistoryList').innerHTML = '<div class="no-history">Error loading resource history. Please try again.</div>';
            });
    }
    
    // Update the UI with resource history data
    function updateResourceHistoryUI(data) {
        const statsContainer = document.getElementById('gradStats');
        const historyList = document.getElementById('gradHistoryList');
        
        // Update statistics
        const statNumbers = statsContainer.querySelectorAll('.history-number');
        if (statNumbers.length >= 3) {
            statNumbers[0].textContent = data.total_resources || 0;
            statNumbers[1].textContent = data.read_resources || 0;
            statNumbers[2].textContent = data.unread_resources || 0;
        }
        
        // Update history list
        if (data.resources && data.resources.length > 0) {
            let historyHTML = '';
            data.resources.forEach(resource => {
                historyHTML += `
                    <div class="history-item">
                        <div>
                            <a href="${resource.resource_url}" target="_blank" class="resource-link">
                                <strong>${resource.resource_title}</strong>
                            </a>
                            ${resource.resource_description ? `<div style="font-size: 0.8rem; color: #666; margin-top: 5px;">${resource.resource_description.substring(0, 50)}...</div>` : ''}
                        </div>
                        <div>${resource.resource_type}</div>
                        <div>
                            <span class="status-badge ${resource.is_read ? 'status-read' : 'status-unread'}">
                                ${resource.is_read ? 'Read' : 'Unread'}
                            </span>
                            <div style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                                ${new Date(resource.shared_at).toLocaleDateString()}
                            </div>
                        </div>
                    </div>
                `;
            });
            historyList.innerHTML = historyHTML;
        } else {
            historyList.innerHTML = '<div class="no-history">No resources have been shared with this alumni yet.</div>';
        }
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
        const modalOverlay = document.getElementById('assistanceModal');
        if (e.target === modalOverlay) {
            closeAssistanceModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAssistanceModal();
        }
    });
    
    // Auto-hide success message after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const notificationMessage = document.getElementById('notificationMessage');
        if (notificationMessage) {
            setTimeout(() => {
                notificationMessage.style.display = 'none';
            }, 5000);
        }
        
        const infoAlert = document.getElementById('infoAlert');
        if (infoAlert) {
            setTimeout(() => {
                infoAlert.style.display = 'none';
            }, 5000);
        }
    });
    
    // Function to close alumni portfolio modal
    function closeGradModal() {
        document.getElementById('gradDetailModal').style.display = 'none';
    }
    
    // Close alumni modal when clicking outside
    window.addEventListener('click', function(event) {
        const gradModal = document.getElementById('gradDetailModal');
        if (event.target == gradModal) {
            closeGradModal();
        }
    });
  </script>
</body>
</html>