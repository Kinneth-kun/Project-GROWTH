<?php
// Define API endpoints for Philippine addresses
define('REGIONS_API', 'https://psgc.gitlab.io/api/regions/');
define('PROVINCES_API', 'https://psgc.gitlab.io/api/regions/{code}/provinces/');
define('CITIES_API', 'https://psgc.gitlab.io/api/provinces/{code}/cities/');
define('MUNICIPALITIES_API', 'https://psgc.gitlab.io/api/provinces/{code}/municipalities/');
define('BARANGAYS_API', 'https://psgc.gitlab.io/api/cities-municipalities/{code}/barangays/');

// Database configuration
$servername = "localhost";
$username = "root";
$password = "06162004";
$dbname = "user_registration";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $region = mysqli_real_escape_string($conn, $_POST['region']);
    $province = mysqli_real_escape_string($conn, $_POST['province']);
    $city = mysqli_real_escape_string($conn, $_POST['city']);
    $barangay = mysqli_real_escape_string($conn, $_POST['barangay']);
    $street = mysqli_real_escape_string($conn, $_POST['street']);

    // Check if email already exists
    $checkEmail = "SELECT id FROM users WHERE email = '$email'";
    $result = $conn->query($checkEmail);
    
    if ($result->num_rows > 0) {
        $message = "Email already exists!";
        $messageType = "error";
    } else {
        // Insert into database
        $sql = "INSERT INTO users (first_name, last_name, email, phone, password, region, province, city, barangay, street, created_at) 
                VALUES ('$first_name', '$last_name', '$email', '$phone', '$password', '$region', '$province', '$city', '$barangay', '$street', NOW())";
        
        if ($conn->query($sql) === TRUE) {
            $message = "Registration successful!";
            $messageType = "success";
            
            // Clear form
            $_POST = array();
        } else {
            $message = "Error: " . $sql . "<br>" . $conn->error;
            $messageType = "error";
        }
    }
}

// Function to fetch data from API
function fetchFromAPI($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Function to sort array by name alphabetically
function sortByName($a, $b) {
    return strcmp($a['name'], $b['name']);
}

// Fetch regions on page load
if (isset($_GET['getRegions'])) {
    header('Content-Type: application/json');
    $regions = fetchFromAPI(REGIONS_API);
    if ($regions) {
        usort($regions, 'sortByName');
    }
    echo json_encode($regions);
    exit;
}

// Fetch provinces based on region
if (isset($_GET['getProvinces']) && isset($_GET['regionCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['regionCode'], PROVINCES_API);
    $provinces = fetchFromAPI($url);
    if ($provinces) {
        usort($provinces, 'sortByName');
    }
    echo json_encode($provinces);
    exit;
}

// Fetch cities/municipalities based on province
if (isset($_GET['getCities']) && isset($_GET['provinceCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['provinceCode'], CITIES_API);
    $cities = fetchFromAPI($url);
    
    // If no cities, try municipalities
    if (empty($cities)) {
        $url = str_replace('{code}', $_GET['provinceCode'], MUNICIPALITIES_API);
        $cities = fetchFromAPI($url);
    }
    
    if ($cities) {
        usort($cities, 'sortByName');
    }
    
    echo json_encode($cities);
    exit;
}

// Fetch barangays based on city/municipality
if (isset($_GET['getBarangays']) && isset($_GET['cityCode'])) {
    header('Content-Type: application/json');
    $url = str_replace('{code}', $_GET['cityCode'], BARANGAYS_API);
    $barangays = fetchFromAPI($url);
    if ($barangays) {
        usort($barangays, 'sortByName');
    }
    echo json_encode($barangays);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up Form with Philippine Address</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 550px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
        }

        .form-container {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e1e1;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .loading {
            display: none;
            color: #667eea;
            text-align: center;
            padding: 10px;
            font-style: italic;
        }

        select:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #f8f9fa;
        }

        .required::after {
            content: " *";
            color: #e74c3c;
        }

        .address-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }

        .address-section h3 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
        }

        .address-cascade {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .container {
                max-width: 100%;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .form-container {
                padding: 30px 20px;
            }
            
            .header {
                padding: 20px;
            }
            
            .address-cascade {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Create Account</h1>
            <p>Fill in your details to get started</p>
        </div>
        
        <div class="form-container">
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form id="signupForm" method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name" class="required">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" 
                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name" class="required">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" 
                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" 
                               required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email" class="required">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label for="phone" class="required">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" 
                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" 
                           pattern="[0-9]{11}" 
                           placeholder="09123456789"
                           required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="required">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="required">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>
                </div>
                
                <div class="address-section">
                    <h3>Address Information</h3>
                    <div class="address-cascade">
                        <div class="form-group">
                            <label for="region" class="required">Region</label>
                            <select id="region" name="region" class="form-control" required>
                                <option value="">Select Region</option>
                            </select>
                            <div class="loading" id="regionLoading">Loading regions...</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="province" class="required">Province</label>
                            <select id="province" name="province" class="form-control" disabled required>
                                <option value="">Select Region First</option>
                            </select>
                            <div class="loading" id="provinceLoading" style="display: none;">Loading provinces...</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="city" class="required">City/Municipality</label>
                            <select id="city" name="city" class="form-control" disabled required>
                                <option value="">Select Province First</option>
                            </select>
                            <div class="loading" id="cityLoading" style="display: none;">Loading cities...</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="barangay" class="required">Barangay</label>
                            <select id="barangay" name="barangay" class="form-control" disabled required>
                                <option value="">Select City First</option>
                            </select>
                            <div class="loading" id="barangayLoading" style="display: none;">Loading barangays...</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="street" class="required">Street/Sitio/Purok/House No.</label>
                        <input type="text" id="street" name="street" class="form-control" 
                               value="<?php echo isset($_POST['street']) ? htmlspecialchars($_POST['street']) : ''; ?>" 
                               placeholder="House number, street name, sitio, or purok" required>
                    </div>
                </div>
                
                <button type="submit" class="btn">Create Account</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.getElementById('signupForm');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            // Password validation
            confirmPassword.addEventListener('input', function() {
                if (password.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            });
            
            // Form submission
            form.addEventListener('submit', function(e) {
                if (password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return false;
                }
                
                // Additional client-side validation
                const phone = document.getElementById('phone');
                if (!phone.value.match(/^[0-9]{11}$/)) {
                    e.preventDefault();
                    alert('Please enter a valid 11-digit phone number');
                    return false;
                }
                
                // Check if all required address fields are filled
                const region = document.getElementById('region');
                const province = document.getElementById('province');
                const city = document.getElementById('city');
                const barangay = document.getElementById('barangay');
                const street = document.getElementById('street');
                
                if (!region.value || !province.value || !city.value || !barangay.value || !street.value.trim()) {
                    e.preventDefault();
                    alert('Please complete all address fields');
                    return false;
                }
                
                return true;
            });
            
            // Address API integration
            const regionSelect = document.getElementById('region');
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');
            const regionLoading = document.getElementById('regionLoading');
            const provinceLoading = document.getElementById('provinceLoading');
            const cityLoading = document.getElementById('cityLoading');
            const barangayLoading = document.getElementById('barangayLoading');
            
            // Load regions on page load
            loadRegions();
            
            async function loadRegions() {
                regionLoading.style.display = 'block';
                try {
                    const response = await fetch('?getRegions=1');
                    const regions = await response.json();
                    
                    regionSelect.innerHTML = '<option value="">Select Region</option>';
                    regions.forEach(region => {
                        const option = document.createElement('option');
                        option.value = region.code;
                        option.textContent = region.name;
                        regionSelect.appendChild(option);
                    });
                    
                    regionLoading.style.display = 'none';
                    
                    // Restore selected region if exists
                    <?php if (isset($_POST['region'])): ?>
                        regionSelect.value = '<?php echo $_POST['region']; ?>';
                        if (regionSelect.value) {
                            loadProvinces(regionSelect.value);
                        }
                    <?php endif; ?>
                } catch (error) {
                    console.error('Error loading regions:', error);
                    regionLoading.textContent = 'Error loading regions. Please refresh.';
                }
            }
            
            // Load provinces when region is selected
            regionSelect.addEventListener('change', function() {
                const regionCode = this.value;
                if (regionCode) {
                    loadProvinces(regionCode);
                    // Clear province, city and barangay selections
                    provinceSelect.innerHTML = '<option value="">Select Province</option>';
                    provinceSelect.disabled = false;
                    citySelect.innerHTML = '<option value="">Select Province First</option>';
                    citySelect.disabled = true;
                    barangaySelect.innerHTML = '<option value="">Select City First</option>';
                    barangaySelect.disabled = true;
                } else {
                    provinceSelect.innerHTML = '<option value="">Select Region First</option>';
                    provinceSelect.disabled = true;
                    citySelect.innerHTML = '<option value="">Select Province First</option>';
                    citySelect.disabled = true;
                    barangaySelect.innerHTML = '<option value="">Select City First</option>';
                    barangaySelect.disabled = true;
                }
            });
            
            async function loadProvinces(regionCode) {
                provinceLoading.style.display = 'block';
                provinceSelect.disabled = true;
                
                try {
                    const response = await fetch(`?getProvinces=1&regionCode=${regionCode}`);
                    const provinces = await response.json();
                    
                    provinceSelect.innerHTML = '<option value="">Select Province</option>';
                    provinces.forEach(province => {
                        const option = document.createElement('option');
                        option.value = province.code;
                        option.textContent = province.name;
                        provinceSelect.appendChild(option);
                    });
                    
                    provinceLoading.style.display = 'none';
                    provinceSelect.disabled = false;
                    
                    // Restore selected province if exists
                    <?php if (isset($_POST['province'])): ?>
                        provinceSelect.value = '<?php echo $_POST['province']; ?>';
                        if (provinceSelect.value) {
                            loadCities(provinceSelect.value);
                        }
                    <?php endif; ?>
                } catch (error) {
                    console.error('Error loading provinces:', error);
                    provinceLoading.textContent = 'Error loading provinces. Please try again.';
                }
            }
            
            // Load cities when province is selected
            provinceSelect.addEventListener('change', function() {
                const provinceCode = this.value;
                if (provinceCode) {
                    loadCities(provinceCode);
                    citySelect.disabled = false;
                    barangaySelect.innerHTML = '<option value="">Select City First</option>';
                    barangaySelect.disabled = true;
                } else {
                    citySelect.innerHTML = '<option value="">Select Province First</option>';
                    citySelect.disabled = true;
                    barangaySelect.innerHTML = '<option value="">Select City First</option>';
                    barangaySelect.disabled = true;
                }
            });
            
            async function loadCities(provinceCode) {
                cityLoading.style.display = 'block';
                citySelect.disabled = true;
                
                try {
                    const response = await fetch(`?getCities=1&provinceCode=${provinceCode}`);
                    const cities = await response.json();
                    
                    citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                    cities.forEach(city => {
                        const option = document.createElement('option');
                        option.value = city.code;
                        option.textContent = city.name;
                        citySelect.appendChild(option);
                    });
                    
                    cityLoading.style.display = 'none';
                    citySelect.disabled = false;
                    
                    // Restore selected city if exists
                    <?php if (isset($_POST['city'])): ?>
                        citySelect.value = '<?php echo $_POST['city']; ?>';
                        if (citySelect.value) {
                            loadBarangays(citySelect.value);
                        }
                    <?php endif; ?>
                } catch (error) {
                    console.error('Error loading cities:', error);
                    cityLoading.textContent = 'Error loading cities. Please try again.';
                }
            }
            
            // Load barangays when city is selected
            citySelect.addEventListener('change', function() {
                const cityCode = this.value;
                if (cityCode) {
                    loadBarangays(cityCode);
                    barangaySelect.disabled = false;
                } else {
                    barangaySelect.innerHTML = '<option value="">Select City First</option>';
                    barangaySelect.disabled = true;
                }
            });
            
            async function loadBarangays(cityCode) {
                barangayLoading.style.display = 'block';
                barangaySelect.disabled = true;
                
                try {
                    const response = await fetch(`?getBarangays=1&cityCode=${cityCode}`);
                    const barangays = await response.json();
                    
                    barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                    barangays.forEach(barangay => {
                        const option = document.createElement('option');
                        option.value = barangay.code;
                        option.textContent = barangay.name;
                        barangaySelect.appendChild(option);
                    });
                    
                    barangayLoading.style.display = 'none';
                    barangaySelect.disabled = false;
                    
                    // Restore selected barangay if exists
                    <?php if (isset($_POST['barangay'])): ?>
                        barangaySelect.value = '<?php echo $_POST['barangay']; ?>';
                    <?php endif; ?>
                } catch (error) {
                    console.error('Error loading barangays:', error);
                    barangayLoading.textContent = 'Error loading barangays. Please try again.';
                }
            }
            
            // Show loading state when selecting options
            [regionSelect, provinceSelect, citySelect, barangaySelect].forEach(select => {
                select.addEventListener('focus', function() {
                    if (this.disabled) {
                        const loadingId = this.id + 'Loading';
                        const loadingElement = document.getElementById(loadingId);
                        if (loadingElement && loadingElement.style.display !== 'none') {
                            this.style.color = '#999';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>