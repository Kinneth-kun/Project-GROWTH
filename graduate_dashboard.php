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
        header("Location: graduate_form.php");
        exit();
    }
    
    // Check if user account is active and approved
    if ($graduate['usr_account_status'] !== 'active' || !$graduate['usr_is_approved']) {
        error_log("User account not active or approved. Status: " . $graduate['usr_account_status'] . ", Approved: " . $graduate['usr_is_approved']);
        header("Location: index.php?error=account_inactive");
        exit();
    }
    
    // ============================================================================
    // MODIFIED: GET JOB PREFERENCES FROM DATABASE
    // ============================================================================
    
    // Get job preferences from graduates table (stored as JSON)
    $job_preferences_json = $graduate['grad_job_preference'] ?? '[]';
    $job_preferences = json_decode($job_preferences_json, true) ?? [];
    
    // If no job preferences found, check for custom job preferences
    if (empty($job_preferences)) {
        // Get custom job preferences from user_job_preferences table
        $custom_pref_stmt = $conn->prepare("
            SELECT pref_name FROM user_job_preferences 
            WHERE grad_usr_id = :graduate_id
        ");
        $custom_pref_stmt->bindParam(':graduate_id', $graduate_id);
        $custom_pref_stmt->execute();
        $custom_preferences = $custom_pref_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($custom_preferences)) {
            $job_preferences = $custom_preferences;
        }
    }
    
    // Create a string representation of job preferences for display
    $job_preference_display = !empty($job_preferences) ? implode(', ', $job_preferences) : 'Not specified';
    
    // ============================================================================
    // ENHANCED NOTIFICATION GENERATION SYSTEM FOR GRADUATES
    // ============================================================================
    
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
     * Check for application status updates and generate notifications
     */
    function checkApplicationUpdates($conn, $graduate_id) {
        $notificationsGenerated = 0;
        
        // Check for recent application status changes
        $recentApps = $conn->prepare("
            SELECT a.app_id, j.job_title, a.app_status, a.app_updated_at
            FROM applications a
            JOIN jobs j ON a.app_job_id = j.job_id
            WHERE a.app_grad_usr_id = ? 
            AND a.app_updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY a.app_updated_at DESC
        ");
        $recentApps->execute([$graduate_id]);
        $applications = $recentApps->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($applications as $app) {
            // Check if notification already exists for this application update
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_message LIKE ? 
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $searchPattern = "%{$app['job_title']}%";
            $existingNotif->execute([$graduate_id, $searchPattern]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $statusText = ucfirst($app['app_status']);
                $message = "Your application for '{$app['job_title']}' has been updated to: {$statusText}";
                
                if (createGraduateNotification($conn, $graduate_id, 'application_update', $message, $app['app_id'])) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * MODIFIED: Check for new job recommendations based on job preferences
     */
    function checkJobRecommendations($conn, $graduate_id, $graduate, $job_preferences) {
        $notificationsGenerated = 0;
        
        if (empty($job_preferences)) {
            return $notificationsGenerated;
        }
        
        // Check for new jobs matching ANY of the job preferences
        foreach ($job_preferences as $preference) {
            $newJobs = $conn->prepare("
                SELECT j.job_id, j.job_title, e.emp_company_name
                FROM jobs j
                JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
                WHERE j.job_status = 'active'
                AND (
                    j.job_title LIKE CONCAT('%', ?, '%') 
                    OR j.job_domain LIKE CONCAT('%', ?, '%')
                    OR j.job_description LIKE CONCAT('%', ?, '%')
                )
                AND j.job_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY j.job_created_at DESC
                LIMIT 2
            ");
            $newJobs->execute([$preference, $preference, $preference]);
            $jobs = $newJobs->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($jobs as $job) {
                // Check if notification already exists
                $existingNotif = $conn->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE notif_usr_id = ? 
                    AND notif_message LIKE ? 
                    AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                ");
                $searchPattern = "%{$job['job_title']}%";
                $existingNotif->execute([$graduate_id, $searchPattern]);
                
                if ($existingNotif->fetchColumn() == 0) {
                    $message = "New job match for '{$preference}': '{$job['job_title']}' at {$job['emp_company_name']}";
                    
                    if (createGraduateNotification($conn, $graduate_id, 'job_recommendation', $message, $job['job_id'])) {
                        $notificationsGenerated++;
                        break; // Only send one notification per preference per day
                    }
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for portfolio completeness and generate notifications
     */
    function checkPortfolioCompleteness($conn, $graduate_id, $portfolio_stats) {
        $notificationsGenerated = 0;
        
        // Check for missing resume
        if (($portfolio_stats['has_resume'] ?? 0) == 0) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'portfolio_reminder'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Complete your portfolio: Upload your resume to increase job matches";
                
                if (createGraduateNotification($conn, $graduate_id, 'portfolio_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        // Check for low skill count
        if (($portfolio_stats['skill_count'] ?? 0) < 3) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'skill_reminder'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Add more skills to your portfolio to improve job recommendations";
                
                if (createGraduateNotification($conn, $graduate_id, 'skill_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for career development opportunities
     */
    function checkCareerDevelopment($conn, $graduate_id) {
        $notificationsGenerated = 0;
        
        // Check for unread shared resources
        $unreadResources = $conn->prepare("
            SELECT COUNT(*) as count FROM shared_resources 
            WHERE grad_usr_id = ? AND is_read = FALSE
        ");
        $unreadResources->execute([$graduate_id]);
        $resourceCount = $unreadResources->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($resourceCount > 0) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'career_resource'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "You have {$resourceCount} new career development resource(s) available";
                
                if (createGraduateNotification($conn, $graduate_id, 'career_resource', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for profile views and generate notifications
     */
    function checkProfileViews($conn, $graduate_id) {
        $notificationsGenerated = 0;
        
        // Check for recent employer profile views
        $recentViews = $conn->prepare("
            SELECT COUNT(*) as view_count, e.emp_company_name
            FROM employer_profile_views epv
            JOIN employers e ON epv.view_emp_usr_id = e.emp_usr_id
            WHERE epv.view_grad_usr_id = ?
            AND epv.view_viewed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY e.emp_company_name
            ORDER BY view_count DESC
            LIMIT 1
        ");
        $recentViews->execute([$graduate_id]);
        $views = $recentViews->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($views as $view) {
            if ($view['view_count'] > 0) {
                $existingNotif = $conn->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE notif_usr_id = ? 
                    AND notif_message LIKE ?
                    AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                ");
                $searchPattern = "%{$view['emp_company_name']}%";
                $existingNotif->execute([$graduate_id, $searchPattern]);
                
                if ($existingNotif->fetchColumn() == 0) {
                    $message = "Your profile was viewed by {$view['emp_company_name']}";
                    
                    if (createGraduateNotification($conn, $graduate_id, 'profile_view', $message)) {
                        $notificationsGenerated++;
                    }
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Enhanced system notifications generator for graduates
     */
    function generateGraduateNotifications($conn, $graduate_id, $graduate, $portfolio_stats, $job_preferences) {
        $totalNotifications = 0;
        
        // 1. Check for application status updates
        $totalNotifications += checkApplicationUpdates($conn, $graduate_id);
        
        // 2. MODIFIED: Check for new job recommendations using job preferences
        $totalNotifications += checkJobRecommendations($conn, $graduate_id, $graduate, $job_preferences);
        
        // 3. Check for portfolio completeness
        $totalNotifications += checkPortfolioCompleteness($conn, $graduate_id, $portfolio_stats);
        
        // 4. Check for career development opportunities
        $totalNotifications += checkCareerDevelopment($conn, $graduate_id);
        
        // 5. Check for profile views
        $totalNotifications += checkProfileViews($conn, $graduate_id);
        
        return $totalNotifications;
    }
    
    // Get application statistics
    $apps_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_applications,
            SUM(CASE WHEN app_status = 'pending' THEN 1 ELSE 0 END) as applied,
            SUM(CASE WHEN app_status = 'reviewed' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN app_status = 'qualified' THEN 1 ELSE 0 END) as qualified,
            SUM(CASE WHEN app_status = 'hired' THEN 1 ELSE 0 END) as hired,
            SUM(CASE WHEN app_status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM applications
        WHERE app_grad_usr_id = :graduate_id
    ");
    $apps_stmt->bindParam(':graduate_id', $graduate_id);
    $apps_stmt->execute();
    $app_stats = $apps_stmt->fetch(PDO::FETCH_ASSOC);
    
    // ============================================================================
    // MODIFIED PORTFOLIO STATS CALCULATION - BASED ON PORTFOLIO PAGE LOGIC
    // ============================================================================
    
    // Get portfolio items with counts
    $portfolio_stmt = $conn->prepare("
        SELECT * FROM portfolio_items 
        WHERE port_usr_id = :graduate_id 
        ORDER BY port_item_type, port_created_at DESC
    ");
    $portfolio_stmt->bindParam(':graduate_id', $graduate_id);
    $portfolio_stmt->execute();
    $portfolio_items = $portfolio_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count by type
    $resume_count = 0;
    $certificate_count = 0;
    $project_count = 0;
    foreach ($portfolio_items as $item) {
        if ($item['port_item_type'] === 'resume') $resume_count++;
        if ($item['port_item_type'] === 'certificate') $certificate_count++;
        if ($item['port_item_type'] === 'project') $project_count++;
    }
    
    // Get skills with count
    $skills_stmt = $conn->prepare("
        SELECT s.skill_id, s.skill_name, s.skill_category, gs.skill_level 
        FROM graduate_skills gs
        JOIN skills s ON gs.skill_id = s.skill_id
        WHERE gs.grad_usr_id = :graduate_id
        ORDER BY gs.skill_level DESC, s.skill_name
    ");
    $skills_stmt->bindParam(':graduate_id', $graduate_id);
    $skills_stmt->execute();
    $skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    $skill_count = count($skills);
    
    // Get portfolio completeness percentage - BASED ON PORTFOLIO PAGE LOGIC
    $completeness = 20; // Base score for having an account
    
    // Check for profile completeness factors
    if (!empty($graduate['usr_phone'])) $completeness += 10;
    if (!empty($graduate['usr_gender'])) $completeness += 5;
    if (!empty($graduate['usr_birthdate'])) $completeness += 5;
    if ($resume_count > 0) $completeness += 20;
    if ($certificate_count > 0) $completeness += 10;
    if ($project_count > 0) $completeness += 10;
    if ($skill_count >= 3) $completeness += 20;
    if ($skill_count >= 5) $completeness += 10; // Bonus for more skills
    
    // Cap at 100%
    $completeness = min($completeness, 100);
    
    // Create portfolio stats for notifications
    $portfolio_stats = [
        'has_resume' => $resume_count,
        'certificate_count' => $certificate_count,
        'project_count' => $project_count,
        'skill_count' => $skill_count,
        'total_items' => count($portfolio_items)
    ];
    
    // ============================================================================
    // ENHANCED JOB RECOMMENDATIONS SYSTEM - CONNECTED WITH JOB PREFERENCES
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
     * MODIFIED: Enhanced job preference matching with multiple preferences
     */
    function calculatePreferenceMatch($job_title, $job_domain, $job_description, $job_preferences) {
        if (empty($job_preferences)) return 0;
        
        $title_lower = strtolower($job_title);
        $domain_lower = strtolower($job_domain);
        $description_lower = strtolower($job_description);
        
        $highest_match = 0;
        
        foreach ($job_preferences as $preference) {
            $preference_lower = strtolower(trim($preference));
            
            if (empty($preference_lower)) continue;
            
            $current_match = 0;
            
            // Exact match in title (highest weight)
            if (strpos($title_lower, $preference_lower) !== false) {
                $current_match = max($current_match, 80);
            }
            
            // Exact match in domain
            if (strpos($domain_lower, $preference_lower) !== false) {
                $current_match = max($current_match, 70);
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
                        $current_match = max($current_match, 60);
                        break;
                    }
                }
            }
            
            // Partial match in description
            if (strpos($description_lower, $preference_lower) !== false) {
                $current_match = max($current_match, 50);
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
                $current_match = max($current_match, min(40 + ($match_count * 10), 70));
            }
            
            // Update highest match
            $highest_match = max($highest_match, $current_match);
        }
        
        return $highest_match;
    }
    
    /**
     * Calculate job match score based on skills and preferences - ENHANCED VERSION
     */
    function calculateJobMatchScore($job, $graduate_skills, $job_preferences) {
        $score = 0;
        $max_score = 100;
        
        // 1. Job preference matching (50% weight)
        $preference_score = calculatePreferenceMatch(
            $job['job_title'], 
            $job['job_domain'], 
            $job['job_description'], 
            $job_preferences
        );
        $score += ($preference_score * 0.5);
        
        // 2. Skills matching (40% weight)
        $skills_score = 0;
        if (!empty($job['job_skills']) && !empty($graduate_skills)) {
            $job_skills = array_map('trim', explode(',', $job['job_skills']));
            $job_skills_lower = array_map('strtolower', $job_skills);
            $graduate_skills_lower = array_map('strtolower', $graduate_skills);
            
            $matched_skills = array_intersect($graduate_skills_lower, $job_skills_lower);
            $match_percentage = count($matched_skills) / max(count($job_skills), 1);
            $skills_score = $match_percentage * 100;
        }
        $score += ($skills_score * 0.4);
        
        // 3. Recency bonus (10% weight) - newer jobs get slight boost
        $posted_date = new DateTime($job['job_created_at']);
        $current_date = new DateTime();
        $days_diff = $current_date->diff($posted_date)->days;
        
        if ($days_diff <= 7) {
            $score += 10; // Posted within last week
        } elseif ($days_diff <= 30) {
            $score += 5; // Posted within last month
        }
        
        return min(round($score), $max_score);
    }
    
    /**
     * MODIFIED: Get personalized job recommendations using job preferences
     */
    function getJobRecommendations($conn, $graduate_id, $graduate, $job_preferences, $limit = 3) {
        $graduate_skills = getGraduateSkills($conn, $graduate_id);
        
        // Get all active jobs that the graduate hasn't applied to
        $jobs_stmt = $conn->prepare("
            SELECT j.*, e.emp_company_name,
                   EXISTS(SELECT 1 FROM applications a2 WHERE a2.app_job_id = j.job_id AND a2.app_grad_usr_id = :graduate_id) as has_applied
            FROM jobs j
            JOIN employers e ON j.job_emp_usr_id = e.emp_usr_id
            WHERE j.job_status = 'active'
            AND NOT EXISTS(SELECT 1 FROM applications a3 WHERE a3.app_job_id = j.job_id AND a3.app_grad_usr_id = :graduate_id2)
            ORDER BY j.job_created_at DESC
        ");
        $jobs_stmt->bindParam(':graduate_id', $graduate_id);
        $jobs_stmt->bindParam(':graduate_id2', $graduate_id);
        $jobs_stmt->execute();
        $all_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate match scores for each job using job preferences
        $scored_jobs = [];
        foreach ($all_jobs as $job) {
            $match_score = calculateJobMatchScore($job, $graduate_skills, $job_preferences);
            $job['match_score'] = $match_score;
            $job['matched_preferences'] = [];
            
            // Find which preferences matched
            foreach ($job_preferences as $preference) {
                if (stripos($job['job_title'], $preference) !== false || 
                    stripos($job['job_domain'], $preference) !== false ||
                    stripos($job['job_description'], $preference) !== false) {
                    $job['matched_preferences'][] = $preference;
                }
            }
            
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
    
    // Generate notifications for graduate using job preferences
    $notificationsGenerated = generateGraduateNotifications($conn, $graduate_id, $graduate, $portfolio_stats, $job_preferences);
    
    // Get enhanced job recommendations using job preferences
    $recommendations = getJobRecommendations($conn, $graduate_id, $graduate, $job_preferences, 3);
    
    // Get skills data - MODIFIED TO FETCH FROM graduate_skills TABLE
    $skills = [];
    try {
        $skills_stmt = $conn->prepare("
            SELECT s.skill_name, s.skill_category 
            FROM graduate_skills gs
            JOIN skills s ON gs.skill_id = s.skill_id
            WHERE gs.grad_usr_id = :graduate_id
            LIMIT 6
        ");
        $skills_stmt->bindParam(':graduate_id', $graduate_id);
        $skills_stmt->execute();
        $skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Skills query error: " . $e->getMessage());
        // Use default skills if table doesn't exist
        $skills = [
            ['skill_name' => 'Python', 'skill_category' => 'Programming'],
            ['skill_name' => 'HTML', 'skill_category' => 'Web Development']
        ];
    }
    
    // Get in-demand skills (from job postings)
    $in_demand_skills = [];
    try {
        $in_demand_skills_stmt = $conn->prepare("
            SELECT DISTINCT job_domain as skill_name
            FROM jobs 
            WHERE job_status = 'active' 
            AND job_domain IS NOT NULL
            ORDER BY job_created_at DESC 
            LIMIT 6
        ");
        $in_demand_skills_stmt->execute();
        $in_demand_skills = $in_demand_skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("In-demand skills query error: " . $e->getMessage());
        // Use default in-demand skills
        $in_demand_skills = [
            ['skill_name' => 'JavaScript'],
            ['skill_name' => 'SQL'],
            ['skill_name' => 'Communication'],
            ['skill_name' => 'Problem Solving']
        ];
    }
    
    // Get comprehensive notifications
    $notifications = [];
    $unread_notif_count = 0;
    try {
        $notif_stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE notif_usr_id = :graduate_id 
            ORDER BY notif_created_at DESC 
            LIMIT 15
        ");
        $notif_stmt->bindParam(':graduate_id', $graduate_id);
        $notif_stmt->execute();
        $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notifications as $notif) {
            if (!$notif['notif_is_read']) $unread_notif_count++;
        }
        
        // If no notifications found, create some default ones based on user activity
        if (empty($notifications)) {
            $notifications = generateDefaultNotifications($conn, $graduate_id, $graduate, $app_stats, $portfolio_stats, $job_preferences);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultNotifications($conn, $graduate_id, $graduate, $app_stats, $portfolio_stats, $job_preferences);
        $unread_notif_count = count($notifications);
    }
    
    // Get recent activity
    $recent_activities = [];
    try {
        $activity_stmt = $conn->prepare("
            SELECT activity_type, activity_details, activity_date 
            FROM user_activities 
            WHERE activity_usr_id = :graduate_id 
            ORDER BY activity_date DESC 
            LIMIT 5
        ");
        $activity_stmt->bindParam(':graduate_id', $graduate_id);
        $activity_stmt->execute();
        $recent_activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("User activities query error: " . $e->getMessage());
        // Create default activity if table doesn't exist
        $recent_activities = [
            [
                'activity_type' => 'login',
                'activity_details' => 'Logged in to dashboard',
                'activity_date' => date('Y-m-d H:i:s')
            ]
        ];
    }
    
    // Get saved jobs count
    $saved_jobs_count = 0;
    try {
        $saved_jobs_stmt = $conn->prepare("
            SELECT COUNT(*) as saved_count 
            FROM saved_jobs 
            WHERE grad_usr_id = :graduate_id
        ");
        $saved_jobs_stmt->bindParam(':graduate_id', $graduate_id);
        $saved_jobs_stmt->execute();
        $saved_jobs = $saved_jobs_stmt->fetch(PDO::FETCH_ASSOC);
        $saved_jobs_count = $saved_jobs['saved_count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Saved jobs query error: " . $e->getMessage());
        $saved_jobs_count = 0;
    }
    
    // Get shared resources count
    $unread_resources_count = 0;
    try {
        $resources_stmt = $conn->prepare("
            SELECT COUNT(*) as resources_count 
            FROM shared_resources 
            WHERE grad_usr_id = :graduate_id AND is_read = FALSE
        ");
        $resources_stmt->bindParam(':graduate_id', $graduate_id);
        $resources_stmt->execute();
        $resources = $resources_stmt->fetch(PDO::FETCH_ASSOC);
        $unread_resources_count = $resources['resources_count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Shared resources query error: " . $e->getMessage());
        $unread_resources_count = 0;
    }
    
    // Get profile views count (last 7 days)
    $profile_views_count = 0;
    try {
        $views_stmt = $conn->prepare("
            SELECT COUNT(*) as views_count 
            FROM employer_profile_views 
            WHERE view_grad_usr_id = :graduate_id 
            AND view_viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $views_stmt->bindParam(':graduate_id', $graduate_id);
        $views_stmt->execute();
        $views = $views_stmt->fetch(PDO::FETCH_ASSOC);
        $profile_views_count = $views['views_count'] ?? 0;
    } catch (PDOException $e) {
        error_log("Profile views query error: " . $e->getMessage());
        $profile_views_count = 0;
    }
    
    // Get applications by month for chart
    $apps_by_month_stmt = $conn->prepare("
        SELECT 
            YEAR(app_applied_at) as year, 
            MONTH(app_applied_at) as month, 
            COUNT(*) as count
        FROM applications
        WHERE app_grad_usr_id = :graduate_id
        AND app_applied_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(app_applied_at), MONTH(app_applied_at)
        ORDER BY year, month
    ");
    $apps_by_month_stmt->bindParam(':graduate_id', $graduate_id);
    $apps_by_month_stmt->execute();
    $apps_by_month = $apps_by_month_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
}

/**
 * MODIFIED: Generate default notifications based on user data and job preferences
 */
function generateDefaultNotifications($conn, $graduate_id, $graduate, $app_stats, $portfolio_stats, $job_preferences) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to CTU-PESO Graduate Dashboard! Complete your profile to get started.',
        'notif_type' => 'system',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s')
    ];
    
    // Portfolio completion notifications
    if (($portfolio_stats['has_resume'] ?? 0) == 0) {
        $default_notifications[] = [
            'notif_message' => 'Upload your resume to complete your portfolio and increase job matches.',
            'notif_type' => 'portfolio_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    }
    
    if (($portfolio_stats['skill_count'] ?? 0) < 3) {
        $default_notifications[] = [
            'notif_message' => 'Add more skills to your portfolio to improve job recommendations.',
            'notif_type' => 'skill_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ];
    }
    
    // Application status notifications
    $total_apps = $app_stats['total_applications'] ?? 0;
    if ($total_apps == 0) {
        $default_notifications[] = [
            'notif_message' => 'Start applying to jobs! Browse available positions in the Jobs section.',
            'notif_type' => 'application_tip',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
        ];
    } else {
        if (($app_stats['hired'] ?? 0) > 0) {
            $default_notifications[] = [
                'notif_message' => 'Congratulations! You have been hired for a position.',
                'notif_type' => 'application_update',
                'notif_is_read' => 0,
                'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ];
        }
        
        if (($app_stats['qualified'] ?? 0) > 0) {
            $default_notifications[] = [
                'notif_message' => 'Great news! Some of your applications have been qualified.',
                'notif_type' => 'application_update',
                'notif_is_read' => 0,
                'notif_created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ];
        }
    }
    
    // Job preference notifications
    if (!empty($job_preferences)) {
        $pref_list = implode(', ', array_slice($job_preferences, 0, 3));
        if (count($job_preferences) > 3) {
            $pref_list .= ' and more';
        }
        
        $default_notifications[] = [
            'notif_message' => "Your job preferences are set to: {$pref_list}. We'll find matching jobs for you.",
            'notif_type' => 'job_recommendation',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    } else {
        $default_notifications[] = [
            'notif_message' => 'Set your job preferences in your profile to get personalized job recommendations.',
            'notif_type' => 'job_recommendation',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
    }
    
    // Career development notifications
    $default_notifications[] = [
        'notif_message' => 'Check out career development resources in the Career Tools section.',
        'notif_type' => 'career_resource',
        'notif_is_read' => 0,
        'notif_created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))
    ];
    
    return $default_notifications;
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
        header("Location: graduate_dashboard.php");
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
        case 'application_update':
            return 'fas fa-file-alt';
        case 'job_recommendation':
            return 'fas fa-briefcase';
        case 'portfolio_reminder':
            return 'fas fa-file-contract';
        case 'skill_reminder':
            return 'fas fa-tools';
        case 'career_resource':
            return 'fas fa-graduation-cap';
        case 'profile_view':
            return 'fas fa-eye';
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
    
    if (strpos($type, 'application_update') !== false || 
        strpos($type, 'hired') !== false ||
        strpos($type, 'qualified') !== false) {
        return 'high';
    } elseif (strpos($type, 'job_recommendation') !== false || 
              strpos($type, 'profile_view') !== false) {
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
    } elseif (strpos($type, 'portfolio') !== false || strpos($message, 'portfolio') !== false || strpos($message, 'resume') !== false) {
        return 'graduate_portfolio.php';
    } elseif (strpos($type, 'skill') !== false || strpos($message, 'skill') !== false) {
        return 'graduate_portfolio.php';
    } elseif (strpos($type, 'career') !== false || strpos($message, 'resource') !== false) {
        return 'graduate_tools.php';
    } elseif (strpos($type, 'profile') !== false || strpos($message, 'profile') !== false) {
        return 'graduate_profile.php';
    } else {
        return 'graduate_dashboard.php';
    }
}

// Prepare data for charts
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Prepare applications data
$applications_data = [];
$applications_labels = [];
foreach ($apps_by_month as $app) {
    $applications_data[] = $app['count'];
    $applications_labels[] = $month_names[$app['month'] - 1] . ' ' . $app['year'];
}

// Prepare application status data for chart
$application_status_data = [
    $app_stats['applied'] ?? 0,
    $app_stats['under_review'] ?? 0,
    $app_stats['qualified'] ?? 0,
    $app_stats['hired'] ?? 0,
    $app_stats['rejected'] ?? 0
];

// Calculate percentages for application status
$total_applications = $app_stats['total_applications'] ?? 0;
$application_percentages = [
    'applied' => $total_applications > 0 ? round(($app_stats['applied'] ?? 0) / $total_applications * 100, 1) : 0,
    'under_review' => $total_applications > 0 ? round(($app_stats['under_review'] ?? 0) / $total_applications * 100, 1) : 0,
    'qualified' => $total_applications > 0 ? round(($app_stats['qualified'] ?? 0) / $total_applications * 100, 1) : 0,
    'hired' => $total_applications > 0 ? round(($app_stats['hired'] ?? 0) / $total_applications * 100, 1) : 0,
    'rejected' => $total_applications > 0 ? round(($app_stats['rejected'] ?? 0) / $total_applications * 100, 1) : 0,
];

// Calculate success rate
$success_rate = ($app_stats['total_applications'] ?? 0) > 0 ? round((($app_stats['qualified'] ?? 0) + ($app_stats['hired'] ?? 0)) / $app_stats['total_applications'] * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Alumni Dashboard</title>
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
        
        /* MODIFIED: Job Preferences Display Styles */
        .job-preferences-display {
            margin-top: 15px;
            padding: 15px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
        }
        
        .job-preferences-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .job-preferences-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .job-preference-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--primary-color);
            color: white;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .job-preference-tag i {
            font-size: 0.75rem;
        }
        
        .no-preferences {
            color: #666;
            font-style: italic;
            font-size: 0.9rem;
        }
        
        /* Job List Styles */
        .job-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            transition: all 0.3s;
        }
        
        .job-item:hover {
            background-color: #f8f9fa;
            padding-left: 10px;
            border-radius: 5px;
        }
        
        .job-item:last-child {
            border-bottom: none;
        }
        
        .job-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .job-details {
            font-size: 0.9rem;
            color: #666;
        }
        
        .job-company {
            font-weight: 500;
            color: var(--primary-color);
        }
        
        .job-match {
            font-size: 0.8rem;
            color: var(--green);
            font-weight: 500;
            margin-top: 3px;
        }
        
        .job-match-score {
            font-size: 0.75rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            display: inline-block;
            margin-left: 5px;
        }
        
        .matched-preferences {
            margin-top: 5px;
            font-size: 0.75rem;
            color: #666;
        }
        
        .matched-preferences span {
            display: inline-block;
            background: #e8f5e9;
            color: #2e7d32;
            padding: 2px 6px;
            border-radius: 4px;
            margin-right: 5px;
            margin-bottom: 3px;
        }
        
        /* No Match Found Section */
        .no-match-found {
            text-align: center;
            padding: 30px 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            margin-top: 10px;
            border: 2px dashed #ddd;
        }
        
        .no-match-icon {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 15px;
        }
        
        .no-match-title {
            font-size: 1.2rem;
            color: #666;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .no-match-description {
            color: #888;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        /* Skill List Styles */
        .skill-list {
            list-style: none;
            margin-top: 10px;
        }
        
        .skill-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .skill-list li:hover {
            background-color: #f8f9fa;
            padding-left: 10px;
            border-radius: 5px;
        }
        
        .skill-list li:last-child {
            border-bottom: none;
        }
        
        .skill-name {
            font-weight: 500;
        }
        
        .skill-status {
            font-size: 0.8rem;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .matched {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        
        .suggested {
            background-color: #fff3e0;
            color: #ef6c00;
        }
        
        /* Activity List Styles */
        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            font-size: 0.9rem;
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
        
        .activity-date {
            color: #666;
            font-size: 0.8rem;
            margin-top: 3px;
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
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
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
                    <a href="graduate_dashboard.php" class="active">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="graduate_jobs.php">
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
                                $notification_link = getNotificationLink($notif);
                                $notification_icon = getNotificationIcon($notif);
                                $priority_class = 'priority-' . getNotificationPriority($notif);
                            ?>
                            <a href="<?= $notification_link ?>" class="notification-link <?= $notif['notif_is_read'] ? '' : 'unread' ?>" data-notif-id="<?= $notif['notif_id'] ?>">
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
            <p>Track your job applications and improve your portfolio. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Alumni Dashboard</h1>
        </div>
        
        <!-- Enhanced Dashboard Cards -->
        <div class="dashboard-cards">
            <!-- Application Status Card with Enhanced Doughnut Chart -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPLICATION STATUS</h3>
                    <i class="fas fa-file-alt card-icon"></i>
                </div>
                <div class="card-value"><?= $app_stats['total_applications'] ?? 0 ?></div>
                <div class="card-percentage positive-percentage">
                    <i class="fas fa-percentage"></i> <?= $success_rate ?>% Success Rate
                </div>
                <div class="card-footer">Total Applications Submitted</div>
                <div class="chart-container">
                    <canvas id="applicationStatusChart"></canvas>
                </div>
            </div>
            
            <!-- Portfolio Completion Card with Enhanced Stats - MODIFIED BASED ON PORTFOLIO PAGE LOGIC -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">PORTFOLIO COMPLETION</h3>
                    <i class="fas fa-graduation-cap card-icon"></i>
                </div>
                <div class="card-value"><?= $completeness ?>%</div>
                <div class="card-percentage <?= $completeness >= 70 ? 'positive-percentage' : 'negative-percentage' ?>">
                    <?php if ($completeness >= 70): ?>
                    <i class="fas fa-check-circle"></i> Well Maintained
                    <?php else: ?>
                    <i class="fas fa-exclamation-circle"></i> Needs Improvement
                    <?php endif; ?>
                </div>
                <div class="card-footer">Portfolio Completion Status</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $resume_count ?></div>
                        <div class="stat-label">Resumes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $certificate_count ?></div>
                        <div class="stat-label">Certificates</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $project_count ?></div>
                        <div class="stat-label">Projects</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $skill_count ?></div>
                        <div class="stat-label">Skills</div>
                    </div>
                </div>
                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-tasks"></i>
                        <?php if ($completeness < 70): ?>
                        Complete your portfolio to get better job matches
                        <?php else: ?>
                        Great job! Your portfolio is well-maintained
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($resume_count == 0): ?>
                        • Upload your resume<br>
                        <?php endif; ?>
                        <?php if ($skill_count < 3): ?>
                        • Add more skills<br>
                        <?php endif; ?>
                        <?php if ($certificate_count == 0): ?>
                        • Add certificates<br>
                        <?php endif; ?>
                        <?php if ($project_count == 0): ?>
                        • Add projects
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- MODIFIED: Job Preferences Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">JOB PREFERENCES</h3>
                    <i class="fas fa-bullseye card-icon"></i>
                </div>
                <div class="card-value"><?= count($job_preferences) ?></div>
                <div class="card-percentage positive-percentage">
                    <i class="fas fa-check-circle"></i> Personalized Matching
                </div>
                <div class="card-footer">Your Job Preferences</div>
                
                <div class="job-preferences-display">
                    <div class="job-preferences-title">
                        <i class="fas fa-list"></i> Your Selected Preferences:
                    </div>
                    
                    <?php if (!empty($job_preferences)): ?>
                        <div class="job-preferences-list">
                            <?php foreach ($job_preferences as $preference): ?>
                                <div class="job-preference-tag">
                                    <i class="fas fa-check"></i>
                                    <?= htmlspecialchars($preference) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-preferences">
                            <i class="fas fa-info-circle"></i> No job preferences set yet.
                            <br>
                            <small>Set your preferences in your profile to get personalized job recommendations.</small>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i>
                        Job Preference Tip
                    </div>
                    <div>
                        <?php if (!empty($job_preferences)): ?>
                        We're finding jobs that match your preferences for better recommendations.
                        <?php else: ?>
                        Set your job preferences in your profile to get better personalized job matches.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Job Recommendations Card - ENHANCED WITH JOB PREFERENCES -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">JOB RECOMMENDATIONS</h3>
                    <i class="fas fa-briefcase card-icon"></i>
                </div>
                <div class="card-value"><?= count($recommendations) ?></div>
                <div class="card-percentage <?= !empty($recommendations) ? 'positive-percentage' : 'negative-percentage' ?>">
                    <i class="fas fa-percentage"></i> 
                    <?php if (!empty($recommendations)): ?>
                    <?= round(array_sum(array_column($recommendations, 'match_score')) / count($recommendations)) ?>% Avg Match
                    <?php else: ?>
                    No Matches
                    <?php endif; ?>
                </div>
                <div class="card-footer">Personalized Job Matches</div>
                
                <?php if (!empty($recommendations)): ?>
                    <?php foreach ($recommendations as $job): ?>
                    <div class="job-item">
                        <div class="job-title"><?= htmlspecialchars($job['job_title']) ?></div>
                        <div class="job-details">
                            <div class="job-company"><?= htmlspecialchars($job['emp_company_name']) ?></div>
                            <div class="job-match">
                                <?php 
                                $match_score = $job['match_score'] ?? 0;
                                if ($match_score >= 80) {
                                    echo 'Excellent Match';
                                } elseif ($match_score >= 60) {
                                    echo 'Good Match';
                                } elseif ($match_score >= 40) {
                                    echo 'Fair Match';
                                } else {
                                    echo 'Related Match';
                                }
                                ?>
                                <span class="job-match-score"><?= $match_score ?>%</span>
                            </div>
                            <?php if (!empty($job['matched_preferences'])): ?>
                            <div class="matched-preferences">
                                <small>Matches: <?= implode(', ', array_slice($job['matched_preferences'], 0, 3)) ?></small>
                            </div>
                            <?php endif; ?>
                            <div><small><?= htmlspecialchars($job['job_type']) ?> • <?= htmlspecialchars($job['job_location']) ?></small></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- No Match Found Section -->
                    <div class="no-match-found">
                        <div class="no-match-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <div class="no-match-title">No Job Match Found</div>
                        <div class="no-match-description">
                            <?php if (empty($job_preferences)): ?>
                            Set your job preference in your profile to get personalized recommendations.
                            <?php else: ?>
                            We couldn't find any jobs matching your preferences.
                            Try updating your preferences or check back later for new opportunities.
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-search"></i>
                        Job Search Tip
                    </div>
                    <div>
                        <?php if (!empty($job_preferences)): ?>
                        We're showing jobs that match your <?= count($job_preferences) ?> job preference(s).
                        <?php else: ?>
                        Update your job preferences in your profile to get better matches
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Skills Match Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">SKILLS MATCHED vs. IN-DEMAND</h3>
                    <i class="fas fa-chart-bar card-icon"></i>
                </div>
                <div class="card-value"><?= count($skills) ?></div>
                <div class="card-footer">Your Skills vs. In-Demand</div>
                <ul class="skill-list">
                    <?php 
                    // Display matched skills
                    $displayed_skills = [];
                    foreach ($skills as $skill): 
                        if (!in_array($skill['skill_name'], $displayed_skills)):
                            $displayed_skills[] = $skill['skill_name'];
                    ?>
                    <li>
                        <span class="skill-name"><?= htmlspecialchars($skill['skill_name']) ?></span>
                        <span class="skill-status matched">Matched</span>
                    </li>
                    <?php 
                        endif;
                    endforeach; 
                    
                    // Display suggested skills (in-demand skills not already in user's skills)
                    $suggested_count = 0;
                    foreach ($in_demand_skills as $skill): 
                        if (!in_array($skill['skill_name'], array_column($skills, 'skill_name')) && $suggested_count < 3):
                            $suggested_count++;
                    ?>
                    <li>
                        <span class="skill-name"><?= htmlspecialchars($skill['skill_name']) ?></span>
                        <span class="skill-status suggested">Suggested</span>
                    </li>
                    <?php 
                        endif;
                    endforeach; 
                    
                    // If no skills found, show message
                    if (empty($skills) && $suggested_count == 0): 
                    ?>
                    <li>
                        <span class="skill-name">No skills added yet</span>
                        <span class="skill-status suggested">Add skills in portfolio</span>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="tip-box">
                    <div class="tip-title">
                        <i class="fas fa-lightbulb"></i>
                        Skill Development
                    </div>
                    <div>Focus on developing suggested skills to increase your job opportunities</div>
                </div>
            </div>
            
            <!-- Application Progress Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">APPLICATION PROGRESS</h3>
                    <i class="fas fa-tasks card-icon"></i>
                </div>
                <div class="stat-item" style="margin-bottom: 15px; background: #e8f5e9; border-left: 4px solid #4caf50;">
                    <div class="stat-value" style="color: #2e7d32;"><?= $app_stats['qualified'] ?? 0 ?></div>
                    <div class="stat-label">Qualified Applications</div>
                </div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $profile_views_count ?></div>
                        <div class="stat-label">Profile Views (7 days)</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $app_stats['total_applications'] ?? 0 ?></div>
                        <div class="stat-label">Total Applications</div>
                    </div>
                </div>
                <div class="tip-box" style="background-color: #e3f2fd; border-left: 4px solid #2196f3;">
                    <div class="tip-title" style="color: #1565c0;">
                        <i class="fas fa-chart-line"></i>
                        Success Rate
                    </div>
                    <div>
                        <?php 
                        $total_apps = $app_stats['total_applications'] ?? 1;
                        $qualified_apps = $app_stats['qualified'] ?? 0;
                        $success_rate = $total_apps > 0 ? round(($qualified_apps / $total_apps) * 100, 1) : 0;
                        ?>
                        Your qualification rate: <strong><?= $success_rate ?>%</strong>
                    </div>
                </div>
            </div>
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
                        url: 'graduate_dashboard.php',
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
            // Enhanced Application Status Chart (Doughnut)
            const applicationStatusCtx = document.getElementById('applicationStatusChart').getContext('2d');
            new Chart(applicationStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Applied', 'Under Review', 'Qualified', 'Hired', 'Rejected'],
                    datasets: [{
                        data: <?= json_encode($application_status_data) ?>,
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
        });
    </script>
</body>
</html>