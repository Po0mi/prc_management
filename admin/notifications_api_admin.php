<?php
/**
 * Fixed Admin Notifications API - Updated for new database schema
 */

require_once __DIR__ . '/../config.php';

// Only set headers and handle API requests if this is an actual API call
if (isset($_GET['action']) || isset($_POST['action'])) {
    // Set JSON content type
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

    // Ensure user is logged in and is admin
    if (!isset($_SESSION['user_id']) || !get_user_role()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = $GLOBALS['pdo'];
    $user_id = $_SESSION['user_id'];
    $user_role = get_user_role();
    $admin_role = $_SESSION['admin_role'] ?? 'super';

    // Get action from request
    $action = $_GET['action'] ?? $_POST['action'] ?? 'check';

    try {
        switch ($action) {
            case 'get_notifications':
                // NEW: Get notifications with view tracking
                $notifications = getTrackedNotifications($pdo, $user_id);
                echo json_encode([
                    'success' => true,
                    'notifications' => $notifications,
                    'timestamp' => time()
                ]);
                break;
                
            case 'mark_viewed':
                // NEW: Mark notification type as viewed
                $type = $_POST['type'] ?? '';
                if ($type) {
                    markNotificationViewed($pdo, $user_id, $type);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Type required']);
                }
                break;
                
            case 'get_sidebar_counts':
                $counts = getSidebarNotificationCounts($pdo, $user_id, $user_role, $admin_role);
                echo json_encode([
                    'success' => true,
                    'counts' => $counts,
                    'timestamp' => time()
                ]);
                break;
                
            case 'check':
                $notifications = getSimpleNotifications($pdo, $user_id, $user_role, $admin_role);
                echo json_encode([
                    'success' => true,
                    'notifications' => $notifications,
                    'count' => count($notifications),
                    'timestamp' => time()
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        error_log("Admin notifications API error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit;
}

/**
 * NEW: Get tracked notifications (only count items since last view)
 */
function getTrackedNotifications($pdo, $user_id) {
    // Create tracking table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_views (
            view_id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            last_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_admin_type (admin_id, notification_type),
            INDEX idx_admin (admin_id)
        )
    ");
    
    $lastViewed = getLastViewedTimestamps($pdo, $user_id);
    $notifications = [];
    
    try {
        // Events - pending registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM registrations 
            WHERE status = 'pending' 
            AND registration_date > :last_viewed
            AND archived = 0
        ");
        $stmt->execute(['last_viewed' => $lastViewed['events']]);
        $notifications['events'] = (int)$stmt->fetchColumn();
        
        // Sessions - pending session registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM session_registrations 
            WHERE status = 'pending' 
            AND registration_date > :last_viewed
        ");
        $stmt->execute(['last_viewed' => $lastViewed['sessions']]);
        $notifications['sessions'] = (int)$stmt->fetchColumn();
        
        // Training Requests - pending only
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM training_requests 
            WHERE status = 'pending' 
            AND created_at > :last_viewed
        ");
        $stmt->execute(['last_viewed' => $lastViewed['training_requests']]);
        $notifications['training_requests'] = (int)$stmt->fetchColumn();
        
        // Donations - pending only
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM donations 
            WHERE status = 'pending' 
            AND created_at > :last_viewed
        ");
        $stmt->execute(['last_viewed' => $lastViewed['donations']]);
        $notifications['donations'] = (int)$stmt->fetchColumn();
        
        // Users - new users
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM users 
            WHERE created_at > :last_viewed
            AND is_admin = 0
        ");
        $stmt->execute(['last_viewed' => $lastViewed['users']]);
        $notifications['users'] = (int)$stmt->fetchColumn();
        
        // Inventory - low stock items
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM inventory_items 
            WHERE current_stock <= minimum_stock 
            AND status = 'active'
            AND updated_at > :last_viewed
        ");
        $stmt->execute(['last_viewed' => $lastViewed['inventory']]);
        $notifications['inventory'] = (int)$stmt->fetchColumn();
        
        // Merch - low stock (10 or less)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM merchandise 
            WHERE stock_quantity <= 10 
            AND is_available = 1
            AND updated_at > :last_viewed
        ");
        $stmt->execute(['last_viewed' => $lastViewed['merch']]);
        $notifications['merch'] = (int)$stmt->fetchColumn();
        
        // Volunteers - new volunteers
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM volunteers 
            WHERE created_at > :last_viewed
            AND status = 'current'
        ");
        $stmt->execute(['last_viewed' => $lastViewed['volunteers']]);
        $notifications['volunteers'] = (int)$stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("Error getting tracked notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * NEW: Get last viewed timestamps for each notification type
 */
function getLastViewedTimestamps($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT notification_type, last_viewed_at 
        FROM notification_views 
        WHERE admin_id = :admin_id
    ");
    $stmt->execute(['admin_id' => $user_id]);
    
    $views = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $views[$row['notification_type']] = $row['last_viewed_at'];
    }
    
    // Default: 7 days ago for new tracking
    $defaultTime = date('Y-m-d H:i:s', strtotime('-7 days'));
    
    return [
        'events' => $views['events'] ?? $defaultTime,
        'sessions' => $views['sessions'] ?? $defaultTime,
        'training_requests' => $views['training_requests'] ?? $defaultTime,
        'donations' => $views['donations'] ?? $defaultTime,
        'users' => $views['users'] ?? $defaultTime,
        'inventory' => $views['inventory'] ?? $defaultTime,
        'merch' => $views['merch'] ?? $defaultTime,
        'volunteers' => $views['volunteers'] ?? $defaultTime,
    ];
}

/**
 * NEW: Mark notification type as viewed
 */
function markNotificationViewed($pdo, $user_id, $type) {
    $stmt = $pdo->prepare("
        INSERT INTO notification_views (admin_id, notification_type, last_viewed_at)
        VALUES (:admin_id, :type, NOW())
        ON DUPLICATE KEY UPDATE last_viewed_at = NOW()
    ");
    
    $stmt->execute([
        'admin_id' => $user_id,
        'type' => $type
    ]);
}

/**
 * Get sidebar notification counts - FIXED for new database schema
 */
function getSidebarNotificationCounts($pdo, $user_id, $user_role, $admin_role) {
    $counts = [];
    
    try {
        // Events/Registrations
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN r.status = 'pending' AND r.registration_date < DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 END) as urgent_action,
                COUNT(CASE WHEN r.status = 'pending' AND r.registration_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 END) as registration,
                COUNT(CASE WHEN e.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND e.event_date > CURDATE() THEN 1 END) as upcoming
            FROM registrations r
            RIGHT JOIN events e ON r.event_id = e.event_id
            WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute();
        $eventCounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $counts['urgent_action'] = (int)($eventCounts['urgent_action'] ?? 0);
        $counts['registration'] = (int)($eventCounts['registration'] ?? 0);
        $counts['upcoming'] = (int)($eventCounts['upcoming'] ?? 0);

        // Training Sessions
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND session_date > CURDATE()
        ");
        $stmt->execute();
        $counts['training_sessions'] = (int)$stmt->fetchColumn();

        // Training Requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM training_requests 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $counts['training_requests'] = (int)$stmt->fetchColumn();
        $counts['requests'] = $counts['training_requests'];

        // Donations
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM donations 
            WHERE status = 'pending'
        ");
        $counts['donation'] = (int)$stmt->fetchColumn();
        $counts['blood_donation'] = 0;

        // Inventory
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM inventory_items 
            WHERE current_stock <= 5 AND current_stock >= 0
        ");
        $stmt->execute();
        $counts['critical_stock'] = (int)$stmt->fetchColumn();
        $counts['inventory'] = $counts['critical_stock'];

        // Users
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM users 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND role = 'user'
        ");
        $counts['new_users'] = (int)$stmt->fetchColumn();
        $counts['user_activity'] = $counts['new_users'];

        // Volunteers
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM volunteers 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $counts['volunteers'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $counts['volunteers'] = 0;
        }
        $counts['volunteer_applications'] = $counts['volunteers'];

        // Announcements
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM announcements 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
            ");
            $counts['announcements'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $counts['announcements'] = 0;
        }
        $counts['announcement'] = $counts['announcements'];

    } catch (Exception $e) {
        error_log("Error getting sidebar notification counts: " . $e->getMessage());
        $counts = [
            'urgent_action' => 0, 'registration' => 0, 'upcoming' => 0,
            'training_sessions' => 0, 'training_requests' => 0, 'requests' => 0,
            'donation' => 0, 'blood_donation' => 0, 'inventory' => 0,
            'critical_stock' => 0, 'volunteers' => 0, 'volunteer_applications' => 0,
            'new_users' => 0, 'user_activity' => 0, 'announcements' => 0,
            'announcement' => 0
        ];
    }
    
    return $counts;
}

/**
 * Get simple notifications for display
 */
function getSimpleNotifications($pdo, $user_id, $user_role, $admin_role) {
    $notifications = [];
    
    try {
        // Critical: Overdue registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM registrations 
            WHERE status = 'pending' 
            AND registration_date < DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ");
        $stmt->execute();
        $overdue = $stmt->fetch();
        
        if ($overdue && $overdue['count'] > 0) {
            $notifications[] = [
                'id' => 'overdue_registrations_' . time(),
                'type' => 'urgent_action',
                'priority' => 'high',
                'title' => 'Overdue Registrations',
                'message' => "{$overdue['count']} registration(s) pending for over 48 hours",
                'icon' => 'fas fa-user-clock',
                'url' => 'manage_events.php',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Critical: Low inventory
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM inventory_items 
            WHERE current_stock <= 5 AND current_stock >= 0
        ");
        $stmt->execute();
        $critical = $stmt->fetch();
        
        if ($critical && $critical['count'] > 0) {
            $notifications[] = [
                'id' => 'critical_inventory_' . time(),
                'type' => 'critical_stock',
                'priority' => 'high',
                'title' => 'Critical Stock Levels',
                'message' => "{$critical['count']} items critically low",
                'icon' => 'fas fa-exclamation-triangle',
                'url' => 'manage_inventory.php',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Medium: Pending registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM registrations 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $pending = $stmt->fetch();
        
        if ($pending && $pending['count'] > 0) {
            $notifications[] = [
                'id' => 'pending_registrations_' . time(),
                'type' => 'registration',
                'priority' => 'medium',
                'title' => 'Pending Registrations',
                'message' => "{$pending['count']} registration(s) awaiting approval",
                'icon' => 'fas fa-user-plus',
                'url' => 'manage_events.php',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Medium: Training requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM training_requests 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $requests = $stmt->fetch();
        
        if ($requests && $requests['count'] > 0) {
            $notifications[] = [
                'id' => 'training_requests_' . time(),
                'type' => 'training_requests',
                'priority' => 'medium',
                'title' => 'Training Requests',
                'message' => "{$requests['count']} request(s) need review",
                'icon' => 'fas fa-clipboard-list',
                'url' => 'training_request.php',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // Low: New users
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM users 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND role = 'user'
        ");
        $stmt->execute();
        $newUsers = $stmt->fetch();
        
        if ($newUsers && $newUsers['count'] > 0) {
            $notifications[] = [
                'id' => 'new_users_' . time(),
                'type' => 'new_users',
                'priority' => 'low',
                'title' => 'New Users',
                'message' => "{$newUsers['count']} new user(s) this week",
                'icon' => 'fas fa-users',
                'url' => 'manage_users.php',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

    } catch (Exception $e) {
        error_log("Error getting notifications: " . $e->getMessage());
    }
    
    return $notifications;
}
?>