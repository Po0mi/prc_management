<?php
/**
 * Enhanced Admin Notifications API
 * Handles real-time notifications for admin users with proper navigation URLs
 */

require_once __DIR__ . '/../config.php';

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
        case 'check':
            $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
            $notifications = getAdminNotifications($pdo, $user_id, $user_role, $admin_role, $since);
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'count' => count($notifications),
                'timestamp' => time()
            ]);
            break;
            
        case 'mark_read':
            $input = json_decode(file_get_contents('php://input'), true);
            $notification_id = $input['notification_id'] ?? null;
            
            if ($notification_id) {
                markNotificationAsRead($pdo, $user_id, $notification_id);
                echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
            }
            break;
            
        case 'mark_all_read':
            markAllNotificationsAsRead($pdo, $user_id);
            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
            break;
            
        case 'get_sidebar_counts':
            $counts = getSidebarNotificationCounts($pdo, $user_id, $user_role, $admin_role);
            echo json_encode([
                'success' => true,
                'counts' => $counts,
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

/**
 * Get admin notifications based on role and permissions
 */
function getAdminNotifications($pdo, $user_id, $user_role, $admin_role, $since = 0) {
    $notifications = [];
    $since_date = $since > 0 ? date('Y-m-d H:i:s', $since) : date('Y-m-d H:i:s', strtotime('-7 days'));
    
    try {
        // Check for unread notifications first
        $stmt = $pdo->prepare("
            SELECT notification_id FROM admin_notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        $unread_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // System-wide critical notifications
        $critical_notifications = getCriticalNotifications($pdo, $admin_role, $since_date);
        $notifications = array_merge($notifications, $critical_notifications);
        
        // Pending registrations notifications
        $registration_notifications = getRegistrationNotifications($pdo, $admin_role, $since_date);
        $notifications = array_merge($notifications, $registration_notifications);
        
        // Training request notifications
        $training_notifications = getTrainingRequestNotifications($pdo, $admin_role, $since_date);
        $notifications = array_merge($notifications, $training_notifications);
        
        // Inventory alerts
        $inventory_notifications = getInventoryNotifications($pdo, $admin_role, $since_date);
        $notifications = array_merge($notifications, $inventory_notifications);
        
        // Donation notifications
        $donation_notifications = getDonationNotifications($pdo, $admin_role, $since_date);
        $notifications = array_merge($notifications, $donation_notifications);
        
        // Enhanced user activity notifications
        $user_notifications = getUserActivityNotifications($pdo, $admin_role, $since_date);
        $notifications = array_merge($notifications, $user_notifications);
        
        // System announcements
        $announcement_notifications = getAnnouncementNotifications($pdo, $since_date);
        $notifications = array_merge($notifications, $announcement_notifications);
        
        // Filter and sort notifications
        $notifications = array_filter($notifications, function($notification) use ($unread_ids) {
            return in_array($notification['id'], $unread_ids) || 
                   strtotime($notification['created_at']) > strtotime('-1 hour');
        });
        
        // Sort by priority and timestamp
        usort($notifications, function($a, $b) {
            $priority_order = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
            
            $a_priority = $priority_order[$a['priority']] ?? 5;
            $b_priority = $priority_order[$b['priority']] ?? 5;
            
            if ($a_priority !== $b_priority) {
                return $a_priority - $b_priority;
            }
            
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Store new notifications in database
        foreach ($notifications as $notification) {
            storeNotification($pdo, $user_id, $notification);
        }
        
        return array_slice($notifications, 0, 20); // Limit to 20 notifications
        
    } catch (Exception $e) {
        error_log("Error getting admin notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Get critical system notifications
 */
function getCriticalNotifications($pdo, $admin_role, $since_date) {
    $notifications = [];
    
    try {
        // System health issues
        if ($admin_role === 'super') {
            // Check for failed database operations
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as error_count 
                FROM system_logs 
                WHERE level = 'ERROR' 
                AND created_at > ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$since_date]);
            $result = $stmt->fetch();
            
            if ($result && $result['error_count'] > 5) {
                $notifications[] = [
                    'id' => 'system_errors_' . time(),
                    'type' => 'system_alert',
                    'priority' => 'critical',
                    'title' => 'System Errors Detected',
                    'message' => "{$result['error_count']} system errors in the last hour. Immediate attention required.",
                    'icon' => 'fas fa-exclamation-triangle',
                    'url' => 'system_logs.php',
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        // Check for overdue registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MIN(registration_date) as oldest_date
            FROM registrations 
            WHERE status = 'pending' 
            AND registration_date < DATE_SUB(NOW(), INTERVAL 72 HOUR)
            AND registration_date > ?
        ");
        $stmt->execute([$since_date]);
        $overdue = $stmt->fetch();
        
        if ($overdue && $overdue['count'] > 0) {
            $notifications[] = [
                'id' => 'overdue_registrations_' . time(),
                'type' => 'event_reminder',
                'priority' => 'high',
                'title' => 'Overdue Registrations',
                'message' => "{$overdue['count']} registration(s) pending for over 72 hours. Oldest from " . date('M j, Y', strtotime($overdue['oldest_date'])),
                'icon' => 'fas fa-user-clock',
                'url' => 'manage_events.php?filter=overdue',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting critical notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Get registration-related notifications
 */
function getRegistrationNotifications($pdo, $admin_role, $since_date) {
    $notifications = [];
    
    try {
        // New registrations needing approval
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(registration_date) as latest_date,
                   GROUP_CONCAT(DISTINCT r.registration_id) as registration_ids
            FROM registrations r
            JOIN events e ON r.event_id = e.event_id
            WHERE r.status = 'pending' 
            AND r.registration_date > ?
            " . ($admin_role !== 'super' ? "AND e.major_service = ?" : "")
        );
        
        $params = [$since_date];
        if ($admin_role !== 'super') {
            $service_map = [
                'health' => 'Health Service',
                'safety' => 'Safety Service',
                'welfare' => 'Welfare Service',
                'disaster' => 'Disaster Management Service',
                'youth' => 'Red Cross Youth'
            ];
            $params[] = $service_map[$admin_role] ?? '';
        }
        
        $stmt->execute($params);
        $pending = $stmt->fetch();
        
        if ($pending && $pending['count'] > 0) {
            $notifications[] = [
                'id' => 'new_registrations_' . strtotime($pending['latest_date']),
                'type' => 'volunteer_application',
                'priority' => 'medium',
                'title' => 'New Event Registrations',
                'message' => "{$pending['count']} new registration(s) need approval.",
                'icon' => 'fas fa-user-plus',
                'url' => 'manage_events.php',
                'registration_ids' => $pending['registration_ids'] ?? null,
                'created_at' => $pending['latest_date']
            ];
        }
        
        // Events reaching capacity
        $stmt = $pdo->prepare("
            SELECT e.title, e.capacity, e.event_id, COUNT(r.registration_id) as registered
            FROM events e
            LEFT JOIN registrations r ON e.event_id = r.event_id AND r.status = 'approved'
            WHERE e.capacity > 0 
            AND e.event_date > NOW()
            AND e.created_at > ?
            " . ($admin_role !== 'super' ? "AND e.major_service = ?" : "") . "
            GROUP BY e.event_id
            HAVING registered >= (e.capacity * 0.9)
        ");
        
        $stmt->execute($params);
        $full_events = $stmt->fetchAll();
        
        foreach ($full_events as $event) {
            $notifications[] = [
                'id' => 'event_capacity_' . $event['event_id'] . '_' . time(),
                'type' => 'event_reminder',
                'priority' => 'medium',
                'title' => 'Event Nearly Full',
                'message' => "'{$event['title']}' is at {$event['registered']}/{$event['capacity']} capacity.",
                'icon' => 'fas fa-users',
                'url' => 'manage_events.php?highlight_event=' . $event['event_id'],
                'event_id' => $event['event_id'],
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting registration notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Get training request notifications
 */
function getTrainingRequestNotifications($pdo, $admin_role, $since_date) {
    $notifications = [];
    
    try {
        // New training requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(created_at) as latest_date,
                   GROUP_CONCAT(DISTINCT id LIMIT 5) as request_ids
            FROM training_requests 
            WHERE status = 'pending' 
            AND created_at > ?
            " . ($admin_role !== 'super' ? "AND service_type = ?" : "")
        );
        
        $params = [$since_date];
        if ($admin_role !== 'super') {
            $service_map = [
                'health' => 'Health Service',
                'safety' => 'Safety Service', 
                'welfare' => 'Welfare Service',
                'disaster' => 'Disaster Management Service',
                'youth' => 'Red Cross Youth'
            ];
            $params[] = $service_map[$admin_role] ?? '';
        }
        
        $stmt->execute($params);
        $pending = $stmt->fetch();
        
        if ($pending && $pending['count'] > 0) {
            $notifications[] = [
                'id' => 'new_training_requests_' . strtotime($pending['latest_date']),
                'type' => 'training_scheduled',
                'priority' => 'medium',
                'title' => 'New Training Requests',
                'message' => "{$pending['count']} new training request(s) need review.",
                'icon' => 'fas fa-graduation-cap',
                'url' => 'manage_training_requests.php',
                'training_ids' => $pending['request_ids'] ?? null,
                'created_at' => $pending['latest_date']
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting training notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Get inventory-related notifications
 */
function getInventoryNotifications($pdo, $admin_role, $since_date) {
    $notifications = [];
    
    try {
        // Low stock items
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as critical_count,
                   GROUP_CONCAT(DISTINCT CONCAT(item_id, ':', item_name) ORDER BY quantity LIMIT 5) as items_data
            FROM inventory_items 
            WHERE quantity <= 5 
            AND quantity > 0
            AND updated_at > ?
            " . ($admin_role !== 'super' ? "AND (service_area = ? OR admin_id = ?)" : "")
        );
        
        $params = [$since_date];
        if ($admin_role !== 'super') {
            $params[] = $admin_role;
            $params[] = $_SESSION['user_id'];
        }
        
        $stmt->execute($params);
        $critical = $stmt->fetch();
        
        if ($critical && $critical['critical_count'] > 0) {
            // Extract item names and IDs
            $items_info = explode(',', $critical['items_data']);
            $item_names = [];
            $item_ids = [];
            
            foreach ($items_info as $item_data) {
                $parts = explode(':', $item_data);
                if (count($parts) >= 2) {
                    $item_ids[] = $parts[0];
                    $item_names[] = $parts[1];
                }
            }
            
            $notifications[] = [
                'id' => 'low_inventory_' . time(),
                'type' => 'inventory_critical',
                'priority' => 'high',
                'title' => 'Critical Stock Levels',
                'message' => "{$critical['critical_count']} items critically low. Including: " . implode(', ', array_slice($item_names, 0, 3)),
                'icon' => 'fas fa-box-open',
                'url' => 'manage_inventory.php?filter=low_stock',
                'item_ids' => implode(',', $item_ids),
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Expired items
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as expired_count,
                   GROUP_CONCAT(DISTINCT item_id LIMIT 5) as expired_ids
            FROM inventory_items 
            WHERE expiry_date < CURDATE()
            AND expiry_date > ?
            " . ($admin_role !== 'super' ? "AND (service_area = ? OR admin_id = ?)" : "")
        );
        
        $stmt->execute($params);
        $expired = $stmt->fetch();
        
        if ($expired && $expired['expired_count'] > 0) {
            $notifications[] = [
                'id' => 'expired_inventory_' . time(),
                'type' => 'inventory_low',
                'priority' => 'medium',
                'title' => 'Expired Items',
                'message' => "{$expired['expired_count']} item(s) have expired and need removal.",
                'icon' => 'fas fa-exclamation-triangle',
                'url' => 'manage_inventory.php?filter=expired',
                'item_ids' => $expired['expired_ids'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting inventory notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Get donation notifications
 */
function getDonationNotifications($pdo, $admin_role, $since_date) {
    $notifications = [];
    
    try {
        // New donations needing approval
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(created_at) as latest_date,
                   GROUP_CONCAT(DISTINCT donation_id LIMIT 5) as donation_ids
            FROM donations 
            WHERE status = 'pending' 
            AND created_at > ?
        ");
        $stmt->execute([$since_date]);
        $pending_donations = $stmt->fetch();
        
        if ($pending_donations && $pending_donations['count'] > 0) {
            $notifications[] = [
                'id' => 'new_donations_' . strtotime($pending_donations['latest_date']),
                'type' => 'new_donation',
                'priority' => 'medium',
                'title' => 'New Donations',
                'message' => "{$pending_donations['count']} new donation(s) need approval.",
                'icon' => 'fas fa-heart',
                'url' => 'manage_donations.php',
                'donation_ids' => $pending_donations['donation_ids'] ?? null,
                'created_at' => $pending_donations['latest_date']
            ];
        }
        
        // Blood donations needing processing
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   GROUP_CONCAT(DISTINCT blood_donation_id LIMIT 5) as blood_donation_ids
            FROM blood_donations 
            WHERE status = 'scheduled' 
            AND donation_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            AND created_at > ?
        ");
        $stmt->execute([$since_date]);
        $upcoming_blood = $stmt->fetch();
        
        if ($upcoming_blood && $upcoming_blood['count'] > 0) {
            $notifications[] = [
                'id' => 'upcoming_blood_donations_' . time(),
                'type' => 'donation_approved',
                'priority' => 'medium',
                'title' => 'Upcoming Blood Donations',
                'message' => "{$upcoming_blood['count']} blood donation(s) scheduled for this week.",
                'icon' => 'fas fa-tint',
                'url' => 'manage_donations.php?type=blood',
                'blood_donation_ids' => $upcoming_blood['blood_donation_ids'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting donation notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Enhanced User Activity Notifications with proper URLs
 */
function getUserActivityNotifications($pdo, $admin_role, $since_date) {
    $notifications = [];
    
    try {
        // New user registrations (shows for any new users)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(created_at) as latest_date,
                   GROUP_CONCAT(DISTINCT user_id ORDER BY created_at DESC LIMIT 10) as user_ids
            FROM users 
            WHERE created_at > ?
            AND role = 'user'
        ");
        $stmt->execute([$since_date]);
        $new_users = $stmt->fetch();
        
        if ($new_users && $new_users['count'] > 0) {
            $priority = $new_users['count'] >= 10 ? 'high' : ($new_users['count'] >= 5 ? 'medium' : 'low');
            
            $notifications[] = [
                'id' => 'new_users_' . strtotime($new_users['latest_date']),
                'type' => 'new_user',
                'priority' => $priority,
                'title' => 'New User Registrations',
                'message' => "{$new_users['count']} new user(s) registered recently. Review and manage user accounts.",
                'icon' => 'fas fa-user-plus',
                'url' => 'manage_users.php',
                'user_ids' => $new_users['user_ids'] ?? null,
                'created_at' => $new_users['latest_date']
            ];
        }
        
        // New admin users (high priority)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(created_at) as latest_date,
                   GROUP_CONCAT(DISTINCT user_id ORDER BY created_at DESC LIMIT 5) as admin_ids
            FROM users 
            WHERE created_at > ?
            AND role = 'admin'
        ");
        $stmt->execute([$since_date]);
        $new_admins = $stmt->fetch();
        
        if ($new_admins && $new_admins['count'] > 0) {
            $notifications[] = [
                'id' => 'new_admins_' . strtotime($new_admins['latest_date']),
                'type' => 'new_user',
                'priority' => 'high',
                'title' => 'New Administrator Accounts',
                'message' => "{$new_admins['count']} new administrator account(s) created. Verify permissions and access levels.",
                'icon' => 'fas fa-user-shield',
                'url' => 'manage_users.php?role=admin',
                'user_ids' => $new_admins['admin_ids'] ?? null,
                'created_at' => $new_admins['latest_date']
            ];
        }
        
        // RCY Member registrations
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, MAX(created_at) as latest_date,
                   GROUP_CONCAT(DISTINCT user_id ORDER BY created_at DESC LIMIT 10) as rcy_ids
            FROM users 
            WHERE created_at > ?
            AND user_type = 'rcy_member'
        ");
        $stmt->execute([$since_date]);
        $new_rcy = $stmt->fetch();
        
        if ($new_rcy && $new_rcy['count'] > 0) {
            $notifications[] = [
                'id' => 'new_rcy_members_' . strtotime($new_rcy['latest_date']),
                'type' => 'new_user',
                'priority' => 'medium',
                'title' => 'New RCY Members',
                'message' => "{$new_rcy['count']} new Red Cross Youth member(s) registered. Assign services and review memberships.",
                'icon' => 'fas fa-users',
                'url' => 'manage_users.php',
                'user_ids' => $new_rcy['rcy_ids'] ?? null,
                'created_at' => $new_rcy['latest_date']
            ];
        }
        
        // Users with incomplete profiles
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   GROUP_CONCAT(DISTINCT user_id LIMIT 10) as incomplete_ids
            FROM users 
            WHERE created_at > ?
            AND (email IS NULL OR email = '' OR phone IS NULL OR phone = '' OR full_name IS NULL OR full_name = '')
        ");
        $stmt->execute([$since_date]);
        $incomplete_profiles = $stmt->fetch();
        
        if ($incomplete_profiles && $incomplete_profiles['count'] > 0) {
            $notifications[] = [
                'id' => 'incomplete_profiles_' . time(),
                'type' => 'new_user',
                'priority' => 'low',
                'title' => 'Incomplete User Profiles',
                'message' => "{$incomplete_profiles['count']} user(s) have incomplete profile information. Follow up for completion.",
                'icon' => 'fas fa-user-edit',
                'url' => 'manage_users.php',
                'user_ids' => $incomplete_profiles['incomplete_ids'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting user activity notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Get system announcements
 */
function getAnnouncementNotifications($pdo, $since_date) {
    $notifications = [];
    
    try {
        // New system announcements
        $stmt = $pdo->prepare("
            SELECT announcement_id, title, content, created_at, priority
            FROM announcements 
            WHERE status = 'published' 
            AND target_audience IN ('all', 'admin')
            AND created_at > ?
            ORDER by priority DESC, created_at DESC 
            LIMIT 3
        ");
        $stmt->execute([$since_date]);
        $announcements = $stmt->fetchAll();
        
        foreach ($announcements as $announcement) {
            $notifications[] = [
                'id' => 'announcement_' . $announcement['announcement_id'] . '_' . strtotime($announcement['created_at']),
                'type' => 'announcement',
                'priority' => $announcement['priority'] ?? 'low',
                'title' => 'System Announcement',
                'message' => substr($announcement['content'], 0, 100) . '...',
                'icon' => 'fas fa-bullhorn',
                'url' => 'manage_announcements.php?highlight_announcement=' . $announcement['announcement_id'],
                'announcement_id' => $announcement['announcement_id'],
                'created_at' => $announcement['created_at']
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error getting announcement notifications: " . $e->getMessage());
    }
    
    return $notifications;
}

/**
 * Enhanced function to track real-time user additions
 * Call this function when a new user is created in manage_users.php
 */
function notifyNewUserCreated($pdo, $user_id, $username, $role, $user_type, $created_by_admin_id) {
    try {
        // Create a real-time notification for all admins
        $stmt = $pdo->prepare("
            SELECT user_id FROM users 
            WHERE role = 'admin' 
            AND user_id != ?
        ");
        $stmt->execute([$created_by_admin_id]);
        $admin_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $notification_data = [
            'id' => 'user_created_' . $user_id . '_' . time(),
            'type' => 'new_user',
            'priority' => $role === 'admin' ? 'high' : 'medium',
            'title' => $role === 'admin' ? 'New Administrator Created' : 'New User Created',
            'message' => "User '{$username}' has been created as " . ($role === 'admin' ? 'an administrator' : 'a regular user') . ($user_type === 'rcy_member' ? ' (RCY Member)' : '') . '.',
            'icon' => $role === 'admin' ? 'fas fa-user-shield' : 'fas fa-user-plus',
            'url' => 'manage_users.php?highlight_user=' . $user_id,
            'user_id' => $user_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Store notification for each admin
        foreach ($admin_users as $admin_id) {
            storeNotification($pdo, $admin_id, $notification_data);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error creating user notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced inventory notification function
 */
function notifyInventoryLow($pdo, $item_id, $item_name, $current_stock, $critical_level = 5) {
    try {
        // Get all admin users who should be notified
        $stmt = $pdo->prepare("
            SELECT user_id FROM users 
            WHERE role = 'admin'
        ");
        $stmt->execute();
        $admin_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $priority = $current_stock <= 2 ? 'critical' : ($current_stock <= $critical_level ? 'high' : 'medium');
        $type = $current_stock <= 2 ? 'inventory_critical' : 'inventory_low';
        
        $notification_data = [
            'id' => 'inventory_low_' . $item_id . '_' . time(),
            'type' => $type,
            'priority' => $priority,
            'title' => $current_stock <= 2 ? 'Critical Stock Alert' : 'Low Inventory Alert',
            'message' => "'{$item_name}' is running " . ($current_stock <= 2 ? 'critically' : '') . " low (Only {$current_stock} left)",
            'icon' => $current_stock <= 2 ? 'fas fa-exclamation-triangle' : 'fas fa-boxes',
            'url' => 'manage_inventory.php?highlight_item=' . $item_id,
            'item_id' => $item_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Store notification for each admin
        foreach ($admin_users as $admin_id) {
            storeNotification($pdo, $admin_id, $notification_data);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error creating inventory notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced donation notification function
 */
function notifyNewDonation($pdo, $donation_id, $donor_name, $donation_type, $amount = null) {
    try {
        // Get all admin users
        $stmt = $pdo->prepare("
            SELECT user_id FROM users 
            WHERE role = 'admin'
        ");
        $stmt->execute();
        $admin_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $message = "New {$donation_type} donation from {$donor_name}";
        if ($amount) {
            $message .= " (Amount: {$amount})";
        }
        $message .= " needs approval.";
        
        $notification_data = [
            'id' => 'donation_new_' . $donation_id . '_' . time(),
            'type' => 'new_donation',
            'priority' => 'medium',
            'title' => 'New Donation Received',
            'message' => $message,
            'icon' => 'fas fa-hand-holding-heart',
            'url' => 'manage_donations.php?highlight_donation=' . $donation_id,
            'donation_id' => $donation_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Store notification for each admin
        foreach ($admin_users as $admin_id) {
            storeNotification($pdo, $admin_id, $notification_data);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error creating donation notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced training notification function
 */
function notifyTrainingScheduled($pdo, $training_id, $training_title, $training_date, $service_type = null) {
    try {
        // Get relevant admin users
        $where_clause = "WHERE role = 'admin'";
        $params = [];
        
        if ($service_type) {
            $where_clause .= " AND (admin_role = 'super' OR admin_role = ?)";
            $params[] = $service_type;
        }
        
        $stmt = $pdo->prepare("
            SELECT user_id FROM users {$where_clause}
        ");
        $stmt->execute($params);
        $admin_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $notification_data = [
            'id' => 'training_scheduled_' . $training_id . '_' . time(),
            'type' => 'training_scheduled',
            'priority' => 'medium',
            'title' => 'Training Session Scheduled',
            'message' => "'{$training_title}' scheduled for " . date('M j, Y', strtotime($training_date)) . ". Review participants and materials.",
            'icon' => 'fas fa-graduation-cap',
            'url' => 'manage_training.php?highlight_training=' . $training_id,
            'training_id' => $training_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Store notification for each admin
        foreach ($admin_users as $admin_id) {
            storeNotification($pdo, $admin_id, $notification_data);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error creating training notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced event registration notification
 */
function notifyEventRegistration($pdo, $registration_id, $event_title, $participant_name, $registration_date) {
    try {
        // Get all admin users
        $stmt = $pdo->prepare("
            SELECT user_id FROM users 
            WHERE role = 'admin'
        ");
        $stmt->execute();
        $admin_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $notification_data = [
            'id' => 'event_registration_' . $registration_id . '_' . time(),
            'type' => 'volunteer_application',
            'priority' => 'medium',
            'title' => 'New Event Registration',
            'message' => "{$participant_name} registered for '{$event_title}'. Review and approve registration.",
            'icon' => 'fas fa-calendar-plus',
            'url' => 'manage_events.php?highlight_registration=' . $registration_id,
            'registration_id' => $registration_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        // Store notification for each admin
        foreach ($admin_users as $admin_id) {
            storeNotification($pdo, $admin_id, $notification_data);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error creating event registration notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Store notification in database
 */
function storeNotification($pdo, $user_id, $notification) {
    try {
        // Create admin_notifications table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `admin_notifications` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `notification_id` varchar(255) NOT NULL,
                `user_id` int(11) NOT NULL,
                `type` varchar(50) NOT NULL,
                `priority` enum('low','medium','high','critical') DEFAULT 'medium',
                `title` varchar(255) NOT NULL,
                `message` text NOT NULL,
                `icon` varchar(100) DEFAULT NULL,
                `url` varchar(255) DEFAULT NULL,
                `metadata` JSON DEFAULT NULL,
                `is_read` tinyint(1) DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `user_notification` (`user_id`, `notification_id`),
                KEY `user_id` (`user_id`),
                KEY `is_read` (`is_read`),
                KEY `type` (`type`),
                KEY `priority` (`priority`),
                FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        // Prepare metadata for additional notification data
        $metadata = [];
        foreach ($notification as $key => $value) {
            if (!in_array($key, ['id', 'type', 'priority', 'title', 'message', 'icon', 'url', 'created_at'])) {
                $metadata[$key] = $value;
            }
        }
        
        // Insert notification if it doesn't exist
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO admin_notifications 
            (notification_id, user_id, type, priority, title, message, icon, url, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $notification['id'],
            $user_id,
            $notification['type'],
            $notification['priority'],
            $notification['title'],
            $notification['message'],
            $notification['icon'],
            $notification['url'] ?? null,
            json_encode($metadata),
            $notification['created_at']
        ]);
        
    } catch (Exception $e) {
        error_log("Error storing notification: " . $e->getMessage());
    }
}

/**
 * Mark notification as read
 */
function markNotificationAsRead($pdo, $user_id, $notification_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE admin_notifications 
            SET is_read = 1 
            WHERE user_id = ? AND notification_id = ?
        ");
        $stmt->execute([$user_id, $notification_id]);
        
    } catch (Exception $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
    }
}

/**
 * Mark all notifications as read
 */
function markAllNotificationsAsRead($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            UPDATE admin_notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        
        // Clean up old read notifications (older than 30 days)
        $stmt = $pdo->prepare("
            DELETE FROM admin_notifications 
            WHERE user_id = ? 
            AND is_read = 1 
            AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        
    } catch (Exception $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
    }
}

/**
 * Get sidebar notification counts (unchanged from original)
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
            " . ($admin_role !== 'super' ? "AND e.major_service = ?" : "")
        );
        
        $params = [];
        if ($admin_role !== 'super') {
            $service_map = [
                'health' => 'Health Service',
                'safety' => 'Safety Service',
                'welfare' => 'Welfare Service',
                'disaster' => 'Disaster Management Service',
                'youth' => 'Red Cross Youth'
            ];
            $params[] = $service_map[$admin_role] ?? '';
        }
        
        $stmt->execute($params);
        $eventCounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $counts['urgent_action'] = (int)$eventCounts['urgent_action'];
        $counts['registration'] = (int)$eventCounts['registration'];
        $counts['upcoming'] = (int)$eventCounts['upcoming'];

        // Training Sessions
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND session_date > CURDATE()
            " . ($admin_role !== 'super' ? "AND major_service = ?" : "")
        );
        $stmt->execute($params);
        $counts['training_sessions'] = (int)$stmt->fetchColumn();

        // Training Requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM training_requests 
            WHERE status = 'pending'
            " . ($admin_role !== 'super' ? "AND service_type = ?" : "")
        );
        $stmt->execute($params);
        $counts['training_requests'] = (int)$stmt->fetchColumn();
        $counts['requests'] = $counts['training_requests']; // Alias

        // Donations
        $stmt = $pdo->query("
            SELECT 
                COUNT(CASE WHEN d.status = 'pending' THEN 1 END) as pending_donations,
                COUNT(CASE WHEN bd.status = 'scheduled' AND bd.donation_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 1 END) as upcoming_blood
            FROM donations d
            LEFT JOIN blood_donations bd ON 1=1
            WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            OR bd.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $donationCounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $counts['donation'] = (int)$donationCounts['pending_donations'];
        $counts['blood_donation'] = (int)$donationCounts['upcoming_blood'];

        // Inventory
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN quantity <= 5 AND quantity > 0 THEN 1 END) as critical_stock,
                COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired
            FROM inventory_items 
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            " . ($admin_role !== 'super' ? "AND (service_area = ? OR admin_id = ?)" : "")
        );
        
        $inventoryParams = [];
        if ($admin_role !== 'super') {
            $inventoryParams[] = $admin_role;
            $inventoryParams[] = $user_id;
        }
        
        $stmt->execute($inventoryParams);
        $inventoryCounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $counts['inventory'] = (int)$inventoryCounts['critical_stock'];
        $counts['critical_stock'] = (int)$inventoryCounts['critical_stock'];

        // Volunteers
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM volunteers 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND status = 'pending'
        ");
        $counts['volunteers'] = (int)$stmt->fetchColumn();
        $counts['volunteer_applications'] = $counts['volunteers']; // Alias

        // Enhanced Users section
        $stmt = $pdo->query("
            SELECT 
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
                COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as last_7d,
                COUNT(CASE WHEN role = 'admin' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_admins,
                COUNT(CASE WHEN user_type = 'rcy_member' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_rcy,
                COUNT(CASE WHEN (email IS NULL OR email = '' OR phone IS NULL OR phone = '' OR full_name IS NULL OR full_name = '') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as incomplete
            FROM users 
            WHERE role = 'user' OR role = 'admin'
        ");
        $userCounts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate total notifications for users page
        $totalUserNotifications = 0;
        
        // Recent users (any new user in last 7 days shows badge)
        if ($userCounts['last_7d'] > 0) {
            $totalUserNotifications += $userCounts['last_7d'];
        }
        
        // Add weight for admins (more important)
        if ($userCounts['new_admins'] > 0) {
            $totalUserNotifications += ($userCounts['new_admins'] * 2); // Admin users count double
        }
        
        // Add RCY members
        if ($userCounts['new_rcy'] > 0) {
            $totalUserNotifications += $userCounts['new_rcy'];
        }
        
        $counts['user_activity'] = $totalUserNotifications;
        $counts['new_users'] = $totalUserNotifications; // Alias
        $counts['new_admins'] = (int)$userCounts['new_admins'];
        $counts['new_rcy_members'] = (int)$userCounts['new_rcy'];
        $counts['incomplete_profiles'] = (int)$userCounts['incomplete'];

        // Announcements
        $stmt = $pdo->query("
    SELECT COUNT(*) as count
    FROM announcements 
    WHERE status = 'draft'
");
$counts['announcements'] = (int)$stmt->fetchColumn();
$counts['announcement'] = $counts['announcements'];

    } catch (Exception $e) {
        error_log("Error getting sidebar notification counts: " . $e->getMessage());
        // Return empty counts on error
        $counts = array_fill_keys([
            'urgent_action', 'registration', 'upcoming', 'training_sessions', 
            'training_requests', 'requests', 'donation', 'blood_donation',
            'inventory', 'critical_stock', 'volunteers', 'volunteer_applications',
            'user_activity', 'new_users', 'announcements', 'announcement',
            'new_admins', 'new_rcy_members', 'incomplete_profiles'
        ], 0);
    }
    
    return $counts;
}

?>