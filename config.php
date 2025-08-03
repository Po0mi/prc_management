<?php

function init_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 86400, 
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => isset($_SERVER['HTTPS']), 
            'httponly' => true,
            'samesite' => 'Lax' 
        ]);
        
        if (!session_start()) {
            throw new RuntimeException('Failed to start session');
        }
        
       
        if (empty($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
    }
}

init_session();


$DB_HOST = 'localhost';
$DB_NAME = 'prc_system';
$DB_USER = 'root';
$DB_PASS = ''; 
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, 
            PDO::ATTR_PERSISTENT => false 
        ]
    );
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    header('HTTP/1.1 503 Service Unavailable');
    die("Service temporarily unavailable. Please try again later.");
}


function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token);
}


function ensure_logged_in() {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
        header("Location: /login.php");
        exit;
    }
}

function ensure_admin() {
    ensure_logged_in();
    if ($_SESSION['role'] !== 'admin') {
        header("HTTP/1.1 403 Forbidden");
        include_once __DIR__ . '/errors/403.php';
        exit;
    }
}

function current_user_id()   { return $_SESSION['user_id'] ?? null; }
function current_user_role() { return $_SESSION['role']    ?? null; }
function current_username()  { return $_SESSION['username']?? ''; }


function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}


spl_autoload_register(function($class) {
    $file = __DIR__ . '/classes/' . strtolower($class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});


set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Error [$errno] in $errfile on line $errline: $errstr");
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo "<div class='error'>Error: $errstr in $errfile on line $errline</div>";
    }
    return true;
});


date_default_timezone_set('Asia/Kuala_Lumpur');