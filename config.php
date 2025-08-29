<?php
// Complete config.php - Enhanced for Role-Based Admin System with Unrestricted Access

// Start session only if one hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
$host = 'localhost';
$dbname = 'prc_system'; // Your database name
$username = 'root';     // Your database username
$password = '';         // Your database password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['pdo'] = $pdo;
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ===============================================
// AUTHENTICATION FUNCTIONS
// ===============================================

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function current_username() {
    return $_SESSION['username'] ?? null;
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function ensure_logged_in() {
    if (!is_logged_in()) {
        header('Location: ../login.php');
        exit;
    }
}

// Enhanced admin checking with role support
function is_admin() {
    if (!is_logged_in()) return false;
    
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT role, admin_role, is_admin FROM users WHERE user_id = ?");
        $stmt->execute([current_user_id()]);
        $user = $stmt->fetch();
        
        return $user && ($user['role'] === 'admin' || $user['is_admin'] == 1);
    } catch (PDOException $e) {
        error_log("Admin check error: " . $e->getMessage());
        return false;
    }
}

function ensure_admin() {
    if (!is_admin()) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. Admin privileges required.');
    }
}

// ===============================================
// ROLE-BASED ACCESS FUNCTIONS - UNRESTRICTED
// ===============================================

/**
 * Get current user's admin role
 */
function get_user_role($user_id = null) {
    global $pdo;
    
    if (!$user_id) {
        $user_id = current_user_id();
    }
    
    if (!$user_id) return null;
    
    try {
        $stmt = $pdo->prepare("SELECT admin_role, role FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) return null;
        
        // Return admin_role if set, otherwise 'super' for admin users, or null for regular users
        if ($user['admin_role']) {
            return $user['admin_role'];
        } elseif ($user['role'] === 'admin') {
            return 'super';
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Get user role error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if user has permission - ALL ADMINS NOW HAVE ALL PERMISSIONS
 */
function user_has_permission($role, $module, $permission) {
    // All admin roles now have unrestricted access
    return !empty($role);
}

/**
 * Ensure user has required role or permission - UNRESTRICTED
 */
function ensure_role_permission($required_role = null, $module = null, $permission = 'view') {
    $user_role = get_user_role();
    
    if (!$user_role) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. Admin privileges required.');
    }
    
    // All admin roles now have unrestricted access - no role or permission checks
    return true;
}

/**
 * Get role-specific WHERE clause - REMOVED RESTRICTIONS
 */
function get_role_filter($role, $table_alias = '') {
    // All roles now have unrestricted access
    return '1=1'; // No filter for any admin
}

/**
 * Filter query results based on user role - NO FILTERING
 */
function add_role_filter_to_query($base_query, $role, $table_alias = '') {
    // All roles now have unrestricted access - no filtering applied
    return $base_query;
}

/**
 * Get allowed major services for a role - ALL SERVICES FOR ALL ROLES
 */
function get_allowed_services($role) {
    // All admin roles can now access all services
    return ['Safety Services', 'Welfare Services', 'Health Services', 'Disaster Management', 'Red Cross Youth'];
}

/**
 * Check if user can access specific service - ALWAYS TRUE FOR ADMINS
 */
function can_access_service($role, $service) {
    // All admin roles can access all services
    return !empty($role);
}

/**
 * Log admin action for audit trail
 */
function log_admin_action($action, $module, $target_id = null, $target_type = null, $details = null) {
    global $pdo;
    
    $user_role = get_user_role();
    $admin_id = current_user_id();
    
    if (!$user_role || !$admin_id) return false;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_audit_log 
            (admin_id, admin_role, action, module, target_id, target_type, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $admin_id,
            $user_role,
            $action,
            $module,
            $target_id,
            $target_type,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get role-specific stats for dashboard - ALL STATS FOR ALL ROLES
 */
function get_role_dashboard_stats($role) {
    global $pdo;
    
    $stats = [];
    
    try {
        // All admin roles now get the same comprehensive stats
        $stats = [
            'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'events' => $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn(),
            'donations' => $pdo->query("SELECT COUNT(*) FROM donations WHERE donation_date = CURDATE()")->fetchColumn(),
            'inventory' => $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity < 10")->fetchColumn(),
            'blood_banks' => $pdo->query("SELECT COUNT(*) FROM blood_banks")->fetchColumn(),
            'registrations' => $pdo->query("SELECT COUNT(*) FROM registrations WHERE registration_date = CURDATE()")->fetchColumn(),
            'training_sessions' => $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()")->fetchColumn(),
        ];
    } catch (PDOException $e) {
        error_log("Error getting role stats: " . $e->getMessage());
        // Return empty stats on error
        $stats = array_fill_keys(['users', 'events', 'donations', 'inventory', 'blood_banks', 'registrations', 'training_sessions'], 0);
    }
    
    return $stats;
}

// ===============================================
// HELPER FUNCTIONS - UNRESTRICTED
// ===============================================

/**
 * Check if current page is an admin page
 */
function is_admin_page() {
    $admin_pages = [
        'dashboard.php', 'manage_users.php', 'manage_events.php', 'manage_sessions.php',
        'manage_donations.php', 'manage_inventory.php', 'manage_blood_banks.php',
        'manage_announcements.php', 'view_registrations.php', 'system_settings.php',
        'admin_management.php', 'reports.php'
    ];
    
    $current_page = basename($_SERVER['PHP_SELF']);
    return in_array($current_page, $admin_pages);
}
function current_user_role() {
    return get_user_role();
}

/**
 * Validate route access - ALL ADMINS CAN ACCESS ALL ROUTES
 */
function validate_route_access($current_page, $role) {
    // All admin roles can now access all pages
    return !empty($role);
}

/**
 * Redirect user based on their role - NO RESTRICTIONS
 */
function enforce_route_access() {
    $current_page = basename($_SERVER['PHP_SELF']);
    $user_role = get_user_role();
    
    // All admin roles can access all pages - no enforcement needed
    return true;
}

/**
 * Initialize role-based session data
 */
function init_role_session($username) {
    $role = get_user_role();
    if ($role) {
        $_SESSION['user_role'] = $role;
        $_SESSION['allowed_services'] = get_allowed_services($role);
    }
}

/**
 * Clean and validate service parameter from URL - NO RESTRICTIONS
 */
function validate_service_parameter($role) {
    $service = $_GET['service'] ?? null;
    
    if (!$service) {
        return null;
    }
    
    // Map service shortcuts to full names
    $service_map = [
        'safety' => 'Safety Services',
        'welfare' => 'Welfare Services', 
        'health' => 'Health Services',
        'disaster' => 'Disaster Management',
        'youth' => 'Red Cross Youth'
    ];
    
    $full_service = $service_map[$service] ?? $service;
    
    // All admin roles can access all services
    return $full_service;
}

/**
 * Generate role-specific navigation menu - FULL ACCESS FOR ALL ROLES
 */
function get_role_navigation($role) {
    // All admin roles now get the full navigation menu
    $full_navigation = [
        ['url' => 'dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
        ['url' => 'manage_users.php', 'icon' => 'fas fa-users-cog', 'label' => 'User Management'],
        ['url' => 'manage_events.php', 'icon' => 'fas fa-calendar-alt', 'label' => 'Events'],
        ['url' => 'manage_sessions.php', 'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Training Sessions'],
        ['url' => 'manage_donations.php', 'icon' => 'fas fa-donate', 'label' => 'Donations'],
        ['url' => 'manage_inventory.php', 'icon' => 'fas fa-warehouse', 'label' => 'Inventory'],
        ['url' => 'manage_blood_banks.php', 'icon' => 'fas fa-hospital', 'label' => 'Blood Banks'],
        ['url' => 'manage_announcements.php', 'icon' => 'fas fa-bullhorn', 'label' => 'Announcements'],
        ['url' => 'view_registrations.php', 'icon' => 'fas fa-clipboard-list', 'label' => 'Registrations'],
        ['url' => 'system_settings.php', 'icon' => 'fas fa-cogs', 'label' => 'System Settings'],
        ['url' => 'admin_management.php', 'icon' => 'fas fa-user-shield', 'label' => 'Admin Management'],
        ['url' => 'reports.php', 'icon' => 'fas fa-chart-bar', 'label' => 'Reports'],
    ];
    
    return $full_navigation;
}

/**
 * Check if current user can edit/delete specific record - ALWAYS TRUE FOR ADMINS
 */
function can_modify_record($role, $record_service, $record_owner_id = null) {
    // All admin roles can now modify any record
    return !empty($role);
}

/**
 * Get role-specific CSS class for styling
 */
function get_role_css_class($role) {
    return 'admin-' . $role;
}

/**
 * Get role display information
 */
function get_role_info($role) {
    $role_info = [
        'safety' => ['name' => 'Safety Services', 'color' => '#28a745'],
        'welfare' => ['name' => 'Welfare Services', 'color' => '#17a2b8'],
        'health' => ['name' => 'Health Services', 'color' => '#dc3545'],
        'disaster' => ['name' => 'Disaster Management', 'color' => '#fd7e14'],
        'youth' => ['name' => 'Red Cross Youth', 'color' => '#6f42c1'],
        'super' => ['name' => 'Super Administrator', 'color' => '#a00000']
    ];
    
    return $role_info[$role] ?? $role_info['super'];
}

// ===============================================
// SYSTEM UTILITIES AND CONSTANTS
// ===============================================

// Define role display names
define('ROLE_NAMES', [
    'safety' => 'Safety Services Administrator',
    'welfare' => 'Welfare Services Administrator', 
    'health' => 'Health Services Administrator',
    'disaster' => 'Disaster Management Administrator',
    'youth' => 'Red Cross Youth Administrator',
    'super' => 'Super Administrator'
]);

// Define role colors
define('ROLE_COLORS', [
    'safety' => '#28a745',
    'welfare' => '#17a2b8',
    'health' => '#dc3545',
    'disaster' => '#fd7e14',
    'youth' => '#6f42c1',
    'super' => '#a00000'
]);

// Helper function to get role display name
function get_role_display_name($role) {
    return ROLE_NAMES[$role] ?? 'Administrator';
}

// Helper function to get role color
function get_role_color($role) {
    return ROLE_COLORS[$role] ?? ROLE_COLORS['super'];
}

// Auto-initialize role session if user is logged in and admin
if (is_logged_in() && is_admin() && !isset($_SESSION['user_role'])) {
    init_role_session(current_username());
}

// Remove auto-enforcement of route access - all admins can access all pages
// if (is_admin_page() && is_logged_in()) {
//     enforce_route_access();
// }

// Set timezone
date_default_timezone_set('Asia/Manila');

?>