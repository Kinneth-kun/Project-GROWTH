<?php
// Database connection and session handling
session_start();

// Database connection settings
$host = 'localhost';
$dbname = 'growth_db';
$username = 'root';
$password = '06162004';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Handle AJAX notification actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        $user_id = $_SESSION['user_id'];
        
        if ($_POST['ajax_action'] === 'mark_notifications_read') {
            if (isset($_POST['mark_all']) && $_POST['mark_all'] === 'true') {
                // Mark all notifications as read for this user
                $stmt = $conn->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_usr_id = :user_id AND notif_is_read = 0");
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                
                echo json_encode(['success' => true, 'action' => 'mark_all']);
                exit();
            } elseif (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
                // Mark specific notifications as read
                $placeholders = str_repeat('?,', count($_POST['notification_ids']) - 1) . '?';
                $stmt = $conn->prepare("UPDATE notifications SET notif_is_read = 1 WHERE notif_id IN ($placeholders) AND notif_usr_id = ?");
                
                $params = array_merge($_POST['notification_ids'], [$user_id]);
                $stmt->execute($params);
                
                echo json_encode(['success' => true, 'action' => 'mark_specific']);
                exit();
            }
        }
        
        if ($_POST['ajax_action'] === 'check_new_notifications') {
            $user_id = $_SESSION['user_id'];
            $stmt = $conn->prepare("
                SELECT COUNT(*) as new_count 
                FROM notifications 
                WHERE notif_usr_id = :user_id 
                AND notif_is_read = 0
                AND notif_created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['has_new' => $result['new_count'] > 0]);
            exit();
        }
    }
    
    // Check if user is logged in and is an employer
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'employer') {
        // Redirect to login if not authenticated
        header("Location: index.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];

    // Check if auto-approve jobs is enabled
    $auto_approve_jobs_setting = 0;
    try {
        $settings_query = "SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_jobs'";
        $settings_stmt = $conn->prepare($settings_query);
        $settings_stmt->execute();
        if ($settings_row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
            $auto_approve_jobs_setting = $settings_row['setting_value'];
        }
    } catch (Exception $e) {
        // If table doesn't exist yet, use default value
        $auto_approve_jobs_setting = 0;
    }

    // Fetch employer details
    $employer_query = "SELECT u.*, e.* FROM users u 
                      JOIN employers e ON u.usr_id = e.emp_usr_id 
                      WHERE u.usr_id = :user_id";
    $stmt = $conn->prepare($employer_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $employer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employer) {
        header("Location: login.php");
        exit();
    }

    // ENHANCED: Fetch ALL notifications with types and icons (removed LIMIT 8)
    $notification_query = "
        SELECT *,
               CASE 
                   WHEN notif_type = 'application' THEN 'fas fa-file-alt'
                   WHEN notif_type = 'job_status' THEN 'fas fa-briefcase'
                   WHEN notif_type = 'profile_views' THEN 'fas fa-eye'
                   WHEN notif_type = 'system' THEN 'fas fa-cog'
                   WHEN notif_type = 'application_update' THEN 'fas fa-sync-alt'
                   ELSE 'fas fa-bell'
               END as notif_icon,
               CASE 
                   WHEN notif_type = 'application' THEN '#0044ff'
                   WHEN notif_type = 'job_status' THEN '#1f7a11'
                   WHEN notif_type = 'profile_views' THEN '#6a0dad'
                   WHEN notif_type = 'system' THEN '#ffa700'
                   WHEN notif_type = 'application_update' THEN '#d32f2f'
                   ELSE '#6e0303'
               END as notif_color
        FROM notifications 
        WHERE notif_usr_id = :user_id 
        ORDER BY notif_created_at DESC";
    $stmt = $conn->prepare($notification_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count unread notifications
    $unread_notif_count = 0;
    foreach ($notifications as $notif) {
        if (!$notif['notif_is_read']) $unread_notif_count++;
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'create_job') {
                // Create new job
                $title = $_POST['jobTitle'];
                $description = $_POST['jobDescription'];
                $requirements = $_POST['jobRequirements'];
                $location = $_POST['jobLocation'];
                $type = $_POST['jobType'];
                
                // Format salary as Philippine Peso
                $min_salary = isset($_POST['minSalary']) ? preg_replace('/[^0-9]/', '', $_POST['minSalary']) : '';
                $max_salary = isset($_POST['maxSalary']) ? preg_replace('/[^0-9]/', '', $_POST['maxSalary']) : '';
                
                $domain = $_POST['jobDomain'];
                $skills = isset($_POST['skills']) ? implode(',', $_POST['skills']) : '';
                
                // Determine job status based on auto-approve setting
                $job_status = $auto_approve_jobs_setting ? 'active' : 'pending';
                
                $insert_query = "INSERT INTO jobs (job_emp_usr_id, job_title, job_description, job_requirements, job_location, job_type, job_salary_range, job_status, job_domain, job_skills) 
                                VALUES (:user_id, :title, :description, :requirements, :location, :type, :salary_range, :status, :domain, :skills)";
                $stmt = $conn->prepare($insert_query);
                $salary_range = $min_salary . '-' . $max_salary;
                $stmt->bindParam(':user_id', $user_id);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':requirements', $requirements);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':salary_range', $salary_range);
                $stmt->bindParam(':status', $job_status);
                $stmt->bindParam(':domain', $domain);
                $stmt->bindParam(':skills', $skills);
                
                if ($stmt->execute()) {
                    $success_message = $auto_approve_jobs_setting ? 
                        "Job posted successfully! It has been automatically approved and is now live." : 
                        "Job posted successfully! It is now pending admin approval.";
                    
                    // Create appropriate notification
                    if ($auto_approve_jobs_setting) {
                        // Notification for employer about auto-approval
                        $notification_msg = "Your job posting '" . $title . "' has been automatically approved and is now live.";
                        $notif_query = "INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at) VALUES (:user_id, :message, 'job_status', 0, NOW())";
                        $stmt_notif = $conn->prepare($notif_query);
                        $stmt_notif->bindParam(':user_id', $user_id);
                        $stmt_notif->bindParam(':message', $notification_msg);
                        $stmt_notif->execute();
                    } else {
                        // Notification for admin about pending approval
                        $notification_msg = "New job posting from " . $employer['emp_company_name'] . " needs approval: " . $title;
                        $admin_query = "SELECT usr_id FROM users WHERE usr_role = 'admin' LIMIT 1";
                        $admin_stmt = $conn->prepare($admin_query);
                        $admin_stmt->execute();
                        if ($admin_row = $admin_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $admin_id = $admin_row['usr_id'];
                            $notif_query = "INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at) VALUES (:admin_id, :message, 'job_pending', 0, NOW())";
                            $stmt_notif = $conn->prepare($notif_query);
                            $stmt_notif->bindParam(':admin_id', $admin_id);
                            $stmt_notif->bindParam(':message', $notification_msg);
                            $stmt_notif->execute();
                        }
                    }
                } else {
                    $error_message = "Error creating job: " . implode(", ", $stmt->errorInfo());
                }
            } elseif ($_POST['action'] === 'update_job') {
                // Update existing job
                $job_id = $_POST['job_id'];
                $title = $_POST['jobTitle'];
                $description = $_POST['jobDescription'];
                $requirements = $_POST['jobRequirements'];
                $location = $_POST['jobLocation'];
                $type = $_POST['jobType'];
                
                // Format salary as Philippine Peso
                $min_salary = isset($_POST['minSalary']) ? preg_replace('/[^0-9]/', '', $_POST['minSalary']) : '';
                $max_salary = isset($_POST['maxSalary']) ? preg_replace('/[^0-9]/', '', $_POST['maxSalary']) : '';
                
                $domain = $_POST['jobDomain'];
                $skills = isset($_POST['skills']) ? implode(',', $_POST['skills']) : '';
                
                $update_query = "UPDATE jobs SET job_title = :title, job_description = :description, job_requirements = :requirements, job_location = :location, job_type = :type, job_salary_range = :salary_range, job_domain = :domain, job_skills = :skills WHERE job_id = :job_id AND job_emp_usr_id = :user_id";
                $stmt = $conn->prepare($update_query);
                $salary_range = $min_salary . '-' . $max_salary;
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':requirements', $requirements);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':salary_range', $salary_range);
                $stmt->bindParam(':domain', $domain);
                $stmt->bindParam(':skills', $skills);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->bindParam(':user_id', $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Job updated successfully!";
                } else {
                    $error_message = "Error updating job: " . implode(", ", $stmt->errorInfo());
                }
            } elseif ($_POST['action'] === 'close_job') {
                // Close job
                $job_id = $_POST['job_id'];
                
                $close_query = "UPDATE jobs SET job_status = 'closed' WHERE job_id = :job_id AND job_emp_usr_id = :user_id";
                $stmt = $conn->prepare($close_query);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->bindParam(':user_id', $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Job closed successfully!";
                } else {
                    $error_message = "Error closing job: " . implode(", ", $stmt->errorInfo());
                }
            } elseif ($_POST['action'] === 'reopen_job') {
                // Reopen job
                $job_id = $_POST['job_id'];
                
                $reopen_query = "UPDATE jobs SET job_status = 'active' WHERE job_id = :job_id AND job_emp_usr_id = :user_id";
                $stmt = $conn->prepare($reopen_query);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->bindParam(':user_id', $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Job reopened successfully!";
                } else {
                    $error_message = "Error reopening job: " . implode(", ", $stmt->errorInfo());
                }
            } elseif ($_POST['action'] === 'delete_job') {
                // Delete job permanently
                $job_id = $_POST['job_id'];
                
                // First delete related records to maintain referential integrity
                $delete_applications_query = "DELETE FROM applications WHERE app_job_id = :job_id";
                $stmt = $conn->prepare($delete_applications_query);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->execute();
                
                $delete_saved_jobs_query = "DELETE FROM saved_jobs WHERE job_id = :job_id";
                $stmt = $conn->prepare($delete_saved_jobs_query);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->execute();
                
                // Now delete the job
                $delete_job_query = "DELETE FROM jobs WHERE job_id = :job_id AND job_emp_usr_id = :user_id";
                $stmt = $conn->prepare($delete_job_query);
                $stmt->bindParam(':job_id', $job_id);
                $stmt->bindParam(':user_id', $user_id);
                
                if ($stmt->execute()) {
                    $success_message = "Job deleted permanently!";
                } else {
                    $error_message = "Error deleting job: " . implode(", ", $stmt->errorInfo());
                }
            } elseif ($_POST['action'] === 'update_app_status') {
                // Update application status
                $app_id = $_POST['app_id'];
                $status = $_POST['status'];
                
                $update_query = "UPDATE applications SET app_status = :status WHERE app_id = :app_id";
                $stmt = $conn->prepare($update_query);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':app_id', $app_id);
                
                if ($stmt->execute()) {
                    $success_message = "Application status updated successfully!";
                    
                    // Create notification for graduate
                    $grad_notif_query = "SELECT app_grad_usr_id, app_job_id FROM applications WHERE app_id = :app_id";
                    $stmt2 = $conn->prepare($grad_notif_query);
                    $stmt2->bindParam(':app_id', $app_id);
                    $stmt2->execute();
                    $grad_row = $stmt2->fetch(PDO::FETCH_ASSOC);
                    
                    if ($grad_row) {
                        $grad_id = $grad_row['app_grad_usr_id'];
                        $job_id = $grad_row['app_job_id'];
                        
                        // Get job title
                        $job_query = "SELECT job_title FROM jobs WHERE job_id = :job_id";
                        $stmt3 = $conn->prepare($job_query);
                        $stmt3->bindParam(':job_id', $job_id);
                        $stmt3->execute();
                        $job_row = $stmt3->fetch(PDO::FETCH_ASSOC);
                        
                        if ($job_row) {
                            $job_title = $job_row['job_title'];
                            $status_text = ucfirst($status);
                            $notification_msg = "Your application for '{$job_title}' has been updated to: {$status_text}";
                            
                            $notif_query = "INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_is_read, notif_created_at) VALUES (:grad_id, :message, 'application_update', 0, NOW())";
                            $stmt_notif = $conn->prepare($notif_query);
                            $stmt_notif->bindParam(':grad_id', $grad_id);
                            $stmt_notif->bindParam(':message', $notification_msg);
                            $stmt_notif->execute();
                        }
                    }
                } else {
                    $error_message = "Error updating application status: " . implode(", ", $stmt->errorInfo());
                }
            }
        }
    }

    // Handle GET actions (view applicants, view credentials)
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'view_applicants') {
            $job_id = $_GET['job_id'];
            $view_applicants = true;
            
            // Fetch applicants for this job
            $applicants_query = "SELECT a.*, u.usr_name, u.usr_email, u.usr_phone, g.grad_degree, g.grad_year_graduated 
                                FROM applications a 
                                JOIN users u ON a.app_grad_usr_id = u.usr_id 
                                JOIN graduates g ON u.usr_id = g.grad_usr_id 
                                WHERE a.app_job_id = :job_id 
                                ORDER BY a.app_applied_at DESC";
            $stmt = $conn->prepare($applicants_query);
            $stmt->bindParam(':job_id', $job_id);
            $stmt->execute();
            $applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get full job details (enhanced)
            $job_query = "SELECT * FROM jobs WHERE job_id = :job_id";
            $stmt = $conn->prepare($job_query);
            $stmt->bindParam(':job_id', $job_id);
            $stmt->execute();
            $job_details = $stmt->fetch(PDO::FETCH_ASSOC);

            // Parse salary range if exists
            if ($job_details && !empty($job_details['job_salary_range'])) {
                $salary_parts = explode('-', $job_details['job_salary_range']);
                $min_raw = isset($salary_parts[0]) ? preg_replace('/[^0-9]/', '', trim($salary_parts[0])) : 0;
                $max_raw = isset($salary_parts[1]) ? preg_replace('/[^0-9]/', '', trim($salary_parts[1])) : 0;
                $job_details['min_salary'] = '₱' . number_format((float)$min_raw, 0);
                $job_details['max_salary'] = '₱' . number_format((float)$max_raw, 0);
            }

            // Parse skills
            $job_details['skills_array'] = !empty($job_details['job_skills']) ? explode(',', $job_details['job_skills']) : [];

            $job_title = $job_details ? $job_details['job_title'] : '';
        } elseif ($_GET['action'] === 'view_credentials') {
            $app_id = $_GET['app_id'];
            $view_credentials = true;
            
            // Fetch applicant credentials - FIXED QUERY to be compatible with sql_mode=only_full_group_by
            $credentials_query = "SELECT 
                    a.*, 
                    u.usr_name, 
                    u.usr_email, 
                    u.usr_phone, 
                    u.usr_profile_photo, 
                    g.grad_degree, 
                    g.grad_year_graduated, 
                    g.grad_school_id, 
                    g.grad_job_preference,
                    g.grad_summary,
                    GROUP_CONCAT(DISTINCT s.skill_name) as skills
                FROM applications a 
                JOIN users u ON a.app_grad_usr_id = u.usr_id 
                JOIN graduates g ON u.usr_id = g.grad_usr_id 
                LEFT JOIN graduate_skills gs ON u.usr_id = gs.grad_usr_id 
                LEFT JOIN skills s ON gs.skill_id = s.skill_id 
                WHERE a.app_id = :app_id 
                GROUP BY a.app_id, u.usr_id, g.grad_id";
            
            $stmt = $conn->prepare($credentials_query);
            $stmt->bindParam(':app_id', $app_id);
            $stmt->execute();
            $credentials = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // NEW: Fetch application files
            if ($credentials && !empty($credentials['app_files'])) {
                $application_files = json_decode($credentials['app_files'], true);
                $credentials['application_files'] = is_array($application_files) ? $application_files : [];
            } else {
                $credentials['application_files'] = [];
            }
            
            // Fetch portfolio items separately to avoid GROUP BY issues
            if ($credentials) {
                $portfolio_query = "SELECT port_item_title, port_item_description, port_item_file, port_item_type, port_created_at 
                                  FROM portfolio_items 
                                  WHERE port_usr_id = :user_id 
                                  ORDER BY port_item_type, port_created_at DESC";
                $stmt_portfolio = $conn->prepare($portfolio_query);
                $stmt_portfolio->bindParam(':user_id', $credentials['app_grad_usr_id']);
                $stmt_portfolio->execute();
                $portfolio_items = $stmt_portfolio->fetchAll(PDO::FETCH_ASSOC);
                $credentials['portfolio_items'] = $portfolio_items;
                
                // Count documents by type
                $resume_count = 0;
                $certificate_count = 0;
                $project_count = 0;
                foreach ($portfolio_items as $item) {
                    if ($item['port_item_type'] === 'resume') $resume_count++;
                    if ($item['port_item_type'] === 'certificate') $certificate_count++;
                    if ($item['port_item_type'] === 'project') $project_count++;
                }
                $credentials['resume_count'] = $resume_count;
                $credentials['certificate_count'] = $certificate_count;
                $credentials['project_count'] = $project_count;
            }
            
            if (!$credentials) {
                $error_message = "Applicant credentials not found.";
                $view_credentials = false;
            }
        }
    }

    // Fetch employer's jobs
    $jobs_query = "SELECT j.*, COUNT(a.app_id) as application_count 
                   FROM jobs j 
                   LEFT JOIN applications a ON j.job_id = a.app_job_id 
                   WHERE j.job_emp_usr_id = :user_id 
                   GROUP BY j.job_id 
                   ORDER BY j.job_created_at DESC";
    $stmt = $conn->prepare($jobs_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If editing a job, fetch job details
    $editing_job = false;
    $edit_job_data = null;
    if (isset($_GET['action']) && $_GET['action'] === 'edit_job') {
        $job_id = $_GET['job_id'];
        $editing_job = true;
        
        $edit_query = "SELECT * FROM jobs WHERE job_id = :job_id AND job_emp_usr_id = :user_id";
        $stmt = $conn->prepare($edit_query);
        $stmt->bindParam(':job_id', $job_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $edit_job_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Parse salary range
            if (!empty($edit_job_data['job_salary_range'])) {
                $salary_parts = explode('-', $edit_job_data['job_salary_range']);
                $min_raw = isset($salary_parts[0]) ? preg_replace('/[^0-9]/', '', trim($salary_parts[0])) : 0;
                $max_raw = isset($salary_parts[1]) ? preg_replace('/[^0-9]/', '', trim($salary_parts[1])) : 0;
                $edit_job_data['min_salary'] = $min_raw;
                $edit_job_data['max_salary'] = $max_raw;
                
                // Format as Philippine Peso
                $edit_job_data['min_salary_formatted'] = '₱' . number_format((float)$min_raw, 0);
                $edit_job_data['max_salary_formatted'] = '₱' . number_format((float)$max_raw, 0);
            }
            
            // Parse skills
            $edit_job_data['skills_array'] = !empty($edit_job_data['job_skills']) ? explode(',', $edit_job_data['job_skills']) : [];
        } else {
            $error_message = "Job not found or you don't have permission to edit it.";
            $editing_job = false;
        }
    }

    // Handle section for direct access to manage or create
    $section = isset($_GET['section']) ? $_GET['section'] : '';

} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Helper function to get file icon based on file extension
function getFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            return 'fas fa-file-image';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'ppt':
        case 'pptx':
            return 'fas fa-file-powerpoint';
        case 'zip':
        case 'rar':
            return 'fas fa-file-archive';
        default:
            return 'fas fa-file';
    }
}

// Helper function to get file type label
function getFileTypeLabel($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'pdf':
            return 'PDF Document';
        case 'doc':
        case 'docx':
            return 'Word Document';
        case 'jpg':
        case 'jpeg':
            return 'JPEG Image';
        case 'png':
            return 'PNG Image';
        case 'gif':
            return 'GIF Image';
        case 'xls':
        case 'xlsx':
            return 'Excel Spreadsheet';
        case 'ppt':
        case 'pptx':
            return 'PowerPoint Presentation';
        case 'zip':
            return 'ZIP Archive';
        case 'rar':
            return 'RAR Archive';
        default:
            return strtoupper($extension) . ' File';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Job Management</title>
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
        
        /* Animation for new notifications */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .notification-pulse {
            animation: pulse 1s infinite;
        }
        
        /* Choice Cards */
        .choice-container {
            display: flex;
            gap: 25px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .choice-card {
            flex: 1;
            min-width: 300px;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            border-top: 4px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
        }
        
        .choice-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .choice-card.active {
            border-color: var(--primary-color);
            background-color: #fff5f5;
        }
        
        .choice-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #fff5e6;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: var(--secondary-color);
            font-size: 2rem;
            transition: all 0.3s;
        }
        
        .choice-card:hover .choice-icon {
            transform: scale(1.1);
            background: var(--primary-color);
            color: white;
        }
        
        .choice-title {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .choice-description {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        
        .choice-button {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border: none;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 10px rgba(110, 3, 3, 0.2);
        }
        
        .choice-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(110, 3, 3, 0.3);
        }
        
        /* Content Frame Styles */
        .content-frame {
            display: none;
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 30px;
            margin-bottom: 30px;
            min-height: 500px;
            border-top: 4px solid var(--secondary-color);
            position: relative;
        }
        
        .content-frame.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        .frame-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .frame-header h2 {
            color: var(--primary-color);
            font-size: 1.7rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
        
        .frame-header p {
            color: #666;
            margin-top: 8px;
            font-size: 1rem;
        }
        
        .back-button {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 12px 25px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            margin-left: auto;
            transition: all 0.3s;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(110, 3, 3, 0.2);
        }
        
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(110, 3, 3, 0.3);
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .form-group {
            flex: 1 0 calc(50% - 20px);
            margin: 0 10px 20px;
            min-width: 250px;
            position: relative;
        }
        
        .form-group-full {
            flex: 1 0 calc(100% - 20px);
            margin: 0 10px 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: #f9f9f9;
        }
        
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
            background-color: white;
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .salary-input-container {
            position: relative;
        }
        
        .salary-prefix {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-weight: bold;
            z-index: 1;
        }
        
        .salary-input {
            padding-left: 35px;
        }
        
        .skills-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .skill-tag {
            background: linear-gradient(135deg, var(--secondary-color), #ff9900);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            margin-bottom: 5px;
            animation: fadeIn 0.3s ease;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(247, 161, 0, 0.2);
        }
        
        .skill-tag i {
            margin-left: 8px;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .skill-tag i:hover {
            color: var(--red);
        }
        
        .add-skill {
            display: flex;
            margin-top: 15px;
        }
        
        .add-skill input {
            flex: 1;
            margin-right: 10px;
        }
        
        .btn {
            padding: 14px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
            box-shadow: 0 4px 10px rgba(0, 68, 255, 0.2);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 68, 255, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #e0e0e0, #d0d0d0);
            color: #333;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        .btn-add {
            background: linear-gradient(135deg, var(--green), #1a6b0a);
            color: white;
            padding: 10px 20px;
            box-shadow: 0 4px 10px rgba(31, 122, 17, 0.2);
        }
        
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(31, 122, 17, 0.3);
        }
        
        .job-preview {
            margin-top: 30px;
            background: linear-gradient(135deg, #f9f9f9, #f0f0f0);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid #eee;
            transition: all 0.3s;
            overflow-x: auto;
        }
        
        .job-preview:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .preview-title {
            margin-bottom: 15px;
            color: var(--primary-color);
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            min-width: 500px;
        }
        
        .preview-table th, .preview-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .preview-table th {
            background-color: #f0f0f0;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-reviewed {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .status-shortlisted {
            background-color: #e6d9ec;
            color: #4a3c55;
        }
        
        .status-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-hired {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-closed {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .status-qualified {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        /* Jobs Table Styles */
        .jobs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .jobs-table th, .jobs-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .jobs-table th {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
        }
        
        .jobs-table tr {
            transition: background-color 0.3s;
        }
        
        .jobs-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 68, 255, 0.3);
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--green), #1a6b0a);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(31, 122, 17, 0.3);
        }
        
        .btn-close {
            background: linear-gradient(135deg, var(--red), #c0392b);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-close:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(211, 47, 47, 0.3);
        }
        
        .btn-reopen {
            background: linear-gradient(135deg, var(--green), #1a6b0a);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-reopen:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(31, 122, 17, 0.3);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, var(--red), #c0392b);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(211, 47, 47, 0.3);
        }
        
        /* Applicants Table Styles */
        .applicants-table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .applicants-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .applicants-table th, .applicants-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }
        
        .applicants-table th {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .applicants-table tr {
            transition: background-color 0.3s;
        }
        
        .applicants-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .applicant-actions {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            align-items: center;
        }
        
        .applicant-status-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
        }
        
        .status-select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            min-width: 120px;
            max-width: 150px;
            background-color: #f9f9f9;
        }
        
        .btn-update {
            background: linear-gradient(135deg, var(--blue), #0033cc);
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 68, 255, 0.3);
        }
        
        .btn-credentials {
            background: linear-gradient(135deg, var(--purple), #5a0da0);
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-credentials:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(106, 13, 173, 0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Credentials View Styles */
        .credentials-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 25px;
            margin-top: 20px;
        }
        
        .credentials-profile {
            background: linear-gradient(135deg, #f9f9f9, #f0f0f0);
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .credentials-profile img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 20px;
            border: 4px solid var(--primary-color);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .credentials-profile h3 {
            color: var(--primary-color);
            margin-bottom: 8px;
            word-break: break-word;
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        .credentials-profile p {
            color: #666;
            margin-bottom: 8px;
            word-break: break-word;
        }
        
        .credentials-details {
            background: linear-gradient(135deg, #f9f9f9, #f0f0f0);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .credentials-section {
            margin-bottom: 25px;
        }
        
        .credentials-section h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--secondary-color);
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .credentials-item {
            margin-bottom: 12px;
            display: flex;
            flex-wrap: wrap;
        }
        
        .credentials-item strong {
            display: inline-block;
            width: 150px;
            color: #555;
            min-width: 120px;
            font-weight: 600;
        }
        
        .credentials-item span {
            flex: 1;
            min-width: 200px;
        }
        
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .skill-pill {
            background: linear-gradient(135deg, var(--secondary-color), #ff9900);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(247, 161, 0, 0.2);
        }
        
        /* NEW: Application Files Section Styles */
        .application-files-section {
            margin-bottom: 25px;
        }
        
        .application-files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .application-file-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid var(--blue);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
        }
        
        .application-file-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .file-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .file-icon {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.5rem;
            color: white;
        }
        
        .file-icon.pdf {
            background: linear-gradient(45deg, #f40f02, #ff6b6b);
        }
        
        .file-icon.word {
            background: linear-gradient(45deg, #2b579a, #4a7bc8);
        }
        
        .file-icon.image {
            background: linear-gradient(45deg, #9b59b6, #8e44ad);
        }
        
        .file-icon.excel {
            background: linear-gradient(45deg, #1d6f42, #27ae60);
        }
        
        .file-icon.powerpoint {
            background: linear-gradient(45deg, #d24726, #e74c3c);
        }
        
        .file-icon.archive {
            background: linear-gradient(45deg, #f39c12, #f1c40f);
        }
        
        .file-icon.default {
            background: linear-gradient(45deg, #7f8c8d, #95a5a6);
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .file-type {
            font-size: 0.8rem;
            color: #666;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
        }
        
        /* UPDATED: View button with sidebar color */
        .btn-view-file {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            font-weight: 500;
            flex: 1;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(110, 3, 3, 0.2);
        }
        
        .btn-view-file:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(110, 3, 3, 0.3);
            color: white;
            text-decoration: none;
        }
        
        /* Document Portfolio Styles */
        .documents-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .document-stat {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 150px;
            transition: all 0.3s;
        }
        
        .document-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .document-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.4rem;
        }
        
        .document-stat-icon.resume {
            background: linear-gradient(45deg, var(--primary-color), #8a0404);
        }
        
        .document-stat-icon.certificate {
            background: linear-gradient(45deg, var(--green), #0f5e0a);
        }
        
        .document-stat-icon.project {
            background: linear-gradient(45deg, var(--blue), #0033cc);
        }
        
        .document-stat-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .document-stat-number {
            font-weight: 700;
            font-size: 1.4rem;
            color: var(--primary-color);
        }
        
        .document-stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .portfolio-item {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 5px solid var(--blue);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .portfolio-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .portfolio-item h5 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .portfolio-item .item-type {
            background-color: #e9ecef;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 0.8rem;
            margin-right: 10px;
            font-weight: 500;
        }
        
        .portfolio-item .item-date {
            font-size: 0.8rem;
            color: #666;
            margin-left: auto;
        }
        
        .portfolio-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
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
        
        /* Back Button Container */
        .back-button-container {
            display: flex;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        /* Modal Styles */
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
        
        .modal {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 500px;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            overflow: hidden;
        }
        
        .modal-overlay.active .modal {
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
        }
        
        .modal-body {
            padding: 25px;
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
        
        .btn-modal-danger {
            background: linear-gradient(135deg, var(--red), #c0392b);
            color: white;
        }
        
        .btn-modal-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(211, 47, 47, 0.3);
        }
        
        /* Job Details Styles (Enhanced) */
        .job-details-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
            margin-top: 20px;
            margin-bottom: 30px;
        }
        
        .job-details {
            background: linear-gradient(135deg, #f9f9f9, #f0f0f0);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .job-details-section {
            margin-bottom: 25px;
        }
        
        .job-details-section h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--secondary-color);
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .job-details-item {
            margin-bottom: 12px;
            display: flex;
            flex-wrap: wrap;
        }
        
        .job-details-item strong {
            display: inline-block;
            width: 150px;
            color: #555;
            min-width: 120px;
            font-weight: 600;
        }
        
        .job-details-item span {
            flex: 1;
            min-width: 200px;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-cards {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
            
            .credentials-container {
                grid-template-columns: 1fr;
            }
            
            .application-files-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }
        
        @media (max-width: 900px) {
            .dashboard-cards {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
            
            .applicants-table {
                min-width: 600px;
            }
            
            .application-files-grid {
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
            
            .choice-container {
                flex-direction: column;
            }
            
            .form-group {
                flex: 1 0 calc(100% - 20px);
            }
            
            .notification-dropdown {
                width: 350px;
                right: -100px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                margin-bottom: 10px;
            }
            
            .applicant-status-form {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .applicant-actions {
                flex-direction: column;
            }
            
            .credentials-item strong {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .back-button {
                width: 100%;
                justify-content: center;
            }
            
            .documents-stats {
                justify-content: center;
            }
            
            .document-stat {
                flex: 1;
                min-width: 120px;
            }
            
            .file-actions {
                flex-direction: column;
            }
            
            .modal {
                width: 95%;
                margin: 10px;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .btn-modal {
                width: 100%;
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
            
            .content-frame {
                padding: 20px;
            }
            
            .frame-header h2 {
                font-size: 1.4rem;
            }
            
            .choice-card {
                padding: 20px;
            }
            
            .choice-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .choice-title {
                font-size: 1.3rem;
            }
            
            .application-file-card {
                padding: 15px;
            }
            
            .file-header {
                flex-direction: column;
                text-align: center;
            }
            
            .file-icon {
                margin-right: 0;
                margin-bottom: 10px;
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
                    <a href="employer_jobs.php" class="active">
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
            <p>Create and Manage your Jobs here.</p>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Job Management</h1>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success" id="successAlert"><?= $success_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error" id="errorAlert"><?= $error_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($view_credentials) && $view_credentials): ?>
        <!-- Credentials View Frame -->
        <div class="content-frame active" id="credentialsFrame">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-file-alt"></i> Applicant Credentials: <?= htmlspecialchars($credentials['usr_name']) ?></h2>
                    <p>View the credentials and portfolio of this applicant.</p>
                </div>
            </div>
            
            <div class="credentials-container">
                <div class="credentials-profile">
                    <img src="<?= !empty($credentials['usr_profile_photo']) ? htmlspecialchars($credentials['usr_profile_photo']) : 'https://ui-avatars.com/api/?name=' . urlencode($credentials['usr_name']) . '&background=random' ?>" alt="Applicant Photo">
                    <h3><?= htmlspecialchars($credentials['usr_name']) ?></h3>
                    <p><?= htmlspecialchars($credentials['usr_email']) ?></p>
                    <p><?= htmlspecialchars($credentials['usr_phone']) ?></p>
                </div>
                
                <div class="credentials-details">
                    <!-- NEW: Application Files Section -->
                    <?php if (!empty($credentials['application_files'])): ?>
                    <div class="application-files-section">
                        <h4>Application Files</h4>
                        <p class="credentials-item">The following files were submitted with this application:</p>
                        <div class="application-files-grid">
                            <?php foreach ($credentials['application_files'] as $file): 
                                $filename = basename($file);
                                $file_icon = getFileIcon($filename);
                                $file_type = getFileTypeLabel($filename);
                                $icon_class = '';
                                
                                // Determine icon class based on file type
                                if (strpos($file_icon, 'pdf') !== false) $icon_class = 'pdf';
                                elseif (strpos($file_icon, 'word') !== false) $icon_class = 'word';
                                elseif (strpos($file_icon, 'image') !== false) $icon_class = 'image';
                                elseif (strpos($file_icon, 'excel') !== false) $icon_class = 'excel';
                                elseif (strpos($file_icon, 'powerpoint') !== false) $icon_class = 'powerpoint';
                                elseif (strpos($file_icon, 'archive') !== false) $icon_class = 'archive';
                                else $icon_class = 'default';
                            ?>
                            <div class="application-file-card">
                                <div class="file-header">
                                    <div class="file-icon <?= $icon_class ?>">
                                        <i class="<?= $file_icon ?>"></i>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name"><?= htmlspecialchars($filename) ?></div>
                                        <div class="file-type"><?= $file_type ?></div>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <!-- UPDATED: Single View button with sidebar color -->
                                    <a href="<?= htmlspecialchars($file) ?>" target="_blank" class="btn-view-file">
                                        <i class="fas fa-eye"></i> View File
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($credentials['app_message'])): ?>
                    <div class="credentials-section">
                        <h4>Applicant Message</h4>
                        <div class="credentials-item">
                            <p><?= nl2br(htmlspecialchars($credentials['app_message'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="credentials-section">
                        <h4>Education</h4>
                        <div class="credentials-item">
                            <strong>Degree:</strong> 
                            <span><?= htmlspecialchars($credentials['grad_degree']) ?></span>
                        </div>
                        <div class="credentials-item">
                            <strong>Alumni ID:</strong> 
                            <span><?= htmlspecialchars($credentials['grad_school_id']) ?></span>
                        </div>
                        <div class="credentials-item">
                            <strong>Graduation Year:</strong> 
                            <span><?= htmlspecialchars($credentials['grad_year_graduated']) ?></span>
                        </div>
                    </div>
                    
                    <div class="credentials-section">
                        <h4>Job Preferences</h4>
                        <div class="credentials-item">
                            <strong>Preferred Job:</strong> 
                            <span><?= htmlspecialchars($credentials['grad_job_preference']) ?></span>
                        </div>
                    </div>
                    
                    <?php if (!empty($credentials['grad_summary'])): ?>
                    <div class="credentials-section">
                        <h4>Career Summary/Objective</h4>
                        <div class="credentials-item">
                            <span><?= nl2br(htmlspecialchars($credentials['grad_summary'])) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($credentials['skills'])): ?>
                    <div class="credentials-section">
                        <h4>Skills</h4>
                        <div class="skills-list">
                            <?php 
                            $skills = explode(',', $credentials['skills']);
                            foreach ($skills as $skill): 
                            ?>
                                <span class="skill-pill"><?= htmlspecialchars(trim($skill)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Document Portfolio Section -->
                    <div class="credentials-section">
                        <h4>Document Portfolio</h4>
                        
                        <?php if ($credentials['resume_count'] > 0 || $credentials['certificate_count'] > 0 || $credentials['project_count'] > 0): ?>
                        <div class="documents-stats">
                            <?php if ($credentials['resume_count'] > 0): ?>
                            <div class="document-stat">
                                <div class="document-stat-icon resume">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <div class="document-stat-info">
                                    <div class="document-stat-number"><?= $credentials['resume_count'] ?></div>
                                    <div class="document-stat-label">Resumes</div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($credentials['certificate_count'] > 0): ?>
                            <div class="document-stat">
                                <div class="document-stat-icon certificate">
                                    <i class="fas fa-certificate"></i>
                                </div>
                                <div class="document-stat-info">
                                    <div class="document-stat-number"><?= $credentials['certificate_count'] ?></div>
                                    <div class="document-stat-label">Certificates</div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($credentials['project_count'] > 0): ?>
                            <div class="document-stat">
                                <div class="document-stat-icon project">
                                    <i class="fas fa-project-diagram"></i>
                                </div>
                                <div class="document-stat-info">
                                    <div class="document-stat-number"><?= $credentials['project_count'] ?></div>
                                    <div class="document-stat-label">Projects</div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($credentials['portfolio_items'])): ?>
                            <?php foreach ($credentials['portfolio_items'] as $portfolio): ?>
                            <div class="portfolio-item">
                                <h5>
                                    <span class="item-type"><?= htmlspecialchars($portfolio['port_item_type']) ?></span>
                                    <?= htmlspecialchars($portfolio['port_item_title']) ?>
                                    <span class="item-date"><?= date('M j, Y', strtotime($portfolio['port_created_at'])) ?></span>
                                </h5>
                                <?php if (!empty($portfolio['port_item_description'])): ?>
                                <p><?= htmlspecialchars($portfolio['port_item_description']) ?></p>
                                <?php endif; ?>
                                <div class="portfolio-actions">
                                    <!-- UPDATED: Single View button with sidebar color -->
                                    <a href="<?= htmlspecialchars($portfolio['port_item_file']) ?>" target="_blank" class="btn-view-file">
                                        <i class="fas fa-eye"></i> View Document
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #6c757d;">
                                <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                                <p>No documents uploaded yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="back-button-container">
                <button class="back-button" onclick="window.location.href='employer_jobs.php?action=view_applicants&job_id=<?= $credentials['app_job_id'] ?>'">
                    <i class="fas fa-arrow-left"></i> Back to Applicants
                </button>
            </div>
        </div>
        
        <?php elseif (isset($view_applicants) && $view_applicants): ?>
        <!-- Applicants View Frame -->
        <div class="content-frame active" id="applicantsFrame">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-users"></i> Applicants for: <?= htmlspecialchars($job_title) ?></h2>
                    <p>View and manage applicants for this job posting.</p>
                </div>
            </div>
            
            <!-- Enhanced: Job Details Section -->
            <?php if ($job_details): ?>
            <div class="job-details-container">
                <div class="job-details">
                    <div class="job-details-section">
                        <h4>Job Details</h4>
                        <div class="job-details-item">
                            <strong>Title:</strong> 
                            <span><?= htmlspecialchars($job_details['job_title']) ?></span>
                        </div>
                        <div class="job-details-item">
                            <strong>Domain:</strong> 
                            <span><?= htmlspecialchars($job_details['job_domain']) ?></span>
                        </div>
                        <div class="job-details-item">
                            <strong>Type:</strong> 
                            <span><?= ucfirst(htmlspecialchars($job_details['job_type'])) ?></span>
                        </div>
                        <div class="job-details-item">
                            <strong>Location:</strong> 
                            <span><?= htmlspecialchars($job_details['job_location']) ?></span>
                        </div>
                        <div class="job-details-item">
                            <strong>Salary Range:</strong> 
                            <span><?= $job_details['min_salary'] ?> - <?= $job_details['max_salary'] ?></span>
                        </div>
                        <div class="job-details-item">
                            <strong>Status:</strong> 
                            <span class="status-badge <?= 'status-' . strtolower($job_details['job_status']) ?>"><?= strtoupper($job_details['job_status']) ?></span>
                        </div>
                        <div class="job-details-item">
                            <strong>Posted Date:</strong> 
                            <span><?= date('Y-m-d', strtotime($job_details['job_created_at'])) ?></span>
                        </div>
                    </div>
                    
                    <div class="job-details-section">
                        <h4>Description</h4>
                        <p><?= nl2br(htmlspecialchars($job_details['job_description'])) ?></p>
                    </div>
                    
                    <div class="job-details-section">
                        <h4>Requirements</h4>
                        <p><?= nl2br(htmlspecialchars($job_details['job_requirements'])) ?></p>
                    </div>
                    
                    <?php if (!empty($job_details['skills_array'])): ?>
                    <div class="job-details-section">
                        <h4>Required Skills</h4>
                        <div class="skills-list">
                            <?php foreach ($job_details['skills_array'] as $skill): ?>
                                <span class="skill-pill"><?= htmlspecialchars(trim($skill)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($applicants)): ?>
            <div class="applicants-table-container">
                <table class="applicants-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Degree</th>
                            <th>Status</th>
                            <th>Applied Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applicants as $applicant): ?>
                        <tr>
                            <td><?= htmlspecialchars($applicant['usr_name']) ?></td>
                            <td><?= htmlspecialchars($applicant['grad_degree']) ?></td>
                            <td>
                                <?php 
                                $status_class = '';
                                switch($applicant['app_status']) {
                                    case 'pending': $status_class = 'status-pending'; break;
                                    case 'reviewed': $status_class = 'status-reviewed'; break;
                                    case 'shortlisted': $status_class = 'status-shortlisted'; break;
                                    case 'rejected': $status_class = 'status-rejected'; break;
                                    case 'hired': $status_class = 'status-hired'; break;
                                    case 'qualified': $status_class = 'status-qualified'; break;
                                    default: $status_class = 'status-pending';
                                }
                                ?>
                                <span class="status-badge <?= $status_class ?>"><?= strtoupper($applicant['app_status']) ?></span>
                            </td>
                            <td><?= date('Y-m-d', strtotime($applicant['app_applied_at'])) ?></td>
                            <td>
                                <div class="applicant-actions">
                                    <a href="employer_jobs.php?action=view_credentials&app_id=<?= $applicant['app_id'] ?>" class="btn-credentials">
                                        <i class="fas fa-file-alt"></i> Credentials
                                    </a>
                                    <form method="POST" action="" class="applicant-status-form">
                                        <input type="hidden" name="action" value="update_app_status">
                                        <input type="hidden" name="app_id" value="<?= $applicant['app_id'] ?>">
                                        <select name="status" class="status-select">
                                            <option value="pending" <?= $applicant['app_status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                            <option value="reviewed" <?= $applicant['app_status'] == 'reviewed' ? 'selected' : '' ?>>Under Review</option>
                                            <option value="qualified" <?= $applicant['app_status'] == 'qualified' ? 'selected' : '' ?>>Qualified</option>
                                            <option value="shortlisted" <?= $applicant['app_status'] == 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                                            <option value="rejected" <?= $applicant['app_status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                            <option value="hired" <?= $applicant['app_status'] == 'hired' ? 'selected' : '' ?>>Hired</option>
                                        </select>
                                        <button type="submit" class="btn-update">Update</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 40px 20px; color: #6c757d;">
                <i class="fas fa-user-times" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                <h3>No applicants yet</h3>
                <p>This job posting doesn't have any applicants at the moment.</p>
            </div>
            <?php endif; ?>
            
            <div class="back-button-container">
                <button class="back-button" onclick="window.location.href='employer_jobs.php'">
                    <i class="fas fa-arrow-left"></i> Back to Jobs
                </button>
            </div>
        </div>
        
        <?php elseif ($editing_job): ?>
        <!-- Edit Job Frame -->
        <div class="content-frame active" id="editJobFrame">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-edit"></i> Edit Job: <?= htmlspecialchars($edit_job_data['job_title']) ?></h2>
                    <p>Update the job details below.</p>
                </div>
            </div>
            
            <form id="jobEditForm" method="POST" action="">
                <input type="hidden" name="action" value="update_job">
                <input type="hidden" name="job_id" value="<?= $edit_job_data['job_id'] ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="jobTitle" class="form-label">Job Title</label>
                        <input type="text" id="jobTitle" name="jobTitle" class="form-input" placeholder="Enter job title" value="<?= htmlspecialchars($edit_job_data['job_title']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="jobDomain" class="form-label">Job Domain</label>
                        <select id="jobDomain" name="jobDomain" class="form-select" required>
                            <option value="">Select domain</option>
                            <option value="Software Dev" <?= $edit_job_data['job_domain'] == 'Software Dev' ? 'selected' : '' ?>>Software Development</option>
                            <option value="Finance" <?= $edit_job_data['job_domain'] == 'Finance' ? 'selected' : '' ?>>Finance</option>
                            <option value="Healthcare" <?= $edit_job_data['job_domain'] == 'Healthcare' ? 'selected' : '' ?>>Healthcare</option>
                            <option value="Education" <?= $edit_job_data['job_domain'] == 'Education' ? 'selected' : '' ?>>Education</option>
                            <option value="Marketing" <?= $edit_job_data['job_domain'] == 'Marketing' ? 'selected' : '' ?>>Marketing</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label for="jobDescription" class="form-label">Job Description</label>
                    <textarea id="jobDescription" name="jobDescription" class="form-textarea" placeholder="Enter job description" required><?= htmlspecialchars($edit_job_data['job_description']) ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="minSalary" class="form-label">Salary Range (Min)</label>
                        <div class="salary-input-container">
                            <span class="salary-prefix">₱</span>
                            <input type="text" id="minSalary" name="minSalary" class="form-input salary-input" placeholder="Min salary" value="<?= $edit_job_data['min_salary_formatted'] ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="maxSalary" class="form-label">Salary Range (Max)</label>
                        <div class="salary-input-container">
                            <span class="salary-prefix">₱</span>
                            <input type="text" id="maxSalary" name="maxSalary" class="form-input salary-input" placeholder="Max salary" value="<?= $edit_job_data['max_salary_formatted'] ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label class="form-label">Required Skills</label>
                    <div class="skills-container" id="skillsContainer">
                        <?php foreach ($edit_job_data['skills_array'] as $skill): ?>
                        <div class="skill-tag">
                            <?= htmlspecialchars($skill) ?>
                            <i class="fas fa-times" onclick="removeSkill('<?= htmlspecialchars($skill) ?>')"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="skillsInput" name="skills[]" value="<?= htmlspecialchars(implode(',', $edit_job_data['skills_array'])) ?>">
                    <div class="add-skill">
                        <input type="text" id="newSkill" class="form-input" placeholder="Add a skill">
                        <button type="button" class="btn btn-add" id="addSkillBtn">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label for="jobRequirements" class="form-label">Requirements</label>
                    <textarea id="jobRequirements" name="jobRequirements" class="form-textarea" placeholder="List job requirements and qualifications" required><?= htmlspecialchars($edit_job_data['job_requirements']) ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="jobLocation" class="form-label">Location</label>
                        <input type="text" id="jobLocation" name="jobLocation" class="form-input" placeholder="e.g., Cebu City" value="<?= htmlspecialchars($edit_job_data['job_location']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="jobType" class="form-label">Job Type</label>
                        <select id="jobType" name="jobType" class="form-select" required>
                            <option value="full-time" <?= $edit_job_data['job_type'] == 'full-time' ? 'selected' : '' ?>>Full-time</option>
                            <option value="part-time" <?= $edit_job_data['job_type'] == 'part-time' ? 'selected' : '' ?>>Part-time</option>
                            <option value="contract" <?= $edit_job_data['job_type'] == 'contract' ? 'selected' : '' ?>>Contract</option>
                            <option value="internship" <?= $edit_job_data['job_type'] == 'internship' ? 'selected' : '' ?>>Internship</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='employer_jobs.php'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Job</button>
                </div>
            </form>
            
            <div class="back-button-container">
                <button class="back-button" onclick="window.location.href='employer_jobs.php'">
                    <i class="fas fa-arrow-left"></i> Back to Jobs
                </button>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Choice Cards (DEFAULT VIEW) -->
        <div class="choice-container" id="choiceContainer">
            <div class="choice-card active" id="createJobCard">
                <div class="choice-icon">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <h3 class="choice-title">Create New Job</h3>
                <p class="choice-description">Post a new job opportunity for alumni. Fill out the job details and submit for approval.</p>
                <button class="choice-button" id="createJobBtn">Create New Job</button>
            </div>
            
            <div class="choice-card" id="manageJobsCard">
                <div class="choice-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <h3 class="choice-title">Manage Jobs</h3>
                <p class="choice-description">View, edit, or close your existing job postings. Track applications and update job status.</p>
                <button class="choice-button" id="manageJobsBtn">Manage Jobs</button>
            </div>
        </div>
        
        <!-- Create Job Frame (initially hidden) -->
        <div class="content-frame" id="createJobFrame">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-plus-circle"></i> Create a new job listing for approval and matching</h2>
                    <p>Fill out the form below to create a new job posting.</p>
                </div>
            </div>
            
            <form id="jobPostForm" method="POST" action="">
                <input type="hidden" name="action" value="create_job">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="jobTitle" class="form-label">Job Title</label>
                        <input type="text" id="jobTitle" name="jobTitle" class="form-input" placeholder="Enter job title" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="jobDomain" class="form-label">Job Domain</label>
                        <select id="jobDomain" name="jobDomain" class="form-select" required>
                            <option value="">Select domain</option>
                            <option value="Software Dev">Software Development</option>
                            <option value="Finance">Finance</option>
                            <option value="Healthcare">Healthcare</option>
                            <option value="Education">Education</option>
                            <option value="Marketing">Marketing</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label for="jobDescription" class="form-label">Job Description</label>
                    <textarea id="jobDescription" name="jobDescription" class="form-textarea" placeholder="Enter job description" required></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="minSalary" class="form-label">Salary Range (Min)</label>
                        <div class="salary-input-container">
                            <span class="salary-prefix">₱</span>
                            <input type="text" id="minSalary" name="minSalary" class="form-input salary-input" placeholder="Min salary" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="maxSalary" class="form-label">Salary Range (Max)</label>
                        <div class="salary-input-container">
                            <span class="salary-prefix">₱</span>
                            <input type="text" id="maxSalary" name="maxSalary" class="form-input salary-input" placeholder="Max salary" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label class="form-label">Required Skills</label>
                    <div class="skills-container" id="skillsContainer">
                        <!-- Skills will be added here dynamically -->
                    </div>
                    <input type="hidden" id="skillsInput" name="skills[]">
                    <div class="add-skill">
                        <input type="text" id="newSkill" class="form-input" placeholder="Add a skill">
                        <button type="button" class="btn btn-add" id="addSkillBtn">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
                
                <div class="form-group form-group-full">
                    <label for="jobRequirements" class="form-label">Requirements</label>
                    <textarea id="jobRequirements" name="jobRequirements" class="form-textarea" placeholder="List job requirements and qualifications" required></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="jobLocation" class="form-label">Location</label>
                        <input type="text" id="jobLocation" name="jobLocation" class="form-input" placeholder="e.g., Cebu City" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="jobType" class="form-label">Job Type</label>
                        <select id="jobType" name="jobType" class="form-select" required>
                            <option value="full-time">Full-time</option>
                            <option value="part-time">Part-time</option>
                            <option value="contract">Contract</option>
                            <option value="internship">Internship</option>
                        </select>
                    </div>
                </div>
                
                <div class="job-preview">
                    <h3 class="preview-title">Sample Job Entry (Preview)</h3>
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Skills Required</th>
                                <th>Domain</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="previewTitle">Junior Dev</td>
                                <td id="previewSkills">Python, JavaScript</td>
                                <td id="previewDomain">Software Dev</td>
                                <td>
                                    <span class="status-badge <?= $auto_approve_jobs_setting ? 'status-active' : 'status-pending' ?>" id="previewStatus">
                                        <?= $auto_approve_jobs_setting ? 'ACTIVE' : 'PENDING' ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="form-actions">
                    <button type="reset" class="btn btn-secondary">Reset Form</button>
                    <button type="submit" class="btn btn-primary">Post Job</button>
                </div>
            </form>
            
            <div class="back-button-container">
                <button class="back-button" id="backFromCreateBtn">
                    <i class="fas fa-arrow-left"></i> Back to Options
                </button>
            </div>
        </div>
        
        <!-- Manage Jobs Frame (initially hidden) -->
        <div class="content-frame" id="manageJobsFrame">
            <div class="frame-header">
                <div>
                    <h2><i class="fas fa-tasks"></i> Manage Your Job Postings</h2>
                    <p>View, edit, or close your existing job postings. Click on a job title to view applications.</p>
                </div>
            </div>
            
            <?php if (!empty($jobs)): ?>
            <table class="jobs-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Posted Date</th>
                        <th>Applications</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= htmlspecialchars($job['job_title']) ?></td>
                        <td><?= date('Y-m-d', strtotime($job['job_created_at'])) ?></td>
                        <td><?= $job['application_count'] ?></td>
                        <td>
                            <?php 
                            $status_class = '';
                            switch($job['job_status']) {
                                case 'active': $status_class = 'status-active'; break;
                                case 'pending': $status_class = 'status-pending'; break;
                                case 'closed': $status_class = 'status-closed'; break;
                                default: $status_class = 'status-pending';
                            }
                            ?>
                            <span class="status-badge <?= $status_class ?>"><?= strtoupper($job['job_status']) ?></span>
                        </td>
                        <td class="action-buttons">
                            <?php if ($job['job_status'] == 'closed'): ?>
                                <!-- Show Reopen and Delete buttons for closed jobs -->
                                <button class="btn-reopen" onclick="showReopenModal(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_title']) ?>')">Reopen</button>
                                <button class="btn-delete" onclick="showDeleteModal(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_title']) ?>')">Delete</button>
                            <?php else: ?>
                                <!-- Show normal action buttons for active/pending jobs -->
                                <button class="btn-view" onclick="viewApplicants(<?= $job['job_id'] ?>)">View</button>
                                <button class="btn-edit" onclick="editJob(<?= $job['job_id'] ?>)">Edit</button>
                                <button class="btn-close" onclick="showCloseModal(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_title']) ?>')">Close</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="text-align: center; padding: 40px 20px; color: #6c757d;">
                <i class="fas fa-briefcase" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                <h3>No job postings found</h3>
                <p>You haven't created any job postings yet. Start by creating your first job!</p>
                <button class="choice-button" id="createFirstJobBtn" style="margin-top: 20px;">
                    <i class="fas fa-plus-circle"></i> Create Your First Job
                </button>
            </div>
            <?php endif; ?>
            
            <div class="back-button-container">
                <button class="back-button" id="backFromManageBtn">
                    <i class="fas fa-arrow-left"></i> Back to Options
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Confirmation Modals -->
    <!-- Close Job Modal -->
    <div class="modal-overlay" id="closeModal">
        <div class="modal">
            <div class="modal-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Close Job Posting</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to close the job posting: <strong id="closeJobTitle"></strong>?</p>
                <p>Once closed, the job will no longer be visible to applicants.</p>
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-cancel" onclick="hideModal('closeModal')">Cancel</button>
                <button class="btn-modal btn-modal-danger" id="confirmCloseBtn">Close Job</button>
            </div>
        </div>
    </div>

    <!-- Reopen Job Modal -->
    <div class="modal-overlay" id="reopenModal">
        <div class="modal">
            <div class="modal-header">
                <i class="fas fa-redo"></i>
                <h3>Reopen Job Posting</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reopen the job posting: <strong id="reopenJobTitle"></strong>?</p>
                <p>Once reopened, the job will become visible to applicants again.</p>
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-cancel" onclick="hideModal('reopenModal')">Cancel</button>
                <button class="btn-modal btn-modal-confirm" id="confirmReopenBtn">Reopen Job</button>
            </div>
        </div>
    </div>

    <!-- Delete Job Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Delete Job Posting</h3>
            </div>
            <div class="modal-body">
                <p>Are you absolutely sure you want to permanently delete the job posting: <strong id="deleteJobTitle"></strong>?</p>
                <p style="color: var(--red); font-weight: bold;">This action cannot be undone and will remove all associated applications.</p>
            </div>
            <div class="modal-footer">
                <button class="btn-modal btn-modal-cancel" onclick="hideModal('deleteModal')">Cancel</button>
                <button class="btn-modal btn-modal-danger" id="confirmDeleteBtn">Delete Permanently</button>
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
        
        // ENHANCED: Mark notifications as read functionality
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
            
            // ENHANCED: Check for new notifications periodically
            setInterval(checkNewNotifications, 30000); // Check every 30 seconds
            
            function checkNewNotifications() {
                const formData = new FormData();
                formData.append('ajax_action', 'check_new_notifications');
                
                fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.has_new) {
                        // Show visual indicator for new notifications
                        const bellIcon = document.querySelector('.notification i');
                        bellIcon.classList.add('notification-pulse');
                        
                        setTimeout(() => {
                            bellIcon.classList.remove('notification-pulse');
                        }, 3000);
                    }
                })
                .catch(error => {
                    console.error('Error checking for new notifications:', error);
                });
            }
        });
        
        // Frame Selection Functionality
        const choiceContainer = document.getElementById('choiceContainer');
        const createJobBtn = document.getElementById('createJobBtn');
        const manageJobsBtn = document.getElementById('manageJobsBtn');
        const createJobFrame = document.getElementById('createJobFrame');
        const manageJobsFrame = document.getElementById('manageJobsFrame');
        const backFromCreateBtn = document.getElementById('backFromCreateBtn');
        const backFromManageBtn = document.getElementById('backFromManageBtn');
        const createFirstJobBtn = document.getElementById('createFirstJobBtn');
        
        createJobBtn.addEventListener('click', function() {
            choiceContainer.style.display = 'none';
            createJobFrame.classList.add('active');
            manageJobsFrame.classList.remove('active');
        });
        
        manageJobsBtn.addEventListener('click', function() {
            choiceContainer.style.display = 'none';
            manageJobsFrame.classList.add('active');
            createJobFrame.classList.remove('active');
        });
        
        if (createFirstJobBtn) {
            createFirstJobBtn.addEventListener('click', function() {
                choiceContainer.style.display = 'none';
                createJobFrame.classList.add('active');
                manageJobsFrame.classList.remove('active');
            });
        }
        
        backFromCreateBtn.addEventListener('click', function() {
            createJobFrame.classList.remove('active');
            choiceContainer.style.display = 'flex';
        });
        
        backFromManageBtn.addEventListener('click', function() {
            manageJobsFrame.classList.remove('active');
            choiceContainer.style.display = 'flex';
        });
        
        // Skills Management
        const skillsContainer = document.getElementById('skillsContainer');
        const skillsInput = document.getElementById('skillsInput');
        const newSkillInput = document.getElementById('newSkill');
        const addSkillBtn = document.getElementById('addSkillBtn');
        let skills = [];
        
        <?php if ($editing_job): ?>
        // Initialize skills for edit mode
        skills = <?= json_encode($edit_job_data['skills_array']) ?>;
        <?php endif; ?>
        
        addSkillBtn.addEventListener('click', function() {
            const skill = newSkillInput.value.trim();
            if (skill && !skills.includes(skill)) {
                addSkill(skill);
                skills.push(skill);
                updateSkillsInput();
                newSkillInput.value = '';
                updatePreview();
            }
        });
        
        function addSkill(skill) {
            const skillTag = document.createElement('div');
            skillTag.className = 'skill-tag';
            skillTag.innerHTML = `
                ${skill}
                <i class="fas fa-times" onclick="removeSkill('${skill}')"></i>
            `;
            skillsContainer.appendChild(skillTag);
        }
        
        function removeSkill(skill) {
            skills = skills.filter(s => s !== skill);
            updateSkillsInput();
            const skillTags = skillsContainer.querySelectorAll('.skill-tag');
            skillTags.forEach(tag => {
                if (tag.textContent.includes(skill)) {
                    tag.remove();
                }
            });
            updatePreview();
        }
        
        function updateSkillsInput() {
            skillsInput.value = skills.join(',');
        }
        
        // Format salary inputs as Philippine Peso
        function formatSalaryInput(input) {
            // Remove non-numeric characters
            let value = input.value.replace(/[^0-9]/g, '');
            
            // Format with Philippine Peso symbol and commas
            if (value) {
                input.value = '₱' + parseInt(value).toLocaleString('en-PH');
            } else {
                input.value = '';
            }
        }
        
        // Add event listeners to format salary inputs
        document.addEventListener('DOMContentLoaded', function() {
            const salaryInputs = document.querySelectorAll('.salary-input');
            salaryInputs.forEach(input => {
                // Format on blur
                input.addEventListener('blur', function() {
                    formatSalaryInput(this);
                });
                
                // Remove formatting on focus for easier editing
                input.addEventListener('focus', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            });
        });
        
        // Form Preview Update
        function updatePreview() {
            const title = document.getElementById('jobTitle')?.value || 'Junior Dev';
            const domain = document.getElementById('jobDomain')?.value || 'Software Dev';
            
            if (document.getElementById('previewTitle')) {
                document.getElementById('previewTitle').textContent = title;
                document.getElementById('previewDomain').textContent = domain;
                
                // Update skills preview
                document.getElementById('previewSkills').textContent = skills.join(', ') || 'Python, JavaScript';
                
                // Update status based on auto-approval setting
                const autoApprove = <?= $auto_approve_jobs_setting ? 'true' : 'false' ?>;
                const statusElement = document.getElementById('previewStatus');
                if (autoApprove) {
                    statusElement.className = 'status-badge status-active';
                    statusElement.textContent = 'ACTIVE';
                } else {
                    statusElement.className = 'status-badge status-pending';
                    statusElement.textContent = 'PENDING';
                }
            }
        }
        
        // Add event listeners to update preview
        document.getElementById('jobTitle')?.addEventListener('input', updatePreview);
        document.getElementById('jobDomain')?.addEventListener('change', updatePreview);
        
        // Form Submission
        document.getElementById('jobPostForm')?.addEventListener('submit', function(e) {
            // Format salary inputs before submission
            const minSalaryInput = document.getElementById('minSalary');
            const maxSalaryInput = document.getElementById('maxSalary');
            
            if (minSalaryInput) minSalaryInput.value = minSalaryInput.value.replace(/[^0-9]/g, '');
            if (maxSalaryInput) maxSalaryInput.value = maxSalaryInput.value.replace(/[^0-9]/g, '');
            
            // Validation is handled by HTML5 required attributes
            // The form will submit to the same page and PHP will process it
        });
        
        document.getElementById('jobEditForm')?.addEventListener('submit', function(e) {
            // Format salary inputs before submission
            const minSalaryInput = document.getElementById('minSalary');
            const maxSalaryInput = document.getElementById('maxSalary');
            
            if (minSalaryInput) minSalaryInput.value = minSalaryInput.value.replace(/[^0-9]/g, '');
            if (maxSalaryInput) maxSalaryInput.value = maxSalaryInput.value.replace(/[^0-9]/g, '');
        });
        
        // Job management functions
        function viewApplicants(jobId) {
            window.location.href = 'employer_jobs.php?action=view_applicants&job_id=' + jobId;
        }
        
        function editJob(jobId) {
            window.location.href = 'employer_jobs.php?action=edit_job&job_id=' + jobId;
        }
        
        // Modal Management
        let currentJobId = null;
        
        function showModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            currentJobId = null;
        }
        
        function showCloseModal(jobId, jobTitle) {
            currentJobId = jobId;
            document.getElementById('closeJobTitle').textContent = jobTitle;
            showModal('closeModal');
        }
        
        function showReopenModal(jobId, jobTitle) {
            currentJobId = jobId;
            document.getElementById('reopenJobTitle').textContent = jobTitle;
            showModal('reopenModal');
        }
        
        function showDeleteModal(jobId, jobTitle) {
            currentJobId = jobId;
            document.getElementById('deleteJobTitle').textContent = jobTitle;
            showModal('deleteModal');
        }
        
        // Modal event listeners
        document.getElementById('confirmCloseBtn').addEventListener('click', function() {
            if (currentJobId) {
                // Submit form to close the job
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'close_job';
                form.appendChild(actionInput);
                
                const jobIdInput = document.createElement('input');
                jobIdInput.type = 'hidden';
                jobIdInput.name = 'job_id';
                jobIdInput.value = currentJobId;
                form.appendChild(jobIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
        
        document.getElementById('confirmReopenBtn').addEventListener('click', function() {
            if (currentJobId) {
                // Submit form to reopen the job
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'reopen_job';
                form.appendChild(actionInput);
                
                const jobIdInput = document.createElement('input');
                jobIdInput.type = 'hidden';
                jobIdInput.name = 'job_id';
                jobIdInput.value = currentJobId;
                form.appendChild(jobIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
        
        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (currentJobId) {
                // Submit form to delete the job
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_job';
                form.appendChild(actionInput);
                
                const jobIdInput = document.createElement('input');
                jobIdInput.type = 'hidden';
                jobIdInput.name = 'job_id';
                jobIdInput.value = currentJobId;
                form.appendChild(jobIdInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    hideModal(this.id);
                }
            });
        });
        
        // Initialize with some skills if not in edit mode
        window.onload = function() {
            <?php if (!$editing_job && !isset($view_applicants) && !isset($view_credentials)): ?>
            // Only add default skills if we're in the create job form
            if (document.getElementById('skillsContainer') && document.getElementById('skillsContainer').children.length === 0) {
                addSkill('Python');
                addSkill('JavaScript');
                skills = ['Python', 'JavaScript'];
                updateSkillsInput();
                updatePreview();
            }
            <?php endif; ?>
            
            // Show alerts if they exist
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
        };
    </script>
</body>
</html>