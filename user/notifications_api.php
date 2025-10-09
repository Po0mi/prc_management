<?php
// User Notifications API
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Only execute API logic if called directly with an action parameter
if (isset($_GET['action']) || isset($_POST['action'])) {
    try {
        require_once __DIR__ . '/../config.php';
        
        if (!isset($_SESSION['user_id'])) {
            throw new Exception('User not logged in');
        }

        $pdo = $GLOBALS['pdo'];
        $user_id = $_SESSION['user_id'];
        $action = $_GET['action'] ?? $_POST['action'] ?? 'check';

        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');

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

            case 'mark_read':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $input = json_decode(file_get_contents('php://input'), true);
                    $result = markAsRead($pdo, $user_id, $input['notification_id'] ?? '');
                    echo json_encode([
                        'success' => $result,
                        'message' => $result ? 'Marked as read' : 'Failed'
                    ]);
                }
                break;

            case 'mark_all_read':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $result = markAllAsRead($pdo, $user_id);
                    echo json_encode([
                        'success' => $result,
                        'message' => $result ? 'All marked as read' : 'Failed'
                    ]);
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }

    } catch (Exception $e) {
        error_log("Notification API Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
    exit();
}

// If we get here, file is being included - just load config and define functions
require_once __DIR__ . '/../config.php';

// ============================================
// FUNCTION DEFINITIONS
// ============================================

function getNewNotifications($pdo, $user_id, $sinceDate) {
    $notifications = [];
    
    try {
        $readNotifications = getUserReadNotifications($pdo, $user_id);
        
        // Get status notifications from the notifications table
        $stmt = $pdo->prepare("
            SELECT id, type, title, message, icon, url, created_at
            FROM notifications 
            WHERE user_id = ? AND is_read = 0 AND created_at > ?
            ORDER BY created_at DESC LIMIT 10
        ");
        $stmt->execute([$user_id, $sinceDate]);
        $statusNotifs = $stmt->fetchAll();

        foreach ($statusNotifs as $notif) {
            $notificationId = 'status_' . $notif['id'];
            
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => $notif['type'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'icon' => $notif['icon'],
                'url' => $notif['url'],
                'created_at' => $notif['created_at']
            ];
        }
        
        // New training sessions
        $stmt = $pdo->prepare("
            SELECT session_id, title, session_date, created_at
            FROM training_sessions 
            WHERE created_at > ? AND session_date >= CURDATE()
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmt->execute([$sinceDate]);
        $sessions = $stmt->fetchAll();

        foreach ($sessions as $session) {
            $notificationId = 'session_' . $session['session_id'];
            
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'info',
                'title' => 'New Training Session',
                'message' => $session['title'] . ' - ' . date('M d, Y', strtotime($session['session_date'])),
                'icon' => 'fas fa-graduation-cap',
                'url' => 'training.php?highlight=' . $session['session_id'],
                'created_at' => $session['created_at']
            ];
        }

        // New events
        $stmt = $pdo->prepare("
            SELECT event_id, title, event_date, created_at
            FROM events 
            WHERE created_at > ? AND event_date >= CURDATE()
            ORDER BY created_at DESC LIMIT 5
        ");
        $stmt->execute([$sinceDate]);
        $events = $stmt->fetchAll();

        foreach ($events as $event) {
            $notificationId = 'event_' . $event['event_id'];
            
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'info',
                'title' => 'New Event Available',
                'message' => $event['title'] . ' - ' . date('M d, Y', strtotime($event['event_date'])),
                'icon' => 'fas fa-calendar-plus',
                'url' => 'registration.php?highlight=' . $event['event_id'],
                'created_at' => $event['created_at']
            ];
        }

        // New announcements
        $stmt = $pdo->prepare("
            SELECT announcement_id, title, posted_at
            FROM announcements 
            WHERE posted_at > ?
            ORDER BY posted_at DESC LIMIT 3
        ");
        $stmt->execute([$sinceDate]);
        $announcements = $stmt->fetchAll();

        foreach ($announcements as $announcement) {
            $notificationId = 'announcement_' . $announcement['announcement_id'];
            
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $notifications[] = [
                'id' => $notificationId,
                'type' => 'info',
                'title' => 'New Announcement',
                'message' => $announcement['title'],
                'icon' => 'fas fa-bullhorn',
                'url' => 'announcements.php?highlight=' . $announcement['announcement_id'],
                'created_at' => $announcement['posted_at']
            ];
        }

        // Donation status updates - Monetary donations
        $stmt = $pdo->prepare("
            SELECT d.donation_id, d.status, d.amount, d.approved_date, d.donation_date,
                   donor.name as donor_name
            FROM donations d
            JOIN donors donor ON d.donor_id = donor.donor_id
            WHERE donor.user_id = ? 
            AND d.status IN ('approved', 'declined')
            AND d.approved_date > ?
            ORDER BY d.approved_date DESC LIMIT 5
        ");
        $stmt->execute([$user_id, $sinceDate]);
        $monetaryDonations = $stmt->fetchAll();

        foreach ($monetaryDonations as $donation) {
            $notificationId = 'donation_monetary_' . $donation['donation_id'];
            
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $isApproved = $donation['status'] === 'approved';
            $notifications[] = [
                'id' => $notificationId,
                'type' => $isApproved ? 'success' : 'error',
                'title' => $isApproved ? 'Donation Approved ✓' : 'Donation Status Update',
                'message' => $isApproved 
                    ? "Your monetary donation of ₱" . number_format($donation['amount'], 2) . " has been approved!"
                    : "Your monetary donation of ₱" . number_format($donation['amount'], 2) . " could not be approved.",
                'icon' => $isApproved ? 'fas fa-check-circle' : 'fas fa-times-circle',
                'url' => 'donations.php',
                'created_at' => $donation['approved_date']
            ];
        }

        // Donation status updates - In-kind donations
        $stmt = $pdo->prepare("
            SELECT d.donation_id, d.status, d.item_description, d.quantity, 
                   d.estimated_value, d.approved_date, d.donation_date,
                   donor.name as donor_name
            FROM in_kind_donations d
            JOIN donors donor ON d.donor_id = donor.donor_id
            WHERE donor.user_id = ? 
            AND d.status IN ('approved', 'declined')
            AND d.approved_date > ?
            ORDER BY d.approved_date DESC LIMIT 5
        ");
        $stmt->execute([$user_id, $sinceDate]);
        $inkindDonations = $stmt->fetchAll();

        foreach ($inkindDonations as $donation) {
            $notificationId = 'donation_inkind_' . $donation['donation_id'];
            
            if (in_array($notificationId, $readNotifications)) {
                continue;
            }
            
            $isApproved = $donation['status'] === 'approved';
            $notifications[] = [
                'id' => $notificationId,
                'type' => $isApproved ? 'success' : 'error',
                'title' => $isApproved ? 'In-Kind Donation Approved ✓' : 'In-Kind Donation Status Update',
                'message' => $isApproved 
                    ? "Your donation of {$donation['item_description']} has been approved!"
                    : "Your donation of {$donation['item_description']} could not be approved.",
                'icon' => $isApproved ? 'fas fa-check-circle' : 'fas fa-times-circle',
                'url' => 'donations.php',
                'created_at' => $donation['approved_date']
            ];
        }

    } catch (PDOException $e) {
        error_log("Error getting notifications: " . $e->getMessage());
    }

    usort($notifications, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    return $notifications;
}

function createNotificationReadTable($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS notification_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            notification_id VARCHAR(100) NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_notification (user_id, notification_id),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating notification_reads table: " . $e->getMessage());
        return false;
    }
}

function getUserReadNotifications($pdo, $user_id) {
    try {
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
        // Handle status notifications from notifications table
        if (strpos($notification_id, 'status_') === 0) {
            $realId = str_replace('status_', '', $notification_id);
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            return $stmt->execute([$realId, $user_id]);
        }
        
        // Handle other notification types
        createNotificationReadTable($pdo);
        
        $stmt = $pdo->prepare("
            INSERT INTO notification_reads (user_id, notification_id, read_at) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ");
        
        return $stmt->execute([$user_id, $notification_id]);
    } catch (PDOException $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

function markAllAsRead($pdo, $user_id) {
    try {
        createNotificationReadTable($pdo);
        
        // Mark all notifications table items as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        
        // Get all current notification IDs
        $sinceDate = date('Y-m-d H:i:s', strtotime('-7 days'));
        $allNotifications = [];
        
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
                continue;
            }
        }
        
        // Add donation notifications to mark as read
        try {
            // Monetary donations
            $stmt = $pdo->prepare("
                SELECT d.donation_id 
                FROM donations d
                JOIN donors donor ON d.donor_id = donor.donor_id
                WHERE donor.user_id = ? 
                AND d.status IN ('approved', 'declined')
                AND d.approved_date > ?
            ");
            $stmt->execute([$user_id, $sinceDate]);
            $results = $stmt->fetchAll();
            foreach ($results as $row) {
                $allNotifications[] = 'donation_monetary_' . $row['donation_id'];
            }
            
            // In-kind donations
            $stmt = $pdo->prepare("
                SELECT d.donation_id 
                FROM in_kind_donations d
                JOIN donors donor ON d.donor_id = donor.donor_id
                WHERE donor.user_id = ? 
                AND d.status IN ('approved', 'declined')
                AND d.approved_date > ?
            ");
            $stmt->execute([$user_id, $sinceDate]);
            $results = $stmt->fetchAll();
            foreach ($results as $row) {
                $allNotifications[] = 'donation_inkind_' . $row['donation_id'];
            }
        } catch (PDOException $e) {
            error_log("Error getting donation notifications: " . $e->getMessage());
        }
        
        // Mark all as read
        foreach ($allNotifications as $notificationId) {
            $stmt = $pdo->prepare("
                INSERT INTO notification_reads (user_id, notification_id, read_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE read_at = NOW()
            ");
            $stmt->execute([$user_id, $notificationId]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}

// ============================================
// NOTIFICATION HELPER FUNCTIONS
// ============================================

/**
 * Create a notification for a user
 */
function createNotification($pdo, $user_id, $type, $title, $message, $url = null, $icon = null) {
    // Create notifications table if it doesn't exist
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                url VARCHAR(500) DEFAULT NULL,
                icon VARCHAR(100) DEFAULT 'fas fa-bell',
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_notifications (user_id, is_read),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log("Error creating notifications table: " . $e->getMessage());
    }
    
    if ($icon === null) {
        $icon = $type === 'success' ? 'fas fa-check-circle' : 
                ($type === 'error' ? 'fas fa-times-circle' : 'fas fa-bell');
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, url, icon, created_at, is_read) 
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
    ");
    
    return $stmt->execute([$user_id, $type, $title, $message, $url, $icon]);
}

/**
 * Notify user about training request status change
 */
function notifyTrainingRequest($pdo, $request_id, $new_status) {
    $stmt = $pdo->prepare("
        SELECT user_id, training_program, service_type
        FROM training_requests WHERE request_id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch();
    
    if (!$request) return false;
    
    if ($new_status === 'approved') {
        $title = "Training Request Approved ✓";
        $message = "Your {$request['training_program']} training request has been approved!";
        $type = "success";
    } elseif ($new_status === 'rejected') {
        $title = "Training Request Update";
        $message = "Your {$request['training_program']} training request could not be approved at this time.";
        $type = "error";
    } elseif ($new_status === 'scheduled') {
        $title = "Training Scheduled ✓";
        $message = "Your {$request['training_program']} training has been scheduled!";
        $type = "success";
    } else {
        return false;
    }
    
    $url = "training_requests.php";
    return createNotification($pdo, $request['user_id'], $type, $title, $message, $url);
}

/**
 * Notify user about session registration status change
 */
function notifySessionRegistration($pdo, $registration_id, $new_status) {
    $stmt = $pdo->prepare("
        SELECT user_id, training_type FROM session_registrations WHERE registration_id = ?
    ");
    $stmt->execute([$registration_id]);
    $reg = $stmt->fetch();
    
    if (!$reg) return false;
    
    if ($new_status === 'approved') {
        $title = "Training Registration Approved ✓";
        $message = "Your registration for {$reg['training_type']} has been approved!";
        $type = "success";
    } elseif ($new_status === 'rejected') {
        $title = "Registration Update";
        $message = "Your registration for {$reg['training_type']} could not be approved.";
        $type = "error";
    } else {
        return false;
    }
    
    $url = "training.php";
    return createNotification($pdo, $reg['user_id'], $type, $title, $message, $url);
}

/**
 * Notify user about event registration status change
 */
function notifyEventRegistration($pdo, $registration_id, $new_status) {
    $stmt = $pdo->prepare("
        SELECT r.user_id, e.title 
        FROM registrations r
        JOIN events e ON r.event_id = e.event_id
        WHERE r.registration_id = ?
    ");
    $stmt->execute([$registration_id]);
    $reg = $stmt->fetch();
    
    if (!$reg) return false;
    
    if ($new_status === 'approved') {
        $title = "Event Registration Approved ✓";
        $message = "Your registration for {$reg['title']} has been approved!";
        $type = "success";
    } elseif ($new_status === 'rejected') {
        $title = "Registration Update";
        $message = "Your registration for {$reg['title']} could not be approved.";
        $type = "error";
    } else {
        return false;
    }
    
    $url = "registration.php";
    return createNotification($pdo, $reg['user_id'], $type, $title, $message, $url);
}

/**
 * Notify user about donation status change
 */
function notifyDonationStatus($pdo, $donation_id, $donation_type, $new_status) {
    try {
        $table = $donation_type === 'monetary' ? 'donations' : 'in_kind_donations';
        
        // Get donation and donor info
        $stmt = $pdo->prepare("
            SELECT d.*, donor.user_id, donor.name as donor_name
            FROM {$table} d
            JOIN donors donor ON d.donor_id = donor.donor_id
            WHERE d.donation_id = ?
        ");
        $stmt->execute([$donation_id]);
        $donation = $stmt->fetch();
        
        if (!$donation || !$donation['user_id']) {
            return false;
        }
        
        $isApproved = $new_status === 'approved';
        
        if ($donation_type === 'monetary') {
            $title = $isApproved ? "Donation Approved ✓" : "Donation Status Update";
            $message = $isApproved 
                ? "Your monetary donation of ₱" . number_format($donation['amount'], 2) . " has been approved! Thank you for your generosity."
                : "Your monetary donation of ₱" . number_format($donation['amount'], 2) . " could not be approved at this time.";
        } else {
            $title = $isApproved ? "In-Kind Donation Approved ✓" : "In-Kind Donation Status Update";
            $message = $isApproved 
                ? "Your donation of {$donation['item_description']} has been approved! Thank you for your contribution."
                : "Your donation of {$donation['item_description']} could not be approved at this time.";
        }
        
        $type = $isApproved ? 'success' : 'error';
        $icon = $isApproved ? 'fas fa-check-circle' : 'fas fa-info-circle';
        $url = 'donations.php';
        
        return createNotification($pdo, $donation['user_id'], $type, $title, $message, $url, $icon);
        
    } catch (PDOException $e) {
        error_log("Error notifying donation status: " . $e->getMessage());
        return false;
    }
}
?>