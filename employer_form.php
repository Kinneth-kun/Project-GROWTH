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
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_FILE_TYPES', ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 
                             'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
define('UPLOAD_DIR', 'uploads/employer_documents/');
define('MIN_PHONE_LENGTH', 10);
define('MAX_PHONE_LENGTH', 11);

// ============================================
// DATABASE CONNECTION WITH ERROR HANDLING
// ============================================
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] employer_form.php: Database Connection Failed: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

// ============================================
// CHECK AUTO-APPROVE SETTING
// ============================================
$auto_approve_users = 0;
try {
    $settings_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_users'");
    $settings_stmt->execute();
    $auto_approve_setting = $settings_stmt->fetch(PDO::FETCH_COLUMN);
    
    if ($auto_approve_setting !== false) {
        $auto_approve_users = intval($auto_approve_setting);
    }
} catch (PDOException $e) {
    // If table doesn't exist yet or setting not found, use default value (0)
    $auto_approve_users = 0;
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
// FETCH INDUSTRIES FROM DATABASE
// ============================================
$industries = [];
$grouped_industries = [];
try {
    $industry_stmt = $conn->prepare("
        SELECT industry_id, industry_name, is_custom 
        FROM industry_categories 
        WHERE is_active = TRUE 
        ORDER BY 
            is_custom ASC, 
            CASE 
                WHEN industry_name LIKE 'Software%' THEN 1
                WHEN industry_name LIKE 'IT%' THEN 2
                WHEN industry_name LIKE 'Telecommunications%' THEN 3
                WHEN industry_name LIKE 'E-commerce%' THEN 4
                WHEN industry_name LIKE 'Digital Marketing%' THEN 5
                WHEN industry_name LIKE 'Cyber%' THEN 6
                ELSE 99
            END,
            industry_name ASC
    ");
    $industry_stmt->execute();
    $industries = $industry_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group industries by category for better display
    foreach ($industries as $industry) {
        $category = '';
        $industry_name = $industry['industry_name'];
        
        // Categorize industries
        if (strpos($industry_name, 'Software') === 0 || 
            strpos($industry_name, 'IT') === 0 ||
            strpos($industry_name, 'Digital') === 0 ||
            strpos($industry_name, 'Data') === 0 ||
            strpos($industry_name, 'Cyber') === 0 ||
            $industry_name === 'E-commerce' ||
            $industry_name === 'Artificial Intelligence') {
            $category = 'Technology & IT';
        } elseif (strpos($industry_name, 'Engineering') !== false || 
                 strpos($industry_name, 'Manufacturing') !== false ||
                 $industry_name === 'Construction' ||
                 $industry_name === 'Automotive' ||
                 $industry_name === 'Aerospace') {
            $category = 'Engineering & Manufacturing';
        } elseif (strpos($industry_name, 'Health') !== false || 
                 strpos($industry_name, 'Medical') !== false ||
                 strpos($industry_name, 'Pharma') !== false ||
                 $industry_name === 'Biotechnology') {
            $category = 'Healthcare & Pharmaceuticals';
        } elseif (strpos($industry_name, 'Finance') !== false || 
                 strpos($industry_name, 'Bank') !== false ||
                 $industry_name === 'Insurance' ||
                 $industry_name === 'Investment' ||
                 $industry_name === 'Accounting' ||
                 $industry_name === 'Fintech') {
            $category = 'Finance & Banking';
        } elseif (strpos($industry_name, 'Education') !== false || 
                 strpos($industry_name, 'Training') !== false ||
                 $industry_name === 'Research & Development') {
            $category = 'Education & Training';
        } elseif (strpos($industry_name, 'Hospitality') !== false || 
                 strpos($industry_name, 'Hotel') !== false ||
                 strpos($industry_name, 'Restaurant') !== false ||
                 strpos($industry_name, 'Travel') !== false ||
                 strpos($industry_name, 'Event') !== false ||
                 strpos($industry_name, 'Food') !== false) {
            $category = 'Hospitality & Tourism';
        } elseif (strpos($industry_name, 'Retail') !== false || 
                 strpos($industry_name, 'Consumer') !== false ||
                 $industry_name === 'Fashion' ||
                 $industry_name === 'FMCG') {
            $category = 'Retail & Consumer Goods';
        } elseif (strpos($industry_name, 'Government') !== false || 
                 strpos($industry_name, 'Public') !== false ||
                 $industry_name === 'Defense') {
            $category = 'Government & Public Sector';
        } else {
            $category = 'Other Industries';
        }
        
        // Separate custom industries
        if ($industry['is_custom']) {
            $category = 'Custom Industries';
        }
        
        if (!isset($grouped_industries[$category])) {
            $grouped_industries[$category] = [];
        }
        
        $grouped_industries[$category][] = $industry;
    }
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] employer_form.php: Error fetching industries - " . $e->getMessage());
    // Fallback to empty array
    $grouped_industries = [];
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
    $user_stmt = $conn->prepare("SELECT usr_name, usr_email, usr_phone, usr_gender, usr_birthdate, usr_address FROM users WHERE usr_id = :user_id");
    $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $user_stmt->execute();
    $user_data = $user_stmt->fetch();
    
    if (!$user_data) {
        $_SESSION['error'] = "User not found.";
        header('Location: index.php?page=login');
        exit;
    }
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] employer_form.php: Error fetching user data - " . $e->getMessage());
    $_SESSION['error'] = "Error loading user data.";
    header('Location: index.php?page=login');
    exit;
}

// ============================================
// CHECK EXISTING EMPLOYER PROFILE
// ============================================
$existing_employer = null;
try {
    $stmt = $conn->prepare("
        SELECT e.* 
        FROM employers e 
        WHERE e.emp_usr_id = :user_id
    ");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $existing_employer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_employer) {
        // If employer exists, redirect based on auto-approve setting
        // Update user status based on auto-approve setting
        if ($auto_approve_users) {
            $update_stmt = $conn->prepare("
                UPDATE users 
                SET usr_is_approved = TRUE, 
                    usr_account_status = 'active',
                    usr_updated_at = NOW()
                WHERE usr_id = :user_id
            ");
        } else {
            $update_stmt = $conn->prepare("
                UPDATE users 
                SET usr_account_status = 'pending',
                    usr_updated_at = NOW()
                WHERE usr_id = :user_id
            ");
        }
        $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Set session variables
        $_SESSION['user_id'] = $user_id;
        $_SESSION['logged_in'] = true;
        $_SESSION['is_approved'] = $auto_approve_users;
        $_SESSION['account_status'] = $auto_approve_users ? 'active' : 'pending';
        
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
        
        // MODIFIED: Redirect to different pages based on auto-approve setting
        if ($auto_approve_users) {
            $redirect_message = "Employer profile already completed.";
            header('Location: employer_dashboard.php?message=' . urlencode($redirect_message));
        } else {
            $redirect_message = "Employer profile completed. Waiting for admin approval.";
            header('Location: employer_profile.php?message=' . urlencode($redirect_message));
        }
        exit;
    }
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] employer_form.php: Database error checking existing profile - " . $e->getMessage());
}

// ============================================
// VALIDATION FUNCTIONS
// ============================================
function validatePhoneNumber($phone, $country_code) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (empty($phone)) {
        return ['valid' => true, 'phone' => '', 'full_phone' => ''];
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

function validateFileUpload($file, $field_name, $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']) {
    // Check if file was uploaded
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => ucfirst($field_name) . " file is required."];
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
        return ['success' => false, 'error' => "Only PDF, JPG, JPEG, PNG, DOC, and DOCX files are allowed."];
    }
    
    // Validate file extension
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['success' => false, 'error' => "Invalid file extension. Allowed: " . implode(', ', $allowed_extensions)];
    }
    
    return ['success' => true, 'mime_type' => $mime_type, 'tmp_name' => $file['tmp_name'], 'name' => $file['name'], 'extension' => $file_extension];
}

// ============================================
// PARSE EXISTING USER DATA FOR PRE-FILLING
// ============================================
$parsed_address = [
    'street_address' => '',
    'barangay' => '',
    'city' => '',
    'province' => '',
    'region' => '',
    'zip_code' => ''
];

if (!empty($user_data['usr_address'])) {
    // Parse the address string (format: Street, Barangay, City, Province, Region, ZIP: 6000)
    $address_parts = explode(', ', $user_data['usr_address']);
    
    foreach ($address_parts as $part) {
        if (strpos($part, 'ZIP: ') === 0) {
            $parsed_address['zip_code'] = str_replace('ZIP: ', '', $part);
        } elseif (empty($parsed_address['street_address'])) {
            $parsed_address['street_address'] = $part;
        } elseif (empty($parsed_address['barangay'])) {
            $parsed_address['barangay'] = $part;
        } elseif (empty($parsed_address['city'])) {
            $parsed_address['city'] = $part;
        } elseif (empty($parsed_address['province'])) {
            $parsed_address['province'] = $part;
        } elseif (empty($parsed_address['region'])) {
            $parsed_address['region'] = $part;
        }
    }
}

// Parse name from usr_name
$full_name = $user_data['usr_name'] ?? '';
$name_parts = explode(' ', $full_name);
$first_name = $name_parts[0] ?? '';
$middle_name = count($name_parts) > 2 ? $name_parts[1] : '';
$last_name = count($name_parts) > 1 ? end($name_parts) : '';

// Parse phone number
$stored_phone = $user_data['usr_phone'] ?? '';
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

// ============================================
// PROCESS FORM SUBMISSION
// ============================================
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    
    // Employer-specific fields
    $company_name = trim(filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_STRING));
    $industry_id = isset($_POST['industry']) ? intval($_POST['industry']) : 0;
    $custom_industry = trim(filter_input(INPUT_POST, 'custom_industry', FILTER_SANITIZE_STRING) ?? '');
    $contact_person = trim(filter_input(INPUT_POST, 'contact_person', FILTER_SANITIZE_STRING));
    $company_description = trim(filter_input(INPUT_POST, 'company_description', FILTER_SANITIZE_STRING));
    
    // Validate required fields
    $required_fields = [
        'last_name' => $last_name,
        'first_name' => $first_name,
        'email' => $email,
        'company_name' => $company_name,
        'contact_person' => $contact_person,
        'region' => $region,
        'province' => $province,
        'city' => $city,
        'barangay' => $barangay
    ];
    
    $missing_fields = [];
    foreach ($required_fields as $field_name => $value) {
        if (empty($value)) {
            $missing_fields[] = $field_name;
        }
    }
    
    if (!empty($missing_fields)) {
        $error = "All required fields must be filled: " . implode(', ', $missing_fields);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
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
            
            // Get industry name based on selection
            $industry_name = '';
            
            if ($industry_id > 0 && $industry_id !== 9999) {
                // Get industry name from database
                try {
                    $industry_stmt = $conn->prepare("SELECT industry_name FROM industry_categories WHERE industry_id = :industry_id");
                    $industry_stmt->bindParam(':industry_id', $industry_id, PDO::PARAM_INT);
                    $industry_stmt->execute();
                    $industry_data = $industry_stmt->fetch();
                    
                    if ($industry_data) {
                        $industry_name = $industry_data['industry_name'];
                    } else {
                        $error = "Selected industry is not valid.";
                    }
                } catch (PDOException $e) {
                    $error = "Error validating industry selection.";
                }
            } elseif ($industry_id === 9999 && !empty($custom_industry)) {
                // Handle custom industry
                $custom_industry = ucfirst(strtolower(trim($custom_industry)));
                $industry_name = $custom_industry;
                
                // Check if custom industry already exists (case-insensitive)
                try {
                    $custom_check_stmt = $conn->prepare("
                        SELECT industry_id FROM industry_categories 
                        WHERE LOWER(industry_name) = LOWER(:industry_name) AND is_active = TRUE
                    ");
                    $custom_check_stmt->bindParam(':industry_name', $custom_industry);
                    $custom_check_stmt->execute();
                    
                    if ($custom_check_stmt->rowCount() === 0) {
                        // Insert new custom industry
                        $insert_industry_stmt = $conn->prepare("
                            INSERT INTO industry_categories (industry_name, is_custom, added_by_user_id, created_at) 
                            VALUES (:industry_name, TRUE, :user_id, NOW())
                        ");
                        $insert_industry_stmt->bindParam(':industry_name', $custom_industry);
                        $insert_industry_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                        $insert_industry_stmt->execute();
                    }
                } catch (PDOException $e) {
                    // If there's an error, still use the custom industry name
                    // This allows the form to proceed even if industry insertion fails
                }
            } else {
                $error = "Please select an industry or enter a custom industry.";
            }
            
            if (empty($error) && !empty($industry_name)) {
                // Process file uploads
                $business_permit_path = null;
                $dti_sec_path = null;
                $upload_error = '';
                
                // Create upload directory if it doesn't exist
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0755, true);
                }
                
                // Validate and upload business permit
                $business_validation = validateFileUpload($_FILES['business_permit_file'], 'business permit');
                if (!$business_validation['success']) {
                    $error = $business_validation['error'];
                } else {
                    // Validate and upload DTI/SEC permit
                    $dti_validation = validateFileUpload($_FILES['dti_permit_file'], 'DTI/SEC permit');
                    if (!$dti_validation['success']) {
                        $error = $dti_validation['error'];
                    } else {
                        // Both files validated, proceed with uploads
                        try {
                            // Generate secure filenames
                            $business_filename = sanitizeFileName($business_validation['name']);
                            $dti_filename = sanitizeFileName($dti_validation['name']);
                            
                            $business_path = UPLOAD_DIR . 'business_' . $business_filename;
                            $dti_path = UPLOAD_DIR . 'dti_' . $dti_filename;
                            
                            // Move uploaded files
                            if (move_uploaded_file($business_validation['tmp_name'], $business_path) &&
                                move_uploaded_file($dti_validation['tmp_name'], $dti_path)) {
                                
                                $business_permit_path = $business_path;
                                $dti_sec_path = $dti_path;
                                
                                // Begin database transaction
                                $conn->beginTransaction();
                                
                                try {
                                    // Check for duplicate company name
                                    $check_stmt = $conn->prepare("SELECT emp_id FROM employers WHERE emp_company_name = :company_name");
                                    $check_stmt->bindParam(':company_name', $company_name);
                                    $check_stmt->execute();
                                    
                                    if ($check_stmt->rowCount() > 0) {
                                        throw new Exception("This company name is already registered.");
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
                                    
                                    // Update user information first
                                    $update_user_stmt = $conn->prepare("
                                        UPDATE users 
                                        SET usr_name = :name, 
                                            usr_email = :email, 
                                            usr_phone = :phone,
                                            usr_gender = :gender,
                                            usr_birthdate = :birthdate,
                                            usr_address = :address,
                                            usr_updated_at = NOW()
                                        WHERE usr_id = :user_id
                                    ");
                                    
                                    $update_user_stmt->bindParam(':name', $full_name);
                                    $update_user_stmt->bindParam(':email', $email);
                                    $update_user_stmt->bindParam(':phone', $full_phone);
                                    $update_user_stmt->bindParam(':gender', $gender);
                                    $update_user_stmt->bindParam(':birthdate', $birthdate);
                                    $update_user_stmt->bindParam(':address', $permanent_address);
                                    $update_user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                    $update_user_stmt->execute();
                                    
                                    // Insert employer profile with emp_industry (not industry_id)
                                    $insert_stmt = $conn->prepare("
                                        INSERT INTO employers (
                                            emp_usr_id, 
                                            emp_company_name, 
                                            emp_industry,
                                            emp_contact_person, 
                                            emp_company_description, 
                                            emp_business_permit, 
                                            emp_dti_sec,
                                            emp_created_at
                                        ) VALUES (
                                            :user_id, 
                                            :company_name, 
                                            :industry,
                                            :contact_person, 
                                            :company_description, 
                                            :business_permit, 
                                            :dti_sec,
                                            NOW()
                                        )
                                    ");
                                    
                                    $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                    $insert_stmt->bindParam(':company_name', $company_name);
                                    $insert_stmt->bindParam(':industry', $industry_name);
                                    $insert_stmt->bindParam(':contact_person', $contact_person);
                                    $insert_stmt->bindParam(':company_description', $company_description);
                                    $insert_stmt->bindParam(':business_permit', $business_permit_path);
                                    $insert_stmt->bindParam(':dti_sec', $dti_sec_path);
                                    $insert_stmt->execute();
                                    
                                    // Update user role to employer
                                    $update_role_stmt = $conn->prepare("
                                        UPDATE users 
                                        SET usr_role = 'employer',
                                            usr_updated_at = NOW()
                                        WHERE usr_id = :user_id
                                    ");
                                    $update_role_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                    $update_role_stmt->execute();
                                    
                                    // Update user status based on auto-approve setting
                                    if ($auto_approve_users) {
                                        $update_status_stmt = $conn->prepare("
                                            UPDATE users 
                                            SET usr_is_approved = TRUE, 
                                                usr_account_status = 'active',
                                                usr_updated_at = NOW()
                                            WHERE usr_id = :user_id
                                        ");
                                    } else {
                                        $update_status_stmt = $conn->prepare("
                                            UPDATE users 
                                            SET usr_account_status = 'pending',
                                                usr_updated_at = NOW()
                                            WHERE usr_id = :user_id
                                        ");
                                    }
                                    $update_status_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                                    $update_status_stmt->execute();
                                    
                                    // Commit transaction
                                    $conn->commit();
                                    
                                    // Set session variables for logged in user
                                    $_SESSION['user_id'] = $user_id;
                                    $_SESSION['logged_in'] = true;
                                    $_SESSION['is_approved'] = $auto_approve_users;
                                    $_SESSION['account_status'] = $auto_approve_users ? 'active' : 'pending';
                                    $_SESSION['user_name'] = $full_name;
                                    $_SESSION['user_role'] = 'employer';
                                    
                                    // Clear verification session if coming from registration
                                    if (isset($_SESSION['verified_user_id'])) {
                                        unset($_SESSION['verified_user_id']);
                                    }
                                    
                                    // Regenerate session ID after privilege change
                                    session_regenerate_id(true);
                                    
                                    // MODIFIED: Redirect to different pages based on auto-approve setting
                                    if ($auto_approve_users) {
                                        $success = "Employer profile completed successfully! Your account has been approved.";
                                        header('Refresh: 2; URL=employer_dashboard.php?success=' . urlencode($success));
                                    } else {
                                        $success = "Employer profile completed successfully! Your account is pending admin approval.";
                                        header('Refresh: 2; URL=employer_profile.php?pending=1&message=' . urlencode($success));
                                    }
                                    $success .= " Redirecting...";
                                    
                                } catch (Exception $e) {
                                    // Rollback transaction on error
                                    $conn->rollBack();
                                    
                                    // Delete uploaded files if transaction failed
                                    if (file_exists($business_path)) unlink($business_path);
                                    if (file_exists($dti_path)) unlink($dti_path);
                                    
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
  <title>CTU-PESO - Complete Employer Profile</title>
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
      border-left: 4px solid var(--green);
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
    
    .company-info {
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
    
    .form-file {
      background-color: #f9f9f9;
      cursor: pointer;
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
    
    .file-upload-group {
      margin-top: 10px;
    }
    
    .file-preview {
      margin-top: 10px;
      padding: 10px;
      background-color: #f8f9fa;
      border-radius: 5px;
      border: 1px dashed #ddd;
    }
    
    .file-preview-item {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 5px;
    }
    
    .file-preview-item:last-child {
      margin-bottom: 0;
    }
    
    .file-icon {
      color: var(--primary-color);
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
    
    /* Industry Selection Styles */
    .industry-selection-container {
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 15px;
      background: #fafafa;
    }
    
    .industry-options-container {
      margin-bottom: 15px;
    }
    
    .custom-industry-input-group {
      display: flex;
      gap: 10px;
      align-items: center;
      margin-top: 10px;
    }
    
    .custom-industry-input {
      flex: 1;
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
      
      .custom-industry-input-group {
        flex-direction: column;
        align-items: stretch;
      }
      
      .custom-industry-input-group .btn {
        width: 100%;
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
  
  <div class="container">
    <div class="header">
      <h1>Complete Your Employer Profile</h1>
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
        <p>Please complete your personal and company information to finalize your registration.</p>
        <?php if (!$auto_approve_users): ?>
          <p style="color: var(--red); font-weight: 500; margin-top: 10px;">
            <i class="fas fa-info-circle"></i> Your account will require admin approval after registration.
          </p>
        <?php else: ?>
          <p style="color: var(--green); font-weight: 500; margin-top: 10px;">
            <i class="fas fa-check-circle"></i> Your account will be automatically approved and you'll be redirected to your dashboard.
          </p>
        <?php endif; ?>
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
      
      <?php if (!empty($success)): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i>
          <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>
      
      <form method="POST" action="" enctype="multipart/form-data" id="employerForm">
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
                         value="<?php echo isset($form_data['last_name']) ? htmlspecialchars($form_data['last_name']) : htmlspecialchars($last_name); ?>" 
                         required placeholder="Last Name">
                  <div class="error-message" id="last_name_error"></div>
                </div>
                <div>
                  <label class="form-label" for="first_name">First Name</label>
                  <input type="text" id="first_name" name="first_name" class="form-input" 
                         value="<?php echo isset($form_data['first_name']) ? htmlspecialchars($form_data['first_name']) : htmlspecialchars($first_name); ?>" 
                         required placeholder="First Name">
                  <div class="error-message" id="first_name_error"></div>
                </div>
                <div>
                  <label class="form-label" for="middle_name">Middle Name</label>
                  <input type="text" id="middle_name" name="middle_name" class="form-input" 
                         value="<?php echo isset($form_data['middle_name']) ? htmlspecialchars($form_data['middle_name']) : htmlspecialchars($middle_name); ?>" 
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
            
            <div class="form-group">
              <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
              <div class="phone-container">
                <select id="country_code" name="country_code" class="form-select country-code">
                  <option value="+63" <?php echo $country_code === '+63' ? 'selected' : ''; ?>>+63 (PH)</option>
                  <option value="+1" <?php echo $country_code === '+1' ? 'selected' : ''; ?>>+1 (US)</option>
                  <option value="+44" <?php echo $country_code === '+44' ? 'selected' : ''; ?>>+44 (UK)</option>
                  <option value="+61" <?php echo $country_code === '+61' ? 'selected' : ''; ?>>+61 (AU)</option>
                  <option value="+65" <?php echo $country_code === '+65' ? 'selected' : ''; ?>>+65 (SG)</option>
                  <option value="+60" <?php echo $country_code === '+60' ? 'selected' : ''; ?>>+60 (MY)</option>
                  <option value="+66" <?php echo $country_code === '+66' ? 'selected' : ''; ?>>+66 (TH)</option>
                  <option value="+84" <?php echo $country_code === '+84' ? 'selected' : ''; ?>>+84 (VN)</option>
                  <option value="+81" <?php echo $country_code === '+81' ? 'selected' : ''; ?>>+81 (JP)</option>
                  <option value="+82" <?php echo $country_code === '+82' ? 'selected' : ''; ?>>+82 (KR)</option>
                  <option value="+86" <?php echo $country_code === '+86' ? 'selected' : ''; ?>>+86 (CN)</option>
                  <option value="+91" <?php echo $country_code === '+91' ? 'selected' : ''; ?>>+91 (IN)</option>
                  <option value="+971" <?php echo $country_code === '+971' ? 'selected' : ''; ?>>+971 (AE)</option>
                  <option value="+973" <?php echo $country_code === '+973' ? 'selected' : ''; ?>>+973 (BH)</option>
                  <option value="+966" <?php echo $country_code === '+966' ? 'selected' : ''; ?>>+966 (SA)</option>
                  <option value="+20" <?php echo $country_code === '+20' ? 'selected' : ''; ?>>+20 (EG)</option>
                  <option value="+27" <?php echo $country_code === '+27' ? 'selected' : ''; ?>>+27 (ZA)</option>
                </select>
                <input type="tel" id="phone" name="phone" class="form-input phone-number" 
                       value="<?php echo isset($form_data['phone']) ? htmlspecialchars($form_data['phone']) : htmlspecialchars($phone_number); ?>" 
                       placeholder="9234567890" maxlength="11" pattern="[0-9]{10,11}" 
                       title="Please enter 10-11 digits only (numbers only)" required>
              </div>
              <div class="error-message" id="phone_error"></div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="gender">Gender</label>
              <select id="gender" name="gender" class="form-select">
                <option value="">Select Gender</option>
                <option value="Male" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'Male') ? 'selected' : ((isset($user_data['usr_gender']) && $user_data['usr_gender'] === 'Male') ? 'selected' : ''); ?>>Male</option>
                <option value="Female" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'Female') ? 'selected' : ((isset($user_data['usr_gender']) && $user_data['usr_gender'] === 'Female') ? 'selected' : ''); ?>>Female</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="birthdate">Birthdate</label>
              <input type="date" id="birthdate" name="birthdate" class="form-input" 
                     value="<?php echo isset($form_data['birthdate']) ? htmlspecialchars($form_data['birthdate']) : htmlspecialchars($user_data['usr_birthdate'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
              <label class="form-label">Company Address Information <span class="required">*</span></label>
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
                         value="<?php echo isset($form_data['zip_code']) ? htmlspecialchars($form_data['zip_code']) : htmlspecialchars($parsed_address['zip_code']); ?>" 
                         placeholder="Zip Code">
                </div>
                <div>
                  <label class="form-label" for="street_address">Street Address</label>
                  <input type="text" id="street_address" name="street_address" class="form-input" 
                         value="<?php echo isset($form_data['street_address']) ? htmlspecialchars($form_data['street_address']) : htmlspecialchars($parsed_address['street_address']); ?>" 
                         placeholder="House No., Street, Subdivision">
                </div>
              </div>
            </div>
          </div>
          
          <!-- Company Information Section -->
          <div class="form-section company-info">
            <div class="form-header">
              <h3 class="form-title">Company Information</h3>
              <p class="form-subtitle">Your company details and business information</p>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="company_name">Company Name <span class="required">*</span></label>
              <input type="text" id="company_name" name="company_name" class="form-input" 
                     value="<?php echo isset($form_data['company_name']) ? htmlspecialchars($form_data['company_name']) : ''; ?>" 
                     required placeholder="Enter your company name">
              <div class="error-message" id="company_name_error"></div>
              <div class="form-text">Official registered name of your company</div>
            </div>
            
            <!-- Dynamic Industry Selection -->
            <div class="form-group">
              <label class="form-label" for="industry">Industry <span class="required">*</span></label>
              
              <!-- Industry Selection Container -->
              <div class="industry-selection-container">
                <div class="industry-options-container">
                  <!-- Database-driven industry options -->
                  <select id="industry" name="industry" class="form-select" required>
                    <option value="">Select Industry</option>
                    <?php if (!empty($grouped_industries)): ?>
                      <?php foreach ($grouped_industries as $category => $category_industries): ?>
                        <?php if ($category !== 'Custom Industries'): ?>
                          <optgroup label="<?php echo htmlspecialchars($category); ?>">
                            <?php foreach ($category_industries as $industry): ?>
                              <option value="<?php echo $industry['industry_id']; ?>"
                                <?php echo (isset($form_data['industry']) && $form_data['industry'] == $industry['industry_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($industry['industry_name']); ?>
                              </option>
                            <?php endforeach; ?>
                          </optgroup>
                        <?php endif; ?>
                      <?php endforeach; ?>
                      
                      <!-- Custom Industries Group -->
                      <?php if (isset($grouped_industries['Custom Industries']) && !empty($grouped_industries['Custom Industries'])): ?>
                        <optgroup label="Custom Industries">
                          <?php foreach ($grouped_industries['Custom Industries'] as $industry): ?>
                            <option value="<?php echo $industry['industry_id']; ?>"
                              <?php echo (isset($form_data['industry']) && $form_data['industry'] == $industry['industry_id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($industry['industry_name']); ?> (Custom)
                            </option>
                          <?php endforeach; ?>
                        </optgroup>
                      <?php endif; ?>
                      
                      <!-- Option to add new custom industry -->
                      <option value="9999">+ Add New Industry</option>
                    <?php else: ?>
                      <!-- Fallback if database query fails -->
                      <optgroup label="Technology & IT">
                        <option value="1">Software Development</option>
                        <option value="2">IT Services</option>
                      </optgroup>
                      <option value="9999">+ Add New Industry</option>
                    <?php endif; ?>
                  </select>
                </div>
                
                <!-- Custom Industry Input (hidden by default) -->
                <div id="customIndustryContainer" style="display: none; margin-top: 10px;">
                  <label class="form-label" for="custom_industry">Enter Custom Industry</label>
                  <div class="custom-industry-input-group">
                    <input type="text" id="custom_industry" name="custom_industry" 
                           class="form-input custom-industry-input" 
                           placeholder="Enter your industry (e.g., Renewable Energy, Gaming, etc.)"
                           value="<?php echo isset($form_data['custom_industry']) ? htmlspecialchars($form_data['custom_industry']) : ''; ?>">
                  </div>
                  <div class="form-text" style="margin-top: 5px;">
                    <i class="fas fa-info-circle"></i> Your custom industry will be saved for future use.
                  </div>
                </div>
              </div>
              <div class="error-message" id="industry_error"></div>
              <div class="form-text">Select the primary industry of your company</div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="contact_person">Contact Person <span class="required">*</span></label>
              <input type="text" id="contact_person" name="contact_person" class="form-input" 
                     value="<?php echo isset($form_data['contact_person']) ? htmlspecialchars($form_data['contact_person']) : ''; ?>" 
                     required placeholder="Enter contact person name">
              <div class="error-message" id="contact_person_error"></div>
              <div class="form-text">Primary contact person for hiring matters</div>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="company_description">Company Description <span class="required">*</span></label>
              <textarea id="company_description" name="company_description" class="form-textarea" 
                        placeholder="Briefly describe your company, mission, values, and what you do..."><?php echo isset($form_data['company_description']) ? htmlspecialchars($form_data['company_description']) : ''; ?></textarea>
              <div class="error-message" id="company_description_error"></div>
            </div>
            
            <!-- File Upload Fields -->
            <div class="form-group file-upload-group">
              <label class="form-label" for="dti_permit_file">Upload DTI/SEC Permit <span class="required">*</span></label>
              <input type="file" id="dti_permit_file" name="dti_permit_file" class="form-file" 
                     accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
              <div class="error-message" id="dti_permit_file_error"></div>
              <div class="form-text">Upload your DTI/SEC permit document (PDF, JPG, JPEG, PNG, DOC, DOCX) - Max 10MB</div>
              <div id="dti-preview" class="file-preview" style="display: none;"></div>
            </div>
            
            <div class="form-group file-upload-group">
              <label class="form-label" for="business_permit_file">Upload Business Permit <span class="required">*</span></label>
              <input type="file" id="business_permit_file" name="business_permit_file" class="form-file" 
                     accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
              <div class="error-message" id="business_permit_file_error"></div>
              <div class="form-text">Upload your business permit document (PDF, JPG, JPEG, PNG, DOC, DOCX) - Max 10MB</div>
              <div id="business-preview" class="file-preview" style="display: none;"></div>
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
        if (phone && !phoneRegex.test(phone)) {
          this.errors.phone = 'Phone number must be 10-11 digits';
          return false;
        }
        return true;
      }
      
      validateIndustry(industryId, customIndustry) {
        if (!industryId || industryId === '') {
          this.errors.industry = 'Please select an industry';
          return false;
        }
        
        if (industryId === '9999' && (!customIndustry || customIndustry.trim() === '')) {
          this.errors.industry = 'Please enter a custom industry name';
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
        
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 
                             'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!allowedTypes.includes(file.type)) {
          this.errors[fieldName] = 'Only PDF, JPG, JPEG, PNG, DOC, and DOCX files are allowed';
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

    // Main application
    document.addEventListener('DOMContentLoaded', function() {
      const addressAPI = new PSGCAddressAPI();
      const validator = new FormValidator();
      const form = document.getElementById('employerForm');
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
      
      // Industry selection elements
      const industrySelect = document.getElementById('industry');
      const customIndustryContainer = document.getElementById('customIndustryContainer');
      const customIndustryInput = document.getElementById('custom_industry');
      
      // File preview elements
      const dtiFileInput = document.getElementById('dti_permit_file');
      const businessFileInput = document.getElementById('business_permit_file');
      const dtiPreview = document.getElementById('dti-preview');
      const businessPreview = document.getElementById('business-preview');
      
      // Pre-filled address values from PHP
      const preFilledRegion = "<?php echo htmlspecialchars($parsed_address['region'] ?? ''); ?>";
      const preFilledProvince = "<?php echo htmlspecialchars($parsed_address['province'] ?? ''); ?>";
      const preFilledCity = "<?php echo htmlspecialchars($parsed_address['city'] ?? ''); ?>";
      const preFilledBarangay = "<?php echo htmlspecialchars($parsed_address['barangay'] ?? ''); ?>";
      
      // Load regions on page load
      loadRegions();
      
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
              
              // Select pre-filled region if exists
              if (region.name === preFilledRegion) {
                option.selected = true;
              }
              
              regionSelect.appendChild(option);
            });
          }
          
          regionLoading.style.display = 'none';
          provinceSelect.disabled = false;
          setupAddressListeners();
          
          // If a region is pre-filled, trigger loading of its provinces
          if (preFilledRegion) {
            setTimeout(() => {
              regionSelect.dispatchEvent(new Event('change'));
            }, 100);
          }
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
                
                // Select pre-filled province if exists
                if (province.name === preFilledProvince) {
                  option.selected = true;
                }
                
                provinceSelect.appendChild(option);
              });
              
              provinceLoading.style.display = 'none';
              provinceSelect.disabled = false;
              
              if (provinces.length === 1) {
                provinceSelect.value = provinces[0].name;
                setTimeout(() => provinceSelect.dispatchEvent(new Event('change')), 100);
              }
              
              // If a province is pre-filled, trigger loading of its cities
              if (preFilledProvince) {
                setTimeout(() => {
                  provinceSelect.dispatchEvent(new Event('change'));
                }, 100);
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
                
                // Select pre-filled city if exists
                if (city.name === preFilledCity) {
                  option.selected = true;
                }
                
                citySelect.appendChild(option);
              });
              
              cityLoading.style.display = 'none';
              citySelect.disabled = false;
              
              if (cities.length === 1) {
                citySelect.value = cities[0].name;
                setTimeout(() => citySelect.dispatchEvent(new Event('change')), 100);
              }
              
              // If a city is pre-filled, trigger loading of its barangays
              if (preFilledCity) {
                setTimeout(() => {
                  citySelect.dispatchEvent(new Event('change'));
                }, 100);
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
                
                // Select pre-filled barangay if exists
                if (barangay.name === preFilledBarangay) {
                  option.selected = true;
                }
                
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
      
      // Industry selection handling
      industrySelect.addEventListener('change', function() {
        if (this.value === '9999') {
          customIndustryContainer.style.display = 'block';
          customIndustryInput.required = true;
        } else {
          customIndustryContainer.style.display = 'none';
          customIndustryInput.required = false;
          customIndustryInput.value = '';
        }
      });
      
      // Initialize industry selection
      if (industrySelect.value === '9999') {
        customIndustryContainer.style.display = 'block';
        customIndustryInput.required = true;
      }
      
      // File preview functionality
      dtiFileInput.addEventListener('change', function(e) {
        handleFilePreview(e, dtiPreview, 'DTI/SEC Permit');
      });
      
      businessFileInput.addEventListener('change', function(e) {
        handleFilePreview(e, businessPreview, 'Business Permit');
      });
      
      function handleFilePreview(event, previewElement, fileType) {
        const file = event.target.files[0];
        if (file) {
          const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
          const fileExtension = file.name.split('.').pop().toLowerCase();
          
          previewElement.innerHTML = `
            <div class="file-preview-item">
              <i class="fas fa-file-upload file-icon"></i>
              <div>
                <strong>${fileType}:</strong> ${file.name}<br>
                <small>Size: ${fileSize} MB | Type: ${fileExtension.toUpperCase()}</small>
              </div>
            </div>
          `;
          previewElement.style.display = 'block';
        } else {
          previewElement.style.display = 'none';
        }
      }
      
      // Phone number validation
      const phoneInput = document.getElementById('phone');
      phoneInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        if (value.length > <?php echo MAX_PHONE_LENGTH; ?>) {
          value = value.substring(0, <?php echo MAX_PHONE_LENGTH; ?>);
        }
        e.target.value = value;
      });
      
      // Form validation
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        validator.clearErrors();
        
        // Validate required fields
        const requiredFields = [
          'last_name', 'first_name', 'email', 'company_name', 
          'contact_person', 'region', 'province', 'city', 'barangay'
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
        if (phone) {
          validator.validatePhone(phone);
        } else {
          validator.errors.phone = 'Phone number is required';
        }
        
        // Validate industry
        const industryId = industrySelect.value;
        const customIndustry = customIndustryInput.value.trim();
        validator.validateIndustry(industryId, customIndustry);
        
        // Validate files
        const dtiFile = dtiFileInput.files[0];
        const businessFile = businessFileInput.files[0];
        
        if (dtiFile) {
          validator.validateFile(dtiFile, 'dti_permit_file');
        } else {
          validator.errors.dti_permit_file = 'DTI/SEC permit file is required';
        }
        
        if (businessFile) {
          validator.validateFile(businessFile, 'business_permit_file');
        } else {
          validator.errors.business_permit_file = 'Business permit file is required';
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
          form.submit();
        } catch (error) {
          console.error('Form submission error:', error);
          showNotification('Error submitting form. Please try again.', 'error');
          showLoading(false);
          submitBtn.disabled = false;
        }
      });
      
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
  </script>
</body>
</html>