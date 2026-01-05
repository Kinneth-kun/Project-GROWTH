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
    
    // Check if admin exists, if not create one
    $adminCheck = $conn->query("SELECT COUNT(*) FROM users WHERE usr_role = 'admin'")->fetchColumn();
    if ($adminCheck == 0) {
        $adminPassword = password_hash('admin123', PASSWORD_BCRYPT);
        $conn->exec("INSERT INTO users (usr_name, usr_email, usr_password, usr_role, usr_gender, usr_birthdate, usr_is_approved, usr_account_status) 
                    VALUES ('Admin User', 'admin@ctu.edu.ph', '$adminPassword', 'admin', 'Male', '1980-01-01', TRUE, 'active')");
    }
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Check if user is logged in as admin
if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// ============================================================================
// ENHANCED NOTIFICATION GENERATION SYSTEM - FIXED AND IMPROVED
// ============================================================================

/**
 * Function to create notifications for admin
 */
function createAdminNotification($conn, $type, $message, $related_id = null) {
    // Get all admin users
    $adminStmt = $conn->query("SELECT usr_id FROM users WHERE usr_role = 'admin'");
    $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($admins as $adminId) {
        $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                               VALUES (?, ?, ?, NOW())");
        $stmt->execute([$adminId, $message, $type]);
    }
}

/**
 * Check for new user registrations and generate notifications - FIXED VERSION
 */
function checkNewUserRegistrations($conn) {
    // Get the last notification time for user registrations
    $lastNotifStmt = $conn->query("SELECT MAX(notif_created_at) FROM notifications WHERE notif_type = 'new_user_registration'");
    $lastNotif = $lastNotifStmt->fetchColumn();
    
    $newUsers = [];
    
    // Build query based on whether we have previous notifications
    if ($lastNotif) {
        $sql = "SELECT usr_id, usr_name, usr_role, usr_email, usr_created_at
                FROM users 
                WHERE usr_created_at > ? 
                AND usr_role != 'admin'
                AND usr_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) -- Limit to last 7 days to avoid old users
                ORDER BY usr_created_at DESC";
        $newUsersStmt = $conn->prepare($sql);
        $newUsersStmt->execute([$lastNotif]);
        $newUsers = $newUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // If no previous notifications, check last 24 hours
        $newUsers = $conn->query("
            SELECT usr_id, usr_name, usr_role, usr_email, usr_created_at
            FROM users 
            WHERE usr_created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND usr_role != 'admin'
            ORDER BY usr_created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (!empty($newUsers)) {
        // Create notification for each new user
        foreach ($newUsers as $user) {
            $message = "New {$user['usr_role']} registered: {$user['usr_name']} ({$user['usr_email']})";
            
            // Insert notification for all admins
            $stmt = $conn->prepare("INSERT INTO notifications (notif_usr_id, notif_message, notif_type, notif_created_at) 
                                   SELECT usr_id, ?, 'new_user_registration', NOW()
                                   FROM users WHERE usr_role = 'admin'");
            $stmt->execute([$message]);
        }
        
        // Create summary notification
        $userCounts = [];
        foreach ($newUsers as $user) {
            $role = $user['usr_role'];
            $userCounts[$role] = ($userCounts[$role] ?? 0) + 1;
        }
        
        $summaryParts = [];
        foreach ($userCounts as $role => $count) {
            $summaryParts[] = "$count $role" . ($count > 1 ? "s" : "");
        }
        
        $summaryMessage = "New user registrations: " . implode(", ", $summaryParts);
        createAdminNotification($conn, 'new_user_summary', $summaryMessage);
        
        return count($newUsers);
    }
    
    return 0;
}

/**
 * Check for pending approvals and generate notifications - ENHANCED VERSION
 */
function checkPendingApprovals($conn) {
    $notificationsGenerated = 0;
    
    // 1. Check for pending employer approvals
    $pendingEmployers = $conn->query("SELECT COUNT(*) as count FROM users WHERE usr_role = 'employer' AND usr_is_approved = FALSE")->fetch(PDO::FETCH_ASSOC);
    
    if ($pendingEmployers['count'] > 0) {
        // Check if notification already exists for today
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'employer_approval' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'employer_approval', 
                "{$pendingEmployers['count']} employer(s) awaiting approval. Review pending registrations.");
            $notificationsGenerated++;
        }
    }
    
    // 2. Check for pending job approvals
    $pendingJobs = $conn->query("SELECT COUNT(*) as count FROM jobs WHERE job_status = 'pending'")->fetch(PDO::FETCH_ASSOC);
    
    if ($pendingJobs['count'] > 0) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'job_approval' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'job_approval', 
                "{$pendingJobs['count']} job posting(s) awaiting approval. Review pending job posts.");
            $notificationsGenerated++;
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Check for profile issues and generate notifications - ENHANCED VERSION
 */
function checkProfileIssues($conn) {
    // Check for incomplete graduate profiles
    $incompleteProfiles = $conn->query("
        SELECT COUNT(*) as count FROM users u 
        LEFT JOIN graduates g ON u.usr_id = g.grad_usr_id 
        WHERE u.usr_role = 'graduate' 
        AND (g.grad_id IS NULL OR g.grad_school_id = '' OR g.grad_degree = '' OR g.grad_job_preference = '')
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($incompleteProfiles['count'] > 0) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'profile_issue' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'profile_issue', 
                "{$incompleteProfiles['count']} graduate profile(s) are incomplete. Review portfolio issues.");
            return 1;
        }
    }
    
    return 0;
}

/**
 * Check for application trends and generate notifications - ENHANCED VERSION
 */
function checkApplicationTrends($conn) {
    // Check for application spikes (last hour)
    $recentApplications = $conn->query("
        SELECT COUNT(*) as count FROM applications 
        WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($recentApplications['count'] >= 5) { // Lowered threshold for testing
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'application_spike' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'application_spike', 
                "Application spike: {$recentApplications['count']} applications in the last hour.");
            return 1;
        }
    }
    
    // Check for new applications since last check
    $lastAppNotif = $conn->query("SELECT MAX(notif_created_at) FROM notifications WHERE notif_type = 'new_application'")->fetchColumn();
    
    if ($lastAppNotif) {
        $newApplications = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at > '$lastAppNotif'
        ")->fetch(PDO::FETCH_ASSOC);
    } else {
        $newApplications = $conn->query("
            SELECT COUNT(*) as count FROM applications 
            WHERE app_applied_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($newApplications['count'] > 0) {
        createAdminNotification($conn, 'new_application', 
            "{$newApplications['count']} new application(s) submitted.");
        return 1;
    }
    
    return 0;
}

/**
 * Check for system analytics and generate notifications - FIXED VERSION
 */
function checkSystemAnalytics($conn) {
    $notificationsGenerated = 0;
    
    // Check employment rate trends
    $employmentRate = $conn->query("
        SELECT 
            COUNT(*) as total_graduates,
            SUM(CASE WHEN a.app_status = 'hired' THEN 1 ELSE 0 END) as hired_graduates
        FROM graduates g
        LEFT JOIN users u ON g.grad_usr_id = u.usr_id
        LEFT JOIN applications a ON u.usr_id = a.app_grad_usr_id
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($employmentRate['total_graduates'] > 0) {
        $employmentPercentage = round(($employmentRate['hired_graduates'] / $employmentRate['total_graduates']) * 100);
        
        if ($employmentPercentage >= 70) {
            $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                            WHERE notif_type = 'employment_success' 
                                            AND DATE(notif_created_at) = CURDATE()");
            $existingNotif->execute();
            
            if ($existingNotif->fetchColumn() == 0) {
                createAdminNotification($conn, 'employment_success', 
                    "Great news! Employment rate is at {$employmentPercentage}% among graduates.");
                $notificationsGenerated++;
            }
        } elseif ($employmentPercentage <= 30) {
            $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                            WHERE notif_type = 'employment_low' 
                                            AND DATE(notif_created_at) = CURDATE()");
            $existingNotif->execute();
            
            if ($existingNotif->fetchColumn() == 0) {
                createAdminNotification($conn, 'employment_low', 
                    "Employment rate is low at {$employmentPercentage}%. Consider career support initiatives.");
                $notificationsGenerated++;
            }
        }
    }
    
    // Check top skills in demand - FIXED: Using job_skills column from jobs table instead of non-existent job_skills table
    $topSkillsQuery = $conn->query("
        SELECT job_skills, COUNT(*) as job_count
        FROM jobs 
        WHERE job_status = 'active' 
        AND job_skills IS NOT NULL 
        AND job_skills != ''
        GROUP BY job_skills
        ORDER BY job_count DESC
        LIMIT 1
    ");
    
    $topSkills = $topSkillsQuery->fetch(PDO::FETCH_ASSOC);
    
    if ($topSkills && $topSkills['job_count'] >= 3) {
        $existingNotif = $conn->prepare("SELECT COUNT(*) FROM notifications 
                                        WHERE notif_type = 'skill_demand' 
                                        AND DATE(notif_created_at) = CURDATE()");
        $existingNotif->execute();
        
        if ($existingNotif->fetchColumn() == 0) {
            createAdminNotification($conn, 'skill_demand', 
                "High demand for '{$topSkills['job_skills']}' skill ({$topSkills['job_count']} job postings).");
            $notificationsGenerated++;
        }
    }
    
    return $notificationsGenerated;
}

/**
 * Enhanced system notifications generator - MAIN FUNCTION
 */
function generateSystemNotifications($conn) {
    $totalNotifications = 0;
    
    // 1. Check for new user registrations
    $totalNotifications += checkNewUserRegistrations($conn);
    
    // 2. Check for pending approvals
    $totalNotifications += checkPendingApprovals($conn);
    
    // 3. Check for profile issues
    $totalNotifications += checkProfileIssues($conn);
    
    // 4. Check for application trends
    $totalNotifications += checkApplicationTrends($conn);
    
    // 5. Check for system analytics
    $totalNotifications += checkSystemAnalytics($conn);
    
    return $totalNotifications;
}

// Generate notifications on every page load
$notificationsGenerated = generateSystemNotifications($conn);

// ============================================================================
// EXISTING PROFILE MANAGEMENT FUNCTIONALITY
// ============================================================================

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Refresh page to update notification count
    header("Location: admin_profile.php");
    exit();
}

// Handle mark single notification as read via AJAX
if (isset($_POST['mark_as_read']) && isset($_POST['notif_id'])) {
    $notif_id = $_POST['notif_id'];
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET notif_is_read = TRUE WHERE notif_id = :notif_id AND notif_usr_id = :admin_id");
    $mark_read_stmt->bindParam(':notif_id', $notif_id);
    $mark_read_stmt->bindParam(':admin_id', $_SESSION['user_id']);
    $mark_read_stmt->execute();
    
    // Return success response
    echo json_encode(['success' => true]);
    exit();
}

// Get admin user details
$admin_id = $_SESSION['user_id'];
$admin_stmt = $conn->prepare("SELECT * FROM users WHERE usr_id = :id");
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_user = $admin_stmt->fetch(PDO::FETCH_ASSOC);

// Parse full name into components
$last_name = '';
$first_name = '';
$middle_name = '';

if (!empty($admin_user['usr_name'])) {
    $name_parts = explode(' ', $admin_user['usr_name']);
    $name_count = count($name_parts);
    
    if ($name_count >= 1) {
        $last_name = $name_parts[0];
    }
    if ($name_count >= 2) {
        $first_name = $name_parts[1];
    }
    if ($name_count >= 3) {
        $middle_name = implode(' ', array_slice($name_parts, 2));
    }
}

// Handle profile update
$update_success = false;
$update_error = false;
$error_message = '';

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_photo'])) {
    if (isset($_POST['cropped_image']) && !empty($_POST['cropped_image'])) {
        // Handle cropped image upload
        $cropped_image_data = $_POST['cropped_image'];
        
        // Remove the data:image/png;base64, part
        $cropped_image_data = str_replace('data:image/png;base64,', '', $cropped_image_data);
        $cropped_image_data = str_replace(' ', '+', $cropped_image_data);
        
        // Decode the base64 data
        $image_data = base64_decode($cropped_image_data);
        
        if ($image_data !== false) {
            // Generate unique filename
            $new_file_name = "admin_" . $admin_id . "_" . time() . ".png";
            $upload_path = "uploads/profile_photos/" . $new_file_name;
            
            // Create directory if it doesn't exist
            if (!file_exists('uploads/profile_photos')) {
                mkdir('uploads/profile_photos', 0777, true);
            }
            
            // Save the image
            if (file_put_contents($upload_path, $image_data)) {
                // Update database with photo path
                $update_photo_stmt = $conn->prepare("UPDATE users SET usr_profile_photo = :photo WHERE usr_id = :id");
                $update_photo_stmt->bindParam(':photo', $new_file_name);
                $update_photo_stmt->bindParam(':id', $admin_id);
                
                if ($update_photo_stmt->execute()) {
                    $update_success = true;
                    // Refresh admin data
                    $admin_stmt->execute();
                    $admin_user = $admin_stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $update_error = true;
                    $error_message = "Error updating database.";
                }
            } else {
                $update_error = true;
                $error_message = "Error saving cropped image.";
            }
        } else {
            $update_error = true;
            $error_message = "Invalid image data.";
        }
    } else {
        $update_error = true;
        $error_message = "No cropped image data received.";
    }
}

// Handle profile information update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $country_code = trim($_POST['country_code'] ?? '+63');
    $phone = filter_var($_POST['phone'], FILTER_SANITIZE_STRING);
    $gender = $_POST['gender'];
    $birthdate = $_POST['birthdate'];
    
    // Combine names into full name
    $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : "") . "$last_name");
    
    // Enhanced phone number validation - CHANGED TO 10-11 DIGITS
    $phone = preg_replace('/[^0-9]/', '', $phone); // Remove any non-numeric characters
    
    // Validate phone number length - CHANGED TO 10-11 DIGITS
    if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 11)) {
        $update_error = true;
        $error_message = "Phone number must be between 10 and 11 digits.";
    } else {
        // Combine country code and phone number
        $full_phone = $country_code . $phone;
        
        // Validate required fields
        if (empty($last_name) || empty($first_name)) {
            $update_error = true;
            $error_message = "Both Last Name and First Name are required.";
        } else {
            try {
                // Check if phone number is already in use by another user
                $phone_check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_phone = :phone AND usr_id != :admin_id");
                $phone_check_stmt->bindParam(':phone', $full_phone);
                $phone_check_stmt->bindParam(':admin_id', $admin_id);
                $phone_check_stmt->execute();
                
                if ($phone_check_stmt->rowCount() > 0) {
                    $update_error = true;
                    $error_message = "This phone number is already registered with another account. Please use a different phone number.";
                } else {
                    // Check if email is already in use by another user
                    $email_check_stmt = $conn->prepare("SELECT usr_id FROM users WHERE usr_email = :email AND usr_id != :admin_id");
                    $email_check_stmt->bindParam(':email', $email);
                    $email_check_stmt->bindParam(':admin_id', $admin_id);
                    $email_check_stmt->execute();
                    
                    if ($email_check_stmt->rowCount() > 0) {
                        $update_error = true;
                        $error_message = "This email address is already registered with another account. Please use a different email address.";
                    } else {
                        $update_stmt = $conn->prepare("UPDATE users SET usr_name = :name, usr_email = :email, usr_phone = :phone, usr_gender = :gender, usr_birthdate = :birthdate WHERE usr_id = :id");
                        $update_stmt->bindParam(':name', $full_name);
                        $update_stmt->bindParam(':email', $email);
                        $update_stmt->bindParam(':phone', $full_phone);
                        $update_stmt->bindParam(':gender', $gender);
                        $update_stmt->bindParam(':birthdate', $birthdate);
                        $update_stmt->bindParam(':id', $admin_id);
                        
                        if ($update_stmt->execute()) {
                            $update_success = true;
                            // Refresh admin data
                            $admin_stmt->execute();
                            $admin_user = $admin_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Update session user name
                            $_SESSION['user_name'] = $full_name;
                        } else {
                            $update_error = true;
                            $error_message = "Error updating profile.";
                        }
                    }
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Integrity constraint violation
                    $update_error = true;
                    $error_message = "This phone number or email is already registered with another account. Please use different contact information.";
                } else {
                    $update_error = true;
                    $error_message = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Function to determine where a notification should link to based on its content
function getNotificationLink($notification) {
    $message = strtolower($notification['notif_message']);
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'system_settings') !== false || 
        strpos($type, 'system_alert') !== false || 
        strpos($message, 'setting') !== false || 
        strpos($message, 'maintenance') !== false || 
        strpos($message, 'auto-approve') !== false ||
        strpos($message, 'password') !== false) {
        return 'admin_system.php';
    } elseif (strpos($type, 'user') !== false || strpos($message, 'user') !== false || strpos($message, 'registered') !== false) {
        return 'admin_manage_users.php';
    } elseif (strpos($type, 'job') !== false || strpos($message, 'job') !== false || strpos($message, 'posting') !== false) {
        return 'admin_job_post.php';
    } elseif (strpos($type, 'employer') !== false || strpos($message, 'employer') !== false) {
        return 'admin_employers.php';
    } elseif (strpos($type, 'portfolio') !== false || strpos($message, 'portfolio') !== false || strpos($type, 'profile') !== false) {
        return 'admin_portfolio.php';
    } elseif (strpos($type, 'report') !== false || strpos($message, 'report') !== false || strpos($type, 'analytics') !== false) {
        return 'admin_reports.php';
    } elseif (strpos($type, 'application') !== false || strpos($message, 'application') !== false) {
        return 'admin_job_post.php';
    } elseif (strpos($type, 'skill') !== false || strpos($message, 'skill') !== false) {
        return 'admin_reports.php';
    } elseif (strpos($type, 'employment') !== false || strpos($message, 'employment') !== false) {
        return 'admin_reports.php';
    } else {
        return 'admin_dashboard.php';
    }
}

// Function to get notification icon based on type
function getNotificationIcon($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'user') !== false) {
        return 'fas fa-user-plus';
    } elseif (strpos($type, 'job') !== false) {
        return 'fas fa-briefcase';
    } elseif (strpos($type, 'employer') !== false) {
        return 'fas fa-building';
    } elseif (strpos($type, 'application') !== false) {
        return 'fas fa-file-alt';
    } elseif (strpos($type, 'portfolio') !== false || strpos($type, 'profile') !== false) {
        return 'fas fa-file-contract';
    } elseif (strpos($type, 'system') !== false || strpos($type, 'security') !== false || strpos($type, 'setting') !== false) {
        return 'fas fa-cog';
    } elseif (strpos($type, 'skill') !== false) {
        return 'fas fa-tools';
    } elseif (strpos($type, 'employment') !== false) {
        return 'fas fa-chart-line';
    } else {
        return 'fas fa-bell';
    }
}

// Function to get notification priority based on type
function getNotificationPriority($notification) {
    $type = strtolower($notification['notif_type']);
    
    if (strpos($type, 'system') !== false || 
        strpos($type, 'security') !== false || 
        strpos($type, 'spike') !== false ||
        strpos($type, 'alert') !== false) {
        return 'high';
    } elseif (strpos($type, 'approval') !== false || 
              strpos($type, 'pending') !== false ||
              strpos($type, 'issue') !== false) {
        return 'medium';
    } else {
        return 'low';
    }
}

// Get notification count for admin
$notif_stmt = $conn->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE notif_usr_id = :admin_id AND notif_is_read = FALSE");
$notif_stmt->bindParam(':admin_id', $admin_id);
$notif_stmt->execute();
$notification_count = $notif_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

// Get recent notifications for admin
$recent_notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE notif_usr_id = :admin_id ORDER BY notif_created_at DESC LIMIT 10");
$recent_notif_stmt->bindParam(':admin_id', $admin_id);
$recent_notif_stmt->execute();
$recent_notifications = $recent_notif_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get profile photo path or use default
$profile_photo = "https://ui-avatars.com/api/?name=" . urlencode($admin_user['usr_name']) . "&background=3498db&color=fff";
if (!empty($admin_user['usr_profile_photo']) && file_exists("uploads/profile_photos/" . $admin_user['usr_profile_photo'])) {
    $profile_photo = "uploads/profile_photos/" . $admin_user['usr_profile_photo'];
}

// ============================================================================
// ENHANCED PHONE NUMBER PARSING - MATCHING EMPLOYER PROFILE CODE
// ============================================================================

// Extract country code and phone number from stored phone
$stored_phone = $admin_user['usr_phone'] ?? '';
$country_code = '+63';
$phone_number = '';

if (!empty($stored_phone)) {
    // Extract country code (assuming format like +639123456789)
    if (strpos($stored_phone, '+') === 0) {
        // Find where the country code ends (after +63)
        $country_code = substr($stored_phone, 0, 3); // +63
        $phone_number = substr($stored_phone, 3); // rest of the number
    } else {
        $phone_number = $stored_phone;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTU-PESO - Admin Profile</title>
    <link rel="icon" type="image/png" href="images/ctu.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
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
        
        .admin-role {
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
        
        /* Profile Container */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Profile Card Styles */
        .profile-card {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            transition: all 0.3s;
            border-top: 4px solid var(--secondary-color);
            position: relative;
            overflow: hidden;
        }
        
        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 25px;
            border: 4px solid var(--primary-color);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .profile-info h2 {
            margin-bottom: 8px;
            color: var(--primary-color);
            font-size: 1.8rem;
            font-weight: 700;
        }
        
        .profile-info p {
            color: #666;
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .profile-role {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        /* Profile Stats */
        .profile-stats {
            margin-top: 20px;
        }
        
        .profile-stats h3 {
            font-size: 1.3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary-color);
        }
        
        .profile-stats p {
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .profile-stats p:last-child {
            border-bottom: none;
        }
        
        .profile-stats strong {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        /* Photo Upload Section */
        .photo-upload {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        
        .photo-preview {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-color);
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .file-input {
            display: none;
        }
        
        .file-label {
            padding: 12px 25px;
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .file-label:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .upload-info {
            font-size: 0.85rem;
            color: #666;
            margin-top: 12px;
            text-align: center;
            line-height: 1.5;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1rem;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: #f8f9fa;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(110, 3, 3, 0.1);
            background-color: white;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8a0404);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #8a0404, #6e0303);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Name Container Styles */
        .name-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        
        /* Enhanced Phone Container Styles - Matching Employer Profile */
        .phone-container {
            display: flex;
            gap: 10px;
        }
        
        .country-code {
            width: 120px;
            flex-shrink: 0;
        }
        
        .phone-number {
            flex: 1;
        }
        
        .phone-requirements {
            font-size: 0.85rem;
            color: #666;
            margin-top: 8px;
            font-style: italic;
        }
        
        .required {
            color: var(--red);
            font-weight: 600;
        }
        
        /* Form Section Title */
        .form-section-title {
            font-size: 1.4rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary-color);
            font-weight: 700;
        }
        
        /* Cropper Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-title {
            font-size: 1.5rem;
            color: var(--primary-color);
            font-weight: 700;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #666;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .close-modal:hover {
            color: var(--red);
        }
        
        .cropper-container {
            width: 100%;
            height: 400px;
            margin-bottom: 20px;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .cropper-image {
            max-width: 100%;
            max-height: 400px;
        }
        
        .cropper-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
        }
        
        .cropper-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-color);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        }
        
        .cropper-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
            
            .name-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }
            
            .sidebar-header h3, .sidebar-menu span, .admin-role, .menu-label {
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
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-avatar {
                margin-right: 0;
                margin-bottom: 20px;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -100px;
            }
            
            .name-container {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .phone-container {
                flex-direction: column;
                gap: 8px;
            }
            
            .country-code {
                width: 100%;
            }
            
            .photo-preview {
                width: 150px;
                height: 150px;
            }
            
            .cropper-controls {
                flex-direction: column;
                gap: 15px;
            }
            
            .cropper-preview {
                width: 120px;
                height: 120px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .profile-card {
                padding: 20px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -140px;
            }
            
            .photo-preview {
                width: 120px;
                height: 120px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                padding: 20px;
                width: 95%;
            }
            
            .cropper-container {
                height: 300px;
            }
        }
    </style>
</head>
<body>
    <!-- Enhanced Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="<?= $profile_photo ?>" alt="Admin">
            <div class="sidebar-header-text">
                <h3><?= htmlspecialchars($admin_user['usr_name']) ?></h3>
                <div class="admin-role">System Administrator</div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <ul>
                <li>
                    <a href="admin_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="admin_manage_users.php">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                </li>
                <li>
                    <a href="admin_job_post.php">
                        <i class="fas fa-briefcase"></i>
                        <span>Job Posts</span>
                    </a>
                </li>
                <li>
                    <a href="admin_employers.php">
                        <i class="fas fa-building"></i>
                        <span>Employers</span>
                    </a>
                </li>
                <li>
                    <a href="admin_portfolio.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Portfolio</span>
                    </a>
                </li>
            </ul>
            
            <div class="menu-label">System</div>
            <ul>
                <li>
                    <a href="admin_reports.php">
                        <i class="fas fa-file-pdf"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li>
                    <a href="admin_system.php">
                        <i class="fas fa-cog"></i>
                        <span>System</span>
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
                    <?php if ($notification_count > 0): ?>
                    <span class="notification-count" id="notificationCount"><?= $notification_count ?></span>
                    <?php endif; ?>
                    <div class="dropdown notification-dropdown" id="notificationDropdown">
                        <div class="dropdown-header">
                            Notifications
                            <?php if ($notification_count > 0): ?>
                            <form method="POST" style="display: inline;">
                                <button type="submit" name="mark_all_read" class="mark-all-read">Mark all as read</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($recent_notifications)): ?>
                            <?php foreach ($recent_notifications as $notif): 
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
                <div class="admin-profile" id="adminProfile">
                    <img src="<?= $profile_photo ?>" alt="Admin">
                    <span class="admin-name"><?= htmlspecialchars($admin_user['usr_name']) ?></span>
                    <i class="fas fa-chevron-down"></i>
                    <div class="dropdown profile-dropdown" id="profileDropdown">
                        <a href="admin_profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="admin_settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <a href="index.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Welcome Message -->
        <div class="welcome-message">
            <h2>Welcome back, <?= htmlspecialchars($admin_user['usr_name']) ?>!</h2>
            <p>Here you can manage your profile information and account settings. <?= $notificationsGenerated > 0 ? "Generated {$notificationsGenerated} new notifications." : "" ?></p>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Admin Profile</h1>
        </div>
        
        <?php if ($update_success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Profile updated successfully!
        </div>
        <?php endif; ?>
        
        <?php if ($update_error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
        </div>
        <?php endif; ?>
        
        <div class="profile-container">
            <div class="profile-card">
                <div class="profile-header">
                    <img src="<?= $profile_photo ?>" alt="Admin Avatar" class="profile-avatar">
                    <div class="profile-info">
                        <h2><?= htmlspecialchars($admin_user['usr_name']) ?></h2>
                        <p><?= htmlspecialchars($admin_user['usr_email']) ?></p>
                        <p><?= htmlspecialchars($admin_user['usr_phone']) ?></p>
                        <span class="profile-role"><?= ucfirst($admin_user['usr_role']) ?></span>
                    </div>
                </div>
                
                <div class="profile-stats">
                    <h3>Account Information</h3>
                    <p><strong>Status:</strong> <span style="color: var(--green); font-weight: 600;">Active</span></p>
                    <p><strong>Member since:</strong> <?= date('F j, Y', strtotime($admin_user['usr_created_at'])) ?></p>
                    <p><strong>Last login:</strong> 
                        <?= $admin_user['usr_last_login'] ? date('F j, Y g:i A', strtotime($admin_user['usr_last_login'])) : 'Never' ?>
                    </p>
                </div>
                
                <div class="photo-upload">
                    <img src="<?= $profile_photo ?>" alt="Profile Photo" class="photo-preview" id="photoPreview">
                    <input type="file" name="profile_photo" id="profilePhoto" class="file-input" accept="image/*">
                    <label for="profilePhoto" class="file-label">
                        <i class="fas fa-upload"></i> Choose Photo
                    </label>
                    <div class="upload-info">Max file size: 2MB<br>Supported formats: JPG, PNG, GIF</div>
                    <form method="POST" action="" id="cropForm">
                        <input type="hidden" name="cropped_image" id="croppedImage">
                        <button type="submit" name="update_photo" class="btn btn-primary" style="margin-top: 15px;" id="uploadBtn" disabled>
                            <i class="fas fa-save"></i> Update Photo
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="profile-card">
                <h3 class="form-section-title">Edit Profile Information</h3>
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <div class="name-container">
                            <div>
                                <label class="form-label" for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-control" 
                                       value="<?= htmlspecialchars($last_name) ?>" 
                                       required placeholder="Last Name">
                            </div>
                            <div>
                                <label class="form-label" for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-control" 
                                       value="<?= htmlspecialchars($first_name) ?>" 
                                       required placeholder="First Name">
                            </div>
                            <div>
                                <label class="form-label" for="middle_name">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" class="form-control" 
                                       value="<?= htmlspecialchars($middle_name) ?>" 
                                       placeholder="Middle Name">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($admin_user['usr_email']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <div class="phone-container">
                            <select id="country_code" name="country_code" class="form-control country-code">
                                <option value="+63" <?= $country_code === '+63' ? 'selected' : '' ?>>+63 (PH)</option>
                                <option value="+1" <?= $country_code === '+1' ? 'selected' : '' ?>>+1 (US)</option>
                                <option value="+44" <?= $country_code === '+44' ? 'selected' : '' ?>>+44 (UK)</option>
                                <option value="+61" <?= $country_code === '+61' ? 'selected' : '' ?>>+61 (AU)</option>
                                <option value="+65" <?= $country_code === '+65' ? 'selected' : '' ?>>+65 (SG)</option>
                                <option value="+60" <?= $country_code === '+60' ? 'selected' : '' ?>>+60 (MY)</option>
                                <option value="+66" <?= $country_code === '+66' ? 'selected' : '' ?>>+66 (TH)</option>
                                <option value="+84" <?= $country_code === '+84' ? 'selected' : '' ?>>+84 (VN)</option>
                                <option value="+81" <?= $country_code === '+81' ? 'selected' : '' ?>>+81 (JP)</option>
                                <option value="+82" <?= $country_code === '+82' ? 'selected' : '' ?>>+82 (KR)</option>
                                <option value="+86" <?= $country_code === '+86' ? 'selected' : '' ?>>+86 (CN)</option>
                                <option value="+91" <?= $country_code === '+91' ? 'selected' : '' ?>>+91 (IN)</option>
                                <option value="+971" <?= $country_code === '+971' ? 'selected' : '' ?>>+971 (AE)</option>
                                <option value="+973" <?= $country_code === '+973' ? 'selected' : '' ?>>+973 (BH)</option>
                                <option value="+966" <?= $country_code === '+966' ? 'selected' : '' ?>>+966 (SA)</option>
                                <option value="+20" <?= $country_code === '+20' ? 'selected' : '' ?>>+20 (EG)</option>
                                <option value="+27" <?= $country_code === '+27' ? 'selected' : '' ?>>+27 (ZA)</option>
                            </select>
                            <input type="tel" id="phone" name="phone" class="form-control phone-number" 
                                value="<?= htmlspecialchars($phone_number) ?>" 
                                placeholder="9234567890" maxlength="11" pattern="[0-9]{10,11}" 
                                title="Please enter 10-11 digits only (numbers only)">
                        </div>
                        <div class="phone-requirements">Enter 10-11 digits only (numbers only)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="gender">Gender <span class="required">*</span></label>
                        <select id="gender" name="gender" class="form-control" required>
                            <option value="Male" <?= $admin_user['usr_gender'] == 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $admin_user['usr_gender'] == 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="birthdate">Birthdate <span class="required">*</span></label>
                        <input type="date" id="birthdate" name="birthdate" class="form-control" value="<?= $admin_user['usr_birthdate'] ?>" required>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Image Cropper Modal -->
    <div class="modal" id="cropperModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Crop Profile Photo</h3>
                <button class="close-modal" id="closeModal">&times;</button>
            </div>
            <div class="cropper-container">
                <img id="imageToCrop" class="cropper-image">
            </div>
            <div class="cropper-controls">
                <div class="cropper-preview">
                    <div id="cropperPreview"></div>
                </div>
                <div class="cropper-actions">
                    <button class="btn btn-secondary" id="cancelCrop">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="btn btn-primary" id="cropImage">
                        <i class="fas fa-crop"></i> Crop & Save
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
                        url: 'admin_profile.php',
                        type: 'POST',
                        data: {
                            mark_as_read: true,
                            notif_id: notifId
                        },
                        success: function(response) {
                            // Update UI
                            notificationItem.removeClass('unread');
                            
                            // Update notification count
                            const currentCount = parseInt($('#notificationCount').text());
                            if (currentCount > 1) {
                                $('#notificationCount').text(currentCount - 1);
                            } else {
                                $('#notificationCount').remove();
                            }
                        },
                        error: function() {
                            console.log('Error marking notification as read');
                        }
                    });
                }
            });
        });
        
        // Image Cropper Functionality
        let cropper;
        const profilePhoto = document.getElementById('profilePhoto');
        const cropperModal = document.getElementById('cropperModal');
        const imageToCrop = document.getElementById('imageToCrop');
        const closeModal = document.getElementById('closeModal');
        const cancelCrop = document.getElementById('cancelCrop');
        const cropImageBtn = document.getElementById('cropImage');
        const croppedImage = document.getElementById('croppedImage');
        const uploadBtn = document.getElementById('uploadBtn');
        const photoPreview = document.getElementById('photoPreview');
        
        profilePhoto.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Check file size (max 2MB)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size must be less than 2MB.');
                    this.value = '';
                    return;
                }
                
                // Check file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, GIF).');
                    this.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Show cropper modal
                    imageToCrop.src = e.target.result;
                    cropperModal.classList.add('active');
                    
                    // Initialize cropper
                    if (cropper) {
                        cropper.destroy();
                    }
                    
                    cropper = new Cropper(imageToCrop, {
                        aspectRatio: 1,
                        viewMode: 1,
                        guides: true,
                        background: false,
                        autoCropArea: 0.8,
                        responsive: true,
                        restore: false,
                        checkCrossOrigin: false,
                        cropBoxResizable: true,
                        cropBoxMovable: true,
                        toggleDragModeOnDblclick: true,
                        preview: '#cropperPreview'
                    });
                };
                reader.readAsDataURL(file);
            }
        });
        
        // Close modal events
        closeModal.addEventListener('click', function() {
            cropperModal.classList.remove('active');
            profilePhoto.value = '';
            if (cropper) {
                cropper.destroy();
            }
        });
        
        cancelCrop.addEventListener('click', function() {
            cropperModal.classList.remove('active');
            profilePhoto.value = '';
            if (cropper) {
                cropper.destroy();
            }
        });
        
        // Crop and save image
        cropImageBtn.addEventListener('click', function() {
            if (cropper) {
                // Get cropped canvas
                const canvas = cropper.getCroppedCanvas({
                    width: 300,
                    height: 300,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });
                
                // Convert canvas to base64
                const croppedImageData = canvas.toDataURL('image/png');
                
                // Set the cropped image data to hidden input
                croppedImage.value = croppedImageData;
                
                // Update preview
                photoPreview.src = croppedImageData;
                
                // Enable upload button
                uploadBtn.disabled = false;
                
                // Close modal
                cropperModal.classList.remove('active');
                
                // Destroy cropper
                cropper.destroy();
                cropper = null;
            }
        });
        
        // Close modal when clicking outside
        cropperModal.addEventListener('click', function(e) {
            if (e.target === cropperModal) {
                cropperModal.classList.remove('active');
                profilePhoto.value = '';
                if (cropper) {
                    cropper.destroy();
                }
            }
        });
        
        // Enhanced Phone Number Validation - CHANGED TO 10-11 DIGITS
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInput = document.getElementById('phone');
            
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
        
        // Client-side validation
        document.querySelector('form[name="update_profile"]').addEventListener('submit', function(e) {
            const lastName = document.getElementById('last_name').value.trim();
            const firstName = document.getElementById('first_name').value.trim();
            const phoneInput = document.getElementById('phone');
            const phoneValue = phoneInput.value.trim();
            const phoneRegex = /^[0-9]{10,11}$/;
            
            if (!lastName || !firstName) {
                e.preventDefault();
                alert('Please fill in both Last Name and First Name fields.');
                return false;
            }
            
            if (phoneValue && !phoneRegex.test(phoneValue)) {
                e.preventDefault();
                alert('Please enter a valid phone number (10-11 digits, numbers only).');
                phoneInput.focus();
                return false;
            }
        });
    </script>
</body>
</html>