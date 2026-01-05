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
        SELECT g.*, u.usr_name, u.usr_email, u.usr_phone, u.usr_profile_photo, 
               u.usr_gender, u.usr_birthdate, u.usr_created_at, u.usr_account_status, u.usr_is_approved
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
    
    // Initialize default values to prevent undefined array key warnings
    $graduate = array_merge([
        'usr_name' => '',
        'usr_email' => '',
        'usr_phone' => '',
        'usr_profile_photo' => '',
        'usr_gender' => '',
        'usr_birthdate' => '',
        'usr_created_at' => '',
        'usr_account_status' => '',
        'usr_is_approved' => '',
        'grad_school_id' => '',
        'grad_degree' => '',
        'grad_year_graduated' => '',
        'grad_job_preference' => '',
        'grad_summary' => ''
    ], $graduate);
    
    // ============================================================================
    // ENHANCED NOTIFICATION SYSTEM FOR PORTFOLIO PAGE
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
                AND notif_message LIKE '%resume%'
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
        
        // Check for missing certificates
        if (($portfolio_stats['certificate_count'] ?? 0) == 0) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'portfolio_reminder'
                AND notif_message LIKE '%certificate%'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Add certificates to showcase your qualifications and achievements";
                
                if (createGraduateNotification($conn, $graduate_id, 'portfolio_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Check for profile completeness and generate notifications
     */
    function checkProfileCompleteness($conn, $graduate_id, $graduate) {
        $notificationsGenerated = 0;
        
        // Check for incomplete personal information
        if (empty($graduate['usr_phone']) || empty($graduate['usr_gender']) || empty($graduate['usr_birthdate'])) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'profile_reminder'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Complete your personal information to improve your profile visibility";
                
                if (createGraduateNotification($conn, $graduate_id, 'profile_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        // Check for incomplete academic information
        if (empty($graduate['grad_degree']) || empty($graduate['grad_year_graduated']) || empty($graduate['grad_job_preference'])) {
            $existingNotif = $conn->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE notif_usr_id = ? 
                AND notif_type = 'profile_reminder'
                AND notif_message LIKE '%academic%'
                AND notif_created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ");
            $existingNotif->execute([$graduate_id]);
            
            if ($existingNotif->fetchColumn() == 0) {
                $message = "Complete your academic background for better job matching";
                
                if (createGraduateNotification($conn, $graduate_id, 'profile_reminder', $message)) {
                    $notificationsGenerated++;
                }
            }
        }
        
        return $notificationsGenerated;
    }
    
    /**
     * Enhanced portfolio notifications generator
     */
    function generatePortfolioNotifications($conn, $graduate_id, $graduate, $portfolio_stats) {
        $totalNotifications = 0;
        
        // 1. Check for portfolio completeness
        $totalNotifications += checkPortfolioCompleteness($conn, $graduate_id, $portfolio_stats);
        
        // 2. Check for profile completeness
        $totalNotifications += checkProfileCompleteness($conn, $graduate_id, $graduate);
        
        return $totalNotifications;
    }
    
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
    
    // Create portfolio stats for notifications
    $portfolio_stats = [
        'has_resume' => $resume_count,
        'certificate_count' => $certificate_count,
        'project_count' => $project_count,
        'skill_count' => $skill_count,
        'total_items' => count($portfolio_items)
    ];
    
    // Generate portfolio-specific notifications
    $notificationsGenerated = generatePortfolioNotifications($conn, $graduate_id, $graduate, $portfolio_stats);
    
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
            $notifications = generateDefaultNotifications($conn, $graduate_id, $graduate, $portfolio_stats);
            $unread_notif_count = count($notifications);
        }
    } catch (PDOException $e) {
        error_log("Notifications query error: " . $e->getMessage());
        // Generate default notifications based on current data
        $notifications = generateDefaultNotifications($conn, $graduate_id, $graduate, $portfolio_stats);
        $unread_notif_count = count($notifications);
    }
    
    // Get all available skills for dropdown, grouped by category
    $all_skills_stmt = $conn->prepare("
        SELECT * FROM skills 
        WHERE skill_id NOT IN (
            SELECT skill_id FROM graduate_skills WHERE grad_usr_id = :graduate_id
        )
        ORDER BY skill_category, skill_name
        LIMIT 300
    ");
    $all_skills_stmt->bindParam(':graduate_id', $graduate_id);
    $all_skills_stmt->execute();
    $all_skills = $all_skills_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group skills by category
    $skills_by_category = [];
    foreach ($all_skills as $skill) {
        $category = $skill['skill_category'] ?? 'Other';
        if (!isset($skills_by_category[$category])) {
            $skills_by_category[$category] = [];
        }
        $skills_by_category[$category][] = $skill;
    }
    
    // ============================================================================
    // ENHANCED JOB PREFERENCES FUNCTIONALITY (FROM graduate_profile.php)
    // ============================================================================
    
    // Fetch job positions and categories for job preferences
    $job_positions = [];
    $job_categories = [];
    
    try {
        // Fetch all active job categories
        $categories_stmt = $conn->prepare("
            SELECT category_id, category_name 
            FROM job_categories 
            WHERE is_active = TRUE 
            ORDER BY category_name
        ");
        $categories_stmt->execute();
        $job_categories = $categories_stmt->fetchAll();
        
        // Fetch all active job positions
        $jobs_stmt = $conn->prepare("
            SELECT 
                jp.position_id,
                jp.position_name,
                jc.category_id,
                jc.category_name
            FROM job_positions jp
            INNER JOIN job_categories jc ON jp.category_id = jc.category_id
            WHERE jp.is_active = TRUE AND jc.is_active = TRUE
            ORDER BY jc.category_name, jp.position_name
        ");
        $jobs_stmt->execute();
        $all_jobs = $jobs_stmt->fetchAll();
        
        // Organize job positions by category
        foreach ($all_jobs as $job) {
            $category_id = $job['category_id'];
            if (!isset($job_positions[$category_id])) {
                $job_positions[$category_id] = [
                    'category_name' => $job['category_name'],
                    'positions' => []
                ];
            }
            $job_positions[$category_id]['positions'][] = [
                'position_id' => $job['position_id'],
                'position_name' => $job['position_name']
            ];
        }
    } catch (PDOException $e) {
        error_log("Error fetching job preferences data - " . $e->getMessage());
        $job_positions = [];
        $job_categories = [];
    }
    
    // Parse job preferences (JSON array)
    $selected_job_preferences = [];
    if (!empty($graduate['grad_job_preference'])) {
        $preferences_array = json_decode($graduate['grad_job_preference'], true);
        if (is_array($preferences_array)) {
            $selected_job_preferences = $preferences_array;
        }
    }
    
    // Get portfolio completeness percentage
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
    
    // Handle form submissions
    $success_message = '';
    $error_message = '';
    
    // Parse full name into components for form display
    $last_name = '';
    $first_name = '';
    $middle_name = '';

    if (!empty($graduate['usr_name'])) {
        $name_parts = explode(' ', $graduate['usr_name']);
        $name_count = count($name_parts);
        
        if ($name_count >= 1) {
            $last_name = $name_parts[$name_count - 1]; // Last part is last name
        }
        if ($name_count >= 2) {
            $first_name = $name_parts[0]; // First part is first name
        }
        if ($name_count >= 3) {
            // Everything in between is middle name
            $middle_name = implode(' ', array_slice($name_parts, 1, $name_count - 2));
        }
    }

    // Parse phone number to extract just the digits
    $phone_digits = '';
    if (!empty($graduate['usr_phone'])) {
        // Extract only digits from phone number
        $phone_digits = preg_replace('/[^0-9]/', '', $graduate['usr_phone']);
        // Remove country code if present (assuming +63)
        if (strpos($graduate['usr_phone'], '+63') === 0) {
            $phone_digits = substr($phone_digits, 2); // Remove the 63
        }
    }
    
    // Handle document upload
    if (isset($_POST['upload_document'])) {
        $item_type = $_POST['item_type'] ?? '';
        $item_title = trim($_POST['item_title'] ?? '');
        $item_description = trim($_POST['item_description'] ?? '');
        
        // Validate inputs
        if (empty($item_title)) {
            $error_message = "Please enter a document title.";
        } elseif (empty($item_type)) {
            $error_message = "Please select a document type.";
        } else {
            // File upload handling
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/portfolio/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate safe filename
                $original_name = $_FILES['document_file']['name'];
                $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $item_title);
                $file_name = time() . '_' . $safe_filename . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                // Check file type
                $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
                
                if (in_array($file_extension, $allowed_types)) {
                    // Check file size (max 5MB)
                    if ($_FILES['document_file']['size'] <= 5 * 1024 * 1024) {
                        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $file_path)) {
                            // Insert into database
                            $insert_stmt = $conn->prepare("
                                INSERT INTO portfolio_items 
                                (port_usr_id, port_item_type, port_item_title, port_item_description, port_item_file, port_item_date) 
                                VALUES (:user_id, :type, :title, :description, :file_path, CURDATE())
                            ");
                            $insert_stmt->bindParam(':user_id', $graduate_id);
                            $insert_stmt->bindParam(':type', $item_type);
                            $insert_stmt->bindParam(':title', $item_title);
                            $insert_stmt->bindParam(':description', $item_description);
                            $insert_stmt->bindParam(':file_path', $file_path);
                            
                            if ($insert_stmt->execute()) {
                                $success_message = "Document uploaded successfully!";
                                
                                // Create notification for document upload
                                $doc_type_map = [
                                    'resume' => 'Resume',
                                    'certificate' => 'Certificate',
                                    'project' => 'Project',
                                    'other' => 'Document'
                                ];
                                $doc_type = $doc_type_map[$item_type] ?? 'Document';
                                createGraduateNotification($conn, $graduate_id, 'portfolio_update', "{$doc_type} '{$item_title}' uploaded successfully");
                                
                                // Refresh portfolio items
                                $portfolio_stmt->execute();
                                $portfolio_items = $portfolio_stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                // Update counts
                                if ($item_type === 'resume') $resume_count++;
                                if ($item_type === 'certificate') $certificate_count++;
                                if ($item_type === 'project') $project_count++;
                                
                                // Reset form
                                $_POST = array();
                            } else {
                                $error_message = "Error saving document to database.";
                            }
                        } else {
                            $error_message = "Error uploading file. Please try again.";
                        }
                    } else {
                        $error_message = "File size too large. Maximum size is 5MB.";
                    }
                } else {
                    $error_message = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
                }
            } else {
                $error_message = "Please select a file to upload.";
            }
        }
    }
    
    // Handle skill addition - MODIFIED FOR MULTIPLE SKILLS
    if (isset($_POST['add_skills'])) {
        $selected_skills = $_POST['selected_skills'] ?? [];
        $skill_level = $_POST['skill_level'] ?? '';
        
        // Validate inputs
        if (empty($selected_skills)) {
            $error_message = "Please select at least one skill to add.";
        } elseif (empty($skill_level)) {
            $error_message = "Please select a skill level.";
        } else {
            $added_count = 0;
            $error_count = 0;
            
            foreach ($selected_skills as $skill_id) {
                // Check if skill already exists for this user
                $check_stmt = $conn->prepare("
                    SELECT * FROM graduate_skills 
                    WHERE grad_usr_id = :user_id AND skill_id = :skill_id
                ");
                $check_stmt->bindParam(':user_id', $graduate_id);
                $check_stmt->bindParam(':skill_id', $skill_id);
                $check_stmt->execute();
                
                if ($check_stmt->rowCount() > 0) {
                    $error_count++;
                } else {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO graduate_skills (grad_usr_id, skill_id, skill_level) 
                        VALUES (:user_id, :skill_id, :skill_level)
                    ");
                    $insert_stmt->bindParam(':user_id', $graduate_id);
                    $insert_stmt->bindParam(':skill_id', $skill_id);
                    $insert_stmt->bindParam(':skill_level', $skill_level);
                    
                    if ($insert_stmt->execute()) {
                        $added_count++;
                    } else {
                        $error_count++;
                    }
                }
            }
            
            if ($added_count > 0) {
                $success_message = "Successfully added $added_count skill(s)!";
                if ($error_count > 0) {
                    $success_message .= " $error_count skill(s) were already added or encountered errors.";
                }
                
                // Create notification for skills addition
                createGraduateNotification($conn, $graduate_id, 'skill_update', "Added {$added_count} new skill(s) to your portfolio");
                
                // Refresh skills
                $skills_stmt->execute();
                $skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
                $skill_count = count($skills);
                
                // Refresh available skills
                $all_skills_stmt->execute();
                $all_skills = $all_skills_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Reset form
                $_POST = array();
            } else {
                $error_message = "No skills were added. They may already exist in your portfolio.";
            }
        }
    }
    
    // Handle custom skill addition
    if (isset($_POST['add_custom_skill'])) {
        $custom_skill_name = trim($_POST['custom_skill_name'] ?? '');
        $custom_skill_category = $_POST['custom_skill_category'] ?? '';
        $custom_skill_level = $_POST['custom_skill_level'] ?? '';
        
        // Validate inputs
        if (empty($custom_skill_name)) {
            $error_message = "Please enter a skill name.";
        } elseif (empty($custom_skill_category)) {
            $error_message = "Please select a skill category.";
        } elseif (empty($custom_skill_level)) {
            $error_message = "Please select a skill level.";
        } else {
            try {
                // Check if skill already exists in database
                $check_skill_stmt = $conn->prepare("SELECT skill_id FROM skills WHERE skill_name = :skill_name");
                $check_skill_stmt->bindParam(':skill_name', $custom_skill_name);
                $check_skill_stmt->execute();
                
                if ($check_skill_stmt->rowCount() > 0) {
                    $skill = $check_skill_stmt->fetch(PDO::FETCH_ASSOC);
                    $skill_id = $skill['skill_id'];
                } else {
                    // Insert new skill into skills table
                    $insert_skill_stmt = $conn->prepare("
                        INSERT INTO skills (skill_name, skill_category) 
                        VALUES (:skill_name, :skill_category)
                    ");
                    $insert_skill_stmt->bindParam(':skill_name', $custom_skill_name);
                    $insert_skill_stmt->bindParam(':skill_category', $custom_skill_category);
                    $insert_skill_stmt->execute();
                    $skill_id = $conn->lastInsertId();
                }
                
                // Check if user already has this skill
                $check_user_skill_stmt = $conn->prepare("
                    SELECT * FROM graduate_skills 
                    WHERE grad_usr_id = :user_id AND skill_id = :skill_id
                ");
                $check_user_skill_stmt->bindParam(':user_id', $graduate_id);
                $check_user_skill_stmt->bindParam(':skill_id', $skill_id);
                $check_user_skill_stmt->execute();
                
                if ($check_user_skill_stmt->rowCount() > 0) {
                    $error_message = "You already have this skill in your portfolio.";
                } else {
                    // Add skill to graduate
                    $insert_graduate_skill_stmt = $conn->prepare("
                        INSERT INTO graduate_skills (grad_usr_id, skill_id, skill_level) 
                        VALUES (:user_id, :skill_id, :skill_level)
                    ");
                    $insert_graduate_skill_stmt->bindParam(':user_id', $graduate_id);
                    $insert_graduate_skill_stmt->bindParam(':skill_id', $skill_id);
                    $insert_graduate_skill_stmt->bindParam(':skill_level', $custom_skill_level);
                    
                    if ($insert_graduate_skill_stmt->execute()) {
                        $success_message = "Custom skill added successfully!";
                        
                        // Create notification for custom skill
                        createGraduateNotification($conn, $graduate_id, 'skill_update', "Added custom skill '{$custom_skill_name}' to your portfolio");
                        
                        // Refresh skills
                        $skills_stmt->execute();
                        $skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $skill_count = count($skills);
                        
                        // Refresh available skills
                        $all_skills_stmt->execute();
                        $all_skills = $all_skills_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Reset form
                        $_POST = array();
                    } else {
                        $error_message = "Error adding custom skill to database.";
                    }
                }
            } catch (PDOException $e) {
                $error_message = "Error adding custom skill: " . $e->getMessage();
            }
        }
    }
    
    // Handle document deletion
    if (isset($_GET['delete_document'])) {
        $document_id = $_GET['delete_document'];
        
        // Verify ownership and get file path
        $file_stmt = $conn->prepare("SELECT port_item_file, port_item_type, port_item_title FROM portfolio_items WHERE port_id = :id AND port_usr_id = :user_id");
        $file_stmt->bindParam(':id', $document_id);
        $file_stmt->bindParam(':user_id', $graduate_id);
        $file_stmt->execute();
        $document = $file_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            $file_path = $document['port_item_file'] ?? '';
            $item_type = $document['port_item_type'] ?? '';
            $item_title = $document['port_item_title'] ?? '';
            
            // Delete from database
            $delete_stmt = $conn->prepare("DELETE FROM portfolio_items WHERE port_id = :id AND port_usr_id = :user_id");
            $delete_stmt->bindParam(':id', $document_id);
            $delete_stmt->bindParam(':user_id', $graduate_id);
            
            if ($delete_stmt->execute()) {
                // Delete the actual file
                if ($file_path && file_exists($file_path)) {
                    unlink($file_path);
                }
                $success_message = "Document deleted successfully!";
                
                // Create notification for document deletion
                $doc_type_map = [
                    'resume' => 'Resume',
                    'certificate' => 'Certificate',
                    'project' => 'Project',
                    'other' => 'Document'
                ];
                $doc_type = $doc_type_map[$item_type] ?? 'Document';
                createGraduateNotification($conn, $graduate_id, 'portfolio_update', "{$doc_type} '{$item_title}' removed from portfolio");
                
                // Refresh portfolio items
                $portfolio_stmt->execute();
                $portfolio_items = $portfolio_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Update counts
                if ($item_type === 'resume') $resume_count = max(0, $resume_count - 1);
                if ($item_type === 'certificate') $certificate_count = max(0, $certificate_count - 1);
                if ($item_type === 'project') $project_count = max(0, $project_count - 1);
            } else {
                $error_message = "Error deleting document from database.";
            }
        } else {
            $error_message = "Document not found or you don't have permission to delete it.";
        }
    }
    
    // Handle skill deletion - MODIFIED TO USE SYSTEM CONFIRMATION
    if (isset($_GET['delete_skill'])) {
        $skill_id = $_GET['delete_skill'];
        
        // Verify ownership and get skill name
        $check_stmt = $conn->prepare("
            SELECT s.skill_name 
            FROM graduate_skills gs
            JOIN skills s ON gs.skill_id = s.skill_id
            WHERE gs.grad_usr_id = :user_id AND gs.skill_id = :skill_id
        ");
        $check_stmt->bindParam(':user_id', $graduate_id);
        $check_stmt->bindParam(':skill_id', $skill_id);
        $check_stmt->execute();
        $skill = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($skill) {
            $skill_name = $skill['skill_name'];
            
            $delete_stmt = $conn->prepare("
                DELETE FROM graduate_skills 
                WHERE grad_usr_id = :user_id AND skill_id = :skill_id
            ");
            $delete_stmt->bindParam(':user_id', $graduate_id);
            $delete_stmt->bindParam(':skill_id', $skill_id);
            
            if ($delete_stmt->execute()) {
                $success_message = "Skill '{$skill_name}' removed successfully!";
                
                // Create notification for skill removal
                createGraduateNotification($conn, $graduate_id, 'skill_update', "Skill '{$skill_name}' removed from portfolio");
                
                // Refresh skills
                $skills_stmt->execute();
                $skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);
                $skill_count = count($skills);
                
                // Refresh available skills
                $all_skills_stmt->execute();
                $all_skills = $all_skills_stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $error_message = "Error removing skill from database.";
            }
        } else {
            $error_message = "Skill not found or you don't have permission to remove it.";
        }
    }
    
    // Handle personal profile update (ENHANCED WITH FULL NAME AND PHONE)
    if (isset($_POST['update_personal_profile'])) {
        $last_name = trim($_POST['last_name'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $country_code = trim($_POST['country_code'] ?? '+63');
        $phone = trim($_POST['mobile_number'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $birthdate = $_POST['birthdate'] ?? '';
        
        // Combine names into full name
        $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : "") . "$last_name");
        
        // Enhanced phone number validation
        $phone = preg_replace('/[^0-9]/', '', $phone); // Remove any non-numeric characters
        
        // Validate phone number length - CHANGED TO 11 DIGITS
        if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 11)) {
            $error_message = "Phone number must be between 10 and 11 digits.";
        }
        
        // Combine country code and phone number
        $full_phone = $country_code . $phone;
        
        // Convert empty strings to NULL for database fields that accept NULL
        $birthdate = empty($birthdate) ? null : $birthdate;
        $gender = empty($gender) ? null : $gender;
        
        if (empty($error_message)) {
            try {
                // Update user table with full name and enhanced phone
                $user_update_stmt = $conn->prepare("
                    UPDATE users 
                    SET usr_name = :full_name,
                        usr_phone = :phone, 
                        usr_gender = :gender, 
                        usr_birthdate = :birthdate 
                    WHERE usr_id = :user_id
                ");
                $user_update_stmt->bindParam(':full_name', $full_name);
                $user_update_stmt->bindParam(':phone', $full_phone);
                $user_update_stmt->bindParam(':gender', $gender);
                $user_update_stmt->bindParam(':birthdate', $birthdate);
                $user_update_stmt->bindParam(':user_id', $graduate_id);
                
                if ($user_update_stmt->execute()) {
                    $success_message = "Personal information updated successfully!";
                    
                    // Create notification for profile update
                    createGraduateNotification($conn, $graduate_id, 'profile_update', "Personal information updated");
                    
                    // Refresh graduate data
                    $stmt->execute();
                    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
                    $graduate = array_merge([
                        'usr_name' => '',
                        'usr_email' => '',
                        'usr_phone' => '',
                        'usr_profile_photo' => '',
                        'usr_gender' => '',
                        'usr_birthdate' => '',
                        'usr_created_at' => '',
                        'grad_school_id' => '',
                        'grad_degree' => '',
                        'grad_year_graduated' => '',
                        'grad_job_preference' => '',
                        'grad_summary' => ''
                    ], $graduate);
                    
                    // Update name components for form display
                    $name_parts = explode(' ', $graduate['usr_name']);
                    $name_count = count($name_parts);
                    
                    if ($name_count >= 1) {
                        $last_name = $name_parts[$name_count - 1];
                    }
                    if ($name_count >= 2) {
                        $first_name = $name_parts[0];
                    }
                    if ($name_count >= 3) {
                        $middle_name = implode(' ', array_slice($name_parts, 1, $name_count - 2));
                    }
                    
                    // Update phone digits for form display
                    $phone_digits = '';
                    if (!empty($graduate['usr_phone'])) {
                        $phone_digits = preg_replace('/[^0-9]/', '', $graduate['usr_phone']);
                        if (strpos($graduate['usr_phone'], '+63') === 0) {
                            $phone_digits = substr($phone_digits, 2);
                        }
                    }
                } else {
                    $error_message = "Error updating personal information.";
                }
                    
            } catch (PDOException $e) {
                $error_message = "Error updating personal information: " . $e->getMessage();
            }
        }
    }
    
    // Handle academic profile update - MODIFIED FOR ENHANCED JOB PREFERENCES
    if (isset($_POST['update_academic_profile'])) {
        $degree = trim($_POST['degree'] ?? '');
        $year_graduated = $_POST['year_graduated'] ?? '';
        $summary = trim($_POST['summary'] ?? '');
        
        // Get job preferences (can be multiple)
        $job_preferences = isset($_POST['job_preferences']) ? $_POST['job_preferences'] : [];
        $custom_job_preferences = isset($_POST['custom_job_preferences']) ? $_POST['custom_job_preferences'] : [];
        
        // Process job preferences
        $all_job_preferences = [];
        
        // Add selected job preferences
        foreach ($job_preferences as $job_pref) {
            if (!empty($job_pref)) {
                $all_job_preferences[] = htmlspecialchars($job_pref);
            }
        }
        
        // Process custom job preferences
        foreach ($custom_job_preferences as $custom_pref) {
            $custom_pref = trim($custom_pref);
            if (!empty($custom_pref)) {
                $all_job_preferences[] = $custom_pref;
                
                // Check if this custom preference already exists in job_positions table
                $check_existing_stmt = $conn->prepare("
                    SELECT position_id FROM job_positions 
                    WHERE position_name = :pref_name 
                    AND is_active = TRUE
                ");
                $check_existing_stmt->bindParam(':pref_name', $custom_pref);
                $check_existing_stmt->execute();
                
                if ($check_existing_stmt->rowCount() == 0) {
                    // Insert new custom job position into job_positions table
                    $category_stmt = $conn->prepare("
                        SELECT category_id FROM job_categories 
                        WHERE category_name LIKE '%Other%' OR category_name LIKE '%Custom%' 
                        AND is_active = TRUE 
                        LIMIT 1
                    ");
                    $category_stmt->execute();
                    $category = $category_stmt->fetch();
                    
                    $category_id = $category ? $category['category_id'] : null;
                    
                    // If no "Other" category exists, create one
                    if (!$category_id) {
                        $insert_category_stmt = $conn->prepare("
                            INSERT INTO job_categories (category_name, is_active, created_at) 
                            VALUES ('Other/Custom', TRUE, NOW())
                        ");
                        $insert_category_stmt->execute();
                        $category_id = $conn->lastInsertId();
                    }
                    
                    // Insert the custom job position
                    $insert_position_stmt = $conn->prepare("
                        INSERT INTO job_positions (
                            category_id, 
                            position_name, 
                            is_custom,
                            is_active, 
                            created_at
                        ) VALUES (
                            :category_id, 
                            :position_name, 
                            TRUE,
                            TRUE, 
                            NOW()
                        )
                    ");
                    $insert_position_stmt->bindParam(':category_id', $category_id, PDO::PARAM_INT);
                    $insert_position_stmt->bindParam(':position_name', $custom_pref);
                    $insert_position_stmt->execute();
                }
            }
        }
        
        // Store job preferences as JSON array
        $job_preferences_json = json_encode($all_job_preferences, JSON_UNESCAPED_SLASHES);
        
        // Validate inputs
        if (empty($degree)) {
            $error_message = "Degree field is required.";
        } elseif (empty($year_graduated)) {
            $error_message = "Year graduated is required.";
        } elseif (empty($job_preferences) && empty($custom_job_preferences)) {
            $error_message = "Please select at least one job preference.";
        } else {
            try {
                // Update graduate table with enhanced job preferences
                $grad_update_stmt = $conn->prepare("
                    UPDATE graduates 
                    SET grad_degree = :degree, grad_year_graduated = :year_graduated, 
                        grad_job_preference = :job_preference,
                        grad_summary = :summary
                    WHERE grad_usr_id = :user_id
                ");
                $grad_update_stmt->bindParam(':degree', $degree);
                $grad_update_stmt->bindParam(':year_graduated', $year_graduated);
                $grad_update_stmt->bindParam(':job_preference', $job_preferences_json);
                $grad_update_stmt->bindParam(':summary', $summary);
                $grad_update_stmt->bindParam(':user_id', $graduate_id);
                
                if ($grad_update_stmt->execute()) {
                    $success_message = "Academic background updated successfully!";
                    
                    // Create notification for academic update
                    createGraduateNotification($conn, $graduate_id, 'profile_update', "Academic background updated");
                    
                    // Refresh graduate data
                    $stmt->execute();
                    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
                    $graduate = array_merge([
                        'usr_name' => '',
                        'usr_email' => '',
                        'usr_phone' => '',
                        'usr_profile_photo' => '',
                        'usr_gender' => '',
                        'usr_birthdate' => '',
                        'usr_created_at' => '',
                        'grad_school_id' => '',
                        'grad_degree' => '',
                        'grad_year_graduated' => '',
                        'grad_job_preference' => '',
                        'grad_summary' => ''
                    ], $graduate);
                    
                    // Update selected job preferences
                    $selected_job_preferences = $all_job_preferences;
                } else {
                    $error_message = "Error updating academic background.";
                }
                
            } catch (PDOException $e) {
                $error_message = "Error updating academic background: " . $e->getMessage();
            }
        }
    }
    
    // Handle profile picture upload
    if (isset($_POST['update_profile_picture'])) {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profile_pictures/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate safe filename
            $file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            $file_name = 'profile_' . $graduate_id . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            // Check file type
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_types)) {
                // Check file size (max 2MB)
                if ($_FILES['profile_picture']['size'] <= 2 * 1024 * 1024) {
                    // Resize and save image
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
                        // Update database
                        $update_stmt = $conn->prepare("
                            UPDATE users SET usr_profile_photo = :photo_path WHERE usr_id = :user_id
                        ");
                        $update_stmt->bindParam(':photo_path', $file_path);
                        $update_stmt->bindParam(':user_id', $graduate_id);
                        
                        if ($update_stmt->execute()) {
                            $success_message = "Profile picture updated successfully!";
                            
                            // Create notification for profile picture update
                            createGraduateNotification($conn, $graduate_id, 'profile_update', "Profile picture updated");
                            
                            // Refresh graduate data
                            $stmt->execute();
                            $graduate = $stmt->fetch(PDO::FETCH_ASSOC);
                            $graduate = array_merge([
                                'usr_name' => '',
                                'usr_email' => '',
                                'usr_phone' => '',
                                'usr_profile_photo' => '',
                                'usr_gender' => '',
                                'usr_birthdate' => '',
                                'usr_created_at' => '',
                                'grad_school_id' => '',
                                'grad_degree' => '',
                                'grad_year_graduated' => '',
                                'grad_job_preference' => '',
                                'grad_summary' => ''
                            ], $graduate);
                        } else {
                            $error_message = "Error updating profile picture in database.";
                        }
                    } else {
                        $error_message = "Error uploading file. Please try again.";
                    }
                } else {
                    $error_message = "File size too large. Maximum size is 2MB.";
                }
            } else {
                $error_message = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
            }
        } else {
            $error_message = "Please select a profile picture to upload.";
        }
    }
    
} catch (PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed. Please try again later.");
}

/**
 * Generate default notifications based on user data and activities
 */
function generateDefaultNotifications($conn, $graduate_id, $graduate, $portfolio_stats) {
    $default_notifications = [];
    
    // Welcome notification
    $default_notifications[] = [
        'notif_message' => 'Welcome to your Digital Portfolio! Start building your profile to showcase your skills.',
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
    
    if (($portfolio_stats['certificate_count'] ?? 0) == 0) {
        $default_notifications[] = [
            'notif_message' => 'Add certificates to showcase your qualifications and achievements.',
            'notif_type' => 'portfolio_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
        ];
    }
    
    // Profile completeness notifications
    if (empty($graduate['usr_phone']) || empty($graduate['usr_gender']) || empty($graduate['usr_birthdate'])) {
        $default_notifications[] = [
            'notif_message' => 'Complete your personal information to improve your profile visibility.',
            'notif_type' => 'profile_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))
        ];
    }
    
    if (empty($graduate['grad_degree']) || empty($graduate['grad_year_graduated']) || empty($graduate['grad_job_preference'])) {
        $default_notifications[] = [
            'notif_message' => 'Complete your academic background for better job matching.',
            'notif_type' => 'profile_reminder',
            'notif_is_read' => 0,
            'notif_created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))
        ];
    }
    
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
        header("Location: graduate_portfolio.php");
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
        case 'portfolio_update':
            return 'fas fa-file-contract';
        case 'skill_update':
            return 'fas fa-tools';
        case 'profile_update':
            return 'fas fa-user-edit';
        case 'portfolio_reminder':
            return 'fas fa-file-alt';
        case 'skill_reminder':
            return 'fas fa-code';
        case 'profile_reminder':
            return 'fas fa-user-circle';
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
    
    if (strpos($type, 'update') !== false) {
        return 'medium';
    } elseif (strpos($type, 'reminder') !== false) {
        return 'low';
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
    
    if (strpos($type, 'portfolio') !== false || strpos($message, 'resume') !== false || strpos($message, 'certificate') !== false || strpos($message, 'document') !== false) {
        return '#documentsForm';
    } elseif (strpos($type, 'skill') !== false || strpos($message, 'skill') !== false) {
        return '#skillsForm';
    } elseif (strpos($type, 'profile') !== false || strpos($message, 'profile') !== false || strpos($message, 'personal') !== false || strpos($message, 'academic') !== false) {
        return '#personalForm';
    } else {
        return 'graduate_portfolio.php';
    }
}

// Get unique categories for custom skill form
$categories_stmt = $conn->prepare("SELECT DISTINCT skill_category FROM skills ORDER BY skill_category");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Digital Portfolio</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --frame-orange: #ffa700;
            --panel-orange: #f7a100;
            --maroon: #6e0303;
            --black: #000;
            --green: #1f7a11;
            --blue: #0044ff;
            --purple: #6a0dad;
            --red: #d32f2f;
            --light-color: #f9f9f9;
            --dark-color: #333;
            --sidebar-bg: var(--maroon);
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
            background-color: #f5f5f5;
            min-height: 100vh;
            color: var(--black);
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
            border-left: 4px solid var(--panel-orange);
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar-menu a.active::before {
            content: '';
            position: absolute;
            right: 15px;
            width: 8px;
            height: 8px;
            background-color: var(--panel-orange);
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
            background-color: var(--light-color);
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
            color: var(--maroon);
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
            border: 2px solid var(--maroon);
        }
        
        .profile-name {
            font-weight: 600;
            color: var(--maroon);
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
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
        }
        
        .mark-all-read {
            background: none;
            border: none;
            color: var(--panel-orange);
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
            color: var(--maroon);
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
            color: var(--maroon);
            font-size: 1.2rem;
            min-width: 24px;
            margin-top: 2px;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .unread {
            background-color: #fff5e6;
            border-left: 3px solid var(--panel-orange);
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
            background-color: var(--panel-orange);
        }
        
        .priority-low {
            background-color: var(--green);
        }
        
        /* Enhanced Welcome Message */
        .welcome-message {
            margin-bottom: 25px;
            padding: 20px 25px;
            background: linear-gradient(135deg, var(--maroon), #8a0404);
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
            color: var(--maroon);
            font-weight: 700;
            position: relative;
            padding-bottom: 10px;
        }
        
        /* Portfolio Stats */
        .portfolio-stats {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .stat-icon.resume {
            background: linear-gradient(45deg, var(--maroon), #8a0404);
        }
        
        .stat-icon.certificate {
            background: linear-gradient(45deg, var(--green), #0f5e0a);
        }
        
        .stat-icon.project {
            background: linear-gradient(45deg, var(--blue), #0033cc);
        }
        
        .stat-icon.skill {
            background: linear-gradient(45deg, var(--purple), #4b0082);
        }
        
        .stat-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .stat-number {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--maroon);
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Portfolio Sections */
        .portfolio-section {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            position: relative;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .section-title {
            font-size: 1.2rem;
            color: var(--maroon);
            font-weight: 600;
        }
        
        .section-badge {
            background: #f0f0f0;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            color: #666;
        }
        
        .toggle-form {
            background: none;
            border: none;
            color: var(--maroon);
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .toggle-form i {
            font-size: 0.8rem;
        }
        
        /* Profile Completeness */
        .completeness-section {
            background: linear-gradient(45deg, var(--maroon), #8a0404);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .completeness-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .completeness-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .completeness-percentage {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .progress-bar {
            height: 10px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        
        .progress {
            height: 100%;
            background: white;
            border-radius: 5px;
            transition: width 0.5s ease;
        }
        
        .completeness-tips {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        /* Enhanced Modal Styles */
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
        
        .modal {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal {
            transform: translateY(0);
        }
        
        .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--maroon), #8a0404);
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
        
        /* Document Viewer Modal */
        .document-viewer {
            width: 100%;
            height: 600px;
            border: 1px solid #eee;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .document-viewer iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .document-viewer img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .document-info {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .document-info h4 {
            color: var(--maroon);
            margin-bottom: 10px;
        }
        
        .document-info p {
            margin-bottom: 8px;
            color: #555;
        }
        
        /* Enhanced Form Container Styles */
        .form-container {
            display: none;
            margin-top: 15px;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            border-left: 4px solid var(--maroon);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }
        
        .form-container::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(110, 3, 3, 0.05);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .form-container.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 0.95rem;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--maroon);
            outline: none;
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
            transform: translateY(-2px);
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #e9ecef, #dee2e6);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--red), #b71c1c);
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #b71c1c, #8b0000);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--maroon);
            color: var(--maroon);
        }
        
        .btn-outline:hover {
            background-color: var(--maroon);
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--green), #0f5e0a);
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #0f5e0a, #0a4a06);
            transform: translateY(-2px);
        }
        
        /* Info Display */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--maroon);
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-label i {
            color: var(--maroon);
            width: 16px;
        }
        
        .info-value {
            color: #333;
            font-size: 1rem;
            font-weight: 500;
        }
        
        /* Profile Picture */
        .profile-picture-section {
            text-align: center;
            margin-bottom: 25px;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
        }
        
        .profile-picture-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--maroon), var(--panel-orange));
        }
        
        .profile-picture {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        
        .profile-picture:hover {
            transform: scale(1.05);
        }
        
        .profile-picture-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        /* Skills Display */
        .skills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 15px;
        }
        
        .skill-tag {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 10px 18px;
            border-radius: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border: 1px solid #e9ecef;
        }
        
        .skill-tag:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.12);
            border-color: var(--maroon);
        }
        
        .skill-tag .skill-level {
            font-size: 0.75rem;
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-weight: 600;
        }
        
        .skill-tag .skill-level.beginner {
            background: linear-gradient(135deg, var(--blue), #0033cc);
        }
        
        .skill-tag .skill-level.intermediate {
            background: linear-gradient(135deg, var(--green), #0f5e0a);
        }
        
        .skill-tag .skill-level.advanced {
            background: linear-gradient(135deg, var(--purple), #4b0082);
        }
        
        .skill-tag .skill-level.expert {
            background: linear-gradient(135deg, var(--maroon), #8a0404);
        }
        
        .delete-skill {
            color: #dc3545;
            cursor: pointer;
            font-size: 0.8rem;
            opacity: 0.7;
            transition: opacity 0.2s;
            background: none;
            border: none;
            padding: 4px;
            border-radius: 50%;
        }
        
        .delete-skill:hover {
            opacity: 1;
            background: rgba(220, 53, 69, 0.1);
        }
        
        /* Enhanced Empty State for Skills - CENTERED LIKE DOCUMENTS */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            width: 100%;
        }
        
        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 20px;
            opacity: 0.5;
            color: var(--maroon);
        }
        
        .empty-state h3 {
            margin-bottom: 12px;
            color: #495057;
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .empty-state p {
            font-size: 1rem;
            max-width: 400px;
            margin: 0 auto 25px;
            line-height: 1.5;
        }
        
        /* ENHANCED: Modified button styles to match "Browse Available Jobs" button from previous code */
        .browse-jobs-btn {
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(110, 3, 3, 0.2);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .browse-jobs-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.7s;
            z-index: -1;
        }
        
        .browse-jobs-btn:hover {
            background: linear-gradient(135deg, #8a0404, var(--maroon));
            transform: translateY(-3px);
            color: white;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(110, 3, 3, 0.3);
        }
        
        .browse-jobs-btn:hover::before {
            left: 100%;
        }
        
        .browse-jobs-btn:active {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(110, 3, 3, 0.3);
        }
        
        .browse-jobs-btn i {
            font-size: 1.1rem;
        }
        
        /* Documents Table */
        .documents-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .documents-table th, .documents-table td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .documents-table th {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .documents-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .document-type {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .document-type i {
            color: var(--maroon);
            font-size: 1.1rem;
        }
        
        .document-actions {
            display: flex;
            gap: 15px;
        }
        
        .document-action {
            color: var(--maroon);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            padding: 6px 12px;
            border-radius: 6px;
            background: #f8f9fa;
        }
        
        .document-action:hover {
            background: var(--maroon);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
        }
        
        /* Alert Messages */
        .alert {
            padding: 18px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            border-left: 4px solid transparent;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left-color: var(--green);
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left-color: var(--red);
        }
        
        .alert i {
            font-size: 1.3rem;
        }
        
        .alert-close {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
            padding: 4px;
            border-radius: 4px;
        }
        
        .alert-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.1);
        }
        
        /* Enhanced skills styling */
        .skills-form-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .skill-category-group {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .skill-category-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .skill-category-title {
            font-weight: 600;
            color: var(--maroon);
            margin-bottom: 15px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
        }
        
        .skill-category-title small {
            font-weight: normal;
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .skill-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 10px;
            margin-left: 10px;
        }
        
        .skill-option {
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .skill-option:hover {
            background: #e9ecef;
            border-color: #ddd;
            transform: translateY(-2px);
        }
        
        .skill-option.selected {
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
            border-color: var(--maroon);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(110, 3, 3, 0.2);
        }
        
        .skill-option input[type="checkbox"] {
            margin: 0;
        }
        
        /* Search input styling */
        #skillSearch {
            margin-bottom: 20px;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            width: 100%;
            font-size: 1rem;
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }
        
        #skillSearch:focus {
            border-color: var(--maroon);
            outline: none;
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
        }
        
        /* Custom skill form */
        .custom-skill-form {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 12px;
            margin-top: 25px;
            border-left: 4px solid var(--green);
            position: relative;
            overflow: hidden;
        }
        
        .custom-skill-form::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: rgba(31, 122, 17, 0.1);
            border-radius: 50%;
            transform: translate(20%, -20%);
        }
        
        .custom-skill-title {
            font-size: 1.2rem;
            color: var(--green);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        /* Selected skills summary */
        .selected-skills-summary {
            background: linear-gradient(135deg, #e7f3ff, #d4e7ff);
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .selected-skills-title {
            font-weight: 600;
            color: var(--blue);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
        }
        
        .selected-skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .selected-skill-badge {
            background: white;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            border: 1px solid #b3d9ff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        /* Scrollbar styling */
        .skills-form-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .skills-form-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .skills-form-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .skills-form-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Tabs for skills */
        .skills-tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 25px;
            background: white;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
        }
        
        .skill-tab {
            padding: 15px 25px;
            cursor: pointer;
            border: none;
            background: #f8f9fa;
            font-weight: 500;
            transition: all 0.3s;
            flex: 1;
            text-align: center;
            color: #6c757d;
        }
        
        .skill-tab.active {
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
            box-shadow: 0 2px 8px rgba(110, 3, 3, 0.2);
        }
        
        .skill-tab-content {
            display: none;
        }
        
        .skill-tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        /* Custom file input */
        .file-input-container {
            position: relative;
        }
        
        .file-input-container input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-input-label {
            display: block;
            padding: 25px;
            border: 2px dashed #ddd;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
        }
        
        .file-input-label:hover {
            border-color: var(--maroon);
            background: #f8f9fa;
            transform: translateY(-2px);
        }
        
        .file-input-label i {
            color: var(--maroon);
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        /* File upload info */
        .file-info {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #666;
            text-align: center;
        }
        
        /* Confirmation Modal Styles */
        .confirmation-modal {
            background-color: white;
            border-radius: 12px;
            width: 95%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(-50px);
            transition: transform 0.3s ease;
        }
        
        .confirmation-modal .modal-header {
            padding: 25px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--maroon), #8a0404);
            color: white;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        
        .confirmation-modal .modal-content {
            padding: 30px;
            text-align: center;
        }
        
        .confirmation-modal .modal-title {
            font-size: 1.5rem;
            color: white;
            font-weight: 600;
        }
        
        .confirmation-icon {
            font-size: 4rem;
            color: var(--maroon);
            margin-bottom: 20px;
        }
        
        .confirmation-message {
            font-size: 1.2rem;
            margin-bottom: 30px;
            color: #333;
        }
        
        .confirmation-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        /* Name Container Styles */
        .name-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        .phone-container {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 10px;
            width: 100%;
        }

        .country-code {
            width: 100%;
        }

        .phone-number {
            width: 100%;
        }
        
        /* ============================================
           ENHANCED JOB PREFERENCES STYLES - FROM graduate_profile.php
           ============================================ */
        .job-preferences-container {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            background: #fafafa;
            margin-bottom: 20px;
        }
        
        .job-preferences-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .job-preferences-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--maroon);
        }
        
        .selection-info {
            font-size: 0.85rem;
            color: #666;
            background: #e8f4ff;
            padding: 5px 10px;
            border-radius: 4px;
        }
        
        .job-categories-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px;
            background: white;
            margin-bottom: 15px;
        }
        
        .job-category {
            margin-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 10px;
        }
        
        .job-category:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .category-title {
            font-weight: 600;
            color: #444;
            margin-bottom: 8px;
            padding: 5px 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .job-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
            padding-left: 10px;
        }
        
        .job-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .job-option:hover {
            background: #f0f8ff;
        }
        
        .job-option.selected {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
        }
        
        .job-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .job-option label {
            cursor: pointer;
            flex: 1;
            font-size: 0.9rem;
        }
        
        .selected-jobs-container {
            margin-top: 20px;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
        }
        
        .selected-jobs-title {
            font-weight: 600;
            color: #444;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .selected-jobs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 40px;
            padding: 10px;
            border: 1px dashed #ddd;
            border-radius: 6px;
            background: white;
        }
        
        .job-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: var(--maroon);
            color: white;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .job-tag i {
            font-size: 0.75rem;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .job-tag i:hover {
            opacity: 1;
        }
        
        .custom-job-input-container {
            margin-top: 15px;
            border-top: 1px solid #e0e0e0;
            padding-top: 15px;
        }
        
        .custom-job-title {
            font-weight: 600;
            color: #444;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .custom-job-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .custom-job-input {
            flex: 1;
        }
        
        .add-custom-job-btn {
            background: var(--green);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 10px 15px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .add-custom-job-btn:hover {
            background: #1a6d0e;
        }
        
        .add-custom-job-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .custom-job-hint {
            font-size: 0.8rem;
            color: #666;
            font-style: italic;
        }
        
        .no-selection-message {
            color: #999;
            font-style: italic;
            padding: 10px;
            text-align: center;
            width: 100%;
        }
        
        /* Job preference error message */
        #job_preferences_error {
            color: var(--red);
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }
        
        #job_preferences_error.show {
            display: block;
        }
        
        /* Responsive Design */
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
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .portfolio-stats {
                width: 100%;
                justify-content: space-between;
            }
            
            .stat-card {
                flex: 1;
                min-width: 120px;
            }
            
            .form-grid, .info-grid {
                grid-template-columns: 1fr;
            }
            
            .documents-table {
                display: block;
                overflow-x: auto;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .skill-options {
                grid-template-columns: 1fr;
            }
            
            .skills-tabs {
                flex-direction: column;
            }
            
            .modal {
                width: 95%;
                margin: 20px;
            }
            
            .modal-content {
                padding: 20px;
            }
            
            .document-viewer {
                height: 400px;
            }
            
            .confirmation-actions {
                flex-direction: column;
            }
            
            .name-container {
                grid-template-columns: 1fr;
            }
            
            .phone-container {
                flex-direction: column;
            }
            
            .country-code {
                width: 100%;
            }
            
            .job-options {
                grid-template-columns: 1fr;
            }
            
            .custom-job-input-group {
                flex-direction: column;
            }
            
            .add-custom-job-btn {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .document-viewer {
                height: 300px;
            }
            
            .job-preferences-container {
                padding: 15px;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Form validation */
        .error {
            color: var(--red);
            font-size: 0.8rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        input.error, select.error, textarea.error {
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
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
                    <a href="graduate_dashboard.php">
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
                    <a href="graduate_portfolio.php" class="active">
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
            <p>Build your digital portfolio to showcase your skills and achievements to employers. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new portfolio notifications." : "" ?></p>
        </div>

        <!-- Page Content -->
        <div class="page-header">
            <h1 class="page-title">Manage Digital Portfolio</h1>
            <div class="portfolio-stats">
                <div class="stat-card">
                    <div class="stat-icon resume">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $resume_count ?></div>
                        <div class="stat-label">Resumes</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon certificate">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $certificate_count ?></div>
                        <div class="stat-label">Certificates</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon project">
                        <i class="fas fa-project-diagram"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $project_count ?></div>
                        <div class="stat-label">Projects</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon skill">
                        <i class="fas fa-code"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?= $skill_count ?></div>
                        <div class="stat-label">Skills</div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <?= $success_message ?>
            <button class="alert-close" onclick="closeAlert('successAlert')">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <?= $error_message ?>
            <button class="alert-close" onclick="closeAlert('errorAlert')">&times;</button>
        </div>
        <?php endif; ?>
        
        <!-- Profile Completeness Section -->
        <div class="completeness-section">
            <div class="completeness-header">
                <div class="completeness-title">Profile Completeness</div>
                <div class="completeness-percentage"><?= $completeness ?>%</div>
            </div>
            <div class="progress-bar">
                <div class="progress" style="width: <?= $completeness ?>%"></div>
            </div>
            <div class="completeness-tips">
                <?php if ($completeness < 100): ?>
                    <p>Complete your profile to increase your visibility to employers. 
                    <?php if ($resume_count == 0): ?>Upload a resume, <?php endif; ?>
                    <?php if ($skill_count < 3): ?>add more skills, <?php endif; ?>
                    <?php if ($certificate_count == 0): ?>add certificates, <?php endif; ?>
                    and fill in all your information.</p>
                <?php else: ?>
                    <p>Great job! Your profile is 100% complete and ready to impress employers.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Profile Picture Section -->
        <div class="portfolio-section">
            <div class="profile-picture-section">
                <img src="<?= !empty($graduate['usr_profile_photo']) ? htmlspecialchars($graduate['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($graduate['usr_name']) . '&background=random' ?>" 
                     alt="Profile Picture" class="profile-picture" id="profilePicture">
                <div class="profile-picture-actions">
                    <form method="POST" enctype="multipart/form-data" id="profilePictureForm">
                        <div class="file-input-container">
                            <label class="file-input-label">
                                <i class="fas fa-camera"></i> Change Photo
                            </label>
                            <input type="file" name="profile_picture" accept="image/*" onchange="document.getElementById('profilePictureForm').submit()">
                        </div>
                        <input type="hidden" name="update_profile_picture" value="1">
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Personal Info Section - ENHANCED WITH FULL NAME EDITING -->
        <div class="portfolio-section">
            <div class="section-header">
                <h2 class="section-title">Personal Information</h2>
                <button class="toggle-form" id="togglePersonalForm">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-user"></i> Full Name
                    </div>
                    <div class="info-value"><?= htmlspecialchars($graduate['usr_name']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-envelope"></i> Email
                    </div>
                    <div class="info-value"><?= htmlspecialchars($graduate['usr_email']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-phone"></i> Phone
                    </div>
                    <div class="info-value"><?= !empty($graduate['usr_phone']) ? htmlspecialchars($graduate['usr_phone']) : '<span style="color:#6c757d; font-style:italic;">Not provided</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-venus-mars"></i> Gender
                    </div>
                    <div class="info-value"><?= !empty($graduate['usr_gender']) ? htmlspecialchars($graduate['usr_gender']) : '<span style="color:#6c757d; font-style:italic;">Not provided</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-birthday-cake"></i> Birthdate
                    </div>
                    <div class="info-value"><?= !empty($graduate['usr_birthdate']) ? date('M j, Y', strtotime($graduate['usr_birthdate'])) : '<span style="color:#6c757d; font-style:italic;">Not provided</span>' ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-calendar-alt"></i> Member Since
                    </div>
                    <div class="info-value"><?= date('M j, Y', strtotime($graduate['usr_created_at'])) ?></div>
                </div>
            </div>
            
            <div class="form-container" id="personalForm">
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <div class="name-container">
                            <div>
                                <label class="form-label" for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-input" 
                                       value="<?= htmlspecialchars($last_name) ?>" required placeholder="Last Name">
                            </div>
                            <div>
                                <label class="form-label" for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-input" 
                                       value="<?= htmlspecialchars($first_name) ?>" required placeholder="First Name">
                            </div>
                            <div>
                                <label class="form-label" for="middle_name">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" class="form-input" 
                                       value="<?= htmlspecialchars($middle_name) ?>" placeholder="Middle Name">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="mobile_number">Mobile Number *</label>
                        <div class="phone-container">
                            <select id="country_code" name="country_code" class="form-select country-code">
                                <option value="+63" selected>+63 (PH)</option>
                                <option value="+1">+1 (US)</option>
                                <option value="+44">+44 (UK)</option>
                                <option value="+61">+61 (AU)</option>
                                <option value="+65">+65 (SG)</option>
                                <option value="+60">+60 (MY)</option>
                                <option value="+66">+66 (TH)</option>
                                <option value="+84">+84 (VN)</option>
                                <option value="+81">+81 (JP)</option>
                                <option value="+82">+82 (KR)</option>
                                <option value="+86">+86 (CN)</option>
                                <option value="+91">+91 (IN)</option>
                                <option value="+971">+971 (AE)</option>
                                <option value="+973">+973 (BH)</option>
                                <option value="+966">+966 (SA)</option>
                                <option value="+20">+20 (EG)</option>
                                <option value="+27">+27 (ZA)</option>
                            </select>
                            <input type="tel" id="mobile_number" name="mobile_number" class="form-input phone-number" 
                                value="<?= htmlspecialchars($phone_digits) ?>" 
                                required placeholder="9234567890" maxlength="11" pattern="[0-9]{10,11}" 
                                title="Please enter 10-11 digits only (numbers only)">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male" <?= ($graduate['usr_gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($graduate['usr_gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= ($graduate['usr_gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="birthdate">Birthdate</label>
                            <input type="date" id="birthdate" name="birthdate" value="<?= htmlspecialchars($graduate['usr_birthdate'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary cancel-form">Cancel</button>
                        <button type="submit" name="update_personal_profile" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Academic Background Section - WITH ENHANCED JOB PREFERENCES -->
        <div class="portfolio-section">
            <div class="section-header">
                <h2 class="section-title">Academic Background</h2>
                <button class="toggle-form" id="toggleAcademicForm">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-id-card"></i> Alumni ID
                    </div>
                    <div class="info-value"><?= htmlspecialchars($graduate['grad_school_id']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-graduation-cap"></i> Degree/Course
                    </div>
                    <div class="info-value"><?= htmlspecialchars($graduate['grad_degree']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-calendar-check"></i> Year Graduated
                    </div>
                    <div class="info-value"><?= htmlspecialchars($graduate['grad_year_graduated'] ?? '') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-briefcase"></i> Job Preference
                    </div>
                    <div class="info-value">
                        <?php if (!empty($selected_job_preferences)): ?>
                            <?php foreach ($selected_job_preferences as $pref): ?>
                                <span class="job-tag" style="margin: 2px; font-size: 0.8rem;"><?= htmlspecialchars($pref) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color:#6c757d; font-style:italic;">Not specified</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($graduate['grad_summary'])): ?>
            <div class="info-item" style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid var(--maroon);">
                <div class="info-label" style="font-size: 1.1rem;">
                    <i class="fas fa-file-alt"></i> Career Summary/Objective
                </div>
                <div class="info-value" style="margin-top: 10px; line-height: 1.6;"><?= nl2br(htmlspecialchars($graduate['grad_summary'])) ?></div>
            </div>
            <?php endif; ?>
            
            <div class="form-container" id="academicForm">
                <form method="POST" action="" id="academicProfileForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="degree">Degree/Course *</label>
                            <select id="degree" name="degree" class="form-select" required>
                                <option value="">Select your course</option>
                                <optgroup label="College of Education (CoEd)">
                                    <option value="BEEd" <?= ($graduate['grad_degree'] ?? '') === 'BEEd' ? 'selected' : '' ?>>BEEd – Bachelor in Elementary Education</option>
                                    <option value="BECEd" <?= ($graduate['grad_degree'] ?? '') === 'BECEd' ? 'selected' : '' ?>>BECEd – Bachelor in Early Childhood Education</option>
                                    <option value="BSNEd" <?= ($graduate['grad_degree'] ?? '') === 'BSNEd' ? 'selected' : '' ?>>BSNEd – Bachelor in Special Needs Education</option>
                                    <option value="BSEd" <?= ($graduate['grad_degree'] ?? '') === 'BSEd' ? 'selected' : '' ?>>BSEd – Bachelor in Secondary Education</option>
                                    <option value="BSEd-Math" <?= ($graduate['grad_degree'] ?? '') === 'BSEd-Math' ? 'selected' : '' ?>>BSEd-Math – Mathematics</option>
                                    <option value="BSEd-Sci" <?= ($graduate['grad_degree'] ?? '') === 'BSEd-Sci' ? 'selected' : '' ?>>BSEd-Sci – Science</option>
                                    <option value="BSEd-Eng" <?= ($graduate['grad_degree'] ?? '') === 'BSEd-Eng' ? 'selected' : '' ?>>BSEd-Eng – English</option>
                                    <option value="BSEd-Fil" <?= ($graduate['grad_degree'] ?? '') === 'BSEd-Fil' ? 'selected' : '' ?>>BSEd-Fil – Filipino</option>
                                    <option value="BSEd-VE" <?= ($graduate['grad_degree'] ?? '') === 'BSEd-VE' ? 'selected' : '' ?>>BSEd-VE – Values Education</option>
                                    <option value="BTLEd" <?= ($graduate['grad_degree'] ?? '') === 'BTLEd' ? 'selected' : '' ?>>BTLEd – Bachelor in Technology and Livelihood Education</option>
                                    <option value="BTLEd-IA" <?= ($graduate['grad_degree'] ?? '') === 'BTLEd-IA' ? 'selected' : '' ?>>BTLEd-IA – Industrial Arts</option>
                                    <option value="BTLEd-HE" <?= ($graduate['grad_degree'] ?? '') === 'BTLEd-HE' ? 'selected' : '' ?>>BTLEd-HE – Home Economics</option>
                                    <option value="BTLEd-ICT" <?= ($graduate['grad_degree'] ?? '') === 'BTLEd-ICT' ? 'selected' : '' ?>>BTLEd-ICT – Information & Communication Technology</option>
                                    <option value="BTVTEd" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd' ? 'selected' : '' ?>>BTVTEd – Bachelor in Technical and Vocational Teacher Education</option>
                                    <option value="BTVTEd-AD" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-AD' ? 'selected' : '' ?>>BTVTEd-AD – Architectural Drafting</option>
                                    <option value="BTVTEd-AT" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-AT' ? 'selected' : '' ?>>BTVTEd-AT – Automotive Technology</option>
                                    <option value="BTVTEd-FSMT" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-FSMT' ? 'selected' : '' ?>>BTVTEd-FSMT – Food Services Management Technology</option>
                                    <option value="BTVTEd-ET" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-ET' ? 'selected' : '' ?>>BTVTEd-ET – Electrical Technology</option>
                                    <option value="BTVTEd-ELXT" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-ELXT' ? 'selected' : '' ?>>BTVTEd-ELXT – Electronics Technology</option>
                                    <option value="BTVTEd-GFDT" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-GFDT' ? 'selected' : '' ?>>BTVTEd-GFDT – Garments, Fashion & Design Technology</option>
                                    <option value="BTVTEd-WFT" <?= ($graduate['grad_degree'] ?? '') === 'BTVTEd-WFT' ? 'selected' : '' ?>>BTVTEd-WFT – Welding & Fabrication Technology</option>
                                </optgroup>
                                <optgroup label="College of Engineering (CoE)">
                                    <option value="BSCE" <?= ($graduate['grad_degree'] ?? '') === 'BSCE' ? 'selected' : '' ?>>BSCE – Bachelor of Science in Civil Engineering</option>
                                    <option value="BSCpE" <?= ($graduate['grad_degree'] ?? '') === 'BSCpE' ? 'selected' : '' ?>>BSCpE – Bachelor of Science in Computer Engineering</option>
                                    <option value="BSECE" <?= ($graduate['grad_degree'] ?? '') === 'BSECE' ? 'selected' : '' ?>>BSECE – Bachelor of Science in Electronics Engineering</option>
                                    <option value="BSEE" <?= ($graduate['grad_degree'] ?? '') === 'BSEE' ? 'selected' : '' ?>>BSEE – Bachelor of Science in Electrical Engineering</option>
                                    <option value="BSIE" <?= ($graduate['grad_degree'] ?? '') === 'BSIE' ? 'selected' : '' ?>>BSIE – Bachelor of Science in Industrial Engineering</option>
                                    <option value="BSME" <?= ($graduate['grad_degree'] ?? '') === 'BSME' ? 'selected' : '' ?>>BSME – Bachelor of Science in Mechanical Engineering</option>
                                </optgroup>
                                <optgroup label="College of Technology (COT)">
                                    <option value="BSMx" <?= ($graduate['grad_degree'] ?? '') === 'BSMx' ? 'selected' : '' ?>>BSMx – Bachelor of Science in Mechatronics</option>
                                    <option value="BSGD" <?= ($graduate['grad_degree'] ?? '') === 'BSGD' ? 'selected' : '' ?>>BSGD – Bachelor of Science in Graphics and Design</option>
                                    <option value="BSTechM" <?= ($graduate['grad_degree'] ?? '') === 'BSTechM' ? 'selected' : '' ?>>BSTechM – Bachelor of Science in Technology Management</option>
                                    <option value="BIT" <?= ($graduate['grad_degree'] ?? '') === 'BIT' ? 'selected' : '' ?>>BIT – Bachelor in Industrial Technology</option>
                                    <option value="BIT-AT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-AT' ? 'selected' : '' ?>>BIT-AT – Automotive Technology</option>
                                    <option value="BIT-CvT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-CvT' ? 'selected' : '' ?>>BIT-CvT – Civil Technology</option>
                                    <option value="BIT-CosT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-CosT' ? 'selected' : '' ?>>BIT-CosT – Cosmetology</option>
                                    <option value="BIT-DT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-DT' ? 'selected' : '' ?>>BIT-DT – Drafting Technology</option>
                                    <option value="BIT-ET" <?= ($graduate['grad_degree'] ?? '') === 'BIT-ET' ? 'selected' : '' ?>>BIT-ET – Electrical Technology</option>
                                    <option value="BIT-ELXT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-ELXT' ? 'selected' : '' ?>>BIT-ELXT – Electronics Technology</option>
                                    <option value="BIT-FPST" <?= ($graduate['grad_degree'] ?? '') === 'BIT-FPST' ? 'selected' : '' ?>>BIT-FPST – Food Preparation & Services Technology</option>
                                    <option value="BIT-FCM" <?= ($graduate['grad_degree'] ?? '') === 'BIT-FCM' ? 'selected' : '' ?>>BIT-FCM – Furniture & Cabinet Making</option>
                                    <option value="BIT-GT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-GT' ? 'selected' : '' ?>>BIT-GT – Garments Technology</option>
                                    <option value="BIT-IDT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-IDT' ? 'selected' : '' ?>>BIT-IDT – Interior Design Technology</option>
                                    <option value="BIT-MST" <?= ($graduate['grad_degree'] ?? '') === 'BIT-MST' ? 'selected' : '' ?>>BIT-MST – Machine Shop Technology</option>
                                    <option value="BIT-PPT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-PPT' ? 'selected' : '' ?>>BIT-PPT – Power Plant Technology</option>
                                    <option value="BIT-RAC" <?= ($graduate['grad_degree'] ?? '') === 'BIT-RAC' ? 'selected' : '' ?>>BIT-RAC – Refrigeration & Air-conditioning Technology</option>
                                    <option value="BIT-WFT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-WFT' ? 'selected' : '' ?>>BIT-WFT – Welding & Fabrication Technology</option>
                                </optgroup>
                                <optgroup label="College of Management & Entrepreneurship (CME)">
                                    <option value="BPA" <?= ($graduate['grad_degree'] ?? '') === 'BPA' ? 'selected' : '' ?>>BPA – Bachelor of Public Administration</option>
                                    <option value="BSHM" <?= ($graduate['grad_degree'] ?? '') === 'BSHM' ? 'selected' : '' ?>>BSHM – Bachelor of Science in Hospitality Management</option>
                                    <option value="BSBA-MM" <?= ($graduate['grad_degree'] ?? '') === 'BSBA-MM' ? 'selected' : '' ?>>BSBA-MM – Bachelor of Science in Business Administration (Major in Marketing Management)</option>
                                    <option value="BSTM" <?= ($graduate['grad_degree'] ?? '') === 'BSTM' ? 'selected' : '' ?>>BSTM – Bachelor of Science in Tourism Management</option>
                                </optgroup>
                                <optgroup label="College of Computer Information & Communications Technology (CCICT)">
                                    <option value="BSIT" <?= ($graduate['grad_degree'] ?? '') === 'BSIT' ? 'selected' : '' ?>>BSIT – Bachelor of Science in Information Technology</option>
                                    <option value="BSIS" <?= ($graduate['grad_degree'] ?? '') === 'BSIS' ? 'selected' : '' ?>>BSIS – Bachelor of Science in Information Systems</option>
                                    <option value="BIT-CT" <?= ($graduate['grad_degree'] ?? '') === 'BIT-CT' ? 'selected' : '' ?>>BIT-CT – Bachelor in Industrial Technology – Computer Technology</option>
                                </optgroup>
                                <optgroup label="College of Arts & Sciences (CAS)">
                                    <option value="BAEL" <?= ($graduate['grad_degree'] ?? '') === 'BAEL' ? 'selected' : '' ?>>BAEL – Bachelor of Arts in English Language</option>
                                    <option value="BAL" <?= ($graduate['grad_degree'] ?? '') === 'BAL' ? 'selected' : '' ?>>BAL – Bachelor of Arts in Literature</option>
                                    <option value="BAF" <?= ($graduate['grad_degree'] ?? '') === 'BAF' ? 'selected' : '' ?>>BAF – Bachelor of Arts in Filipino</option>
                                    <option value="BS Math" <?= ($graduate['grad_degree'] ?? '') === 'BS Math' ? 'selected' : '' ?>>BS Math – Bachelor of Science in Mathematics</option>
                                    <option value="BS Stat" <?= ($graduate['grad_degree'] ?? '') === 'BS Stat' ? 'selected' : '' ?>>BS Stat – Bachelor of Science in Statistics</option>
                                    <option value="BS DevCom" <?= ($graduate['grad_degree'] ?? '') === 'BS DevCom' ? 'selected' : '' ?>>BS DevCom – Bachelor of Science in Development Communication</option>
                                    <option value="BSPsy" <?= ($graduate['grad_degree'] ?? '') === 'BSPsy' ? 'selected' : '' ?>>BSPsy – Bachelor of Science in Psychology</option>
                                    <option value="BSN" <?= ($graduate['grad_degree'] ?? '') === 'BSN' ? 'selected' : '' ?>>BSN – Bachelor of Science in Nursing</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="year_graduated">Year Graduated *</label>
                            <input type="number" id="year_graduated" name="year_graduated" min="1990" max="2099" value="<?= htmlspecialchars($graduate['grad_year_graduated'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <!-- ENHANCED JOB PREFERENCES SECTION -->
                    <div class="form-group">
                        <label class="form-label">Job Preference/Field <span class="required">*</span></label>
                        <div class="job-preferences-container">
                            <div class="job-preferences-header">
                                <div class="job-preferences-title">Select your job preferences</div>
                                <div class="selection-info">
                                    <i class="fas fa-info-circle"></i> Select as many as you want
                                </div>
                            </div>
                            
                            <div class="job-categories-container" id="jobCategoriesContainer">
                                <?php if (!empty($job_positions)): ?>
                                    <?php foreach ($job_positions as $category_id => $category_data): ?>
                                        <div class="job-category">
                                            <div class="category-title"><?php echo htmlspecialchars($category_data['category_name']); ?></div>
                                            <div class="job-options">
                                                <?php foreach ($category_data['positions'] as $position): ?>
                                                    <div class="job-option">
                                                        <input type="checkbox" 
                                                               id="job_pref_<?php echo $position['position_id']; ?>" 
                                                               name="job_preferences[]" 
                                                               value="<?php echo htmlspecialchars($position['position_name']); ?>"
                                                               class="job-preference-checkbox"
                                                               <?= in_array($position['position_name'], $selected_job_preferences) ? 'checked' : '' ?>>
                                                        <label for="job_pref_<?php echo $position['position_id']; ?>">
                                                            <?php echo htmlspecialchars($position['position_name']); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 20px; color: #666;">
                                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                        <p>No job positions available. Please add custom preferences below.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="selected-jobs-container">
                                <div class="selected-jobs-title">
                                    <i class="fas fa-check-circle"></i> Selected Job Preferences
                                </div>
                                <div class="selected-jobs-list" id="selectedJobsList">
                                    <?php if (!empty($selected_job_preferences)): ?>
                                        <?php foreach ($selected_job_preferences as $job): ?>
                                            <div class="job-tag">
                                                <?php echo htmlspecialchars($job); ?>
                                                <i class="fas fa-times" data-job="<?php echo htmlspecialchars($job); ?>" data-custom="false"></i>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="no-selection-message">No job preferences selected yet</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="custom-job-input-container">
                                <div class="custom-job-title">
                                    <i class="fas fa-plus-circle"></i> Add Custom Job Preference
                                </div>
                                <div class="custom-job-input-group">
                                    <input type="text" 
                                           id="customJobInput" 
                                           class="form-input custom-job-input" 
                                           placeholder="Enter a job preference not listed above">
                                    <button type="button" id="addCustomJobBtn" class="add-custom-job-btn">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                                <div class="custom-job-hint">
                                    <i class="fas fa-lightbulb"></i> Your custom job preferences will be saved in the system for future users
                                </div>
                            </div>
                        </div>
                        <div class="error-message" id="job_preferences_error"></div>
                        
                        <!-- Hidden inputs for custom job preferences -->
                        <div id="customJobPreferencesContainer"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="summary">Career Summary/Objective</label>
                        <textarea id="summary" name="summary" placeholder="Briefly describe your career goals, and objectives..."><?= htmlspecialchars($graduate['grad_summary'] ?? '') ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary cancel-form">Cancel</button>
                        <button type="submit" name="update_academic_profile" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Skills Section - MODIFIED FOR MULTIPLE SELECTION -->
        <div class="portfolio-section">
            <div class="section-header">
                <h2 class="section-title">Skills</h2>
                <span class="section-badge"><?= $skill_count ?> Skill(s)</span>
                <button class="toggle-form" id="toggleSkillsForm">
                    <i class="fas fa-plus"></i> Add Skills
                </button>
            </div>
            
            <div class="skills-container">
                <?php if (count($skills) > 0): ?>
                    <?php 
                    // Group skills by category for display
                    $display_skills_by_category = [];
                    foreach ($skills as $skill) {
                        $category = $skill['skill_category'] ?? 'Uncategorized';
                        if (!isset($display_skills_by_category[$category])) {
                            $display_skills_by_category[$category] = [];
                        }
                        $display_skills_by_category[$category][] = $skill;
                    }
                    ?>
                    
                    <?php foreach ($display_skills_by_category as $category => $category_skills): ?>
                    <div style="margin-bottom: 20px; width: 100%;">
                        <div style="font-weight: 600; color: var(--maroon); margin-bottom: 12px; font-size: 1rem; display: flex; align-items: center; gap: 8px; padding: 10px 15px; background: #f8f9fa; border-radius: 8px;">
                            <i class="fas fa-folder"></i> <?= htmlspecialchars($category) ?>
                            <span style="font-weight: normal; color: #6c757d; font-size: 0.8rem;">(<?= count($category_skills) ?> skills)</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                            <?php foreach ($category_skills as $skill): ?>
                            <div class="skill-tag">
                                <span style="font-weight: 500;"><?= htmlspecialchars($skill['skill_name']) ?></span>
                                <span class="skill-level <?= $skill['skill_level'] ?>"><?= ucfirst($skill['skill_level']) ?></span>
                                <a href="#" class="delete-skill" data-skill-id="<?= $skill['skill_id'] ?>" data-skill-name="<?= htmlspecialchars($skill['skill_name']) ?>">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- ENHANCED: CENTERED EMPTY STATE LIKE DOCUMENTS SECTION -->
                    <div class="empty-state">
                        <i class="fas fa-code"></i>
                        <h3>No skills added yet</h3>
                        <p>Add your first skill to showcase your abilities to employers</p>
                        <!-- MODIFIED: Smaller button matching "Browse Available Jobs" style -->
                        <button class="browse-jobs-btn" id="toggleSkillsFormFromEmpty">
                            <i class="fas fa-plus"></i> Add Your First Skill
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="form-container" id="skillsForm">
                <div class="skills-tabs">
                    <div class="skill-tab active" data-tab="existing">Choose from Existing Skills</div>
                    <div class="skill-tab" data-tab="custom">Add Custom Skill</div>
                </div>
                
                <!-- Existing Skills Tab - MODIFIED FOR MULTIPLE SELECTION -->
                <div class="skill-tab-content active" id="existingSkillsTab">
                    <form method="POST" action="" id="addSkillsForm">
                        <div class="form-group">
                            <label for="skillSearch">Search Skills</label>
                            <input type="text" id="skillSearch" placeholder="Type to search skills..." class="form-control">
                        </div>
                        
                        <!-- Selected Skills Summary -->
                        <div class="selected-skills-summary" id="selectedSkillsSummary" style="display: none;">
                            <div class="selected-skills-title">
                                <i class="fas fa-check-circle"></i> Selected Skills
                            </div>
                            <div class="selected-skills-list" id="selectedSkillsList">
                                <!-- Selected skills will appear here -->
                            </div>
                        </div>
                        
                        <div class="skills-form-container">
                            <?php if (!empty($skills_by_category)): ?>
                                <?php foreach ($skills_by_category as $category => $category_skills): ?>
                                <div class="skill-category-group">
                                    <div class="skill-category-title">
                                        <i class="fas fa-folder"></i> <?= htmlspecialchars($category) ?>
                                        <small>(<?= count($category_skills) ?> skills)</small>
                                    </div>
                                    <div class="skill-options">
                                        <?php foreach ($category_skills as $skill): ?>
                                        <div class="skill-option" onclick="toggleSkillSelection(<?= $skill['skill_id'] ?>, '<?= htmlspecialchars($skill['skill_name']) ?>', this)">
                                            <input type="checkbox" name="selected_skills[]" value="<?= $skill['skill_id'] ?>" style="display: none;">
                                            <?= htmlspecialchars($skill['skill_name']) ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h3>All skills added!</h3>
                                    <p>You've added all available skills to your portfolio.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="skill_level">Skill Level for Selected Skills *</label>
                            <select id="skill_level" name="skill_level" required>
                                <option value="">Select proficiency level</option>
                                <option value="beginner">Beginner</option>
                                <option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option>
                                <option value="expert">Expert</option>
                            </select>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary cancel-form">Cancel</button>
                            <button type="submit" name="add_skills" class="btn btn-primary">Add Selected Skills</button>
                        </div>
                    </form>
                </div>
                
                <!-- Custom Skills Tab -->
                <div class="skill-tab-content" id="customSkillsTab">
                    <form method="POST" action="" id="addCustomSkillForm">
                        <div class="custom-skill-form">
                            <div class="custom-skill-title">
                                <i class="fas fa-plus-circle"></i> Add a Custom Skill
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="custom_skill_name">Skill Name *</label>
                                    <input type="text" id="custom_skill_name" name="custom_skill_name" required placeholder="Enter your custom skill name">
                                </div>
                                <div class="form-group">
                                    <label for="custom_skill_category">Category *</label>
                                    <select id="custom_skill_category" name="custom_skill_category" required>
                                        <option value="">Select a category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="custom_skill_level">Skill Level *</label>
                                    <select id="custom_skill_level" name="custom_skill_level" required>
                                        <option value="">Select proficiency level</option>
                                        <option value="beginner">Beginner</option>
                                        <option value="intermediate">Intermediate</option>
                                        <option value="advanced">Advanced</option>
                                        <option value="expert">Expert</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary cancel-form">Cancel</button>
                                <button type="submit" name="add_custom_skill" class="btn btn-success">Add Custom Skill</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Documents Section -->
        <div class="portfolio-section">
            <div class="section-header">
                <h2 class="section-title">Upload Documents</h2>
                <span class="section-badge"><?= count($portfolio_items) ?> File(s)</span>
                <button class="toggle-form" id="toggleDocumentsForm">
                    <i class="fas fa-upload"></i> Upload Document
                </button>
            </div>
            
            <?php if (count($portfolio_items) > 0): ?>
            <table class="documents-table">
                <thead>
                    <tr>
                        <th>File Type</th>
                        <th>File Name</th>
                        <th>Description</th>
                        <th>Uploaded On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($portfolio_items as $item): ?>
                    <tr>
                        <td>
                            <div class="document-type">
                                <?php if ($item['port_item_type'] === 'resume'): ?>
                                    <i class="fas fa-file-alt"></i>
                                <?php elseif ($item['port_item_type'] === 'certificate'): ?>
                                    <i class="fas fa-certificate"></i>
                                <?php elseif ($item['port_item_type'] === 'project'): ?>
                                    <i class="fas fa-project-diagram"></i>
                                <?php else: ?>
                                    <i class="fas fa-file"></i>
                                <?php endif; ?>
                                <?= ucfirst(htmlspecialchars($item['port_item_type'])) ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($item['port_item_title']) ?></td>
                        <td><?= !empty($item['port_item_description']) ? htmlspecialchars($item['port_item_description']) : '<span style="color:#6c757d; font-style:italic;">No description</span>' ?></td>
                        <td><?= date('M j, Y', strtotime($item['port_created_at'])) ?></td>
                        <td class="document-actions">
                            <a href="#" class="document-action view-document" data-file="<?= htmlspecialchars($item['port_item_file']) ?>" data-title="<?= htmlspecialchars($item['port_item_title']) ?>" data-type="<?= htmlspecialchars($item['port_item_type']) ?>" data-description="<?= htmlspecialchars($item['port_item_description']) ?>">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="?delete_document=<?= $item['port_id'] ?>" class="document-action" onclick="return confirm('Are you sure you want to delete this document?')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <!-- ENHANCED: CENTERED EMPTY STATE FOR DOCUMENTS -->
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No documents uploaded yet</h3>
                <p>Upload your resume, certificates, or projects to build your portfolio</p>
                <!-- MODIFIED: Smaller button matching "Browse Available Jobs" style -->
                <button class="browse-jobs-btn" id="toggleDocumentsFormFromEmpty">
                    <i class="fas fa-upload"></i> Upload Your First Document
                </button>
            </div>
            <?php endif; ?>
            
            <div class="form-container" id="documentsForm">
                <form method="POST" action="" enctype="multipart/form-data" id="uploadDocumentForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="item_type">Document Type *</label>
                            <select id="item_type" name="item_type" required>
                                <option value="">Select document type</option>
                                <option value="resume" <?= (isset($_POST['item_type']) && $_POST['item_type'] == 'resume') ? 'selected' : '' ?>>Resume/CV</option>
                                <option value="certificate" <?= (isset($_POST['item_type']) && $_POST['item_type'] == 'certificate') ? 'selected' : '' ?>>Certificate</option>
                                <option value="project" <?= (isset($_POST['item_type']) && $_POST['item_type'] == 'project') ? 'selected' : '' ?>>Project</option>
                                <option value="other" <?= (isset($_POST['item_type']) && $_POST['item_type'] == 'other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="item_title">Document Title *</label>
                            <input type="text" id="item_title" name="item_title" value="<?= isset($_POST['item_title']) ? htmlspecialchars($_POST['item_title']) : '' ?>" required placeholder="e.g., My Resume, Java Certificate">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="document_file">File (Max 5MB) *</label>
                        <div class="file-input-container">
                            <label class="file-input-label" id="fileInputLabel">
                                <i class="fas fa-cloud-upload-alt"></i> 
                                <span id="fileLabelText">Choose file (PDF, DOC, DOCX, TXT, JPG, PNG)</span>
                            </label>
                            <input type="file" id="document_file" name="document_file" required accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png" onchange="updateFileName(this)">
                        </div>
                        <div class="file-info">Maximum file size: 5MB</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="item_description">Description (Optional)</label>
                        <textarea id="item_description" name="item_description" placeholder="Brief description of this document..."><?= isset($_POST['item_description']) ? htmlspecialchars($_POST['item_description']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary cancel-form">Cancel</button>
                        <button type="submit" name="upload_document" class="btn btn-primary">Upload Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div class="modal-overlay" id="documentModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="documentModalTitle">Document Viewer</h2>
                <button class="modal-close" id="closeDocumentModal">&times;</button>
            </div>
            <div class="modal-content">
                <div class="document-viewer" id="documentViewer">
                    <!-- Document will be displayed here -->
                </div>
                <div class="document-info" id="documentInfo">
                    <!-- Document information will be displayed here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal for Skill Removal -->
    <div class="modal-overlay" id="confirmationModal">
        <div class="modal confirmation-modal">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Skill Removal</h2>
                <button class="modal-close" id="closeConfirmationModal">&times;</button>
            </div>
            <div class="modal-content">
                <div class="confirmation-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="confirmation-message" id="confirmationMessage">
                    Are you sure you want to remove this skill from your portfolio?
                </div>
                <div class="confirmation-actions">
                    <button class="btn btn-secondary" id="cancelRemoveSkill">Cancel</button>
                    <button class="btn btn-danger" id="confirmRemoveSkill">Remove Skill</button>
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
        
        // Close alert messages
        function closeAlert(alertId) {
            document.getElementById(alertId).style.display = 'none';
        }
        
        // Auto-close alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
        
        // Form Toggle Functionality
        const togglePersonalForm = document.getElementById('togglePersonalForm');
        const personalForm = document.getElementById('personalForm');
        const toggleAcademicForm = document.getElementById('toggleAcademicForm');
        const academicForm = document.getElementById('academicForm');
        const toggleSkillsForm = document.getElementById('toggleSkillsForm');
        const skillsForm = document.getElementById('skillsForm');
        const toggleDocumentsForm = document.getElementById('toggleDocumentsForm');
        const documentsForm = document.getElementById('documentsForm');
        const cancelButtons = document.querySelectorAll('.cancel-form');
        
        // Enhanced toggle functionality
        togglePersonalForm.addEventListener('click', function() {
            personalForm.classList.toggle('active');
            academicForm.classList.remove('active');
            skillsForm.classList.remove('active');
            documentsForm.classList.remove('active');
        });
        
        toggleAcademicForm.addEventListener('click', function() {
            academicForm.classList.toggle('active');
            personalForm.classList.remove('active');
            skillsForm.classList.remove('active');
            documentsForm.classList.remove('active');
        });
        
        toggleSkillsForm.addEventListener('click', function() {
            skillsForm.classList.toggle('active');
            personalForm.classList.remove('active');
            academicForm.classList.remove('active');
            documentsForm.classList.remove('active');
        });
        
        toggleDocumentsForm.addEventListener('click', function() {
            documentsForm.classList.toggle('active');
            personalForm.classList.remove('active');
            academicForm.classList.remove('active');
            skillsForm.classList.remove('active');
        });
        
        // Enhanced: Add buttons in empty states to open forms
        document.getElementById('toggleSkillsFormFromEmpty')?.addEventListener('click', function() {
            skillsForm.classList.add('active');
            personalForm.classList.remove('active');
            academicForm.classList.remove('active');
            documentsForm.classList.remove('active');
        });
        
        document.getElementById('toggleDocumentsFormFromEmpty')?.addEventListener('click', function() {
            documentsForm.classList.add('active');
            personalForm.classList.remove('active');
            academicForm.classList.remove('active');
            skillsForm.classList.remove('active');
        });
        
        cancelButtons.forEach(button => {
            button.addEventListener('click', function() {
                personalForm.classList.remove('active');
                academicForm.classList.remove('active');
                skillsForm.classList.remove('active');
                documentsForm.classList.remove('active');
            });
        });
        
        // File input functionality
        function updateFileName(input) {
            const fileName = input.files[0]?.name;
            const fileSize = input.files[0]?.size;
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (fileName) {
                document.getElementById('fileLabelText').textContent = fileName;
                
                // Validate file size
                if (fileSize > maxSize) {
                    alert('File size exceeds 5MB limit. Please choose a smaller file.');
                    input.value = '';
                    document.getElementById('fileLabelText').textContent = 'Choose file (PDF, DOC, DOCX, TXT, JPG, PNG)';
                }
            } else {
                document.getElementById('fileLabelText').textContent = 'Choose file (PDF, DOC, DOCX, TXT, JPG, PNG)';
            }
        }
        
        // ============================================================================
        // ENHANCED JOB PREFERENCES MANAGER (FROM graduate_profile.php)
        // ============================================================================
        class JobPreferencesManager {
            constructor() {
                this.selectedJobs = new Set();
                this.customJobs = new Set();
                this.container = document.getElementById('selectedJobsList');
                this.customContainer = document.getElementById('customJobPreferencesContainer');
                this.errorElement = document.getElementById('job_preferences_error');
                this.initialize();
            }
            
            initialize() {
                // Load previously selected jobs from PHP
                <?php foreach ($selected_job_preferences as $job): ?>
                    this.selectedJobs.add('<?php echo addslashes($job); ?>');
                <?php endforeach; ?>
                
                // Add event listeners to checkboxes
                document.querySelectorAll('.job-preference-checkbox').forEach(checkbox => {
                    checkbox.addEventListener('change', (e) => this.handleCheckboxChange(e));
                    
                    // Pre-check boxes based on PHP data
                    if (this.selectedJobs.has(checkbox.value)) {
                        checkbox.checked = true;
                    }
                });
                
                // Add event listener to custom job button
                document.getElementById('addCustomJobBtn').addEventListener('click', () => this.addCustomJob());
                
                // Allow Enter key to add custom job
                document.getElementById('customJobInput').addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.addCustomJob();
                    }
                });
                
                // Update UI
                this.updateUI();
            }
            
            handleCheckboxChange(event) {
                const checkbox = event.target;
                const jobName = checkbox.value;
                
                if (checkbox.checked) {
                    this.selectedJobs.add(jobName);
                } else {
                    this.selectedJobs.delete(jobName);
                }
                
                this.clearError();
                this.updateUI();
            }
            
            addCustomJob() {
                const input = document.getElementById('customJobInput');
                const jobName = input.value.trim();
                
                if (!jobName) {
                    this.showError('Please enter a job preference name.');
                    return;
                }
                
                // Check if already selected
                if (this.selectedJobs.has(jobName) || this.customJobs.has(jobName)) {
                    this.showError('This job preference is already selected.');
                    return;
                }
                
                this.customJobs.add(jobName);
                input.value = '';
                this.clearError();
                this.updateUI();
            }
            
            removeJob(jobName, isCustom = false) {
                if (isCustom) {
                    this.customJobs.delete(jobName);
                } else {
                    this.selectedJobs.delete(jobName);
                    // Also uncheck the checkbox
                    const checkbox = document.querySelector(`.job-preference-checkbox[value="${jobName}"]`);
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                }
                this.updateUI();
            }
            
            getTotalSelections() {
                return this.selectedJobs.size + this.customJobs.size;
            }
            
            updateUI() {
                // Update selected jobs display
                this.container.innerHTML = '';
                
                if (this.getTotalSelections() === 0) {
                    this.container.innerHTML = '<div class="no-selection-message">No job preferences selected yet</div>';
                } else {
                    // Add selected jobs from checkboxes
                    this.selectedJobs.forEach(jobName => {
                        this.addJobTag(jobName, false);
                    });
                    
                    // Add custom jobs
                    this.customJobs.forEach(jobName => {
                        this.addJobTag(jobName, true);
                    });
                }
                
                // Update hidden inputs for form submission
                this.updateHiddenInputs();
                
                // Update selection count
                this.updateSelectionCount();
            }
            
            addJobTag(jobName, isCustom) {
                const tag = document.createElement('div');
                tag.className = 'job-tag';
                tag.innerHTML = `
                    ${jobName}
                    <i class="fas fa-times" data-job="${jobName}" data-custom="${isCustom}"></i>
                `;
                
                tag.querySelector('i').addEventListener('click', (e) => {
                    e.stopPropagation();
                    const job = e.target.getAttribute('data-job');
                    const isCustom = e.target.getAttribute('data-custom') === 'true';
                    this.removeJob(job, isCustom);
                });
                
                this.container.appendChild(tag);
            }
            
            updateHiddenInputs() {
                // Clear existing custom job inputs
                this.customContainer.innerHTML = '';
                
                // Add hidden inputs for each custom job
                this.customJobs.forEach(jobName => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'custom_job_preferences[]';
                    input.value = jobName;
                    this.customContainer.appendChild(input);
                });
            }
            
            updateSelectionCount() {
                const count = this.getTotalSelections();
                const info = document.querySelector('.selection-info');
                if (info) {
                    info.innerHTML = `
                        <i class="fas fa-info-circle"></i> 
                        Selected: ${count} job preference${count !== 1 ? 's' : ''}
                    `;
                }
            }
            
            showError(message) {
                if (this.errorElement) {
                    this.errorElement.textContent = message;
                    this.errorElement.classList.add('show');
                }
            }
            
            clearError() {
                if (this.errorElement) {
                    this.errorElement.textContent = '';
                    this.errorElement.classList.remove('show');
                }
            }
            
            validate() {
                if (this.getTotalSelections() === 0) {
                    this.showError('Please select at least one job preference.');
                    return false;
                }
                
                this.clearError();
                return true;
            }
        }
        
        // Initialize job preferences manager
        let jobManager;
        
        // ============================================================================
        // SKILL SELECTION FUNCTIONALITY
        // ============================================================================
        let selectedSkills = new Map();
        
        function toggleSkillSelection(skillId, skillName, element) {
            const checkbox = element.querySelector('input[type="checkbox"]');
            
            if (selectedSkills.has(skillId)) {
                // Remove from selection
                selectedSkills.delete(skillId);
                element.classList.remove('selected');
                checkbox.checked = false;
            } else {
                // Add to selection
                selectedSkills.set(skillId, skillName);
                element.classList.add('selected');
                checkbox.checked = true;
            }
            
            updateSelectedSkillsSummary();
        }
        
        function updateSelectedSkillsSummary() {
            const summaryContainer = document.getElementById('selectedSkillsSummary');
            const skillsList = document.getElementById('selectedSkillsList');
            
            if (selectedSkills.size > 0) {
                summaryContainer.style.display = 'block';
                skillsList.innerHTML = '';
                
                selectedSkills.forEach((skillName, skillId) => {
                    const skillBadge = document.createElement('div');
                    skillBadge.className = 'selected-skill-badge';
                    skillBadge.textContent = skillName;
                    skillsList.appendChild(skillBadge);
                });
            } else {
                summaryContainer.style.display = 'none';
            }
        }
        
        // Skill search functionality
        function addSkillSearch() {
            const skillSearch = document.getElementById('skillSearch');
            if (skillSearch) {
                skillSearch.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const skillOptions = document.querySelectorAll('.skill-option');
                    
                    skillOptions.forEach(option => {
                        const skillName = option.textContent.toLowerCase();
                        if (skillName.includes(searchTerm)) {
                            option.style.display = 'flex';
                        } else {
                            option.style.display = 'none';
                        }
                    });
                    
                    // Show/hide category headers based on visible skills
                    document.querySelectorAll('.skill-category-group').forEach(category => {
                        const visibleSkills = category.querySelectorAll('.skill-option[style="display: flex"]');
                        if (visibleSkills.length === 0) {
                            category.style.display = 'none';
                        } else {
                            category.style.display = 'block';
                        }
                    });
                });
            }
        }
        
        // Skill tabs functionality
        function initializeSkillTabs() {
            const tabs = document.querySelectorAll('.skill-tab');
            const tabContents = document.querySelectorAll('.skill-tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    // Add active class to current tab and content
                    this.classList.add('active');
                    document.getElementById(tabId + 'SkillsTab').classList.add('active');
                });
            });
        }
        
        // Document Viewer Modal Functionality
        const documentModal = document.getElementById('documentModal');
        const closeDocumentModal = document.getElementById('closeDocumentModal');
        const documentViewer = document.getElementById('documentViewer');
        const documentModalTitle = document.getElementById('documentModalTitle');
        const documentInfo = document.getElementById('documentInfo');
        
        // Open document modal when view button is clicked
        document.addEventListener('click', function(e) {
            if (e.target.closest('.view-document')) {
                e.preventDefault();
                const button = e.target.closest('.view-document');
                const filePath = button.getAttribute('data-file');
                const title = button.getAttribute('data-title');
                const type = button.getAttribute('data-type');
                const description = button.getAttribute('data-description');
                
                openDocumentModal(filePath, title, type, description);
            }
        });
        
        function openDocumentModal(filePath, title, type, description) {
            documentModalTitle.textContent = title;
            
            // Clear previous content
            documentViewer.innerHTML = '';
            documentInfo.innerHTML = '';
            
            // Get file extension
            const fileExtension = filePath.split('.').pop().toLowerCase();
            
            // Display document based on file type
            if (['pdf'].includes(fileExtension)) {
                // Display PDF
                const iframe = document.createElement('iframe');
                iframe.src = filePath;
                documentViewer.appendChild(iframe);
            } else if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension)) {
                // Display image
                const img = document.createElement('img');
                img.src = filePath;
                img.alt = title;
                documentViewer.appendChild(img);
            } else {
                // For other file types, show download link
                const message = document.createElement('div');
                message.style.padding = '40px';
                message.style.textAlign = 'center';
                message.innerHTML = `
                    <i class="fas fa-download" style="font-size: 3rem; color: var(--maroon); margin-bottom: 20px;"></i>
                    <h3 style="color: var(--maroon); margin-bottom: 15px;">Document Preview Not Available</h3>
                    <p style="margin-bottom: 20px;">This file type cannot be previewed in the browser.</p>
                    <a href="${filePath}" class="btn btn-primary" download>
                        <i class="fas fa-download"></i> Download Document
                    </a>
                `;
                documentViewer.appendChild(message);
            }
            
            // Add document information
            documentInfo.innerHTML = `
                <h4>Document Information</h4>
                <p><strong>Title:</strong> ${title}</p>
                <p><strong>Type:</strong> ${type.charAt(0).toUpperCase() + type.slice(1)}</p>
                <p><strong>Description:</strong> ${description || 'No description provided'}</p>
                <p><strong>File:</strong> ${filePath.split('/').pop()}</p>
            `;
            
            // Show modal
            documentModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // Close document modal
        closeDocumentModal.addEventListener('click', function() {
            documentModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        });
        
        // Close modal when clicking outside
        documentModal.addEventListener('click', function(e) {
            if (e.target === documentModal) {
                documentModal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
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
                        url: 'graduate_portfolio.php',
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
            $('.portfolio-section').hover(
                function() {
                    $(this).css('transform', 'translateY(-2px)');
                    $(this).css('box-shadow', '0 4px 15px rgba(0, 0, 0, 0.1)');
                },
                function() {
                    $(this).css('transform', 'translateY(0)');
                    $(this).css('box-shadow', '0 2px 10px rgba(0, 0, 0, 0.05)');
                }
            );
        });
        
        // Form validation
        document.getElementById('addSkillsForm')?.addEventListener('submit', function(e) {
            const skillLevel = document.getElementById('skill_level').value;
            
            if (selectedSkills.size === 0) {
                e.preventDefault();
                alert('Please select at least one skill to add.');
                return false;
            }
            
            if (!skillLevel) {
                e.preventDefault();
                alert('Please select a skill level.');
                return false;
            }
        });
        
        document.getElementById('addCustomSkillForm')?.addEventListener('submit', function(e) {
            const skillName = document.getElementById('custom_skill_name').value;
            const skillCategory = document.getElementById('custom_skill_category').value;
            const skillLevel = document.getElementById('custom_skill_level').value;
            
            if (!skillName) {
                e.preventDefault();
                alert('Please enter a skill name.');
                return false;
            }
            
            if (!skillCategory) {
                e.preventDefault();
                alert('Please select a skill category.');
                return false;
            }
            
            if (!skillLevel) {
                e.preventDefault();
                alert('Please select a skill level.');
                return false;
            }
        });
        
        document.getElementById('uploadDocumentForm')?.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('document_file');
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (fileInput.files.length > 0 && fileInput.files[0].size > maxSize) {
                e.preventDefault();
                alert('File size exceeds 5MB limit. Please choose a smaller file.');
                return false;
            }
        });
        
        // Academic form validation with job preferences
        document.getElementById('academicProfileForm')?.addEventListener('submit', function(e) {
            // Validate job preferences
            if (jobManager && !jobManager.validate()) {
                e.preventDefault();
                // Scroll to job preferences section
                document.getElementById('job_preferences_error').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            
            // Validate other required fields
            const degree = document.getElementById('degree').value;
            const yearGraduated = document.getElementById('year_graduated').value;
            
            if (!degree) {
                e.preventDefault();
                alert('Please select your degree/course.');
                return false;
            }
            
            if (!yearGraduated) {
                e.preventDefault();
                alert('Please enter your year graduated.');
                return false;
            }
        });
        
        // Enhanced Phone Number Validation - CHANGED TO 11 DIGITS
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInput = document.getElementById('mobile_number');
            
            // Only allow numbers and limit to 11 digits
            phoneInput.addEventListener('input', function(e) {
                // Remove any non-numeric characters
                let value = e.target.value.replace(/[^0-9]/g, '');
                
                // Limit to 11 digits
                if (value.length > 11) {
                    value = value.substring(0, 11);
                }
                
                e.target.value = value;
            });
            
            // Prevent non-numeric input on keydown
            phoneInput.addEventListener('keydown', function(e) {
                // Allow: backspace, delete, tab, escape, enter, and decimal point
                if ([46, 8, 9, 27, 13, 110, 190].indexOf(e.keyCode) !== -1 ||
                     // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                     (e.keyCode === 65 && e.ctrlKey === true) || 
                     (e.keyCode === 67 && e.ctrlKey === true) ||
                     (e.keyCode === 86 && e.ctrlKey === true) ||
                     (e.keyCode === 88 && e.ctrlKey === true) ||
                     // Allow: home, end, left, right
                     (e.keyCode >= 35 && e.keyCode <= 39)) {
                    return;
                }
                
                // Ensure that it is a number and stop the keypress if not
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            });
            
            // Validate on paste
            phoneInput.addEventListener('paste', function(e) {
                const pastedData = e.clipboardData.getData('text');
                if (!/^\d+$/.test(pastedData)) {
                    e.preventDefault();
                }
            });
        });
        
        // Auto-open form if there's an error
        <?php if (!empty($error_message)): ?>
            window.addEventListener('load', function() {
                <?php if (isset($_POST['upload_document'])): ?>
                    documentsForm.classList.add('active');
                <?php elseif (isset($_POST['add_skills']) || isset($_POST['add_custom_skill'])): ?>
                    skillsForm.classList.add('active');
                    
                    <?php if (isset($_POST['add_skills'])): ?>
                        // Re-select skills if there was an error
                        <?php if (isset($_POST['selected_skills']) && is_array($_POST['selected_skills'])): ?>
                            <?php foreach ($_POST['selected_skills'] as $skillId): ?>
                                const skillOption = document.querySelector(`.skill-option input[value="<?= $skillId ?>"]`);
                                if (skillOption) {
                                    const skillName = skillOption.parentElement.textContent.trim();
                                    toggleSkillSelection(<?= $skillId ?>, skillName, skillOption.parentElement);
                                }
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php elseif (isset($_POST['add_custom_skill'])): ?>
                        // Switch to custom skills tab if there was an error with custom skill
                        document.querySelector('[data-tab="custom"]').click();
                    <?php endif; ?>
                <?php elseif (isset($_POST['update_personal_profile'])): ?>
                    personalForm.classList.add('active');
                <?php elseif (isset($_POST['update_academic_profile'])): ?>
                    academicForm.classList.add('active');
                <?php endif; ?>
                
                // Scroll to the alert message
                const alert = document.querySelector('.alert');
                if (alert) {
                    alert.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        <?php endif; ?>
        
        // Profile picture preview
        document.getElementById('profilePictureForm').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profilePicture').src = e.target.result;
                }
                reader.readAsDataURL(e.target.files[0]);
            }
        });
        
        // Initialize skill selection display and search
        window.addEventListener('load', function() {
            addSkillSearch();
            initializeSkillTabs();
            updateSelectedSkillsSummary();
            
            // Initialize job preferences manager
            jobManager = new JobPreferencesManager();
        });
        
        // Skill Removal Confirmation System - NEW FUNCTIONALITY
        const confirmationModal = document.getElementById('confirmationModal');
        const closeConfirmationModal = document.getElementById('closeConfirmationModal');
        const cancelRemoveSkill = document.getElementById('cancelRemoveSkill');
        const confirmRemoveSkill = document.getElementById('confirmRemoveSkill');
        const confirmationMessage = document.getElementById('confirmationMessage');
        
        let currentSkillId = null;
        let currentSkillName = null;
        
        // Handle skill removal clicks
        document.addEventListener('click', function(e) {
            if (e.target.closest('.delete-skill')) {
                e.preventDefault();
                const deleteButton = e.target.closest('.delete-skill');
                currentSkillId = deleteButton.getAttribute('data-skill-id');
                currentSkillName = deleteButton.getAttribute('data-skill-name');
                
                // Show confirmation modal
                confirmationMessage.textContent = `Are you sure you want to remove the skill "${currentSkillName}" from your portfolio?`;
                confirmationModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
        
        // Close confirmation modal
        closeConfirmationModal.addEventListener('click', function() {
            confirmationModal.classList.remove('active');
            document.body.style.overflow = 'auto';
            currentSkillId = null;
            currentSkillName = null;
        });
        
        // Cancel skill removal
        cancelRemoveSkill.addEventListener('click', function() {
            confirmationModal.classList.remove('active');
            document.body.style.overflow = 'auto';
            currentSkillId = null;
            currentSkillName = null;
        });
        
        // Confirm skill removal
        confirmRemoveSkill.addEventListener('click', function() {
            if (currentSkillId) {
                // Redirect to remove the skill
                window.location.href = `?delete_skill=${currentSkillId}`;
            }
        });
        
        // Close modal when clicking outside
        confirmationModal.addEventListener('click', function(e) {
            if (e.target === confirmationModal) {
                confirmationModal.classList.remove('active');
                document.body.style.overflow = 'auto';
                currentSkillId = null;
                currentSkillName = null;
            }
        });
    </script>
</body>
</html>