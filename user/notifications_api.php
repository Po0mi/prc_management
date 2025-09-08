<?php
// Notifications API - Place in /user/ directory
// File: /user/notifications_api.php

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    // Include your existing config
    require_once __DIR__ . '/../config.php';
    
    // Check if user is logged in using your existing function
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }

    // Use your existing PDO connection
    $pdo = $GLOBALS['pdo'];
    $user_id = $_SESSION['user_id'];
    $action = $_GET['action'] ?? 'check';

    // Set JSON header
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    // Handle different actions
    switch ($action) {
        case 'check':
            $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
            $sinceDate = $since > 0 ? date('Y-m-d H:i:s', $since / 1000) : date('Y-m-d H:i:s', strtotime('-1 hour'));
            
            $notifications = getNewNotifications($pdo, $user_id, $sinceDate);
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'count' => count($notifications),
                'timestamp' => date('c')
            ]);
            break;

        case 'stats':
            $stats = getNotificationStats($pdo, $user_id);
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;

        case 'mark_read':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                $result = markAsRead($pdo, $user_id, $input['notification_id'] ?? '');
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'Marked as read' : 'Failed to mark as read'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'POST required']);
            }
            break;

        case 'mark_all_read':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $result = markAllAsRead($pdo, $user_id);
                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'All marked as read' : 'Failed to mark all as read'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'POST required']);
            }
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
    }

} catch (Exception $e) {
    error_log("Notification API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function getNewNotifications($pdo, $user_id, $sinceDate) {
    $notifications = [];
    
    try {
        // Get user's read notifications
        $readNotifications = getUserReadNotifications($pdo, $user_id);
        
        // Check for new training sessions
        $stmt = $pdo->prepare("
            SELECT session_id, title, major_service, session_date, created_at
            FROM training_sessions 
            WHERE created_at > ? AND session_date >= CURDATE()
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmt->execute([$sinceDate]);
        $sessions = $stmt->fetchAll();

        foreach ($sessions as $session) {
            $notificationId = 'session_' . $session['session_id'];
            
            // Skip if already read
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'session',
                'title' => 'New Training Session',
                'message' => $session['title'] . ' - ' . date('M d, Y', strtotime($session['session_date'])),
                'icon' => 'fas fa-graduation-cap',
                'color' => '#28a745',
                'url' => 'training.php?highlight=' . $session['session_id'],
                'created_at' => $session['created_at']
            ];
        }

        // Check for new events
        $stmt = $pdo->prepare("
            SELECT event_id, title, major_service, event_date, created_at
            FROM events 
            WHERE created_at > ? AND event_date >= CURDATE()
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmt->execute([$sinceDate]);
        $events = $stmt->fetchAll();

        foreach ($events as $event) {
            $notificationId = 'event_' . $event['event_id'];
            
            // Skip if already read
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'event',
                'title' => 'New Event Available',
                'message' => $event['title'] . ' - ' . date('M d, Y', strtotime($event['event_date'])),
                'icon' => 'fas fa-calendar-plus',
                'color' => '#007bff',
                'url' => 'registration.php?highlight=' . $event['event_id'],
                'created_at' => $event['created_at']
            ];
        }

        // Check for new announcements
        $stmt = $pdo->prepare("
            SELECT announcement_id, title, content, posted_at
            FROM announcements 
            WHERE posted_at > ?
            ORDER BY posted_at DESC LIMIT 3
        ");
        $stmt->execute([$sinceDate]);
        $announcements = $stmt->fetchAll();

        foreach ($announcements as $announcement) {
            $notificationId = 'announcement_' . $announcement['announcement_id'];
            
            // Skip if already read
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'announcement',
                'title' => 'New Announcement',
                'message' => $announcement['title'],
                'icon' => 'fas fa-bullhorn',
                'color' => '#fd7e14',
                'url' => 'announcements.php?highlight=' . $announcement['announcement_id'],
                'created_at' => $announcement['posted_at']
            ];
        }

        // Try merchandise if table exists
        try {
            $stmt = $pdo->prepare("
                SELECT merch_id, name, category, price, created_at
                FROM merchandise 
                WHERE created_at > ? AND is_available = 1
                ORDER BY created_at DESC LIMIT 3
            ");
            $stmt->execute([$sinceDate]);
            $merchandise = $stmt->fetchAll();

            foreach ($merchandise as $merch) {
                $notificationId = 'merch_' . $merch['merch_id'];
                
                // Skip if already read
                if (in_array($notificationId, $readNotifications)) {
                    continue;
                }
                
                $notifications[] = [
                    'id' => $notificationId,
                    'type' => 'merchandise',
                    'title' => 'New Merchandise',
                    'message' => $merch['name'] . ' - ₱' . number_format($merch['price'], 2),
                    'icon' => 'fas fa-store',
                    'color' => '#6f42c1',
                    'url' => 'merch.php?highlight=' . $merch['merch_id'],
                    'created_at' => $merch['created_at']
                ];
            }
        } catch (PDOException $e) {
            // Merchandise table doesn't exist
        }

    } catch (PDOException $e) {
        error_log("Error getting notifications: " . $e->getMessage());
    }

    // Sort by creation time
    usort($notifications, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    return $notifications;
}

function getNotificationStats($pdo, $user_id) {
    try {
        $stats = [
            'total_events' => 0,
            'total_sessions' => 0,
            'total_announcements' => 0,
            'total_merchandise' => 0
        ];

        // Count events
        $stmt = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()");
        $stats['total_events'] = $stmt->fetchColumn();

        // Count training sessions
        $stmt = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()");
        $stats['total_sessions'] = $stmt->fetchColumn();

        // Count announcements
        $stmt = $pdo->query("SELECT COUNT(*) FROM announcements WHERE posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stats['total_announcements'] = $stmt->fetchColumn();

        // Count merchandise
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM merchandise WHERE is_available = 1");
            $stats['total_merchandise'] = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $stats['total_merchandise'] = 0;
        }

        return $stats;

    } catch (PDOException $e) {
        error_log("Error getting stats: " . $e->getMessage());
        return ['error' => 'Failed to get stats'];
    }
}

function createNotificationReadTable($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS notification_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            notification_id VARCHAR(100) NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_notification (user_id, notification_id),
            INDEX idx_user_id (user_id),
            INDEX idx_read_at (read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating notification_reads table: " . $e->getMessage());
        return false;
    }
}

function getUserReadNotifications($pdo, $user_id) {
    try {
        // Create table if it doesn't exist
        createNotificationReadTable($pdo);
        
        $stmt = $pdo->prepare("
            SELECT notification_id 
            FROM notification_reads 
            WHERE user_id = ? AND read_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$user_id]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Error getting read notifications: " . $e->getMessage());
        return [];
    }
}

function markAsRead($pdo, $user_id, $notification_id) {
    if (empty($notification_id)) {
        return false;
    }
    
    try {
        // Handle registration notifications differently
        if (strpos($notification_id, 'registration_') === 0) {
            $realId = str_replace('registration_', '', $notification_id);
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            return $stmt->execute([$realId, $user_id]);
        }
        
        // Your existing code for other notification types
        createNotificationReadTable($pdo);
        
        $stmt = $pdo->prepare("
            INSERT INTO notification_reads (user_id, notification_id, read_at) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ");
        
        $result = $stmt->execute([$user_id, $notification_id]);
        
        if ($result) {
            error_log("Marked notification as read: $notification_id for user $user_id");
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

function markAllAsRead($pdo, $user_id) {
    try {
        // Create table if it doesn't exist
        createNotificationReadTable($pdo);
        
        // Get all current notifications
        $sinceDate = date('Y-m-d H:i:s', strtotime('-7 days')); // Get notifications from last 7 days
        $allNotifications = [];
        
        // Get all possible notification IDs
        $tables = [
            'training_sessions' => ['session_id', 'session_', 'created_at', 'session_date >= CURDATE()'],
            'events' => ['event_id', 'event_', 'created_at', 'event_date >= CURDATE()'],
            'announcements' => ['announcement_id', 'announcement_', 'posted_at', '1=1']
        ];
        
        foreach ($tables as $table => $config) {
            try {
                $stmt = $pdo->prepare("
                    SELECT {$config[0]} 
                    FROM {$table} 
                    WHERE {$config[2]} > ? AND {$config[3]}
                ");
                $stmt->execute([$sinceDate]);
                $results = $stmt->fetchAll();
                
                foreach ($results as $row) {
                    $allNotifications[] = $config[1] . $row[$config[0]];
                }
            } catch (PDOException $e) {
                // Table might not exist, continue
                continue;
            }
        }
        
        // Try merchandise
        try {
            $stmt = $pdo->prepare("
                SELECT merch_id 
                FROM merchandise 
                WHERE created_at > ? AND is_available = 1
            ");
            $stmt->execute([$sinceDate]);
            $merchandise = $stmt->fetchAll();
            
            foreach ($merchandise as $merch) {
                $allNotifications[] = 'merch_' . $merch['merch_id'];
            }
        } catch (PDOException $e) {
            // Merchandise table doesn't exist
        }
        
        // Mark all as read
        $success = true;
        foreach ($allNotifications as $notificationId) {
            $stmt = $pdo->prepare("
                INSERT INTO notification_reads (user_id, notification_id, read_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE read_at = NOW()
            ");
            
            if (!$stmt->execute([$user_id, $notificationId])) {
                $success = false;
            }
        }
        
        if ($success) {
            error_log("Marked all notifications as read for user $user_id (" . count($allNotifications) . " notifications)");
        }
        
        return $success;
        
    } catch (PDOException $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}
// Add this section to your existing getNewNotifications function
// Check for registration status notifications
try {
    $stmt = $pdo->prepare("
        SELECT id, type, title, message, icon, url, created_at
        FROM notifications 
        WHERE user_id = ? AND is_read = 0 AND created_at > ?
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$user_id, $sinceDate]);
    $regNotifications = $stmt->fetchAll();

    foreach ($regNotifications as $regNotif) {
        $notificationId = 'registration_' . $regNotif['id'];
        
        // Skip if already read
        if (in_array($notificationId, $readNotifications)) {
            continue;
        }
        
        $notifications[] = [
            'id' => $notificationId,
            'type' => $regNotif['type'],
            'title' => $regNotif['title'],
            'message' => $regNotif['message'],
            'icon' => $regNotif['icon'],
            'color' => $regNotif['type'] === 'success' ? '#28a745' : ($regNotif['type'] === 'warning' ? '#ffc107' : '#007bff'),
            'url' => $regNotif['url'],
            'created_at' => $regNotif['created_at']
        ];
    }
} catch (PDOException $e) {
    error_log("Error getting registration notifications: " . $e->getMessage());
}
// Clean up old notification reads (run occasionally)
function cleanupOldReads($pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM notification_reads WHERE read_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error cleaning up old reads: " . $e->getMessage());
        return false;
    }
}

// Run cleanup 5% of the time
if (rand(1, 100) <= 5) {
    cleanupOldReads($pdo);
}
?>