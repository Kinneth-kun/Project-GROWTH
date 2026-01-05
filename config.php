<?php
// config.php - Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'growth_db');
define('DB_USER', 'root');
define('DB_PASS', '06162004');

// Security settings
define('ENVIRONMENT', 'development'); // Change to 'production' in production
define('SESSION_LIFETIME', 3600); // 1 hour

// File upload settings
define('MAX_FILE_UPLOAD_SIZE', 2097152); // 2MB in bytes

// Error reporting based on environment
if (defined('ENVIRONMENT')) {
    switch (ENVIRONMENT) {
        case 'development':
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            break;
        case 'production':
            error_reporting(0);
            ini_set('display_errors', 0);
            break;
        default:
            exit('The application environment is not set correctly.');
    }
}