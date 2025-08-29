<?php
// user/api.php - Combined API for notifications and messages
require_once __DIR__ . '/../config.php';
ensure_logged_in();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$pdo = $GLOBALS['pdo'];
$userId = current_user_id();

try {
    switch ($action) {
        case 'get_notifications':
            // Check if notifications table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'notifications'");
            if ($tableCheck->rowCount() == 0) {
                // Table doesn't exist, return empty array
                echo json_encode([
                    'success' => true,
                    'notifications' => []
                ]);
                break;
            }
            
            $stmt = $pdo->prepare("
                SELECT notification_id as id, title, message as content, created_at, is_read
                FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications
            ]);
            break;

        case 'get_messages':
            // Check if announcements table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'announcements'");
            if ($tableCheck->rowCount() == 0) {
                // Table doesn't exist, return empty array
                echo json_encode([
                    'success' => true,
                    'messages' => []
                ]);
                break;
            }
            
            // Use announcements as messages for now
            $stmt = $pdo->prepare("
                SELECT 
                    announcement_id as id,
                    title as subject,
                    content,
                    'PRC Admin' as sender_name,
                    posted_at as created_at,
                    0 as is_read
                FROM announcements 
                ORDER BY posted_at DESC 
                LIMIT 10
            ");
            $stmt->execute();
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
            break;

        case 'mark_as_read':
            $input = json_decode(file_get_contents('php://input'), true);
            $type = $input['type'];
            $id = $input['id'];
            
            if ($type === 'notification') {
                // Check if notifications table exists
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'notifications'");
                if ($tableCheck->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE notifications 
                        SET is_read = 1, read_at = NOW() 
                        WHERE notification_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$id, $userId]);
                }
            }
            
            echo json_encode([
                'success' => true
            ]);
            break;

        case 'get_counts':
            $notificationCount = 0;
            $messageCount = 0;
            
            // Check if notifications table exists before querying
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'notifications'");
            if ($tableCheck->rowCount() > 0) {
                $notifStmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM notifications 
                    WHERE user_id = ? AND is_read = 0
                ");
                $notifStmt->execute([$userId]);
                $notificationCount = $notifStmt->fetchColumn();
            }
            
            // Check if announcements table exists for messages count
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'announcements'");
            if ($tableCheck->rowCount() > 0) {
                // For now, messages count is 0 since we're using announcements as messages
                $messageCount = 0;
            }
            
            echo json_encode([
                'success' => true,
                'notifications' => $notificationCount,
                'messages' => $messageCount
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action'
            ]);
            break;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'debug' => [
            'action' => $action,
            'userId' => $userId,
            'file' => __FILE__
        ]
    ]);
}
?>