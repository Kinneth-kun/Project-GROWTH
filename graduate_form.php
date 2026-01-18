<?php
// ============================================
// CONFIGURATION
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'growth_db');
define('DB_USER', 'root');
define('DB_PASS', '06162004');
define('ENVIRONMENT', 'development');

// PSGC API Endpoints
define('REGIONS_API', 'https://psgc.gitlab.io/api/regions/');
define('PROVINCES_API', 'https://psgc.gitlab.io/api/regions/{code}/provinces/');
define('CITIES_API', 'https://psgc.gitlab.io/api/provinces/{code}/cities-municipalities/');
define('BARANGAYS_API', 'https://psgc.gitlab.io/api/cities-municipalities/{code}/barangays/');

session_start();
ob_start();

// ============================================
// SECURITY HEADERS
// ============================================
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ============================================
// CONSTANTS
// ============================================
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_FILE_TYPES', ['image/jpeg', 'image/jpg', 'image/png', 'image/gif']);
define('UPLOAD_DIR', 'uploads/alumni_photos/');
define('MIN_YEAR_GRADUATED', 1990);
define('MAX_PHONE_LENGTH', 11);
define('MIN_PHONE_LENGTH', 10);

// ============================================
// DATABASE CONNECTION WITH ERROR HANDLING
// ============================================
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Database Connection Failed: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

// ============================================
// CHECK AUTO-APPROVE SETTING
// ============================================
$auto_approve_users = false;
try {
    $settings_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_users'");
    $settings_stmt->execute();
    $setting = $settings_stmt->fetch();
    
    if ($setting && isset($setting['setting_value'])) {
        $auto_approve_users = (bool)$setting['setting_value'];
    }
} catch (PDOException $e) {
    // If table doesn't exist or error, default to false
    $auto_approve_users = false;
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Error fetching auto-approve setting - " . $e->getMessage());
}

// ============================================
// REDIRECTION HANDLER FOR PENDING APPROVAL
// ============================================
if (isset($_GET['pending_approval']) && $_GET['pending_approval'] == 'true') {
    // Display a success page with countdown timer
    $countdown_seconds = 5; // 5 seconds countdown
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registration Complete - CTU-PESO</title>
        <link rel="icon" type="image/png" href="images/ctu.png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <style>
            :root {
                --primary-color: #6e0303;
                --secondary-color: #f7a100;
                --accent-color: #ffa700;
                --green: #1f7a11;
                --blue: #0044ff;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            body {
                background-color: #f5f6f8;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 20px;
                color: #333;
            }
            
            .success-container {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                padding: 40px;
                text-align: center;
                max-width: 600px;
                width: 100%;
                border-top: 5px solid var(--secondary-color);
            }
            
            .success-icon {
                font-size: 5rem;
                color: var(--green);
                margin-bottom: 20px;
                animation: bounce 1s ease infinite;
            }
            
            @keyframes bounce {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-10px); }
            }
            
            .success-title {
                font-size: 2.2rem;
                color: var(--primary-color);
                margin-bottom: 15px;
                font-weight: 700;
            }
            
            .success-message {
                font-size: 1.1rem;
                color: #555;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            
            .highlight-box {
                background: linear-gradient(135deg, #e3f2fd, #bbdefb);
                border-left: 4px solid var(--blue);
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 30px;
                text-align: left;
            }
            
            .highlight-title {
                font-weight: 600;
                color: var(--primary-color);
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .highlight-title i {
                color: var(--blue);
            }
            
            .highlight-list {
                list-style: none;
                padding-left: 10px;
            }
            
            .highlight-list li {
                margin-bottom: 8px;
                display: flex;
                align-items: flex-start;
                gap: 10px;
            }
            
            .highlight-list i {
                color: var(--green);
                margin-top: 3px;
            }
            
            .countdown-container {
                background: #f8f9fa;
                border-radius: 10px;
                padding: 25px;
                margin-bottom: 30px;
                border: 1px solid #e0e0e0;
            }
            
            .countdown-text {
                font-size: 1rem;
                color: #666;
                margin-bottom: 15px;
            }
            
            .countdown-timer {
                font-size: 2.5rem;
                font-weight: 700;
                color: var(--primary-color);
                margin-bottom: 10px;
            }
            
            .redirect-message {
                font-size: 0.9rem;
                color: #888;
                font-style: italic;
            }
            
            .action-buttons {
                display: flex;
                gap: 15px;
                justify-content: center;
                margin-top: 20px;
            }
            
            .btn {
                padding: 12px 25px;
                border: none;
                border-radius: 8px;
                font-size: 1rem;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                text-decoration: none;
            }
            
            .btn-primary {
                background: linear-gradient(to right, var(--primary-color), #8a0404);
                color: white;
            }
            
            .btn-primary:hover {
                background: linear-gradient(to right, #8a0404, #6e0303);
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(110, 3, 3, 0.2);
            }
            
            .btn-outline {
                background: transparent;
                border: 1px solid #ddd;
                color: #666;
            }
            
            .btn-outline:hover {
                background: #f8f9fa;
                border-color: #ccc;
            }
            
            .logo-container {
                margin-top: 30px;
                text-align: center;
            }
            
            .logo-container img {
                height: 60px;
                margin-bottom: 10px;
            }
            
            .logo-text {
                color: var(--primary-color);
                font-weight: 600;
                font-size: 1.1rem;
            }
            
            @media (max-width: 768px) {
                .success-container {
                    padding: 30px 20px;
                }
                
                .success-title {
                    font-size: 1.8rem;
                }
                
                .success-message {
                    font-size: 1rem;
                }
                
                .countdown-timer {
                    font-size: 2rem;
                }
                
                .action-buttons {
                    flex-direction: column;
                }
                
                .btn {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <div class="success-container">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            
            <h1 class="success-title">Registration Complete!</h1>
            
            <div class="success-message">
                Thank you for completing your graduate profile registration with CTU-PESO.
            </div>
            
            <div class="highlight-box">
                <div class="highlight-title">
                    <i class="fas fa-info-circle"></i>
                    Account Status: Pending Admin Approval
                </div>
                <ul class="highlight-list">
                    <li><i class="fas fa-clock"></i> Your account is currently pending approval by our administrators</li>
                    <li><i class="fas fa-envelope"></i> You will receive an email notification once your account is approved</li>
                    <li><i class="fas fa-user-check"></i> After approval, you can log in and access all platform features</li>
                    <li><i class="fas fa-history"></i> Approval typically takes 1-2 business days</li>
                </ul>
            </div>
            
            <div class="countdown-container">
                <div class="countdown-text">
                    You will be redirected to the homepage in:
                </div>
                <div class="countdown-timer" id="countdown">
                    <?php echo $countdown_seconds; ?> seconds
                </div>
                <div class="redirect-message">
                    You will be able to log in once your account is approved
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Go to Homepage Now
                </a>
                <a href="index.php?page=contact" class="btn btn-outline">
                    <i class="fas fa-headset"></i> Contact Support
                </a>
            </div>
            
            <div class="logo-container">
                <div class="logo-text">CTU-PESO G.R.O.W.T.H. Platform</div>
            </div>
        </div>
        
        <script>
            // Countdown timer
            let seconds = <?php echo $countdown_seconds; ?>;
            const countdownElement = document.getElementById('countdown');
            
            const countdownInterval = setInterval(() => {
                seconds--;
                countdownElement.textContent = seconds + " second" + (seconds !== 1 ? "s" : "");
                
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = 'index.php';
                }
            }, 1000);
            
            // Auto-redirect after countdown
            setTimeout(() => {
                window.location.href = 'index.php';
            }, <?php echo $countdown_seconds * 1000; ?>);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// PSGC API FUNCTIONS
// ============================================
function fetchFromAPI($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("PSGC API Error: HTTP $httpCode for URL: $url");
        return null;
    }
    
    return json_decode($response, true);
}

function sortByName($a, $b) {
    return strcmp($a['name'], $b['name']);
}

// Handle PSGC API requests
if (isset($_GET['getRegions'])) {
    header('Content-Type: application/json');
    $regions = fetchFromAPI(REGIONS_API);
    if ($regions && is_array($regions)) {
        usort($regions, 'sortByName');
        echo json_encode($regions);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['getProvinces']) && isset($_GET['regionCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['regionCode'], PROVINCES_API);
    $provinces = fetchFromAPI($url);
    if ($provinces && is_array($provinces)) {
        usort($provinces, 'sortByName');
        echo json_encode($provinces);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['getCities']) && isset($_GET['provinceCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['provinceCode'], CITIES_API);
    $cities = fetchFromAPI($url);
    if ($cities && is_array($cities)) {
        usort($cities, 'sortByName');
        echo json_encode($cities);
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['getBarangays']) && isset($_GET['cityCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['cityCode'], BARANGAYS_API);
    $barangays = fetchFromAPI($url);
    if ($barangays && is_array($barangays)) {
        usort($barangays, 'sortByName');
        echo json_encode($barangays);
    } else {
        echo json_encode([]);
    }
    exit;
}

// ============================================
// ENHANCED COURSE AND DEPARTMENT FETCHING
// ============================================
$courses = [];
$job_positions = [];
$colleges = [];

try {
    // ENHANCED: Fetch all active courses with proper grouping
    $courses_stmt = $conn->prepare("
        SELECT 
            course_id, 
            course_code, 
            course_name, 
            course_college,
            course_description,
            created_at
        FROM courses 
        WHERE is_active = TRUE 
        ORDER BY course_college ASC, course_name ASC
    ");
    $courses_stmt->execute();
    $all_courses = $courses_stmt->fetchAll();
    
    // Organize courses by college with enhanced data
    $college_counts = [];
    foreach ($all_courses as $course) {
        $college = $course['course_college'];
        if (!isset($courses[$college])) {
            $courses[$college] = [];
            $colleges[] = $college;
        }
        $courses[$college][] = $course;
        
        // Track course count per college
        if (!isset($college_counts[$college])) {
            $college_counts[$college] = 0;
        }
        $college_counts[$college]++;
    }
    
    // Sort colleges alphabetically
    sort($colleges);
    
    // ENHANCED: Get course statistics for debugging/logging
    $total_courses = count($all_courses);
    $total_colleges = count($colleges);
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Loaded $total_courses courses from $total_colleges colleges/departments");
    
    // Debug output if needed
    if (ENVIRONMENT === 'development') {
        error_log("Colleges found: " . implode(', ', $colleges));
        foreach ($colleges as $college) {
            error_log("$college: " . count($courses[$college]) . " courses");
        }
    }
    
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
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Error fetching dropdown data - " . $e->getMessage());
    
    // ENHANCED: Better fallback with clear error message
    $courses = [];
    $job_positions = [];
    $colleges = [];
    
    // Log detailed error for debugging
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Database error details - " . $e->getMessage());
}

// ============================================
// CSRF PROTECTION
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// VALIDATION FUNCTIONS
// ============================================
function validatePhoneNumber($phone, $country_code) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (empty($phone)) {
        return ['valid' => false, 'error' => "Phone number is required."];
    }
    
    if (strlen($phone) < MIN_PHONE_LENGTH || strlen($phone) > MAX_PHONE_LENGTH) {
        return ['valid' => false, 'error' => "Phone number must be between " . MIN_PHONE_LENGTH . " and " . MAX_PHONE_LENGTH . " digits."];
    }
    
    $full_phone = $country_code . $phone;
    return ['valid' => true, 'phone' => $phone, 'full_phone' => $full_phone];
}

function sanitizeFileName($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9\._-]/', '', $filename);
    $filename = preg_replace('/\.\.+/', '.', $filename);
    return time() . '_' . $filename;
}

function validateFileUpload($file, $field_name) {
    // Check if file was uploaded
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => ucfirst($field_name) . " photo is required."];
    }
    
    // Check upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => "File size too large. Maximum size is " . (MAX_FILE_SIZE / 1024 / 1024) . "MB."];
            case UPLOAD_ERR_PARTIAL:
                return ['success' => false, 'error' => "File upload was incomplete."];
            case UPLOAD_ERR_NO_TMP_DIR:
                return ['success' => false, 'error' => "Missing temporary folder."];
            case UPLOAD_ERR_CANT_WRITE:
                return ['success' => false, 'error' => "Failed to write file to disk."];
            case UPLOAD_ERR_EXTENSION:
                return ['success' => false, 'error' => "A PHP extension stopped the file upload."];
            default:
                return ['success' => false, 'error' => "File upload error occurred."];
        }
    }
    
    // Validate file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => "File size must be less than " . (MAX_FILE_SIZE / 1024 / 1024) . "MB."];
    }
    
    // Validate file type using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, ALLOWED_FILE_TYPES)) {
        return ['success' => false, 'error' => "Only JPG, JPEG, PNG, and GIF files are allowed."];
    }
    
    // Validate file extension
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['success' => false, 'error' => "Invalid file extension. Allowed: " . implode(', ', $allowed_extensions)];
    }
    
    // Check for image validity
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return ['success' => false, 'error' => "Uploaded file is not a valid image."];
    }
    
    return ['success' => true, 'mime_type' => $mime_type, 'tmp_name' => $file['tmp_name'], 'name' => $file['name']];
}

// ============================================
// SESSION VALIDATION
// ============================================
if (!isset($_SESSION['verified_user_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please complete the registration process first.";
    header('Location: index.php?page=login');
    exit;
}

// Determine user ID based on session
$user_id = isset($_SESSION['verified_user_id']) ? $_SESSION['verified_user_id'] : $_SESSION['user_id'];

// Session fixation protection
if (!isset($_SESSION['session_regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['session_regenerated'] = true;
}

// ============================================
// USER DATA FETCH
// ============================================
try {
    $user_stmt = $conn->prepare("SELECT usr_name, usr_email, usr_phone FROM users WHERE usr_id = :user_id");
    $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $user_stmt->execute();
    $user_data = $user_stmt->fetch();
    
    if (!$user_data) {
        $_SESSION['error'] = "User not found.";
        header('Location: index.php?page=login');
        exit;
    }
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Error fetching user data - " . $e->getMessage());
    $_SESSION['error'] = "Error loading user data.";
    header('Location: index.php?page=login');
    exit;
}

// ============================================
// CHECK EXISTING GRADUATE PROFILE
// ============================================
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM graduates WHERE grad_usr_id = :user_id");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->fetchColumn() > 0) {
        // Update user status to active
        $update_stmt = $conn->prepare("
            UPDATE users 
            SET usr_is_approved = TRUE, 
                usr_account_status = 'active',
                usr_updated_at = NOW()
            WHERE usr_id = :user_id
        ");
        $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Set session variables
        $_SESSION['user_id'] = $user_id;
        $_SESSION['logged_in'] = true;
        $_SESSION['is_approved'] = true;
        $_SESSION['account_status'] = 'active';
        
        // Get user name and role
        $user_stmt = $conn->prepare("SELECT usr_name, usr_role FROM users WHERE usr_id = :user_id");
        $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $user_stmt->execute();
        $user_data = $user_stmt->fetch();
        
        if ($user_data) {
            $_SESSION['user_name'] = $user_data['usr_name'];
            $_SESSION['user_role'] = $user_data['usr_role'];
        }
        
        if (isset($_SESSION['verified_user_id'])) {
            unset($_SESSION['verified_user_id']);
        }
        
        header('Location: graduate_dashboard.php?message=' . urlencode("Graduate profile already completed."));
        exit;
    }
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] graduate_form.php: Database error checking existing profile - " . $e->getMessage());
    $_SESSION['error'] = "Database error. Please try again.";
}

// ============================================
// PROCESS FORM SUBMISSION
// ============================================
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission. Please try again.";
    } else {
        // Validate Terms and Conditions acceptance
        if (!isset($_POST['accept_terms']) || $_POST['accept_terms'] !== 'yes') {
            $error = "You must accept the Terms and Conditions and Data Privacy Act to proceed.";
        } else {
            // Sanitize and validate input
            $last_name = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING));
            $first_name = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING));
            $middle_name = trim(filter_input(INPUT_POST, 'middle_name', FILTER_SANITIZE_STRING));
            $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
            $country_code = trim(filter_input(INPUT_POST, 'country_code', FILTER_SANITIZE_STRING));
            $phone = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING));
            $gender = trim(filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING));
            $birthdate = trim(filter_input(INPUT_POST, 'birthdate', FILTER_SANITIZE_STRING));
            
            // Address fields - IMPORTANT: Store PSGC names
            $region = trim(filter_input(INPUT_POST, 'region', FILTER_SANITIZE_STRING));
            $province = trim(filter_input(INPUT_POST, 'province', FILTER_SANITIZE_STRING));
            $city = trim(filter_input(INPUT_POST, 'city', FILTER_SANITIZE_STRING));
            $barangay = trim(filter_input(INPUT_POST, 'barangay', FILTER_SANITIZE_STRING));
            $zip_code = trim(filter_input(INPUT_POST, 'zip_code', FILTER_SANITIZE_STRING));
            $street_address = trim(filter_input(INPUT_POST, 'street_address', FILTER_SANITIZE_STRING));
            
            $alumni_number = trim(filter_input(INPUT_POST, 'alumni_number', FILTER_SANITIZE_STRING));
            $degree = trim(filter_input(INPUT_POST, 'degree', FILTER_SANITIZE_STRING));
            $year_graduated = trim(filter_input(INPUT_POST, 'year_graduated', FILTER_SANITIZE_NUMBER_INT));
            $summary = trim(filter_input(INPUT_POST, 'summary', FILTER_SANITIZE_STRING));
            
            // Get job preferences (can be multiple)
            $job_preferences = isset($_POST['job_preferences']) ? $_POST['job_preferences'] : [];
            $custom_job_preferences = isset($_POST['custom_job_preferences']) ? $_POST['custom_job_preferences'] : [];
            
            // Validate required fields
            $required_fields = [
                'last_name' => $last_name,
                'first_name' => $first_name,
                'email' => $email,
                'alumni_number' => $alumni_number,
                'degree' => $degree,
                'year_graduated' => $year_graduated,
                'region' => $region,
                'province' => $province,
                'city' => $city,
                'barangay' => $barangay,
                'phone' => $phone,
                'gender' => $gender,
                'birthdate' => $birthdate
            ];
            
            $missing_fields = [];
            foreach ($required_fields as $field_name => $value) {
                if (empty($value)) {
                    $missing_fields[] = $field_name;
                }
            }
            
            // Validate job preferences - only check if at least one is selected
            if (empty($job_preferences) && empty($custom_job_preferences)) {
                $missing_fields[] = 'job_preferences';
            }
            
            if (!empty($missing_fields)) {
                $error = "All required fields must be filled: " . implode(', ', $missing_fields);
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } elseif ($year_graduated < MIN_YEAR_GRADUATED || $year_graduated > date('Y')) {
                $error = "Please enter a valid graduation year (" . MIN_YEAR_GRADUATED . "-" . date('Y') . ").";
            } else {
                // Validate phone number
                $phone_validation = validatePhoneNumber($phone, $country_code);
                if (!$phone_validation['valid']) {
                    $error = $phone_validation['error'];
                } else {
                    $full_phone = $phone_validation['full_phone'];
                    
                    // Combine names
                    $full_name = trim("$first_name " . ($middle_name ? "$middle_name " : "") . "$last_name");
                    
                    // Combine address components properly
                    $address_components = [];
                    if (!empty($street_address)) $address_components[] = $street_address;
                    if (!empty($barangay)) $address_components[] = $barangay;
                    if (!empty($city)) $address_components[] = $city;
                    if (!empty($province)) $address_components[] = $province;
                    if (!empty($region)) $address_components[] = $region;
                    if (!empty($zip_code)) $address_components[] = "ZIP: " . $zip_code;
                    
                    $permanent_address = implode(', ', $address_components);
                    
                    // Process file uploads
                    $alumni_id_photo_front = null;
                    $alumni_id_photo_back = null;
                    $upload_error = '';
                    
                    // Validate and upload front photo
                    $front_validation = validateFileUpload($_FILES['alumni_id_photo_front'], 'front');
                    if (!$front_validation['success']) {
                        $error = $front_validation['error'];
                    } else {
                        // Validate and upload back photo
                        $back_validation = validateFileUpload($_FILES['alumni_id_photo_back'], 'back');
                        if (!$back_validation['success']) {
                            $error = $back_validation['error'];
                        } else {
                            // Both files validated, proceed with uploads
                            try {
                                // Create upload directory if it doesn't exist
                                if (!is_dir(UPLOAD_DIR)) {
                                    mkdir(UPLOAD_DIR, 0755, true);
                                }
                                
                                // Generate secure filenames
                                $front_filename = sanitizeFileName($front_validation['name']);
                                $back_filename = sanitizeFileName($back_validation['name']);
                                
                                $front_path = UPLOAD_DIR . 'front_' . $front_filename;
                                $back_path = UPLOAD_DIR . 'back_' . $back_filename;
                                
                                // Move uploaded files
                                if (move_uploaded_file($front_validation['tmp_name'], $front_path) &&
                                    move_uploaded_file($back_validation['tmp_name'], $back_path)) {
                                    
                                    $alumni_id_photo_front = $front_path;
                                    $alumni_id_photo_back = $back_path;
                                    
                                    // Begin database transaction
                                    $conn->beginTransaction();
                                    
                                    try {
                                        // Check for duplicate alumni number
                                        $check_stmt = $conn->prepare("SELECT grad_id FROM graduates WHERE grad_school_id = :alumni_number");
                                        $check_stmt->bindParam(':alumni_number', $alumni_number);
                                        $check_stmt->execute();
                                        
                                        if ($check_stmt->rowCount() > 0) {
                                            throw new Exception("This alumni number is already registered.");
                                        }
                                        
                                        // Check for duplicate phone (if provided)
                                        if (!empty($full_phone)) {
                                            $phone_check_stmt = $conn->prepare("
                                                SELECT usr_id FROM users 
                                                WHERE usr_phone = :phone 
                                                AND usr_id != :user_id 
                                                AND usr_phone IS NOT NULL 
                                                AND usr_phone != ''
                                            ");
                                            $phone_check_stmt->bindParam(':phone', $full_phone);
                                            $phone_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                            $phone_check_stmt->execute();
                                            
                                            if ($phone_check_stmt->rowCount() > 0) {
                                                throw new Exception("This phone number is already registered with another account.");
                                            }
                                        }
                                        
                                        // Check for duplicate email
                                        $email_check_stmt = $conn->prepare("
                                            SELECT usr_id FROM users 
                                            WHERE usr_email = :email 
                                            AND usr_id != :user_id
                                        ");
                                        $email_check_stmt->bindParam(':email', $email);
                                        $email_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                        $email_check_stmt->execute();
                                        
                                        if ($email_check_stmt->rowCount() > 0) {
                                            throw new Exception("This email address is already registered with another account.");
                                        }
                                        
                                        // Determine approval status based on system setting
                                        $is_approved = $auto_approve_users ? TRUE : FALSE;
                                        $account_status = $auto_approve_users ? 'active' : 'pending';
                                        
                                        // Update user information with address
                                        $update_user_stmt = $conn->prepare("
                                            UPDATE users 
                                            SET usr_name = :name, 
                                                usr_email = :email, 
                                                usr_phone = :phone,
                                                usr_gender = :gender,
                                                usr_birthdate = :birthdate,
                                                usr_address = :address,
                                                usr_is_approved = :is_approved,
                                                usr_account_status = :account_status,
                                                usr_updated_at = NOW()
                                            WHERE usr_id = :user_id
                                        ");
                                        
                                        $update_user_stmt->bindParam(':name', $full_name);
                                        $update_user_stmt->bindParam(':email', $email);
                                        $update_user_stmt->bindParam(':phone', $full_phone);
                                        $update_user_stmt->bindParam(':gender', $gender);
                                        $update_user_stmt->bindParam(':birthdate', $birthdate);
                                        $update_user_stmt->bindParam(':address', $permanent_address);
                                        $update_user_stmt->bindParam(':is_approved', $is_approved, PDO::PARAM_BOOL);
                                        $update_user_stmt->bindParam(':account_status', $account_status);
                                        $update_user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                        $update_user_stmt->execute();
                                        
                                        // Process job preferences
                                        $all_job_preferences = [];
                                        
                                        // Add selected job preferences
                                        foreach ($job_preferences as $job_pref) {
                                            if (!empty($job_pref)) {
                                                $all_job_preferences[] = htmlspecialchars($job_pref);
                                            }
                                        }
                                        
                                        // Process custom job preferences
                                        $processed_custom_jobs = [];
                                        foreach ($custom_job_preferences as $custom_pref) {
                                            $custom_pref = trim($custom_pref);
                                            if (!empty($custom_pref)) {
                                                $all_job_preferences[] = $custom_pref;
                                                $processed_custom_jobs[] = $custom_pref;
                                                
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
                                                    // First, we need to find an appropriate category
                                                    $category_stmt = $conn->prepare("
                                                        SELECT category_id FROM job_categories 
                                                        WHERE (category_name LIKE '%Other%' OR category_name LIKE '%Custom%') 
                                                        AND is_active = TRUE 
                                                        LIMIT 1
                                                    ");
                                                    $category_stmt->execute();
                                                    $category = $category_stmt->fetch();
                                                    
                                                    $category_id = $category ? $category['category_id'] : null;
                                                    
                                                    // If no "Other" category exists, create one
                                                    if (!$category_id) {
                                                        $insert_category_stmt = $conn->prepare("
                                                            INSERT INTO job_categories (category_name, description, is_active, created_at) 
                                                            VALUES ('Other/Custom', 'Custom job positions added by users', TRUE, NOW())
                                                        ");
                                                        $insert_category_stmt->execute();
                                                        $category_id = $conn->lastInsertId();
                                                    }
                                                    
                                                    // Insert the custom job position - REMOVED is_custom column
                                                    $insert_position_stmt = $conn->prepare("
                                                        INSERT INTO job_positions (
                                                            category_id, 
                                                            position_name, 
                                                            is_active, 
                                                            created_at
                                                        ) VALUES (
                                                            :category_id, 
                                                            :position_name, 
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
                                        
                                        // Insert graduate profile
                                        $insert_stmt = $conn->prepare("
                                            INSERT INTO graduates (
                                                grad_usr_id, 
                                                grad_school_id, 
                                                grad_degree, 
                                                grad_year_graduated, 
                                                grad_job_preference, 
                                                grad_summary, 
                                                grad_alumni_id_photo,
                                                grad_created_at
                                            ) VALUES (
                                                :user_id, 
                                                :alumni_number, 
                                                :degree, 
                                                :year_graduated, 
                                                :job_preference, 
                                                :summary, 
                                                :alumni_id_photo,
                                                NOW()
                                            )
                                        ");
                                        
                                        // Store photo paths as JSON
                                        $alumni_photos = json_encode([
                                            'front' => $alumni_id_photo_front,
                                            'back' => $alumni_id_photo_back
                                        ], JSON_UNESCAPED_SLASHES);
                                        
                                        if ($alumni_photos === false) {
                                            throw new Exception("Failed to process photo data.");
                                        }
                                        
                                        $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                        $insert_stmt->bindParam(':alumni_number', $alumni_number);
                                        $insert_stmt->bindParam(':degree', $degree);
                                        $insert_stmt->bindParam(':year_graduated', $year_graduated);
                                        $insert_stmt->bindParam(':job_preference', $job_preferences_json);
                                        $insert_stmt->bindParam(':summary', $summary);
                                        $insert_stmt->bindParam(':alumni_id_photo', $alumni_photos);
                                        $insert_stmt->execute();
                                        
                                        // Commit transaction
                                        $conn->commit();
                                        
                                        // Set session variables
                                        $_SESSION['user_id'] = $user_id;
                                        $_SESSION['logged_in'] = true;
                                        $_SESSION['is_approved'] = $is_approved;
                                        $_SESSION['account_status'] = $account_status;
                                        $_SESSION['user_name'] = $full_name;
                                        $_SESSION['user_role'] = 'graduate';
                                        
                                        // Clear verification session
                                        if (isset($_SESSION['verified_user_id'])) {
                                            unset($_SESSION['verified_user_id']);
                                        }
                                        
                                        // Regenerate session ID after privilege change
                                        session_regenerate_id(true);
                                        
                                        // Redirect based on auto-approve setting
                                        if ($auto_approve_users) {
                                            $_SESSION['success'] = "Graduate profile completed successfully! Your account is now active.";
                                            header('Location: graduate_dashboard.php');
                                        } else {
                                            // Redirect to pending approval success page
                                            header('Location: graduate_form.php?pending_approval=true');
                                        }
                                        exit;
                                        
                                    } catch (Exception $e) {
                                        // Rollback transaction on error
                                        $conn->rollBack();
                                        
                                        // Delete uploaded files if transaction failed
                                        if (file_exists($front_path)) unlink($front_path);
                                        if (file_exists($back_path)) unlink($back_path);
                                        
                                        throw $e;
                                    }
                                    
                                } else {
                                    throw new Exception("Failed to upload files. Please try again.");
                                }
                                
                            } catch (Exception $e) {
                                $error = $e->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }
}

// Store errors in session for persistence
if (!empty($error)) {
    $_SESSION['form_error'] = $error;
    $_SESSION['form_data'] = $_POST; // Store form data for repopulation
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CTU-PESO - Complete Graduate Profile</title>
  <link rel="icon" type="image/png" href="images/ctu.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    }
    
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    body {
      background-color: #f5f6f8;
      min-height: 100vh;
      color: var(--text-color);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    
    .container {
      width: 100%;
      max-width: 1200px;
      background: white;
      border-radius: 10px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      overflow: hidden;
    }
    
    .header {
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      color: white;
      padding: 30px;
      text-align: center;
    }
    
    .header h1 {
      font-size: 2.2rem;
      margin-bottom: 10px;
    }
    
    .header p {
      font-size: 1.1rem;
      opacity: 0.9;
    }
    
    .progress-container {
      background: #f8f9fa;
      padding: 15px 30px;
      border-bottom: 1px solid #e9ecef;
    }
    
    .progress-bar {
      height: 8px;
      background: #e0e0e0;
      border-radius: 4px;
      overflow: hidden;
      margin: 10px 0;
    }
    
    .progress {
      height: 100%;
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      border-radius: 4px;
      width: 66%;
      transition: width 0.5s ease;
    }
    
    .progress-text {
      display: flex;
      justify-content: space-between;
      font-size: 0.9rem;
      color: #666;
    }
    
    .content {
      padding: 30px;
    }
    
    .welcome-message {
      background: linear-gradient(to right, #e3f2fd, #bbdefb);
      padding: 20px;
      border-radius: 8px;
      margin-bottom: 25px;
      border-left: 4px solid var(--blue);
    }
    
    .welcome-message h2 {
      color: var(--primary-color);
      margin-bottom: 10px;
    }
    
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
    }
    
    .form-section {
      background: white;
      border-radius: 8px;
      padding: 25px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .personal-info {
      border-top: 4px solid var(--blue);
    }
    
    .academic-info {
      border-top: 4px solid var(--green);
    }
    
    .form-header {
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 1px solid #eee;
    }
    
    .form-title {
      font-size: 1.4rem;
      color: var(--primary-color);
      font-weight: 600;
    }
    
    .form-subtitle {
      color: #666;
      margin-top: 5px;
      font-size: 0.95rem;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--primary-color);
    }
    
    .form-input, .form-select, .form-textarea, .form-file {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 5px;
      font-size: 1rem;
      transition: border-color 0.3s;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus, .form-file:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 2px rgba(110, 3, 3, 0.1);
    }
    
    .form-textarea {
      height: 100px;
      resize: vertical;
    }
    
    .form-text {
      font-size: 0.85rem;
      color: #666;
      margin-top: 5px;
    }
    
    .required {
      color: var(--red);
    }
    
    .name-container {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 15px;
    }
    
    .address-container {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
    }
    
    .btn {
      padding: 12px 25px;
      border: none;
      border-radius: 5px;
      font-size: 1rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    
    .btn-primary {
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      color: white;
    }
    
    .btn-primary:hover {
      background: linear-gradient(to right, #8a0404, #6e0303);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(110, 3, 3, 0.2);
    }
    
    .btn-outline {
      background: transparent;
      border: 1px solid #ddd;
      color: #666;
    }
    
    .btn-outline:hover {
      background: #f8f9fa;
      border-color: #ccc;
    }
    
    .alert {
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .alert-error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    
    .alert i {
      font-size: 1.2rem;
    }
    
    .action-buttons {
      display: flex;
      gap: 15px;
      margin-top: 30px;
      justify-content: center;
    }
    
    .phone-container {
      display: flex;
      gap: 10px;
    }
    
    .country-code {
      width: 100px;
      flex-shrink: 0;
    }
    
    .phone-number {
      flex: 1;
    }
    
    /* Enhanced File Upload Styles */
    .photo-upload-section {
      margin-top: 30px;
      border-top: 1px solid #eee;
      padding-top: 20px;
    }
    
    .photo-upload-title {
      font-size: 1.2rem;
      color: var(--primary-color);
      margin-bottom: 15px;
      padding-bottom: 10px;
      border-bottom: 1px solid #eee;
    }
    
    .photo-upload-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }
    
    .file-upload-card {
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 20px;
      background: #fafafa;
      transition: all 0.3s ease;
      display: flex;
      flex-direction: column;
      height: 100%;
      min-height: 300px;
      position: relative;
    }
    
    .file-upload-card:hover {
      border-color: var(--primary-color);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .file-upload-card.dragover {
      border-color: var(--primary-color);
      background: #f0f8ff;
    }
    
    .file-upload-header {
      display: flex;
      align-items: center;
      margin-bottom: 15px;
    }
    
    .file-upload-icon {
      font-size: 1.8rem;
      color: var(--primary-color);
      margin-right: 12px;
    }
    
    .file-upload-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--primary-color);
    }
    
    .file-upload-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      transition: all 0.3s ease;
    }
    
    .file-upload-body.has-preview {
      justify-content: flex-start;
    }
    
    .file-upload-text {
      margin-bottom: 15px;
      color: #666;
      font-size: 0.9rem;
      max-width: 90%;
    }
    
    .file-upload-btn {
      background: var(--primary-color);
      color: white;
      padding: 10px 16px;
      border-radius: 5px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background 0.3s;
      font-weight: 500;
      margin-bottom: 15px;
    }
    
    .file-upload-btn:hover {
      background: #8a0404;
    }
    
    .file-requirements {
      font-size: 0.8rem;
      color: #888;
      margin-top: 5px;
    }
    
    .file-preview-container {
      width: 100%;
      display: none;
      flex-direction: column;
      align-items: center;
    }
    
    .file-preview {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
    }
    
    .file-preview img {
      max-width: 100%;
      max-height: 200px;
      border-radius: 4px;
      border: 1px solid #ddd;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      margin-bottom: 10px;
    }
    
    .file-info {
      font-size: 0.85rem;
      color: #666;
      text-align: center;
      width: 100%;
      margin-bottom: 10px;
    }
    
    .file-info-item {
      margin-bottom: 3px;
    }
    
    .file-status {
      padding: 5px 10px;
      border-radius: 4px;
      font-size: 0.8rem;
      font-weight: 500;
      margin-top: 10px;
    }
    
    .file-status.success {
      background-color: #e8f5e9;
      color: #2e7d32;
    }
    
    .file-status.error {
      background-color: #ffebee;
      color: #c62828;
    }
    
    .file-input {
      display: none;
    }
    
    .preview-actions {
      display: flex;
      gap: 10px;
      margin-top: 10px;
    }
    
    .preview-action-btn {
      padding: 6px 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: white;
      color: #666;
      cursor: pointer;
      font-size: 0.8rem;
      transition: all 0.3s;
    }
    
    .preview-action-btn:hover {
      background: #f5f5f5;
      border-color: #ccc;
    }
    
    .preview-action-btn.primary {
      background: var(--primary-color);
      color: white;
      border-color: var(--primary-color);
    }
    
    .preview-action-btn.primary:hover {
      background: #8a0404;
    }
    
    /* Address Loading Spinner */
    .loading-spinner {
      display: none;
      color: var(--primary-color);
      text-align: center;
      padding: 10px;
    }
    
    .loading-spinner i {
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* Form validation styles */
    .form-input.error, .form-select.error, .form-textarea.error {
      border-color: var(--red);
      background-color: #fff8f8;
    }
    
    .error-message {
      color: var(--red);
      font-size: 0.85rem;
      margin-top: 5px;
      display: none;
    }
    
    .error-message.show {
      display: block;
    }
    
    .form-group.has-error .form-label {
      color: var(--red);
    }
    
    /* Loading overlay */
    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.8);
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      display: none;
    }
    
    .loading-spinner-large {
      font-size: 3rem;
      color: var(--primary-color);
      margin-bottom: 20px;
    }
    
    /* ============================================
       ENHANCED JOB PREFERENCES STYLES - NO LIMIT
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
      color: var(--primary-color);
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
      background: var(--primary-color);
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
    
    /* ============================================
       TERMS AND CONDITIONS STYLES
       ============================================ */
    .terms-section {
      background: #f8f9fa;
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 25px;
      margin-bottom: 25px;
      border-top: 4px solid var(--purple);
    }
    
    .terms-header {
      margin-bottom: 20px;
      text-align: center;
    }
    
    .terms-title {
      font-size: 1.5rem;
      color: var(--primary-color);
      font-weight: 600;
      margin-bottom: 10px;
    }
    
    .terms-subtitle {
      color: #666;
      font-size: 1rem;
    }
    
    .terms-content {
      max-height: 300px;
      overflow-y: auto;
      padding: 20px;
      background: white;
      border: 1px solid #ddd;
      border-radius: 6px;
      margin-bottom: 20px;
      line-height: 1.6;
    }
    
    .terms-content h3 {
      color: var(--primary-color);
      margin: 15px 0 10px 0;
      font-size: 1.2rem;
    }
    
    .terms-content h4 {
      color: #444;
      margin: 12px 0 8px 0;
      font-size: 1.1rem;
    }
    
    .terms-content p {
      margin-bottom: 10px;
      text-align: justify;
    }
    
    .terms-content ul, .terms-content ol {
      margin-left: 20px;
      margin-bottom: 10px;
    }
    
    .terms-content li {
      margin-bottom: 5px;
    }
    
    .terms-checkbox {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 15px;
      background: white;
      border: 1px solid #ddd;
      border-radius: 6px;
      margin-bottom: 20px;
    }
    
    .terms-checkbox input[type="checkbox"] {
      width: 20px;
      height: 20px;
      margin-top: 2px;
      cursor: pointer;
    }
    
    .terms-checkbox label {
      flex: 1;
      cursor: pointer;
      font-weight: 500;
      color: #333;
    }
    
    .terms-checkbox label span {
      color: var(--red);
      font-weight: bold;
    }
    
    .terms-checkbox.error {
      border-color: var(--red);
      background-color: #fff8f8;
    }
    
    .terms-error {
      color: var(--red);
      font-size: 0.9rem;
      margin-top: 5px;
      display: none;
    }
    
    .terms-error.show {
      display: block;
    }
    
    .terms-actions {
      display: flex;
      justify-content: center;
      gap: 15px;
      margin-top: 20px;
    }
    
    .terms-modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 10000;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }
    
    .terms-modal-content {
      background: white;
      border-radius: 10px;
      width: 100%;
      max-width: 800px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
      animation: modalFadeIn 0.3s ease;
    }
    
    @keyframes modalFadeIn {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    .terms-modal-header {
      background: linear-gradient(to right, var(--primary-color), #8a0404);
      color: white;
      padding: 20px;
      border-radius: 10px 10px 0 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .terms-modal-title {
      font-size: 1.5rem;
      font-weight: 600;
    }
    
    .terms-modal-close {
      background: none;
      border: none;
      color: white;
      font-size: 1.5rem;
      cursor: pointer;
      padding: 5px;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.3s;
    }
    
    .terms-modal-close:hover {
      background: rgba(255, 255, 255, 0.2);
    }
    
    .terms-modal-body {
      padding: 20px;
      overflow-y: auto;
      flex: 1;
    }
    
    .terms-modal-footer {
      padding: 20px;
      border-top: 1px solid #eee;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }
    
    /* Responsive adjustments */
    @media (max-width: 968px) {
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .name-container {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .address-container {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      
      .photo-upload-grid {
        grid-template-columns: 1fr;
      }
      
      .job-options {
        grid-template-columns: 1fr;
      }
    }
    
    @media (max-width: 768px) {
      .container {
        margin: 10px;
        width: calc(100% - 20px);
      }
      
      .header {
        padding: 20px;
      }
      
      .header h1 {
        font-size: 1.8rem;
      }
      
      .content {
        padding: 20px;
      }
      
      .action-buttons {
        flex-direction: column;
      }
      
      .btn {
        width: 100%;
      }
      
      .phone-container {
        flex-direction: column;
        gap: 8px;
      }
      
      .country-code {
        width: 100%;
      }
      
      .file-upload-card {
        min-height: 250px;
      }
      
      .custom-job-input-group {
        flex-direction: column;
      }
      
      .add-custom-job-btn {
        width: 100%;
      }
      
      .terms-actions {
        flex-direction: column;
      }
      
      .terms-modal {
        padding: 10px;
      }
      
      .terms-modal-content {
        max-height: 95vh;
      }
    }
  </style>
</head>
<body>
  <!-- Loading Overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner-large">
      <i class="fas fa-spinner fa-spin"></i>
    </div>
    <p>Processing your request...</p>
  </div>
  
  <!-- Terms and Conditions Modal -->
  <div class="terms-modal" id="termsModal">
    <div class="terms-modal-content">
      <div class="terms-modal-header">
        <div class="terms-modal-title">Terms and Conditions & Data Privacy Act</div>
        <button type="button" class="terms-modal-close" id="closeTermsModal">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="terms-modal-body">
        <h2>CTU-PESO G.R.O.W.T.H. Platform</h2>
        <h3>Terms and Conditions</h3>
        
        <h4>1. Acceptance of Terms</h4>
        <p>By accessing and using the CTU-PESO G.R.O.W.T.H. Platform, you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions. If you do not agree with any part of these terms, you must not use this platform.</p>
        
        <h4>2. User Eligibility</h4>
        <p>This platform is exclusively for CTU alumni, verified employers, and authorized staff. You must be at least 18 years old to register and use the platform. By registering, you confirm that all information provided is accurate and truthful.</p>
        
        <h4>3. User Responsibilities</h4>
        <ul>
          <li>Provide accurate and complete information during registration</li>
          <li>Maintain the confidentiality of your account credentials</li>
          <li>Notify CTU-PESO immediately of any unauthorized use of your account</li>
          <li>Use the platform only for lawful purposes</li>
          <li>Not engage in any fraudulent, deceptive, or malicious activities</li>
        </ul>
        
        <h4>4. Platform Usage</h4>
        <p>The platform serves as a bridge between CTU alumni and potential employers. CTU-PESO facilitates connections but does not guarantee employment. Users are responsible for verifying the legitimacy of job opportunities and employer information.</p>
        
        <h4>5. Content Submission</h4>
        <p>Users retain ownership of content they submit but grant CTU-PESO a non-exclusive license to use, display, and distribute such content for platform operations. Users are solely responsible for the content they submit and must ensure it does not violate any laws or third-party rights.</p>
        
        <h4>6. Termination</h4>
        <p>CTU-PESO reserves the right to suspend or terminate accounts that violate these terms, provide false information, or engage in activities that compromise platform security or integrity.</p>
        
        <h4>7. Limitation of Liability</h4>
        <p>CTU-PESO shall not be liable for any indirect, incidental, or consequential damages arising from the use or inability to use the platform. While we strive for accuracy, we do not guarantee the completeness or reliability of information on the platform.</p>
        
        <h3>Data Privacy Act (Republic Act 10173)</h3>
        
        <h4>1. Data Collection</h4>
        <p>We collect personal information necessary for platform functionality, including but not limited to: name, contact information, educational background, employment history, skills, and profile photos.</p>
        
        <h4>2. Purpose of Data Collection</h4>
        <p>Your personal data is collected and processed for the following purposes:</p>
        <ul>
          <li>User account creation and management</li>
          <li>Job matching and employment facilitation</li>
          <li>Platform improvement and development</li>
          <li>Communication regarding platform updates and opportunities</li>
          <li>Compliance with legal and regulatory requirements</li>
        </ul>
        
        <h4>3. Data Storage and Security</h4>
        <p>Your data is stored securely on our servers with appropriate technical and organizational measures to prevent unauthorized access, disclosure, alteration, or destruction. We implement industry-standard security protocols to protect your information.</p>
        
        <h4>4. Data Sharing</h4>
        <p>Your information may be shared with:</p>
        <ul>
          <li>Potential employers for job matching purposes</li>
          <li>CTU administration for verification and reporting</li>
          <li>Service providers who assist in platform operations (under confidentiality agreements)</li>
          <li>Government agencies when required by law</li>
        </ul>
        
        <h4>5. Data Retention</h4>
        <p>We retain your personal data for as long as necessary to fulfill the purposes outlined in this notice, or as required by applicable laws. You may request account deletion, subject to legal retention requirements.</p>
        
        <h4>6. Your Rights</h4>
        <p>Under the Data Privacy Act, you have the right to:</p>
        <ul>
          <li>Access your personal data</li>
          <li>Correct inaccurate or incomplete data</li>
          <li>Request deletion of your data</li>
          <li>Withdraw consent for data processing</li>
          <li>Be informed of automated decision-making processes</li>
          <li>Data portability</li>
        </ul>
        
        <h4>7. Consent</h4>
        <p>By checking the agreement box, you provide explicit consent for CTU-PESO to collect, process, store, and share your personal data as described in this notice.</p>
        
        <h4>8. Contact Information</h4>
        <p>For data privacy concerns, you may contact our Data Protection Officer at:</p>
        <p>CTU-PESO Office<br>
        Cebu Technological University<br>
        Email: dpo@ctu.edu.ph<br>
        Phone: (032) 123-4567</p>
        
        <h4>9. Updates to Privacy Notice</h4>
        <p>We may update this privacy notice periodically. Significant changes will be communicated through platform notifications. Continued use of the platform constitutes acceptance of updated terms.</p>
        
        <div class="terms-checkbox" style="margin-top: 20px;">
          <input type="checkbox" id="modalAgreeCheckbox">
          <label for="modalAgreeCheckbox">I have read and understood the Terms and Conditions and Data Privacy Act provisions above.</label>
        </div>
      </div>
      <div class="terms-modal-footer">
        <button type="button" class="btn btn-outline" id="declineTerms">Decline</button>
        <button type="button" class="btn btn-primary" id="acceptTermsModal" disabled>Accept Terms</button>
      </div>
    </div>
  </div>
  
  <div class="container">
    <div class="header">
      <h1>Complete Your Alumni Profile</h1>
      <p>Welcome to CTU-PESO G.R.O.W.T.H. Platform</p>
    </div>
    
    <div class="progress-container">
      <div class="progress-text">
        <span>Registration Progress</span>
        <span>Step 2 of 2</span>
      </div>
      <div class="progress-bar">
        <div class="progress"></div>
      </div>
    </div>
    
    <div class="content">
      <div class="welcome-message">
        <h2>Welcome!</h2>
        <p>Please complete your personal and academic information to finalize your registration.</p>
      </div>
      
      <?php 
      // Display session errors
      if (isset($_SESSION['form_error'])): 
        $error = $_SESSION['form_error'];
        unset($_SESSION['form_error']);
      ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i>
          <?php echo htmlspecialchars($_SESSION['success']); ?>
          <?php unset($_SESSION['success']); ?>
        </div>
      <?php endif; ?>
      
      <!-- Terms and Conditions Section -->
      <div class="terms-section" id="termsSection">
        <div class="terms-header">
          <h3 class="terms-title">Terms and Conditions & Data Privacy Act</h3>
          <p class="terms-subtitle">Please read and accept the terms to proceed with registration</p>
        </div>
        
        <div class="terms-content">
          <h3>Important Notice</h3>
          <p>Before completing your registration, please review our Terms and Conditions and Data Privacy Act compliance statement. These documents outline your rights and responsibilities as a user of the CTU-PESO G.R.O.W.T.H. Platform.</p>
          
          <h4>Key Points:</h4>
          <ul>
            <li>Your personal data will be used for job matching and career development purposes</li>
            <li>We implement security measures to protect your information</li>
            <li>You have rights under the Data Privacy Act of 2012</li>
            <li>By accepting, you consent to data processing as described</li>
            <li>You agree to provide accurate information and use the platform responsibly</li>
          </ul>
          
          <p><strong>Please click the "View Full Terms" button below to read the complete Terms and Conditions and Data Privacy Act provisions.</strong></p>
        </div>
        
        <div class="terms-checkbox" id="termsCheckbox">
          <input type="checkbox" id="accept_terms" name="accept_terms" value="yes">
          <label for="accept_terms">
            I have read, understood, and agree to the <span>Terms and Conditions</span> and consent to the processing of my personal data in accordance with the <span>Data Privacy Act of 2012 (Republic Act 10173)</span>.
          </label>
        </div>
        <div class="terms-error" id="terms_error">You must accept the Terms and Conditions to proceed.</div>
        
        <div class="terms-actions">
          <button type="button" class="btn btn-outline" id="viewTermsBtn">
            <i class="fas fa-file-contract"></i> View Full Terms
          </button>
          <button type="button" class="btn btn-primary" id="proceedBtn" disabled>
            <i class="fas fa-arrow-right"></i> Proceed to Registration
          </button>
        </div>
      </div>
      
      <!-- Main Form (Initially Hidden) -->
      <form method="POST" action="" enctype="multipart/form-data" id="graduateForm" style="display: none;">
        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <!-- Terms Acceptance -->
        <input type="hidden" name="accept_terms" id="hiddenAcceptTerms" value="">
        
        <div class="form-grid">
          <!-- Personal Information Section -->
          <div class="form-section personal-info">
            <div class="form-header">
              <h3 class="form-title">Personal Information</h3>
              <p class="form-subtitle">Your basic personal details</p>
            </div>
            
            <?php
            // Retrieve form data from session if exists
            $form_data = isset($_SESSION['form_data']) ? $_SESSION['form_data'] : [];
            unset($_SESSION['form_data']);
            ?>
            
            <div class="form-group">
              <label class="form-label">Full Name <span class="required">*</span></label>
              <div class="name-container">
                <div>
                  <label class="form-label" for="last_name">Last Name</label>
                  <input type="text" id="last_name" name="last_name" class="form-input" 
                         value="<?php echo isset($form_data['last_name']) ? htmlspecialchars($form_data['last_name']) : ''; ?>" 
                         required placeholder="Last Name">
                  <div class="error-message" id="last_name_error"></div>
                </div>
                <div>
                  <label class="form-label" for="first_name">First Name</label>
                  <input type="text" id="first_name" name="first_name" class="form-input" 
                         value="<?php echo isset($form_data['first_name']) ? htmlspecialchars($form_data['first_name']) : ''; ?>" 
                         required placeholder="First Name">
                  <div class="error-message" id="first_name_error"></div>
                </div>
                <div>
                  <label class="form-label" for="middle_name">Middle Name</label>
                  <input type="text" id="middle_name" name="middle_name" class="form-input" 
                         value="<?php echo isset($form_data['middle_name']) ? htmlspecialchars($form_data['middle_name']) : ''; ?>" 
                         placeholder="Middle Name">
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="email">Email Address <span class="required">*</span></label>
              <input type="email" id="email" name="email" class="form-input" 
                     value="<?php echo isset($form_data['email']) ? htmlspecialchars($form_data['email']) : htmlspecialchars($user_data['usr_email']); ?>" 
                     required placeholder="Enter your email address">
              <div class="error-message" id="email_error"></div>
            </div>
            
            <!-- MODIFIED: Phone Number now required -->
            <div class="form-group">
              <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
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
                <input type="tel" id="phone" name="phone" class="form-input phone-number" 
                       value="<?php echo isset($form_data['phone']) ? htmlspecialchars($form_data['phone']) : ''; ?>" 
                       required placeholder="9234567890" maxlength="11" pattern="[0-9]{10,11}" 
                       title="Please enter 10-11 digits only (numbers only)">
              </div>
              <div class="form-text">Enter your phone number without spaces or dashes</div>
              <div class="error-message" id="phone_error"></div>
            </div>
            
            <!-- MODIFIED: Gender now required -->
            <div class="form-group">
              <label class="form-label" for="gender">Gender <span class="required">*</span></label>
              <select id="gender" name="gender" class="form-select" required>
                <option value="">Select Gender</option>
                <option value="Male" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
              </select>
              <div class="error-message" id="gender_error"></div>
            </div>
            
            <!-- MODIFIED: Birthdate now required -->
            <div class="form-group">
              <label class="form-label" for="birthdate">Birthdate <span class="required">*</span></label>
              <input type="date" id="birthdate" name="birthdate" class="form-input" 
                     value="<?php echo isset($form_data['birthdate']) ? htmlspecialchars($form_data['birthdate']) : ''; ?>"
                     required>
              <div class="error-message" id="birthdate_error"></div>
            </div>
            
            <div class="form-group">
              <label class="form-label">Address Information <span class="required">*</span></label>
              <div class="address-container">
                <div>
                  <label class="form-label" for="region">Region <span class="required">*</span></label>
                  <select id="region" name="region" class="form-select" required>
                    <option value="">Select Region</option>
                  </select>
                  <div class="error-message" id="region_error"></div>
                  <div class="loading-spinner" id="regionLoading">
                    <i class="fas fa-spinner"></i> Loading regions...
                  </div>
                </div>
                <div>
                  <label class="form-label" for="province">Province <span class="required">*</span></label>
                  <select id="province" name="province" class="form-select" required disabled>
                    <option value="">Select Province</option>
                  </select>
                  <div class="error-message" id="province_error"></div>
                  <div class="loading-spinner" id="provinceLoading" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading provinces...
                  </div>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <div class="address-container">
                <div>
                  <label class="form-label" for="city">City/Municipality <span class="required">*</span></label>
                  <select id="city" name="city" class="form-select" required disabled>
                    <option value="">Select City/Municipality</option>
                  </select>
                  <div class="error-message" id="city_error"></div>
                  <div class="loading-spinner" id="cityLoading" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading cities...
                  </div>
                </div>
                <div>
                  <label class="form-label" for="barangay">Barangay <span class="required">*</span></label>
                  <select id="barangay" name="barangay" class="form-select" required disabled>
                    <option value="">Select Barangay</option>
                  </select>
                  <div class="error-message" id="barangay_error"></div>
                  <div class="loading-spinner" id="barangayLoading" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading barangays...
                  </div>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <div class="address-container">
                <div>
                  <label class="form-label" for="zip_code">Zip Code</label>
                  <input type="text" id="zip_code" name="zip_code" class="form-input" 
                         value="<?php echo isset($form_data['zip_code']) ? htmlspecialchars($form_data['zip_code']) : ''; ?>" 
                         placeholder="Zip Code">
                </div>
                <div>
                  <label class="form-label" for="street_address">Street Address</label>
                  <input type="text" id="street_address" name="street_address" class="form-input" 
                         value="<?php echo isset($form_data['street_address']) ? htmlspecialchars($form_data['street_address']) : ''; ?>" 
                         placeholder="House No., Street, Subdivision">
                </div>
              </div>
            </div>

            <!-- Alumni ID Photo Upload Section - MOVED TO PERSONAL INFORMATION -->
            <div class="photo-upload-section">
              <h3 class="photo-upload-title">Alumni ID Verification <span class="required">*</span></h3>
              <p class="form-text" style="margin-bottom: 15px;">Please upload clear photos of both sides of your Alumni ID for verification purposes.</p>
              
              <div class="photo-upload-grid">
                <!-- Front Photo Upload -->
                <div class="file-upload-card" id="fileUploadCardFront">
                  <div class="file-upload-header">
                    <div class="file-upload-icon">
                      <i class="fas fa-id-card"></i>
                    </div>
                    <div class="file-upload-title">Front of Alumni ID</div>
                  </div>
                  <div class="file-upload-body" id="uploadBodyFront">
                    <div class="file-upload-text">
                      Upload a clear photo of the front side of your Alumni ID showing your photo and details.
                    </div>
                    <label for="alumni_id_photo_front" class="file-upload-btn">
                      <i class="fas fa-upload"></i> Choose File
                    </label>
                    <div class="file-requirements">
                      <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB
                    </div>
                  </div>
                  <input type="file" id="alumni_id_photo_front" name="alumni_id_photo_front" class="file-input" 
                         accept="image/jpeg,image/jpg,image/png,image/gif" required>
                  <div class="error-message" id="alumni_id_photo_front_error"></div>
                </div>
                
                <!-- Back Photo Upload -->
                <div class="file-upload-card" id="fileUploadCardBack">
                  <div class="file-upload-header">
                    <div class="file-upload-icon">
                      <i class="fas fa-id-card-alt"></i>
                    </div>
                    <div class="file-upload-title">Back of Alumni ID</div>
                  </div>
                  <div class="file-upload-body" id="uploadBodyBack">
                    <div class="file-upload-text">
                      Upload a clear photo of the back side of your Alumni ID showing additional details.
                    </div>
                    <label for="alumni_id_photo_back" class="file-upload-btn">
                      <i class="fas fa-upload"></i> Choose File
                    </label>
                    <div class="file-requirements">
                      <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB
                    </div>
                  </div>
                  <input type="file" id="alumni_id_photo_back" name="alumni_id_photo_back" class="file-input" 
                         accept="image/jpeg,image/jpg,image/png,image/gif" required>
                  <div class="error-message" id="alumni_id_photo_back_error"></div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Academic Information Section -->
          <div class="form-section academic-info">
            <div class="form-header">
              <h3 class="form-title">Academic Information</h3>
              <p class="form-subtitle">Your educational background and career preferences</p>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="alumni_number">Alumni ID Number <span class="required">*</span></label>
              <input type="text" id="alumni_number" name="alumni_number" class="form-input" 
                     value="<?php echo isset($form_data['alumni_number']) ? htmlspecialchars($form_data['alumni_number']) : ''; ?>" 
                     required placeholder="Enter your alumni ID number">
              <div class="error-message" id="alumni_number_error"></div>
            </div>
            
            <!-- ENHANCED: Course Selection - KEEPING ORIGINAL UI BUT ENSURING ALL COURSES ARE LOADED -->
            <div class="form-group">
              <label class="form-label" for="degree">Degree/Course <span class="required">*</span></label>
              <select id="degree" name="degree" class="form-select" required>
                <option value="">Select your course</option>
                <?php if (!empty($courses) && !empty($colleges)): ?>
                  <?php foreach ($colleges as $college): ?>
                    <?php if (isset($courses[$college]) && !empty($courses[$college])): ?>
                      <optgroup label="<?php echo htmlspecialchars($college); ?>">
                        <?php foreach ($courses[$college] as $course): ?>
                          <option value="<?php echo htmlspecialchars($course['course_code']); ?>"
                            <?php echo (isset($form_data['degree']) && $form_data['degree'] === $course['course_code']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($course['course_name']); ?>
                          </option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <!-- Fallback in case database query fails - This will be replaced by the PHP code above if courses exist -->
                  <optgroup label="College of Education (CoEd)">
                    <option value="BEEd">BEEd – Bachelor in Elementary Education</option>
                    <option value="BSEd">BSEd – Bachelor in Secondary Education</option>
                  </optgroup>
                  <optgroup label="College of Engineering (CoE)">
                    <option value="BSCE">BSCE – Bachelor of Science in Civil Engineering</option>
                    <option value="BSECE">BSECE – Bachelor of Science in Electronics Engineering</option>
                  </optgroup>
                <?php endif; ?>
              </select>
              <div class="error-message" id="degree_error"></div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="year_graduated">Year Graduated <span class="required">*</span></label>
              <input type="number" id="year_graduated" name="year_graduated" class="form-input" 
                     value="<?php echo isset($form_data['year_graduated']) ? htmlspecialchars($form_data['year_graduated']) : ''; ?>" 
                     required min="<?php echo MIN_YEAR_GRADUATED; ?>" max="<?php echo date('Y'); ?>" 
                     placeholder="<?php echo date('Y'); ?>">
              <div class="error-message" id="year_graduated_error"></div>
            </div>
            
            <!-- Enhanced Job Preferences Section - NO LIMIT -->
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
                                     class="job-preference-checkbox">
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
                    <div class="no-selection-message">No job preferences selected yet</div>
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
              <label class="form-label" for="summary">Career Summary/Objective</label>
              <textarea id="summary" name="summary" class="form-textarea" 
                        placeholder="Briefly describe your career goals, and objectives..."><?php echo isset($form_data['summary']) ? htmlspecialchars($form_data['summary']) : ''; ?></textarea>
              <div class="form-text">Optional: Share your career aspirations and key skills</div>
            </div>
          </div>
        </div>
        
        <div class="action-buttons">
          <button type="submit" class="btn btn-primary" id="submitBtn">
            <i class="fas fa-check-circle"></i> Complete Registration
          </button>
          <a href="index.php?page=select_role" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back to Role Selection
          </a>
        </div>
      </form>
    </div>
  </div>

  <script>
    // PSGC API Integration
    class PSGCAddressAPI {
      constructor() {
        this.baseURL = window.location.origin + window.location.pathname;
        this.cache = {
          regions: null,
          provinces: {},
          cities: {},
          barangays: {}
        };
      }
      
      async fetchData(endpoint, params = {}) {
        const url = new URL(this.baseURL);
        Object.keys(params).forEach(key => {
          url.searchParams.append(key, params[key]);
        });
        url.searchParams.append(endpoint, '1');
        
        try {
          const response = await fetch(url.toString(), {
            method: 'GET',
            headers: {
              'Accept': 'application/json'
            }
          });
          
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }
          
          const data = await response.json();
          return Array.isArray(data) ? data : [];
        } catch (error) {
          console.warn('API fetch failed:', error);
          return [];
        }
      }
      
      async getRegions() {
        if (this.cache.regions) {
          return this.cache.regions;
        }
        
        const regions = await this.fetchData('getRegions');
        this.cache.regions = regions;
        return regions;
      }
      
      async getProvinces(regionCode) {
        if (this.cache.provinces[regionCode]) {
          return this.cache.provinces[regionCode];
        }
        
        const provinces = await this.fetchData('getProvinces', { regionCode: regionCode });
        this.cache.provinces[regionCode] = provinces;
        return provinces;
      }
      
      async getCities(provinceCode) {
        if (this.cache.cities[provinceCode]) {
          return this.cache.cities[provinceCode];
        }
        
        const cities = await this.fetchData('getCities', { provinceCode: provinceCode });
        this.cache.cities[provinceCode] = cities;
        return cities;
      }
      
      async getBarangays(cityCode) {
        if (this.cache.barangays[cityCode]) {
          return this.cache.barangays[cityCode];
        }
        
        const barangays = await this.fetchData('getBarangays', { cityCode: cityCode });
        this.cache.barangays[cityCode] = barangays;
        return barangays;
      }
    }

    // Form validation and utilities
    class FormValidator {
      constructor() {
        this.errors = {};
      }
      
      validateRequired(value, fieldName) {
        if (!value || value.trim() === '') {
          this.errors[fieldName] = `${fieldName.replace('_', ' ')} is required`;
          return false;
        }
        return true;
      }
      
      validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailRegex.test(email)) {
          this.errors.email = 'Please enter a valid email address';
          return false;
        }
        return true;
      }
      
      validatePhone(phone) {
        const phoneRegex = /^[0-9]{10,11}$/;
        if (!phone) {
          this.errors.phone = 'Phone number is required';
          return false;
        }
        if (!phoneRegex.test(phone)) {
          this.errors.phone = 'Phone number must be 10-11 digits';
          return false;
        }
        return true;
      }
      
      validateYear(year) {
        const currentYear = new Date().getFullYear();
        const minYear = <?php echo MIN_YEAR_GRADUATED; ?>;
        
        if (year < minYear || year > currentYear) {
          this.errors.year_graduated = `Year must be between ${minYear} and ${currentYear}`;
          return false;
        }
        return true;
      }
      
      validateGender(gender) {
        if (!gender) {
          this.errors.gender = 'Gender is required';
          return false;
        }
        return true;
      }
      
      validateBirthdate(birthdate) {
        if (!birthdate) {
          this.errors.birthdate = 'Birthdate is required';
          return false;
        }
        
        const birthDate = new Date(birthdate);
        const today = new Date();
        const minAgeDate = new Date();
        minAgeDate.setFullYear(today.getFullYear() - 150); // Max age 150 years
        
        if (birthDate > today) {
          this.errors.birthdate = 'Birthdate cannot be in the future';
          return false;
        }
        
        if (birthDate < minAgeDate) {
          this.errors.birthdate = 'Please enter a valid birthdate';
          return false;
        }
        
        return true;
      }
      
      validateFile(file, fieldName) {
        if (!file || file.size === 0) {
          this.errors[fieldName] = `${fieldName} is required`;
          return false;
        }
        
        const maxSize = <?php echo MAX_FILE_SIZE; ?>;
        if (file.size > maxSize) {
          this.errors[fieldName] = `File size must be less than ${maxSize / 1024 / 1024}MB`;
          return false;
        }
        
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
          this.errors[fieldName] = 'Only JPG, JPEG, PNG, and GIF files are allowed';
          return false;
        }
        
        return true;
      }
      
      clearErrors() {
        this.errors = {};
        document.querySelectorAll('.error-message').forEach(el => {
          el.classList.remove('show');
          el.textContent = '';
        });
        document.querySelectorAll('.form-input, .form-select').forEach(el => {
          el.classList.remove('error');
        });
        document.querySelectorAll('.form-group').forEach(el => {
          el.classList.remove('has-error');
        });
      }
      
      showErrors() {
        for (const [field, message] of Object.entries(this.errors)) {
          const errorElement = document.getElementById(`${field}_error`);
          const inputElement = document.getElementById(field) || document.querySelector(`[name="${field}"]`);
          const formGroup = inputElement ? inputElement.closest('.form-group') : null;
          
          if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.add('show');
          }
          
          if (inputElement) {
            inputElement.classList.add('error');
          }
          
          if (formGroup) {
            formGroup.classList.add('has-error');
          }
        }
      }
      
      isValid() {
        return Object.keys(this.errors).length === 0;
      }
    }

    // Job Preferences Manager - NO LIMIT
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
        // Add event listeners to checkboxes
        document.querySelectorAll('.job-preference-checkbox').forEach(checkbox => {
          checkbox.addEventListener('change', (e) => this.handleCheckboxChange(e));
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

    // Terms and Conditions Manager
    class TermsManager {
      constructor() {
        this.termsModal = document.getElementById('termsModal');
        this.closeTermsModalBtn = document.getElementById('closeTermsModal');
        this.viewTermsBtn = document.getElementById('viewTermsBtn');
        this.proceedBtn = document.getElementById('proceedBtn');
        this.declineTermsBtn = document.getElementById('declineTerms');
        this.acceptTermsModalBtn = document.getElementById('acceptTermsModal');
        this.termsCheckbox = document.getElementById('accept_terms');
        this.hiddenAcceptTerms = document.getElementById('hiddenAcceptTerms');
        this.termsSection = document.getElementById('termsSection');
        this.graduateForm = document.getElementById('graduateForm');
        this.modalAgreeCheckbox = document.getElementById('modalAgreeCheckbox');
        this.termsError = document.getElementById('terms_error');
        
        this.initialize();
      }
      
      initialize() {
        // Event listeners for terms management
        this.viewTermsBtn.addEventListener('click', () => this.showModal());
        this.closeTermsModalBtn.addEventListener('click', () => this.hideModal());
        this.declineTermsBtn.addEventListener('click', () => this.declineTerms());
        this.acceptTermsModalBtn.addEventListener('click', () => this.acceptTermsFromModal());
        this.modalAgreeCheckbox.addEventListener('change', (e) => this.toggleModalAcceptButton(e));
        this.termsCheckbox.addEventListener('change', (e) => this.toggleProceedButton(e));
        this.proceedBtn.addEventListener('click', () => this.proceedToForm());
        
        // Close modal when clicking outside
        window.addEventListener('click', (e) => {
          if (e.target === this.termsModal) {
            this.hideModal();
          }
        });
        
        // Escape key to close modal
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape' && this.termsModal.style.display === 'flex') {
            this.hideModal();
          }
        });
      }
      
      showModal() {
        this.termsModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Reset modal checkbox
        this.modalAgreeCheckbox.checked = false;
        this.acceptTermsModalBtn.disabled = true;
      }
      
      hideModal() {
        this.termsModal.style.display = 'none';
        document.body.style.overflow = 'auto';
      }
      
      toggleModalAcceptButton(e) {
        this.acceptTermsModalBtn.disabled = !e.target.checked;
      }
      
      toggleProceedButton(e) {
        this.proceedBtn.disabled = !e.target.checked;
        this.termsCheckbox.classList.toggle('error', false);
        this.termsError.classList.remove('show');
      }
      
      acceptTermsFromModal() {
        if (this.modalAgreeCheckbox.checked) {
          this.termsCheckbox.checked = true;
          this.proceedBtn.disabled = false;
          this.hideModal();
          this.showNotification('Terms accepted successfully. You may now proceed to registration.', 'success');
        }
      }
      
      declineTerms() {
        if (confirm('Declining the Terms and Conditions will cancel your registration. Are you sure you want to decline?')) {
          window.location.href = 'index.php?page=select_role';
        }
      }
      
      proceedToForm() {
        if (this.termsCheckbox.checked) {
          // Set the hidden field value
          this.hiddenAcceptTerms.value = 'yes';
          
          // Hide terms section and show form
          this.termsSection.style.display = 'none';
          this.graduateForm.style.display = 'block';
          
          // Scroll to top of form
          this.graduateForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
          
          this.showNotification('Please complete the registration form below.', 'info');
        } else {
          this.termsCheckbox.classList.add('error');
          this.termsError.classList.add('show');
          this.showNotification('You must accept the Terms and Conditions to proceed.', 'error');
        }
      }
      
      validateTerms() {
        if (!this.termsCheckbox.checked) {
          this.termsCheckbox.classList.add('error');
          this.termsError.classList.add('show');
          return false;
        }
        return true;
      }
      
      showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
        notification.innerHTML = `
          <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'check-circle'}"></i>
          ${message}
        `;
        
        const content = document.querySelector('.content');
        content.insertBefore(notification, content.firstChild);
        
        setTimeout(() => {
          notification.remove();
        }, 5000);
      }
    }

    // Main application
    document.addEventListener('DOMContentLoaded', function() {
      const addressAPI = new PSGCAddressAPI();
      const validator = new FormValidator();
      const jobManager = new JobPreferencesManager();
      const termsManager = new TermsManager();
      const form = document.getElementById('graduateForm');
      const submitBtn = document.getElementById('submitBtn');
      const loadingOverlay = document.getElementById('loadingOverlay');
      
      // DOM elements
      const regionSelect = document.getElementById('region');
      const provinceSelect = document.getElementById('province');
      const citySelect = document.getElementById('city');
      const barangaySelect = document.getElementById('barangay');
      const regionLoading = document.getElementById('regionLoading');
      const provinceLoading = document.getElementById('provinceLoading');
      const cityLoading = document.getElementById('cityLoading');
      const barangayLoading = document.getElementById('barangayLoading');
      
      // File upload elements
      const fileInputFront = document.getElementById('alumni_id_photo_front');
      const uploadBodyFront = document.getElementById('uploadBodyFront');
      const fileUploadCardFront = document.getElementById('fileUploadCardFront');
      const fileInputBack = document.getElementById('alumni_id_photo_back');
      const uploadBodyBack = document.getElementById('uploadBodyBack');
      const fileUploadCardBack = document.getElementById('fileUploadCardBack');
      
      // Load regions on page load (but only when form is shown)
      // We'll load them when the form becomes visible
      
      async function loadRegions() {
        regionLoading.style.display = 'block';
        try {
          const regions = await addressAPI.getRegions();
          regionSelect.innerHTML = '<option value="">Select Region</option>';
          
          if (regions.length === 0) {
            showNotification('Unable to load regions. Please refresh the page.', 'error');
          } else {
            regions.forEach(region => {
              const option = document.createElement('option');
              option.value = region.name; // Store the name
              option.setAttribute('data-code', region.code);
              option.textContent = region.name;
              regionSelect.appendChild(option);
            });
          }
          
          regionLoading.style.display = 'none';
          provinceSelect.disabled = false;
          setupAddressListeners();
        } catch (error) {
          console.error('Error loading regions:', error);
          regionLoading.style.display = 'none';
          showNotification('Error loading address data. Please refresh the page.', 'error');
        }
      }
      
      function setupAddressListeners() {
        regionSelect.addEventListener('change', async function() {
          const regionName = this.value;
          const regionCode = this.options[this.selectedIndex].getAttribute('data-code');
          
          if (!regionName || !regionCode) {
            resetDropdown(provinceSelect, 'Select Province');
            resetDropdown(citySelect, 'Select City/Municipality');
            resetDropdown(barangaySelect, 'Select Barangay');
            return;
          }
          
          provinceLoading.style.display = 'block';
          provinceSelect.disabled = true;
          provinceSelect.innerHTML = '<option value="">Select Province</option>';
          
          resetDropdown(citySelect, 'Select City/Municipality');
          resetDropdown(barangaySelect, 'Select Barangay');
          
          try {
            const provinces = await addressAPI.getProvinces(regionCode);
            provinceSelect.innerHTML = '<option value="">Select Province</option>';
            
            if (provinces.length > 0) {
              provinces.forEach(province => {
                const option = document.createElement('option');
                option.value = province.name;
                option.setAttribute('data-code', province.code);
                option.textContent = province.name;
                provinceSelect.appendChild(option);
              });
              
              provinceLoading.style.display = 'none';
              provinceSelect.disabled = false;
              
              if (provinces.length === 1) {
                provinceSelect.value = provinces[0].name;
                setTimeout(() => provinceSelect.dispatchEvent(new Event('change')), 100);
              }
            } else {
              provinceLoading.style.display = 'none';
              provinceSelect.disabled = false;
              showNotification('No provinces found for this region.', 'warning');
            }
          } catch (error) {
            console.error('Error loading provinces:', error);
            provinceLoading.style.display = 'none';
            provinceSelect.disabled = false;
            showNotification('Error loading provinces', 'error');
          }
        });
        
        provinceSelect.addEventListener('change', async function() {
          const provinceName = this.value;
          const provinceCode = this.options[this.selectedIndex].getAttribute('data-code');
          
          if (!provinceName || !provinceCode) {
            resetDropdown(citySelect, 'Select City/Municipality');
            resetDropdown(barangaySelect, 'Select Barangay');
            return;
          }
          
          cityLoading.style.display = 'block';
          citySelect.disabled = true;
          citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
          resetDropdown(barangaySelect, 'Select Barangay');
          
          try {
            const cities = await addressAPI.getCities(provinceCode);
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            
            if (cities.length > 0) {
              cities.forEach(city => {
                const option = document.createElement('option');
                option.value = city.name;
                option.setAttribute('data-code', city.code);
                option.textContent = city.name;
                citySelect.appendChild(option);
              });
              
              cityLoading.style.display = 'none';
              citySelect.disabled = false;
              
              if (cities.length === 1) {
                citySelect.value = cities[0].name;
                setTimeout(() => citySelect.dispatchEvent(new Event('change')), 100);
              }
            } else {
              cityLoading.style.display = 'none';
              citySelect.disabled = false;
              showNotification('No cities/municipalities found for this province.', 'warning');
            }
          } catch (error) {
            console.error('Error loading cities:', error);
            cityLoading.style.display = 'none';
            citySelect.disabled = false;
            showNotification('Error loading cities', 'error');
          }
        });
        
        citySelect.addEventListener('change', async function() {
          const cityName = this.value;
          const cityCode = this.options[this.selectedIndex].getAttribute('data-code');
          
          if (!cityName || !cityCode) {
            resetDropdown(barangaySelect, 'Select Barangay');
            return;
          }
          
          barangayLoading.style.display = 'block';
          barangaySelect.disabled = true;
          barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
          
          try {
            const barangays = await addressAPI.getBarangays(cityCode);
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            
            if (barangays.length > 0) {
              barangays.forEach(barangay => {
                const option = document.createElement('option');
                option.value = barangay.name;
                option.textContent = barangay.name;
                barangaySelect.appendChild(option);
              });
              
              barangayLoading.style.display = 'none';
              barangaySelect.disabled = false;
              
              if (barangays.length === 1) {
                barangaySelect.value = barangays[0].name;
              }
            } else {
              barangayLoading.style.display = 'none';
              barangaySelect.disabled = false;
              showNotification('No barangays found for this city/municipality.', 'warning');
            }
          } catch (error) {
            console.error('Error loading barangays:', error);
            barangayLoading.style.display = 'none';
            barangaySelect.disabled = false;
            showNotification('Error loading barangays', 'error');
          }
        });
      }
      
      function resetDropdown(selectElement, placeholder) {
        selectElement.innerHTML = `<option value="">${placeholder}</option>`;
        selectElement.disabled = true;
        selectElement.value = '';
      }
      
      // File upload handlers
      fileInputFront.addEventListener('change', function(e) {
        handleFileUpload(e, uploadBodyFront, 'front');
      });
      
      fileInputBack.addEventListener('change', function(e) {
        handleFileUpload(e, uploadBodyBack, 'back');
      });
      
      function handleFileUpload(event, uploadBody, side) {
        const file = event.target.files[0];
        if (file) {
          const maxSize = <?php echo MAX_FILE_SIZE; ?>;
          const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
          
          if (!allowedTypes.includes(file.type)) {
            showNotification('Please select a valid image file (JPG, JPEG, PNG, GIF).', 'error');
            event.target.value = '';
            return;
          }
          
          if (file.size > maxSize) {
            showNotification(`File size must be less than ${maxSize / 1024 / 1024}MB.`, 'error');
            event.target.value = '';
            return;
          }
          
          const reader = new FileReader();
          reader.onload = function(e) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            const fileName = file.name.length > 20 ? file.name.substring(0, 20) + '...' : file.name;
            
            uploadBody.innerHTML = `
              <div class="file-preview">
                <img src="${e.target.result}" alt="${side} Preview">
                <div class="file-info">
                  <div class="file-info-item"><strong>File:</strong> ${fileName}</div>
                  <div class="file-info-item"><strong>Size:</strong> ${fileSize} MB</div>
                  <div class="file-info-item"><strong>Type:</strong> ${file.type.split('/')[1].toUpperCase()}</div>
                </div>
                <div class="file-status success">
                  <i class="fas fa-check-circle"></i> File uploaded
                </div>
                <div class="preview-actions">
                  <button type="button" class="preview-action-btn primary" onclick="document.getElementById('alumni_id_photo_${side}').click()">
                    <i class="fas fa-sync-alt"></i> Replace
                  </button>
                  <button type="button" class="preview-action-btn" onclick="removeUploadedImage('${side}')">
                    <i class="fas fa-trash"></i> Remove
                  </button>
                </div>
              </div>
            `;
            uploadBody.classList.add('has-preview');
          };
          reader.readAsDataURL(file);
        }
      }
      
      // Drag and drop
      [fileUploadCardFront, fileUploadCardBack].forEach((card, index) => {
        const side = index === 0 ? 'front' : 'back';
        
        card.addEventListener('dragover', function(e) {
          e.preventDefault();
          card.classList.add('dragover');
        });
        
        card.addEventListener('dragleave', function(e) {
          e.preventDefault();
          card.classList.remove('dragover');
        });
        
        card.addEventListener('drop', function(e) {
          e.preventDefault();
          card.classList.remove('dragover');
          
          const files = e.dataTransfer.files;
          if (files.length > 0) {
            const fileInput = document.getElementById(`alumni_id_photo_${side}`);
            fileInput.files = files;
            fileInput.dispatchEvent(new Event('change'));
          }
        });
      });
      
      // Phone number validation
      const phoneInput = document.getElementById('phone');
      phoneInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        if (value.length > <?php echo MAX_PHONE_LENGTH; ?>) {
          value = value.substring(0, <?php echo MAX_PHONE_LENGTH; ?>);
        }
        e.target.value = value;
      });
      
      // Birthdate validation - set max date to today
      const birthdateInput = document.getElementById('birthdate');
      const today = new Date().toISOString().split('T')[0];
      birthdateInput.setAttribute('max', today);
      
      // Form validation
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // First validate terms acceptance
        if (!termsManager.validateTerms()) {
          showNotification('You must accept the Terms and Conditions to proceed.', 'error');
          return;
        }
        
        validator.clearErrors();
        jobManager.clearError();
        
        // Validate job preferences
        if (!jobManager.validate()) {
          showNotification('Please correct the errors in the form.', 'error');
          return;
        }
        
        // Validate required fields
        const requiredFields = [
          'last_name', 'first_name', 'email', 'alumni_number', 
          'degree', 'year_graduated', 'region', 'province', 'city', 'barangay',
          'phone', 'gender', 'birthdate'
        ];
        
        requiredFields.forEach(field => {
          const element = document.getElementById(field);
          if (element) {
            validator.validateRequired(element.value, field);
          }
        });
        
        // Validate email
        const email = document.getElementById('email').value;
        validator.validateEmail(email);
        
        // Validate phone
        const phone = phoneInput.value;
        validator.validatePhone(phone);
        
        // Validate gender
        const gender = document.getElementById('gender').value;
        validator.validateGender(gender);
        
        // Validate birthdate
        const birthdate = birthdateInput.value;
        validator.validateBirthdate(birthdate);
        
        // Validate year graduated
        const yearGraduated = parseInt(document.getElementById('year_graduated').value);
        if (!isNaN(yearGraduated)) {
          validator.validateYear(yearGraduated);
        }
        
        // Validate files
        const frontFile = fileInputFront.files[0];
        const backFile = fileInputBack.files[0];
        
        if (frontFile) {
          validator.validateFile(frontFile, 'alumni_id_photo_front');
        } else {
          validator.errors.alumni_id_photo_front = 'Front photo is required';
        }
        
        if (backFile) {
          validator.validateFile(backFile, 'alumni_id_photo_back');
        } else {
          validator.errors.alumni_id_photo_back = 'Back photo is required';
        }
        
        // Validate address selections
        if (regionSelect.value === '') {
          validator.errors.region = 'Region is required';
        }
        if (provinceSelect.value === '') {
          validator.errors.province = 'Province is required';
        }
        if (citySelect.value === '') {
          validator.errors.city = 'City/Municipality is required';
        }
        if (barangaySelect.value === '') {
          validator.errors.barangay = 'Barangay is required';
        }
        
        if (!validator.isValid()) {
          validator.showErrors();
          showNotification('Please correct the errors in the form.', 'error');
          return;
        }
        
        // Show loading overlay
        showLoading(true);
        submitBtn.disabled = true;
        
        // Submit form
        try {
          // Allow the form to submit normally
          form.submit();
        } catch (error) {
          console.error('Form submission error:', error);
          showNotification('Error submitting form. Please try again.', 'error');
          showLoading(false);
          submitBtn.disabled = false;
        }
      });
      
      // Load regions when form becomes visible
      const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
            if (form.style.display !== 'none') {
              loadRegions();
              observer.disconnect(); // Stop observing once loaded
            }
          }
        });
      });
      
      // Start observing the form for style changes
      observer.observe(form, { attributes: true });
      
      // Helper functions
      function showLoading(show) {
        if (show) {
          loadingOverlay.style.display = 'flex';
        } else {
          loadingOverlay.style.display = 'none';
        }
      }
      
      function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
        notification.innerHTML = `
          <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'check-circle'}"></i>
          ${message}
        `;
        
        const content = document.querySelector('.content');
        content.insertBefore(notification, content.firstChild);
        
        setTimeout(() => {
          notification.remove();
        }, 5000);
      }
    });
    
    // Global function to remove uploaded image
    function removeUploadedImage(side) {
      const fileInput = document.getElementById(`alumni_id_photo_${side}`);
      const uploadBody = document.getElementById(`uploadBody${side.charAt(0).toUpperCase() + side.slice(1)}`);
      
      fileInput.value = '';
      uploadBody.innerHTML = `
        <div class="file-upload-text">
          Upload a clear photo of the ${side} side of your Alumni ID showing your photo and details.
        </div>
        <label for="alumni_id_photo_${side}" class="file-upload-btn">
          <i class="fas fa-upload"></i> Choose File
        </label>
        <div class="file-requirements">
          <i class="fas fa-info-circle"></i> Accepted formats: JPG, JPEG, PNG, GIF | Max size: <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB
        </div>
      `;
      uploadBody.classList.remove('has-preview');
    }
  </script>
</body>
</html>