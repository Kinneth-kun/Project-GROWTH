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
    
    // Debug session data
    error_log("Session Data: " . print_r($_SESSION, true));
    
    // Check if user is logged in and is a graduate - FIXED VERSION
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        error_log("User not logged in or session missing, redirecting to index.php");
        header("Location: index.php");
        exit();
    }
    
    // Check if user role is graduate
    if ($_SESSION['user_role'] !== 'graduate') {
        error_log("User role is not graduate, redirecting to index.php. Role: " . $_SESSION['user_role']);
        header("Location: index.php");
        exit();
    }
    
    // Get graduate data
    $graduate_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT g.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, u.usr_gender, u.usr_birthdate,
               u.usr_account_status, u.usr_is_approved
        FROM graduates g 
        JOIN users u ON g.grad_usr_id = u.usr_id 
        WHERE g.grad_usr_id = :graduate_id
    ");
    $stmt->bindParam(':graduate_id', $graduate_id);
    $stmt->execute();
    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$graduate) {
        error_log("No graduate record found for user ID: $graduate_id, redirecting to profile setup");
        // If no graduate record found, redirect to profile setup
        header("Location: graduate_profile.php?setup=1");
        exit();
    }
    
    // Check if user account is active and approved
    if ($graduate['usr_account_status'] !== 'active' || !$graduate['usr_is_approved']) {
        error_log("User account not active or approved. Status: " . $graduate['usr_account_status'] . ", Approved: " . $graduate['usr_is_approved']);
        header("Location: index.php?error=account_inactive");
        exit();
    }
    
    // ============================================================================
    // ENHANCED JOB MATCHING AND RECOMMENDATION SYSTEM
    // ============================================================================
    
    /**
     * Get graduate's skills for job matching
     */
    function getGraduateSkills($conn, $graduate_id) {
        $skills_stmt = $conn->prepare("
            SELECT s.skill_name 
            FROM graduate_skills gs
            JOIN skills s ON gs.skill_id = s.skill_id
            WHERE gs.grad_usr_id = :graduate_id
        ");
        $skills_stmt->bindParam(':graduate_id', $graduate_id);
        $skills_stmt->execute();
        return $skills_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Enhanced job preference matching with semantic analysis
     */
    function calculatePreferenceMatch($job_title, $job_domain, $job_description, $job_preference) {
        if (empty($job_preference)) return 0;
        
        $preference_lower = strtolower(trim($job_preference));
        $title_lower = strtolower($job_title);
        $domain_lower = strtolower($job_domain);
        $description_lower = strtolower($job_description);
        
        // Exact match in title (highest weight)
        if (strpos($title_lower, $preference_lower) !== false) {
            return 80;
        }
        
        // Exact match in domain
        if (strpos($domain_lower, $preference_lower) !== false) {
            return 70;
        }
        
        // Semantic matching for common job categories
        $semantic_groups = [
            'education' => ['teacher', 'educator', 'instructor', 'professor', 'faculty', 'teaching', 'education', 'school', 'university', 'college', 'tutor'],
            'technology' => ['developer', 'programmer', 'engineer', 'software', 'it', 'technology', 'tech', 'computer', 'system', 'network', 'database'],
            'healthcare' => ['nurse', 'doctor', 'medical', 'healthcare', 'hospital', 'clinic', 'physician', 'therapist', 'health', 'care'],
            'business' => ['manager', 'analyst', 'consultant', 'business', 'admin', 'administration', 'executive', 'director', 'officer'],
            'engineering' => ['engineer', 'engineering', 'technical', 'technician', 'mechanic', 'designer', 'architect'],
            'sales' => ['sales', 'account', 'representative', 'marketing', 'business development'],
            'customer service' => ['customer', 'service', 'support', 'client', 'helpdesk'],
            'creative' => ['designer', 'artist', 'creative', 'graphic', 'multimedia', 'video', 'animation'],
            'hospitality' => ['hotel', 'restaurant', 'tourism', 'hospitality', 'chef', 'cook', 'service']
        ];
        
        // Find which semantic group the preference belongs to
        $preference_group = '';
        foreach ($semantic_groups as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($preference_lower, $keyword) !== false) {
                    $preference_group = $group;
                    break 2;
                }
            }
        }
        
        // Check if job matches the semantic group
        if (!empty($preference_group)) {
            foreach ($semantic_groups[$preference_group] as $keyword) {
                if (strpos($title_lower, $keyword) !== false || 
                    strpos($domain_lower, $keyword) !== false ||
                    strpos($description_lower, $keyword) !== false) {
                    return 60;
                }
            }
        }
        
        // Partial match in description
        if (strpos($description_lower, $preference_lower) !== false) {
            return 50;
        }
        
        // Word-based matching
        $preference_words = explode(' ', $preference_lower);
        $match_count = 0;
        foreach ($preference_words as $word) {
            if (strlen($word) > 3 && (
                strpos($title_lower, $word) !== false ||
                strpos($domain_lower, $word) !== false ||
                strpos($description_lower, $word) !== false
            )) {
                $match_count++;
            }
        }
        
        if ($match_count > 0) {
            return min(40 + ($match_count * 10), 70);
        }
        
        return 0;
    }
    
    /**
     * Calculate job match score based on skills and preferences - MODIFIED VERSION (No recency bonus)
     */
    function calculateJobMatchScore($job, $graduate_skills, $job_preference) {
        $score = 0;
        $max_score = 100;
        
        // 1. Job preference matching (50% weight)
        $preference_score = calculatePreferenceMatch(
            $job['job_title'], 
            $job['job_domain'], 
            $job['job_description'], 
            $job_preference
        );
        $score += ($preference_score * 0.5);
        
        // 2. Skills matching (50% weight) - CHANGED from 40% to 50% to compensate for removed recency bonus
        $skills_score = 0;
        if (!empty($job['job_skills']) && !empty($graduate_skills)) {
            $job_skills = array_map('trim', explode(',', $job['job_skills']));
            $job_skills_lower = array_map('strtolower', $job_skills);
            $graduate_skills_lower = array_map('strtolower', $graduate_skills);
            
            $matched_skills = array_intersect($graduate_skills_lower, $job_skills_lower);
            $match_percentage = count($matched_skills) / max(count($job_skills), 1);
            $skills_score = $match_percentage * 100;
        }
        $score += ($skills_score * 0.5); // CHANGED from 0.4 to 0.5
        
        // RECENCY BONUS REMOVED - No longer adding points for recent jobs
        
        return min(round($score), $max_score);
    }
    
    /**
     * Get personalized job recommendations - MODIFIED TO EXCLUDE APPLIED JOBS
     */
    function getJobRecommendations($conn, $graduate_id, $graduate, $limit = 6) {
        $graduate_skills = getGraduateSkills($conn, $graduate_id);
        $job_preference = $graduate['grad_job_preference'] ?? '';
        
        // Get all active jobs that the graduate hasn't applied to
        $jobs_stmt = $conn->prepare("
            SELECT j.*, e.emp_company_name,
                   EXISTS(SELECT 1 FROM applications a2 WHERE a2.app_job_id = j.job_id AND a2.app_grad_usr_id = :graduate_id) as has_applied,
                   EXISTS(SELECT 1 FROM saved_jobs s WHERE s.job_id = j.job_id AND s.grad_usr_id = :graduate_id2) as is_saved
            FROM jobs j
            JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
            WHERE j.job_status = 'active'
            AND NOT EXISTS(SELECT 1 FROM applications a3 WHERE a3.app_job_id = j.job_id AND a3.app_grad_usr_id = :graduate_id3)
            ORDER BY j.job_created_at DESC
        ");
        $jobs_stmt->bindParam(':graduate_id', $graduate_id);
        $jobs_stmt->bindParam(':graduate_id2', $graduate_id);
        $jobs_stmt->bindParam(':graduate_id3', $graduate_id);
        $jobs_stmt->execute();
        $all_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate match scores for each job
        $scored_jobs = [];
        foreach ($all_jobs as $job) {
            $match_score = calculateJobMatchScore($job, $graduate_skills, $job_preference);
            $job['match_score'] = $match_score;
            $scored_jobs[] = $job;
        }
        
        // Sort by match score (descending) and get top recommendations
        usort($scored_jobs, function($a, $b) {
            return $b['match_score'] - $a['match_score'];
        });
        
        // Filter to only include jobs with meaningful matches (score > 20)
        $filtered_recommendations = array_filter($scored_jobs, function($job) {
            return $job['match_score'] > 20;
        });
        
        return array_slice($filtered_recommendations, 0, $limit);
    }
    
    /**
     * Get skill-based job matches - MODIFIED: Pure skill matching only
     */
    function getSkillBasedJobMatches($conn, $graduate_id, $limit = 6) {
        // Get graduate's skills
        $graduate_skills = getGraduateSkills($conn, $graduate_id);
        
        if (empty($graduate_skills)) {
            return [];
        }
        
        // Create a search pattern for skills
        $skill_patterns = [];
        foreach ($graduate_skills as $skill) {
            $skill_patterns[] = '%' . $skill . '%';
        }
        
        // Build query to find jobs that match graduate's skills
        $query = "
            SELECT j.*, e.emp_company_name,
                   EXISTS(SELECT 1 FROM applications a2 WHERE a2.app_job_id = j.job_id AND a2.app_grad_usr_id = :graduate_id) as has_applied,
                   EXISTS(SELECT 1 FROM saved_jobs s WHERE s.job_id = j.job_id AND s.grad_usr_id = :graduate_id2) as is_saved
            FROM jobs j
            JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
            WHERE j.job_status = 'active'
            AND NOT EXISTS(SELECT 1 FROM applications a3 WHERE a3.app_job_id = j.job_id AND a3.app_grad_usr_id = :graduate_id3)
            AND (";
        
        // Add skill matching conditions
        $skill_conditions = [];
        for ($i = 0; $i < count($skill_patterns); $i++) {
            $skill_conditions[] = "j.job_title LIKE :skill_$i OR j.job_description LIKE :skill_$i OR j.job_requirements LIKE :skill_$i OR j.job_skills LIKE :skill_$i";
        }
        $query .= implode(' OR ', $skill_conditions);
        $query .= ") ORDER BY j.job_created_at DESC LIMIT :limit";
        
        $jobs_stmt = $conn->prepare($query);
        $jobs_stmt->bindParam(':graduate_id', $graduate_id);
        $jobs_stmt->bindParam(':graduate_id2', $graduate_id);
        $jobs_stmt->bindParam(':graduate_id3', $graduate_id);
        $jobs_stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        
        // Bind skill parameters
        for ($i = 0; $i < count($skill_patterns); $i++) {
            $jobs_stmt->bindParam(":skill_$i", $skill_patterns[$i]);
        }
        
        $jobs_stmt->execute();
        $matched_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate match scores based purely on skill overlap - MODIFIED
        $scored_jobs = [];
        foreach ($matched_jobs as $job) {
            $match_score = calculatePureSkillMatchScore($job, $graduate_skills);
            $job['match_score'] = $match_score;
            $scored_jobs[] = $job;
        }
        
        // Sort by match score (descending)
        usort($scored_jobs, function($a, $b) {
            return $b['match_score'] - $a['match_score'];
        });
        
        return $scored_jobs;
    }
    
    /**
     * Calculate pure skill-based match score - MODIFIED VERSION (No preference/recency)
     */
    function calculatePureSkillMatchScore($job, $graduate_skills) {
        $score = 0;
        
        if (!empty($job['job_skills']) && !empty($graduate_skills)) {
            $job_skills = array_map('trim', explode(',', $job['job_skills']));
            $job_skills_lower = array_map('strtolower', $job_skills);
            $graduate_skills_lower = array_map('strtolower', $graduate_skills);
            
            $matched_skills = array_intersect($graduate_skills_lower, $job_skills_lower);
            $match_percentage = count($matched_skills) / max(count($job_skills), 1);
            $score = $match_percentage * 100;
        } else if (!empty($job['job_skills'])) {
            // If job has skills but graduate doesn't, score is 0
            $score = 0;
        }
        
        // Additional skill mentions in job content (small bonus)
        $content = strtolower($job['job_title'] . ' ' . $job['job_description'] . ' ' . $job['job_requirements']);
        $bonus_points = 0;
        foreach ($graduate_skills as $skill) {
            $skill_lower = strtolower($skill);
            // Check if skill is mentioned in job content
            if (strpos($content, $skill_lower) !== false) {
                $bonus_points += 2; // Small bonus for each skill mention in content
            }
        }
        
        // Add bonus points but cap at 100%
        $score = min(round($score + $bonus_points), 100);
        
        return $score;
    }
    
    /**
     * Function to create notifications for graduate
     */
    function createGraduateNotification($conn, $graduate_id, $type, $message, $related_id = null) {
        try {
            $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                                   VALUES (?, ?, ?, NOW())");
            $stmt->execute([$graduate_id, $message, $type]);
            return true;
        } catch (PDOException $e) {
            error_log("Notification creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enhanced system notifications generator for graduates
     */
    function generateGraduateNotifications($conn, $graduate_id, $graduate) {
        $totalNotifications = 0;
        
        // Check for new high-match job recommendations
        $recommendations = getJobRecommendations($conn, $graduate_id, $graduate, 3);
        foreach ($recommendations as $job) {
            if ($job['match_score'] >= 70) {
                $existingNotif = $conn->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE notif_usr_id = ? 
                    AND notif_message LIKE ? 
                    AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                ");
                $searchPattern = "%{$job['job_title']}%";
                $existingNotif->execute([$graduate_id, $searchPattern]);
                
                if ($existingNotif->fetchColumn() == 0) {
                    $message = "High match ({$job['match_score']}%): '{$job['job_title']}' at {$job['emp_company_name']}";
                    
                    if (createGraduateNotification($conn, $graduate_id, 'job_recommendation', $message, $job['job_id'])) {
                        $totalNotifications++;
                    }
                }
            }
        }
        
        return $totalNotifications;
    }
    
    // Generate notifications and recommendations
    $notificationsGenerated = generateGraduateNotifications($conn, $graduate_id, $graduate);
    $job_recommendations = getJobRecommendations($conn, $graduate_id, $graduate, 8);
    
    // Get skill-based job matches - MODIFIED: Pure skill matching only
    $skill_based_matches = getSkillBasedJobMatches($conn, $graduate_id, 8);
    $graduate_skills = getGraduateSkills($conn, $graduate_id);
    
    // Get recent notifications
    $notifications = [];
    $unread_notif_count = 0;
    try {
        $notif_stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE notif_usr_id = :graduate_id 
            ORDER BY notif_created_at DESC 
            LIMIT 10
        ");
        $notif_stmt->bindParam(':graduate_id', $graduate_id);
        $notif_stmt->execute();
        $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notifications as $notif) {
            if (!$notif['notif_is_read']) $unread_notif_count++;
        }
        
        // If no notifications found, create some default ones
        if (empty($notifications)) {
            $notifications = [
                [
                    'notif_message' => 'Welcome to CTU-PESO Job Portal! Start exploring job opportunities.',
                    'notif_type' => 'system',
                    'notif_is_read' => 0,
                    'notif_created_at' => date('Y-m-d H:i:s')
                ]
            ];
            $unread_notif_count = 1;
        }
    } catch (PDOException $e) {
        error_log("Notifications query error: " . $e->getMessage());
        $notifications = [
            [
                'notif_message' => 'Welcome to CTU-PESO Job Portal! Start exploring job opportunities.',
                'notif_type' => 'system',
                'notif_is_read' => 0,
                'notif_created_at' => date('Y-m-d H:i:s')
            ]
        ];
        $unread_notif_count = 1;
    }
    
    // Initialize filter variables
    $company_filter = isset($_GET['company']) ? $_GET['company'] : '';
    $location_filter = isset($_GET['location']) ? $_GET['location'] : '';
    $date_filter = isset($_GET['date_posted']) ? $_GET['date_posted'] : '';
    $domain_filter = isset($_GET['domain']) ? $_GET['domain'] : '';
    $search_query = isset($_GET['search']) ? $_GET['search'] : '';
    $sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
    
    // Build the base query for jobs - MODIFIED TO PRIORITIZE SAVED JOBS
    $query = "
        SELECT j.*, e.emp_company_name, 
               COUNT(a.app_id) as application_count,
               EXISTS(SELECT 1 FROM applications a2 WHERE a2.app_job_id = j.job_id AND a2.app_grad_usr_id = :graduate_id) as has_applied,
               EXISTS(SELECT 1 FROM saved_jobs s WHERE s.job_id = j.job_id AND s.grad_usr_id = :graduate_id2) as is_saved
        FROM jobs j
        JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
        LEFT JOIN applications a ON j.job_id = a.app_job_id
        WHERE j.job_status = 'active'
    ";
    
    $params = [
        ':graduate_id' => $graduate_id,
        ':graduate_id2' => $graduate_id
    ];
    
    // Add filters to the query
    if (!empty($company_filter) && $company_filter != 'all') {
        $query .= " AND e.emp_company_name = :company";
        $params[':company'] = $company_filter;
    }
    
    if (!empty($location_filter) && $location_filter != 'all') {
        $query .= " AND j.job_location LIKE :location";
        $params[':location'] = "%$location_filter%";
    }
    
    if (!empty($date_filter)) {
        $date_condition = "";
        switch ($date_filter) {
            case 'last_7_days':
                $date_condition = " AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'last_30_days':
                $date_condition = " AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
        }
        $query .= $date_condition;
    }
    
    if (!empty($domain_filter) && $domain_filter != 'all') {
        $query .= " AND j.job_domain = :domain";
        $params[':domain'] = $domain_filter;
    }
    
    if (!empty($search_query)) {
        $query .= " AND (j.job_title LIKE :search OR j.job_description LIKE :search OR j.job_requirements LIKE :search)";
        $params[':search'] = "%$search_query%";
    }
    
    // Complete the query with grouping and ordering
    $query .= " GROUP BY j.job_id";
    
    // Add sorting - MODIFIED TO PRIORITIZE SAVED JOBS
    switch ($sort_by) {
        case 'newest':
            $query .= " ORDER BY is_saved DESC, j.job_created_at DESC";
            break;
        case 'oldest':
            $query .= " ORDER BY is_saved DESC, j.job_created_at ASC";
            break;
        case 'title_asc':
            $query .= " ORDER BY is_saved DESC, j.job_title ASC";
            break;
        case 'title_desc':
            $query .= " ORDER BY is_saved DESC, j.job_title DESC";
            break;
        case 'company_asc':
            $query .= " ORDER BY is_saved DESC, e.emp_company_name ASC";
            break;
        case 'company_desc':
            $query .= " ORDER BY is_saved DESC, e.emp_company_name DESC";
            break;
        default:
            $query .= " ORDER BY is_saved DESC, j.job_created_at DESC";
    }
    
    // Prepare and execute the query
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique companies for filter
    $company_stmt = $conn->prepare("SELECT DISTINCT emp_company_name FROM employers ORDER BY emp_company_name");
    $company_stmt->execute();
    $companies = $company_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique locations for filter
    $location_stmt = $conn->prepare("SELECT DISTINCT job_location FROM jobs WHERE job_location IS NOT NULL ORDER BY job_location");
    $location_stmt->execute();
    $locations = $location_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get unique domains for filter
    $domain_stmt = $conn->prepare("SELECT DISTINCT job_domain FROM jobs WHERE job_domain IS NOT NULL ORDER BY job_domain");
    $domain_stmt->execute();
    $domains = $domain_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Handle job application
    if (isset($_POST['apply_job'])) {
        $job_id = $_POST['job_id'];
        
        // Check if already applied
        $check_stmt = $conn->prepare("SELECT * FROM applications WHERE app_job_id = :job_id AND app_grad_usr_id = :graduate_id");
        $check_stmt->bindParam(':job_id', $job_id);
        $check_stmt->bindParam(':graduate_id', $graduate_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() == 0) {
            // Handle file uploads - MODIFIED TO ALLOW MULTIPLE FILES
            $uploaded_files = [];
            $upload_dir = "uploads/applications/"; // Ensure this directory exists and is writable
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            if (isset($_FILES['application_files'])) {
                $files = $_FILES['application_files'];
                $file_names = $files['name'];
                $file_tmp = $files['tmp_name'];
                $file_errors = $files['error'];
                
                $has_valid_file = false;
                for ($i = 0; $i < count($file_names); $i++) {
                    if ($file_errors[$i] === UPLOAD_ERR_OK && !empty($file_names[$i])) {
                        $file_name = basename($file_names[$i]);
                        $target_file = $upload_dir . uniqid() . '_' . $file_name;
                        if (move_uploaded_file($file_tmp[$i], $target_file)) {
                            $uploaded_files[] = $target_file;
                            $has_valid_file = true;
                        }
                    }
                }
                
                if (!$has_valid_file) {
                    $error_message = "At least one valid file is required for application.";
                } else {
                    // Serialize uploaded files for storage
                    $file_paths = json_encode($uploaded_files);
                    
                    // Get optional message
                    $message = $_POST['message'] ?? '';
                    
                    // Insert application
                    $apply_stmt = $conn->prepare("INSERT INTO applications (app_job_id, app_grad_usr_id, app_files, app_message, app_status) 
                                                VALUES (:job_id, :graduate_id, :file_paths, :message, 'pending')");
                    $apply_stmt->bindParam(':job_id', $job_id);
                    $apply_stmt->bindParam(':graduate_id', $graduate_id);
                    $apply_stmt->bindParam(':file_paths', $file_paths);
                    $apply_stmt->bindParam(':message', $message);
                    $apply_stmt->execute();
                    
                    // Add notification
                    $job_title_stmt = $conn->prepare("SELECT job_title FROM jobs WHERE job_id = :job_id");
                    $job_title_stmt->bindParam(':job_id', $job_id);
                    $job_title_stmt->execute();
                    $job_title = $job_title_stmt->fetchColumn();
                    
                    $notif_msg = "You applied for: " . $job_title;
                    $notif_stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type) 
                                                VALUES (:user_id, :message, 'application')");
                    $notif_stmt->bindParam(':user_id', $graduate_id);
                    $notif_stmt->bindParam(':message', $notif_msg);
                    $notif_stmt->execute();
                    
                    $success_message = "Application submitted successfully with " . count($uploaded_files) . " file(s)!";
                    
                    // Refresh notifications
                    $notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE notif_usr_id = :graduate_id ORDER BY notif_created_at DESC LIMIT 10");
                    $notif_stmt->bindParam(':graduate_id', $graduate_id);
                    $notif_stmt->execute();
                    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
                    $unread_notif_count = 0;
                    foreach ($notifications as $notif) {
                        if (!$notif['notif_is_read']) $unread_notif_count++;
                    }
                    
                    // Refresh jobs and recommendations
                    $stmt = $conn->prepare($query);
                    foreach ($params as $key => $value) {
                        $stmt->bindValue($key, $value);
                    }
                    $stmt->execute();
                    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $job_recommendations = getJobRecommendations($conn, $graduate_id, $graduate, 8);
                    $skill_based_matches = getSkillBasedJobMatches($conn, $graduate_id, 8);
                }
            } else {
                $error_message = "Please select at least one file for your application.";
            }
        } else {
            $error_message = "You have already applied for this job.";
        }
    }
    
    // Handle save/unsave job - FIXED VERSION
    if (isset($_POST['toggle_save_job'])) {
        $job_id = $_POST['job_id'];
        
        // Check if job is already saved
        $check_saved_stmt = $conn->prepare("SELECT * FROM saved_jobs WHERE job_id = :job_id AND grad_usr_id = :graduate_id");
        $check_saved_stmt->bindParam(':job_id', $job_id);
        $check_saved_stmt->bindParam(':graduate_id', $graduate_id);
        $check_saved_stmt->execute();
        
        if ($check_saved_stmt->rowCount() > 0) {
            // Remove from saved jobs
            $unsave_stmt = $conn->prepare("DELETE FROM saved_jobs WHERE job_id = :job_id AND grad_usr_id = :graduate_id");
            $unsave_stmt->bindParam(':job_id', $job_id);
            $unsave_stmt->bindParam(':graduate_id', $graduate_id);
            $unsave_stmt->execute();
            
            // Log the action
            error_log("Job $job_id unsaved by user $graduate_id");
            
            // Return JSON response for AJAX
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['status' => 'unsaved', 'message' => 'Job removed from saved list.']);
                exit();
            } else {
                $success_message = "Job removed from saved list.";
            }
        } else {
            // Add to saved jobs
            $save_stmt = $conn->prepare("INSERT INTO saved_jobs (job_id, grad_usr_id) VALUES (:job_id, :graduate_id)");
            $save_stmt->bindParam(':job_id', $job_id);
            $save_stmt->bindParam(':graduate_id', $graduate_id);
            $save_stmt->execute();
            
            // Log the action
            error_log("Job $job_id saved by user $graduate_id");
            
            // Return JSON response for AJAX
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['status' => 'saved', 'message' => 'Job saved successfully!']);
                exit();
            } else {
                $success_message = "Job saved successfully!";
            }
        }
        
        // Only redirect for non-AJAX requests
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
            header("Location: " . $_SERVER['PHP_SELF'] . "?" . http_build_query($_GET));
            exit();
        }
    }
    
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Handle mark as read for notifications
if (isset($_POST['mark_all_read']) && $_POST['mark_all_read'] == '1') {
    try {
        $update_stmt = $conn->prepare("
            UPDATE notifications 
            SET notif_is_read = 1 
            WHERE notif_usr_id = :graduate_id AND notif_is_read = 0
        ");
        $update_stmt->bindParam(':graduate_id', $graduate_id);
        $update_stmt->execute();
        
        // Refresh page to show updated notifications
        header("Location: graduate_jobs.php?" . http_build_query($_GET));
        exit();
    } catch (PDOException $e) {
        error_log("Mark notifications as read error: " . $e->getMessage());
    }
}

// Handle mark single notification as read via AJAX
if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
    $notif_id = $_POST['notif_id'];
    try {
        $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :graduate_id");
        $mark_read_stmt->bindParam(':notif_id', $notif_id);
        $mark_read_stmt->bindParam(':graduate_id', $graduate_id);
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
 * Get appropriate icon for notification type
 */
function getNotificationIcon($type) {
    switch ($type) {
        case 'application':
            return 'fas fa-file-alt';
        case 'job_recommendation':
            return 'fas fa-briefcase';
        case 'system':
        default:
            return 'fas fa-info-circle';
    }
}

/**
 * Get notification priority based on type
 */
function getNotificationPriority($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'application') !== false) {
        return 'high';
    } elseif (strpos($type, 'job_recommendation') !== false) {
        return 'medium';
    } else {
        return 'low';
    }
}

/**
 * Function to determine where a notification should link to based on its content
 */
function getNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'application') !== false || strpos($message, 'application') !== false) {
        return 'graduate_applications.php';
    } elseif (strpos($type, 'job') !== false || strpos($message, 'job') !== false) {
        return 'graduate_jobs.php';
    } else {
        return 'graduate_dashboard.php';
    }
}

/**
 * Get match badge class based on score
 */
function getMatchBadgeClass($score) {
    if ($score >= 80) return 'match-excellent';
    if ($score >= 60) return 'match-good';
    if ($score >= 40) return 'match-fair';
    return 'match-poor';
}

/**
 * Get match badge text based on score
 */
function getMatchBadgeText($score) {
    if ($score >= 80) return 'Excellent Match';
    if ($score >= 60) return 'Good Match';
    if ($score >= 40) return 'Fair Match';
    return 'Low Match';
}

/**
 * Format date for display
 */
function formatDate($date) {
    $now = new DateTime();
    $dateObj = new DateTime($date);
    $interval = $now->diff($dateObj);
    
    if ($interval->days == 0) {
        return 'Today';
    } elseif ($interval->days == 1) {
        return 'Yesterday';
    } elseif ($interval->days < 7) {
        return $interval->days . ' days ago';
    } else {
        return $dateObj->format('M j, Y');
    }
}

/**
 * Format salary with peso sign
 */
function formatSalary($salary) {
    if (empty($salary) || $salary === 'Salary not specified') {
        return 'Salary not specified';
    }
    
    // Check if already has peso sign
    if (strpos($salary, '₱') !== false || strpos($salary, 'P') !== false) {
        return $salary;
    }
    
    // Add peso sign if it's a numeric range
    if (preg_match('/\d/', $salary)) {
        return '₱' . $salary;
    }
    
    return $salary;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Search & Apply Jobs</title>
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
        
        .graduate-role {
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
        
        .notification, .profile {
            position: relative;
            margin-left: 20px;
            cursor: pointer;
        }
        
        .notification i, .profile i {
            font-size: 1.3rem;
            color: var(--primary-color);
            transition: all 0.3s;
        }
        
        .notification:hover i, .profile:hover i {
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
        
        .profile {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 30px;
            transition: all 0.3s;
            background: rgba(110, 3, 3, 0.05);
        }
        
        .profile:hover {
            background: rgba(110, 3, 3, 0.1);
        }
        
        .profile img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }
        
        .profile-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-right: 8px;
        }
        
        .profile i.fa-chevron-down {
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
        
        /* Enhanced Welcome Message */
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
        
        /* Job Matching Section */
        .job-matching-section {
            background: linear-gradient(135deg, #1a2a6c, #b21f1f, #fdbb2d);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .job-matching-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .matching-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .matching-title {
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .match-stats {
            display: flex;
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            display: block;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .recommendations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .recommendation-card {
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            border-left: 4px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
        }
        
        .recommendation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .match-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
        }
        
        .match-excellent {
            background: linear-gradient(135deg, #00b09b, #96c93d);
        }
        
        .match-good {
            background: linear-gradient(135deg, #2196f3, #21cbf3);
        }
        
        .match-fair {
            background: linear-gradient(135deg, #ff9800, #ffc107);
        }
        
        .match-poor {
            background: linear-gradient(135deg, #f44336, #e91e63);
        }
        
        .match-score {
            font-size: 2rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        /* Skill-based Job Matching Section - NEW STYLES */
        .skill-matching-section {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .skill-matching-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .skills-display {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .skills-title {
            font-size: 1rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .skills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .skill-tag {
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        /* No Match Found Section */
        .no-match-section {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 60px 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .no-match-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .no-match-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.8;
        }
        
        .no-match-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .no-match-description {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 25px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Filter Section */
        .filter-section {
            background-color: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            border-top: 4px solid var(--secondary-color);
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .filter-group select:focus, .filter-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .search-box {
            display: flex;
            margin-top: 15px;
        }
        
        .search-box input {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px 0 0 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        .search-box button {
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border: none;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .search-box button:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-1px);
        }
        
        /* Sort and Results Section */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .results-count {
            font-size: 1.1rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .sort-options {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sort-options label {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .sort-options select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        
        /* Job Listings - MODIFIED TO 4 PER ROW */
        .job-listings {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .job-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 20px;
            transition: all 0.3s;
            border-top: 4px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .job-card.saved-job {
            border-top: 4px solid var(--green);
            background: linear-gradient(135deg, #f8fff8, #f0fff0);
        }
        
        .job-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .job-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .job-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
            line-height: 1.3;
        }
        
        .job-company {
            font-size: 0.95rem;
            color: #666;
            font-weight: 600;
        }
        
        .job-posted {
            font-size: 0.8rem;
            color: #999;
            margin-top: 5px;
        }
        
        .job-save {
            background: none;
            border: none;
            color: #ccc;
            font-size: 1.4rem;
            cursor: pointer;
            transition: all 0.3s;
            padding: 5px;
        }
        
        .job-save.saved {
            color: var(--accent-color);
        }
        
        .job-save:hover {
            color: var(--accent-color);
            transform: scale(1.1);
        }
        
        .job-details {
            margin-bottom: 15px;
            flex-grow: 1;
        }
        
        .job-detail {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }
        
        .job-detail i {
            margin-right: 10px;
            color: var(--primary-color);
            width: 14px;
            text-align: center;
        }
        
        .job-skills {
            margin-top: 15px;
        }
        
        .skill-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }
        
        .skill-tag {
            background: linear-gradient(135deg, #f0f0f0, #e0e0e0);
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            color: #555;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .skill-tag:hover {
            background: linear-gradient(135deg, #e0e0e0, #d0d0d0);
            transform: translateY(-1px);
        }
        
        .job-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 0.85rem;
        }
        
        .btn-view {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #333;
        }
        
        .btn-view:hover {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            transform: translateY(-1px);
        }
        
        .btn-apply {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .btn-apply:hover {
            background: linear-gradient(135deg, #8a0404, var(--primary-color));
            transform: translateY(-1px);
        }
        
        .btn-applied {
            background: linear-gradient(135deg, var(--green), #2e7d32);
            color: white;
            cursor: not-allowed;
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
            padding: 20px;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Enhanced Job Details Modal */
        .job-detail-modal {
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
        
        .modal-overlay.active .job-detail-modal {
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
        
        /* Enhanced Job Detail Content */
        .job-detail-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .job-info-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .job-main-info {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
            position: relative;
        }
        
        .job-main-title {
            font-size: 26px;
            margin-bottom: 10px;
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .job-main-company {
            color: var(--text-color);
            margin-bottom: 10px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .job-detail-list {
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
        
        .job-description-section, .job-requirements-section, .job-skills-section {
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
        
        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        /* Enhanced Application Modal with File Upload */
        .application-modal {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .application-modal {
            transform: translateY(0);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1rem;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color 0.3s;
        }
        
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        /* Enhanced File Upload Styles for Application */
        .file-upload-section {
            margin-top: 20px;
        }
        
        .file-upload-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            font-weight: 600;
        }
        
        .file-upload-card {
            border: 2px dashed #e0e0e0;
            border-radius: 12px;
            padding: 30px;
            background: #fafafa;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            min-height: 200px;
            justify-content: center;
        }
        
        .file-upload-card:hover {
            border-color: var(--primary-color);
            background: #f0f8ff;
        }
        
        .file-upload-card.dragover {
            border-color: var(--primary-color);
            background: #e8f4ff;
        }
        
        .file-upload-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        
        .file-upload-text {
            margin-bottom: 20px;
            color: #666;
            font-size: 1rem;
            max-width: 90%;
        }
        
        .file-upload-btn {
            background: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.3s;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
        }
        
        .file-upload-btn:hover {
            background: #8a0404;
            transform: translateY(-1px);
        }
        
        .file-requirements {
            font-size: 0.85rem;
            color: #888;
            margin-top: 15px;
            line-height: 1.4;
        }
        
        .file-preview-container {
            width: 100%;
            margin-top: 20px;
        }
        
        .file-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .file-preview-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            transition: all 0.3s;
        }
        
        .file-preview-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .file-preview-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .file-preview-info {
            flex: 1;
            width: 100%;
        }
        
        .file-preview-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .file-preview-size {
            font-size: 0.75rem;
            color: #666;
            margin-bottom: 10px;
        }
        
        .file-preview-actions {
            display: flex;
            gap: 8px;
            width: 100%;
        }
        
        .preview-action-btn {
            flex: 1;
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            color: #666;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.3s;
        }
        
        .preview-action-btn:hover {
            background: #f5f5f5;
            border-color: #ccc;
        }
        
        .preview-action-btn.remove {
            background: #ffebee;
            color: #d32f2f;
            border-color: #ffcdd2;
        }
        
        .preview-action-btn.remove:hover {
            background: #ffcdd2;
        }
        
        .file-input {
            display: none;
        }
        
        .uploaded-files-count {
            background: var(--primary-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid;
            font-weight: 500;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        /* Toast notification styles */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 8px;
            border-left: 4px solid #c3e6cb;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            max-width: 300px;
            font-weight: 500;
            animation: slideInRight 0.3s ease;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .job-listings {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
            
            .recommendations-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
            
            .job-detail-content {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 992px) {
            .job-listings {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            }
            
            .matching-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .results-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }
            
            .sidebar-header h3, .sidebar-menu span, .graduate-role, .menu-label {
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
                padding: 15px;
            }
            
            .top-nav {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .job-listings {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
            
            .recommendations-grid {
                grid-template-columns: 1fr;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .modal-content {
                padding: 20px;
            }
            
            .job-matching-section {
                padding: 20px;
            }
            
            .matching-title {
                font-size: 1.5rem;
            }
            
            .file-preview-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .job-listings {
                grid-template-columns: 1fr;
            }
            
            .job-card {
                padding: 20px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .job-actions {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn {
                width: 100%;
                text-align: center;
            }
            
            .match-stats {
                flex-direction: column;
                gap: 10px;
            }
            
            .stat-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .file-upload-card {
                padding: 20px;
                min-height: 150px;
            }
            
            .file-upload-icon {
                font-size: 2.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Modal Overlays -->
    <div class="modal-overlay" id="jobDetailModalOverlay"></div>
    <div class="modal-overlay" id="applyModalOverlay"></div>
    
    <!-- Enhanced Job Details Modal -->
    <div class="modal-overlay" id="jobDetailModal">
        <div class="job-detail-modal">
            <div class="modal-header">
                <h2 class="modal-title">Job Details</h2>
                <button class="modal-close" onclick="closeJobDetailModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <div class="job-detail-content">
                    <div class="job-info-section">
                        <div class="job-main-info">
                            <h2 class="job-main-title" id="detail-job-title"></h2>
                            <p class="job-main-company" id="detail-job-company"></p>
                        </div>
                        
                        <div class="job-detail-list">
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-building"></i>
                                    Company
                                </span>
                                <span class="detail-value" id="detail-company-name"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Location
                                </span>
                                <span class="detail-value" id="detail-job-location"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-clock"></i>
                                    Job Type
                                </span>
                                <span class="detail-value" id="detail-job-type"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-money-bill-wave"></i>
                                    Salary
                                </span>
                                <span class="detail-value" id="detail-job-salary"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-tag"></i>
                                    Domain
                                </span>
                                <span class="detail-value" id="detail-job-domain"></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">
                                    <i class="fas fa-calendar"></i>
                                    Posted
                                </span>
                                <span class="detail-value" id="detail-job-posted"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="job-description-section">
                            <h3 class="section-title"><i class="fas fa-file-alt"></i> Job Description</h3>
                            <div id="detail-job-description"></div>
                        </div>
                        
                        <div class="job-requirements-section">
                            <h3 class="section-title"><i class="fas fa-tasks"></i> Job Requirements</h3>
                            <div id="detail-job-requirements"></div>
                        </div>
                        
                        <div class="job-skills-section">
                            <h3 class="section-title"><i class="fas fa-tools"></i> Required Skills</h3>
                            <div class="skills" id="detail-job-skills"></div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button class="btn btn-view" onclick="closeJobDetailModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button class="btn btn-apply" id="detail-apply-btn">
                        <i class="fas fa-paper-plane"></i> Apply Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Application Modal with File Upload -->
    <div class="modal-overlay" id="applyModal">
        <div class="application-modal">
            <div class="modal-header">
                <h2 class="modal-title">Apply for <span id="modal-job-title"></span></h2>
                <button class="modal-close" onclick="closeApplyModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-content">
                <form method="POST" action="" enctype="multipart/form-data" id="applicationForm">
                    <input type="hidden" name="job_id" id="modal-job-id">
                    
                    <div class="file-upload-section">
    <h3 class="file-upload-title">
        Upload Application Files 
        <span class="required">*</span>
        <span class="uploaded-files-count" id="fileCount">0 files</span>
    </h3>
    
    <div class="file-upload-card" id="fileUploadCard">
        <div class="file-upload-icon">
            <i class="fas fa-cloud-upload-alt"></i>
        </div>
        <div class="file-upload-text">
            Drag and drop your files here or click the button below to browse
        </div>
        <label for="application_files" class="file-upload-btn">
            <i class="fas fa-upload"></i> Choose Files
        </label>
        <div class="file-requirements">
            <i class="fas fa-info-circle"></i> 
            Supported formats: PDF, DOC, DOCX, JPG, PNG, TXT | 
            Max file size: 5MB per file | 
            You can select multiple files
        </div>
    </div>
    
    <input type="file" id="application_files" name="application_files[]" 
           class="file-input" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt" 
           style="display: none;">
    
    <div class="file-preview-container" id="filePreviewContainer" style="display: none;">
        <h4 style="margin-bottom: 15px; color: var(--primary-color);">Selected Files:</h4>
        <div class="file-preview-grid" id="filePreviewGrid">
            <!-- File previews will be dynamically added here -->
        </div>
    </div>
</div>
                    
                    <div class="form-group">
                        <label for="message">Short Message for Employer (Optional)</label>
                        <textarea id="message" name="message" placeholder="Add a short message to the employer (e.g., why you're interested, your qualifications)..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-view" onclick="closeApplyModal()">Cancel</button>
                        <button type="submit" name="apply_job" class="btn btn-apply" id="submitApplication">
                            <i class="fas fa-paper-plane"></i> Submit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Enhanced Sidebar with Main Navigation -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" alt="Profile">
            <div class="sidebar-header-text">
                <h3><?= htmlspecialchars($graduate['usr_name']) ?></h3>
                <div class="graduate-role">Alumni</div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <ul>
                <li>
                    <a href="graduate_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_jobs.php" class="active">
                        <i class="fas fa-briefcase"></i>
                        <span>Apply Jobs</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_employers.php">
                        <i class="fas fa-building"></i>
                        <span>Employers</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_portfolio.php">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Digital Portfolio</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_applications.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Applications</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_tools.php">
                        <i class="fas fa-tools"></i>
                        <span>Career Tools</span>
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
                            Notifications
                            <?php if ($unread_notif_count > 0): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="mark_all_read" value="1">
                                <button type="submit" class="mark-all-read">Mark all as read</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notif): 
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
                <div class="profile" id="profile">
                    <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" alt="Profile">
                    <span class="profile-name"><?= htmlspecialchars($graduate['usr_name']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                    <div class="dropdown profile-dropdown" id="profileDropdown">
                        <a href="graduate_profile.php" class="dropdown-item"><i class="fas fa-user"></i> My Profile</a>
                        <a href="graduate_settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <div class="dropdown-item" style="border-top: 1px solid #eee; margin-top: 5px; padding-top: 15px;">
                            <i class="fas fa-user-graduate"></i> Logged in as: <strong><?= htmlspecialchars($graduate['usr_name']) ?></strong>
                        </div>
                        <a href="?logout=1" class="dropdown-item" style="color: var(--red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?= htmlspecialchars($graduate['usr_name']) ?>!</h2>
            <p>Find your dream job from our curated listings. <?= $notificationsGenerated > 0 ? "Found {$notificationsGenerated} new job matches!" : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Search & Apply for Jobs</h1>
        </div>
        
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $success_message ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
        <?php endif; ?>
        
        <!-- Job Matching & Recommendations Section -->
        <?php if (count($job_recommendations) > 0): ?>
        <div class="job-matching-section">
            <div class="matching-header">
                <h2 class="matching-title">
                    <i class="fas fa-bullseye"></i> Personalized Job Recommendations
                </h2>
                <div class="match-stats">
                    <div class="stat-item">
                        <span class="stat-value"><?= count($job_recommendations) ?></span>
                        <span class="stat-label">Recommended Jobs</span>
                    </div>
                </div>
            </div>
            
            <div class="recommendations-grid">
                <?php foreach ($job_recommendations as $job): ?>
                <div class="recommendation-card">
                    <div class="match-badge <?= getMatchBadgeClass($job['match_score']) ?>">
                        <?= getMatchBadgeText($job['match_score']) ?>
                    </div>
                    
                    <div class="match-score">
                        <?= $job['match_score'] ?>%
                    </div>
                    
                    <h3 class="job-title"><?= htmlspecialchars($job['job_title']) ?></h3>
                    <div class="job-company"><?= htmlspecialchars($job['emp_company_name']) ?></div>
                    
                    <div class="job-details">
                        <div class="job-detail">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= htmlspecialchars($job['job_location'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-clock"></i>
                            <span><?= ucfirst(str_replace('-', ' ', $job['job_type'])) ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-money-bill-wave"></i>
                            <span><?= formatSalary($job['job_salary_range'] ?? 'Salary not specified') ?></span>
                        </div>
                        <div class="job-posted">
                            <i class="far fa-clock"></i> <?= formatDate($job['job_created_at']) ?>
                        </div>
                    </div>
                    
                    <div class="job-actions">
                        <button class="btn btn-view view-job-detail" 
                                data-job-id="<?= $job['job_id'] ?>" 
                                data-job-title="<?= htmlspecialchars($job['job_title']) ?>" 
                                data-job-company="<?= htmlspecialchars($job['emp_company_name']) ?>"
                                data-job-description="<?= htmlspecialchars($job['job_description'] ?? 'No description available') ?>" 
                                data-job-requirements="<?= htmlspecialchars($job['job_requirements'] ?? 'No requirements specified') ?>" 
                                data-job-location="<?= htmlspecialchars($job['job_location'] ?? 'Not specified') ?>" 
                                data-job-type="<?= htmlspecialchars($job['job_type'] ?? 'Not specified') ?>" 
                                data-job-salary="<?= formatSalary($job['job_salary_range'] ?? 'Salary not specified') ?>" 
                                data-job-domain="<?= htmlspecialchars($job['job_domain'] ?? 'Not specified') ?>" 
                                data-job-skills="<?= htmlspecialchars($job['job_skills'] ?? '') ?>" 
                                data-job-posted="<?= htmlspecialchars($job['job_created_at'] ?? 'Not specified') ?>">
                            View Details
                        </button>
                        <button class="btn btn-apply apply-job" 
                                data-job-id="<?= $job['job_id'] ?>" 
                                data-job-title="<?= htmlspecialchars($job['job_title']) ?>">
                            <i class="fas fa-paper-plane"></i> Apply Now
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- No Match Found Section -->
        <div class="no-match-section">
            <div class="no-match-icon">
                <i class="fas fa-search"></i>
            </div>
            <h2 class="no-match-title">No Match Found</h2>
            <p class="no-match-description">
                We couldn't find any job recommendations that match your profile and preferences at the moment. 
                This could be because you've already applied to relevant jobs or there are no current openings 
                that match your criteria.
            </p>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                <a href="graduate_profile.php" class="btn btn-apply" style="display: inline-block;">
                    <i class="fas fa-user-edit"></i> Update Profile
                </a>
                <a href="graduate_jobs.php" class="btn btn-view" style="display: inline-block; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">
                    <i class="fas fa-briefcase"></i> Browse All Jobs
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- MODIFIED: Skill-based Job Matching Section - Pure Skill Matching Only -->
        <?php if (count($skill_based_matches) > 0): ?>
        <div class="skill-matching-section">
            <div class="matching-header">
                <h2 class="matching-title">
                    <i class="fas fa-code"></i> Pure Skill-Based Job Matches
                </h2>
                <div class="match-stats">
                    <div class="stat-item">
                        <span class="stat-value"><?= count($skill_based_matches) ?></span>
                        <span class="stat-label">Skill Matches</span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($graduate_skills)): ?>
            <div class="skills-display">
                <div class="skills-title">
                    <i class="fas fa-tags"></i> Your Skills (for matching):
                </div>
                <div class="skills-container">
                    <?php foreach ($graduate_skills as $skill): ?>
                    <div class="skill-tag"><?= htmlspecialchars($skill) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="recommendations-grid">
                <?php foreach ($skill_based_matches as $job): ?>
                <div class="recommendation-card">
                    <div class="match-badge <?= getMatchBadgeClass($job['match_score']) ?>">
                        <?= getMatchBadgeText($job['match_score']) ?>
                    </div>
                    
                    <div class="match-score">
                        <?= $job['match_score'] ?>%
                    </div>
                    
                    <h3 class="job-title"><?= htmlspecialchars($job['job_title']) ?></h3>
                    <div class="job-company"><?= htmlspecialchars($job['emp_company_name']) ?></div>
                    
                    <div class="job-details">
                        <div class="job-detail">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= htmlspecialchars($job['job_location'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-clock"></i>
                            <span><?= ucfirst(str_replace('-', ' ', $job['job_type'])) ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-money-bill-wave"></i>
                            <span><?= formatSalary($job['job_salary_range'] ?? 'Salary not specified') ?></span>
                        </div>
                        <div class="job-posted">
                            <i class="far fa-clock"></i> <?= formatDate($job['job_created_at']) ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($job['job_skills'])): ?>
                    <div class="job-skills">
                        <strong>Required Skills:</strong>
                        <div class="skill-tags">
                            <?php
                            $skills = explode(',', $job['job_skills']);
                            foreach ($skills as $skill):
                                $skill = trim($skill);
                                if (!empty($skill)):
                            ?>
                            <span class="skill-tag"><?= htmlspecialchars($skill) ?></span>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="job-actions">
                        <button class="btn btn-view view-job-detail" 
                                data-job-id="<?= $job['job_id'] ?>" 
                                data-job-title="<?= htmlspecialchars($job['job_title']) ?>" 
                                data-job-company="<?= htmlspecialchars($job['emp_company_name']) ?>"
                                data-job-description="<?= htmlspecialchars($job['job_description'] ?? 'No description available') ?>" 
                                data-job-requirements="<?= htmlspecialchars($job['job_requirements'] ?? 'No requirements specified') ?>" 
                                data-job-location="<?= htmlspecialchars($job['job_location'] ?? 'Not specified') ?>" 
                                data-job-type="<?= htmlspecialchars($job['job_type'] ?? 'Not specified') ?>" 
                                data-job-salary="<?= formatSalary($job['job_salary_range'] ?? 'Salary not specified') ?>" 
                                data-job-domain="<?= htmlspecialchars($job['job_domain'] ?? 'Not specified') ?>" 
                                data-job-skills="<?= htmlspecialchars($job['job_skills'] ?? '') ?>" 
                                data-job-posted="<?= htmlspecialchars($job['job_created_at'] ?? 'Not specified') ?>">
                            View Details
                        </button>
                        <?php if ($job['has_applied']): ?>
                            <button class="btn btn-applied" disabled><i class="fas fa-check"></i> Applied</button>
                        <?php else: ?>
                            <button class="btn btn-apply apply-job" 
                                    data-job-id="<?= $job['job_id'] ?>" 
                                    data-job-title="<?= htmlspecialchars($job['job_title']) ?>">
                                <i class="fas fa-paper-plane"></i> Apply Now
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!empty($graduate_skills)): ?>
        <!-- No Skill Matches Found Section -->
        <div class="no-match-section">
            <div class="no-match-icon">
                <i class="fas fa-code"></i>
            </div>
            <h2 class="no-match-title">No Pure Skill Matches Found</h2>
            <p class="no-match-description">
                We couldn't find any jobs that match your current skills. 
                This section shows only jobs where your skills directly match the required skills.
            </p>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                <a href="graduate_portfolio.php" class="btn btn-apply" style="display: inline-block;">
                    <i class="fas fa-plus-circle"></i> Add More Skills
                </a>
                <a href="graduate_jobs.php" class="btn btn-view" style="display: inline-block; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">
                    <i class="fas fa-briefcase"></i> Browse All Jobs
                </a>
            </div>
        </div>
        <?php else: ?>
        <!-- No Skills Added Section -->
        <div class="no-match-section">
            <div class="no-match-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h2 class="no-match-title">No Skills Added</h2>
            <p class="no-match-description">
                You haven't added any skills to your portfolio yet. 
                Add your skills to get pure skill-based job matches.
            </p>
            <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                <a href="graduate_portfolio.php" class="btn btn-apply" style="display: inline-block;">
                    <i class="fas fa-plus-circle"></i> Add Skills to Portfolio
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="company">Company Name</label>
                        <select id="company" name="company">
                            <option value="all">All Companies</option>
                            <?php foreach ($companies as $company): ?>
                            <option value="<?= htmlspecialchars($company) ?>" <?= $company_filter == $company ? 'selected' : '' ?>>
                                <?= htmlspecialchars($company) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="location">Location</label>
                        <select id="location" name="location">
                            <option value="all">All Locations</option>
                            <?php foreach ($locations as $location): ?>
                            <option value="<?= htmlspecialchars($location) ?>" <?= $location_filter == $location ? 'selected' : '' ?>>
                                <?= htmlspecialchars($location) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_posted">Date Posted</label>
                        <select id="date_posted" name="date_posted">
                            <option value="all" <?= $date_filter == 'all' ? 'selected' : '' ?>>Any Time</option>
                            <option value="last_7_days" <?= $date_filter == 'last_7_days' ? 'selected' : '' ?>>Last 7 Days</option>
                            <option value="last_30_days" <?= $date_filter == 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="domain">Domain</label>
                        <select id="domain" name="domain">
                            <option value="all">All Domains</option>
                            <?php foreach ($domains as $domain): ?>
                            <option value="<?= htmlspecialchars($domain) ?>" <?= $domain_filter == $domain ? 'selected' : '' ?>>
                                <?= htmlspecialchars($domain) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search jobs by title, description, or requirements..." value="<?= htmlspecialchars($search_query) ?>">
                    <button type="submit"><i class="fas fa-search"></i> Search</button>
                </div>
            </form>
        </div>
        
        <!-- Results Header with Sorting -->
        <div class="results-header">
            <div class="results-count">
                <i class="fas fa-briefcase"></i> <?= count($jobs) ?> Job<?= count($jobs) != 1 ? 's' : '' ?> Found
            </div>
            <div class="sort-options">
                <label for="sort">Sort by:</label>
                <select id="sort" name="sort" onchange="this.form.submit()">
                    <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="oldest" <?= $sort_by == 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="title_asc" <?= $sort_by == 'title_asc' ? 'selected' : '' ?>>Title (A-Z)</option>
                    <option value="title_desc" <?= $sort_by == 'title_desc' ? 'selected' : '' ?>>Title (Z-A)</option>
                    <option value="company_asc" <?= $sort_by == 'company_asc' ? 'selected' : '' ?>>Company (A-Z)</option>
                    <option value="company_desc" <?= $sort_by == 'company_desc' ? 'selected' : '' ?>>Company (Z-A)</option>
                </select>
            </div>
        </div>
        
        <!-- All Jobs Section -->
        <div class="job-listings">
            <?php if (count($jobs) > 0): ?>
                <?php foreach ($jobs as $job): ?>
                <div class="job-card <?= $job['is_saved'] ? 'saved-job' : '' ?>">
                    <div class="job-header">
                        <div>
                            <h3 class="job-title"><?= htmlspecialchars($job['job_title']) ?></h3>
                            <div class="job-company"><?= htmlspecialchars($job['emp_company_name']) ?></div>
                            <div class="job-posted">
                                <i class="far fa-clock"></i> <?= formatDate($job['job_created_at']) ?>
                            </div>
                        </div>
                        <form method="POST" class="save-form">
                            <input type="hidden" name="job_id" value="<?= $job['job_id'] ?>">
                            <input type="hidden" name="toggle_save_job" value="1">
                            <button type="submit" class="job-save <?= $job['is_saved'] ? 'saved' : '' ?>">
                                <i class="fas fa-bookmark"></i>
                            </button>
                        </form>
                    </div>
                    
                    <div class="job-details">
                        <div class="job-detail">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= htmlspecialchars($job['job_location'] ?? 'Not specified') ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-clock"></i>
                            <span><?= ucfirst(str_replace('-', ' ', $job['job_type'])) ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-money-bill-wave"></i>
                            <span><?= formatSalary($job['job_salary_range'] ?? 'Salary not specified') ?></span>
                        </div>
                        <div class="job-detail">
                            <i class="fas fa-tag"></i>
                            <span><?= htmlspecialchars($job['job_domain'] ?? 'Not specified') ?></span>
                        </div>
                    </div>
                    
                    <?php if (!empty($job['job_skills'])): ?>
                    <div class="job-skills">
                        <strong>Required Skills:</strong>
                        <div class="skill-tags">
                            <?php
                            $skills = explode(',', $job['job_skills']);
                            foreach ($skills as $skill):
                                $skill = trim($skill);
                                if (!empty($skill)):
                            ?>
                            <span class="skill-tag"><?= htmlspecialchars($skill) ?></span>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="job-actions">
                        <button class="btn btn-view view-job-detail" 
                                data-job-id="<?= $job['job_id'] ?>" 
                                data-job-title="<?= htmlspecialchars($job['job_title']) ?>" 
                                data-job-company="<?= htmlspecialchars($job['emp_company_name']) ?>"
                                data-job-description="<?= htmlspecialchars($job['job_description'] ?? 'No description available') ?>" 
                                data-job-requirements="<?= htmlspecialchars($job['job_requirements'] ?? 'No requirements specified') ?>" 
                                data-job-location="<?= htmlspecialchars($job['job_location'] ?? 'Not specified') ?>" 
                                data-job-type="<?= htmlspecialchars($job['job_type'] ?? 'Not specified') ?>" 
                                data-job-salary="<?= formatSalary($job['job_salary_range'] ?? 'Salary not specified') ?>" 
                                data-job-domain="<?= htmlspecialchars($job['job_domain'] ?? 'Not specified') ?>" 
                                data-job-skills="<?= htmlspecialchars($job['job_skills'] ?? '') ?>" 
                                data-job-posted="<?= htmlspecialchars($job['job_created_at'] ?? 'Not specified') ?>">
                            View Details
                        </button>
                        <?php if ($job['has_applied']): ?>
                            <button class="btn btn-applied" disabled><i class="fas fa-check"></i> Applied</button>
                        <?php else: ?>
                            <button class="btn btn-apply apply-job" 
                                    data-job-id="<?= $job['job_id'] ?>" 
                                    data-job-title="<?= htmlspecialchars($job['job_title']) ?>">
                                <i class="fas fa-paper-plane"></i> Apply Now
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 60px 40px; background: white; border-radius: 12px; border-top: 4px solid var(--secondary-color);">
                    <i class="fas fa-briefcase" style="font-size: 4rem; color: #ccc; margin-bottom: 20px;"></i>
                    <h3 style="color: var(--primary-color); margin-bottom: 15px;">No jobs found</h3>
                    <p style="color: #666; font-size: 1.1rem;">Try adjusting your filters or search terms to find more jobs.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Common Dropdown Functionality
        const notification = document.getElementById('notification');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const profile = document.getElementById('profile');
        const profileDropdown = document.getElementById('profileDropdown');
        
        notification.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('active');
            profileDropdown.classList.remove('active');
        });
        
        profile.addEventListener('click', function(e) {
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
                        url: 'graduate_jobs.php',
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
            $('.job-card, .recommendation-card').hover(
                function() {
                    $(this).css('transform', 'translateY(-5px)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                }
            );
            
            // Enhanced save job functionality
            $('.save-form').on('submit', function(e) {
                e.preventDefault();
                
                const form = $(this);
                const saveButton = form.find('.job-save');
                const jobCard = form.closest('.job-card');
                const formData = new FormData(this);
                
                $.ajax({
                    url: '',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        try {
                            const result = JSON.parse(response);
                            if (result.status === 'saved') {
                                saveButton.addClass('saved');
                                jobCard.addClass('saved-job');
                                showToast(result.message, 'success');
                                
                                // Move saved job to the top
                                const jobListings = $('.job-listings');
                                jobCard.prependTo(jobListings);
                            } else if (result.status === 'unsaved') {
                                saveButton.removeClass('saved');
                                jobCard.removeClass('saved-job');
                                showToast(result.message, 'success');
                            }
                        } catch (e) {
                            // If response is not JSON (page reload), show generic message
                            showToast('Job saved status updated!', 'success');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Save job error:', error);
                        showToast('Error updating save status', 'error');
                    }
                });
            });
        });
        
        // Toast notification function
        function showToast(message, type = 'success') {
            // Remove existing toasts
            $('.toast-notification').remove();
            
            const toast = $('<div class="toast-notification"></div>');
            const bgColor = type === 'success' ? '#d4edda' : '#f8d7da';
            const textColor = type === 'success' ? '#155724' : '#721c24';
            const borderColor = type === 'success' ? '#c3e6cb' : '#f5c6cb';
            
            toast.css({
                'background-color': bgColor,
                'color': textColor,
                'border-left-color': borderColor
            });
            
            toast.html(`
                <div class="toast-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                    <span>${message}</span>
                </div>
            `);
            
            $('body').append(toast);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        }
        
        // Enhanced Job Details Modal Functions
        function openJobDetailModal(jobData) {
            // Populate modal with job data
            document.getElementById('detail-job-title').textContent = jobData.title;
            document.getElementById('detail-job-company').textContent = jobData.company;
            document.getElementById('detail-company-name').textContent = jobData.company;
            document.getElementById('detail-job-location').textContent = jobData.location;
            document.getElementById('detail-job-type').textContent = jobData.type;
            document.getElementById('detail-job-salary').textContent = jobData.salary;
            document.getElementById('detail-job-domain').textContent = jobData.domain;
            document.getElementById('detail-job-description').innerHTML = formatText(jobData.description);
            document.getElementById('detail-job-requirements').innerHTML = formatText(jobData.requirements);
            
            // Format and display skills
            const skillsContainer = document.getElementById('detail-job-skills');
            if (jobData.skills && jobData.skills.trim() !== '') {
                const skills = jobData.skills.split(',').map(skill => skill.trim());
                skillsContainer.innerHTML = skills.map(skill => 
                    `<span class="skill">${skill}</span>`
                ).join('');
            } else {
                skillsContainer.innerHTML = '<span class="skill">No specific skills required</span>';
            }
            
            // Format date
            const postedDate = new Date(jobData.posted);
            document.getElementById('detail-job-posted').textContent = postedDate.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Set up apply button
            const applyBtn = document.getElementById('detail-apply-btn');
            applyBtn.onclick = function() {
                closeJobDetailModal();
                openApplyModal(jobData.id, jobData.title);
            };
            
            // Show modal
            document.getElementById('jobDetailModal').classList.add('active');
        }
        
        function closeJobDetailModal() {
            document.getElementById('jobDetailModal').classList.remove('active');
        }
        
        // Application Modal Functions with Enhanced File Upload
        let selectedFiles = [];
        
        function openApplyModal(jobId, jobTitle) {
            // Reset selected files
            selectedFiles = [];
            updateFilePreview();
            
            document.getElementById('modal-job-id').value = jobId;
            document.getElementById('modal-job-title').textContent = jobTitle;
            document.getElementById('applyModal').classList.add('active');
        }
        
        function closeApplyModal() {
            document.getElementById('applyModal').classList.remove('active');
            // Reset form
            document.getElementById('applicationForm').reset();
            selectedFiles = [];
            updateFilePreview();
        }
        
        // Helper function to format text with line breaks
        function formatText(text) {
            if (!text) return '<p>No information provided.</p>';
            return text.split('\n').map(paragraph => 
                paragraph.trim() ? `<p>${paragraph}</p>` : ''
            ).join('');
        }
        
        // Enhanced File Upload Functionality
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('application_files');
    const fileUploadCard = document.getElementById('fileUploadCard');
    const fileUploadBtn = document.querySelector('.file-upload-btn');
    const filePreviewContainer = document.getElementById('filePreviewContainer');
    const filePreviewGrid = document.getElementById('filePreviewGrid');
    const fileCount = document.getElementById('fileCount');
    const submitApplication = document.getElementById('submitApplication');
    
    // File input change event
    fileInput.addEventListener('change', function(e) {
        handleFiles(e.target.files);
    });
    
    // Drag and drop functionality - ONLY for the card, not the button
    fileUploadCard.addEventListener('dragover', function(e) {
        e.preventDefault();
        fileUploadCard.classList.add('dragover');
    });
    
    fileUploadCard.addEventListener('dragleave', function(e) {
        e.preventDefault();
        fileUploadCard.classList.remove('dragover');
    });
    
    fileUploadCard.addEventListener('drop', function(e) {
        e.preventDefault();
        fileUploadCard.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        handleFiles(files);
    });
    
    // REMOVED: The problematic click event on the entire card
    // Only the button should trigger file selection
    
    // Handle file selection
    function handleFiles(files) {
        const maxSize = 5 * 1024 * 1024; // 5MB
        const allowedTypes = ['application/pdf', 'application/msword', 
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'image/jpeg', 'image/jpg', 'image/png', 'text/plain'];
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            
            // Validate file type
            if (!allowedTypes.includes(file.type)) {
                showToast(`File "${file.name}" is not a supported format.`, 'error');
                continue;
            }
            
            // Validate file size
            if (file.size > maxSize) {
                showToast(`File "${file.name}" exceeds 5MB size limit.`, 'error');
                continue;
            }
            
            // Check if file already exists
            const fileExists = selectedFiles.some(f => f.name === file.name && f.size === file.size);
            if (fileExists) {
                showToast(`File "${file.name}" is already selected.`, 'error');
                continue;
            }
            
            // Add to selected files
            selectedFiles.push(file);
        }
        
        updateFilePreview();
    }
            
            // Update file preview display
            function updateFilePreview() {
                filePreviewGrid.innerHTML = '';
                
                if (selectedFiles.length > 0) {
                    filePreviewContainer.style.display = 'block';
                    fileCount.textContent = `${selectedFiles.length} file${selectedFiles.length !== 1 ? 's' : ''}`;
                    submitApplication.disabled = false;
                    
                    selectedFiles.forEach((file, index) => {
                        const fileSize = (file.size / 1024 / 1024).toFixed(2);
                        const fileExtension = file.name.split('.').pop().toUpperCase();
                        const fileIcon = getFileIcon(file.type);
                        
                        const previewItem = document.createElement('div');
                        previewItem.className = 'file-preview-item';
                        previewItem.innerHTML = `
                            <div class="file-preview-icon">
                                <i class="${fileIcon}"></i>
                            </div>
                            <div class="file-preview-info">
                                <div class="file-preview-name" title="${file.name}">${file.name}</div>
                                <div class="file-preview-size">${fileSize} MB • ${fileExtension}</div>
                            </div>
                            <div class="file-preview-actions">
                                <button type="button" class="preview-action-btn remove" onclick="removeFile(${index})">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </div>
                        `;
                        
                        filePreviewGrid.appendChild(previewItem);
                    });
                } else {
                    filePreviewContainer.style.display = 'none';
                    fileCount.textContent = '0 files';
                    submitApplication.disabled = true;
                }
            }
            
            // Get appropriate icon for file type
            function getFileIcon(fileType) {
                if (fileType.includes('pdf')) return 'fas fa-file-pdf';
                if (fileType.includes('word') || fileType.includes('document')) return 'fas fa-file-word';
                if (fileType.includes('image')) return 'fas fa-file-image';
                if (fileType.includes('text')) return 'fas fa-file-alt';
                return 'fas fa-file';
            }
            
            // Form submission handler
            document.getElementById('applicationForm').addEventListener('submit', function(e) {
                if (selectedFiles.length === 0) {
                    e.preventDefault();
                    showToast('Please select at least one file for your application.', 'error');
                    return;
                }
                
                // Create a new FormData object and append files
                const formData = new FormData(this);
                
                // Remove existing file inputs
                formData.delete('application_files[]');
                
                // Append selected files
                selectedFiles.forEach(file => {
                    formData.append('application_files[]', file);
                });
                
                // You would typically send this via AJAX, but for now we'll let the form submit normally
                // For AJAX submission, you would use:
                // e.preventDefault();
                // $.ajax({
                //     url: '',
                //     type: 'POST',
                //     data: formData,
                //     processData: false,
                //     contentType: false,
                //     success: function(response) {
                //         // Handle success
                //     }
                // });
            });
        });
        
        // Remove file from selection
        function removeFile(index) {
            selectedFiles.splice(index, 1);
            updateFilePreview();
        }
        
        // Global function to update file preview (needed for removeFile)
        function updateFilePreview() {
            const filePreviewContainer = document.getElementById('filePreviewContainer');
            const filePreviewGrid = document.getElementById('filePreviewGrid');
            const fileCount = document.getElementById('fileCount');
            const submitApplication = document.getElementById('submitApplication');
            
            filePreviewGrid.innerHTML = '';
            
            if (selectedFiles.length > 0) {
                filePreviewContainer.style.display = 'block';
                fileCount.textContent = `${selectedFiles.length} file${selectedFiles.length !== 1 ? 's' : ''}`;
                submitApplication.disabled = false;
                
                selectedFiles.forEach((file, index) => {
                    const fileSize = (file.size / 1024 / 1024).toFixed(2);
                    const fileExtension = file.name.split('.').pop().toUpperCase();
                    const fileIcon = getFileIcon(file.type);
                    
                    const previewItem = document.createElement('div');
                    previewItem.className = 'file-preview-item';
                    previewItem.innerHTML = `
                        <div class="file-preview-icon">
                            <i class="${fileIcon}"></i>
                        </div>
                        <div class="file-preview-info">
                            <div class="file-preview-name" title="${file.name}">${file.name}</div>
                            <div class="file-preview-size">${fileSize} MB • ${fileExtension}</div>
                        </div>
                        <div class="file-preview-actions">
                            <button type="button" class="preview-action-btn remove" onclick="removeFile(${index})">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                    `;
                    
                    filePreviewGrid.appendChild(previewItem);
                });
            } else {
                filePreviewContainer.style.display = 'none';
                fileCount.textContent = '0 files';
                submitApplication.disabled = true;
            }
        }
        
        // Get file icon helper function
        function getFileIcon(fileType) {
            if (fileType.includes('pdf')) return 'fas fa-file-pdf';
            if (fileType.includes('word') || fileType.includes('document')) return 'fas fa-file-word';
            if (fileType.includes('image')) return 'fas fa-file-image';
            if (fileType.includes('text')) return 'fas fa-file-alt';
            return 'fas fa-file';
        }
        
        // Event listeners for job detail buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Job detail buttons in recommendations
            document.querySelectorAll('.view-job-detail').forEach(button => {
                button.addEventListener('click', function() {
                    const jobData = {
                        id: this.getAttribute('data-job-id'),
                        title: this.getAttribute('data-job-title'),
                        company: this.getAttribute('data-job-company'),
                        description: this.getAttribute('data-job-description'),
                        requirements: this.getAttribute('data-job-requirements'),
                        location: this.getAttribute('data-job-location'),
                        type: this.getAttribute('data-job-type'),
                        salary: this.getAttribute('data-job-salary'),
                        domain: this.getAttribute('data-job-domain'),
                        skills: this.getAttribute('data-job-skills'),
                        posted: this.getAttribute('data-job-posted')
                    };
                    openJobDetailModal(jobData);
                });
            });
            
            // Apply job buttons
            document.querySelectorAll('.apply-job').forEach(button => {
                button.addEventListener('click', function() {
                    const jobId = this.getAttribute('data-job-id');
                    const jobTitle = this.getAttribute('data-job-title');
                    openApplyModal(jobId, jobTitle);
                });
            });
            
            // Close modals when clicking outside
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });
            
            // Close modals with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-overlay').forEach(modal => {
                        modal.classList.remove('active');
                    });
                }
            });
        });
    </script>
</body>
</html>