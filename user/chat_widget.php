<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

$pdo = $GLOBALS['pdo'];
$current_user_id = current_user_id();
$current_user_role = get_user_role();
$is_admin = ($current_user_role && $current_user_role !== 'user');

function logError($message) {
    error_log("[CHAT_WIDGET] " . $message);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_message':
            $receiver_id = (int)$_POST['receiver_id'];
            $message = trim($_POST['message']);
            
            if (!empty($message) && $receiver_id > 0) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
                    $result = $stmt->execute([$current_user_id, $receiver_id, $message]);
                    
                    if ($result) {
                        echo json_encode(['success' => true]);
                    } else {
                        logError("Failed to insert message: " . print_r($stmt->errorInfo(), true));
                        echo json_encode(['success' => false, 'error' => 'Database error']);
                    }
                } catch (Exception $e) {
                    logError("Message insert exception: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid message']);
            }
            exit;

        case 'upload_file':
            $receiver_id = (int)$_POST['receiver_id'];
            $file_message = trim($_POST['file_message'] ?? '');
            
            if (!$receiver_id || !isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid upload request']);
                exit;
            }

            $file = $_FILES['file'];
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'File too large (exceeds php.ini limit)',
                    UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds form limit)',
                    UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                ];
                $errorMessage = $errorMessages[$file['error']] ?? 'Unknown upload error';
                echo json_encode(['success' => false, 'error' => $errorMessage]);
                exit;
            }

            if ($file['size'] > 10 * 1024 * 1024) {
                echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
                exit;
            }

            $allowedTypes = [
                'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff',
                'application/pdf', 
                'application/msword', 
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain', 'text/csv', 'text/rtf',
                'application/zip', 'application/x-zip-compressed',
                'application/octet-stream'
            ];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!$mimeType || $mimeType === 'application/octet-stream') {
                $mimeType = $_FILES['file']['type'] ?? 'application/octet-stream';
            }
            
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff',
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'txt', 'csv', 'rtf', 'zip'
            ];
            
            $mimeTypeAllowed = in_array($mimeType, $allowedTypes);
            $extensionAllowed = in_array($fileExtension, $allowedExtensions);
            
            if (!$mimeTypeAllowed && !$extensionAllowed) {
                echo json_encode(['success' => false, 'error' => "File type not allowed"]);
                exit;
            }

            $uploadDir = __DIR__ . '/../uploads/chat_files/';
            
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
                    exit;
                }
            }

            if (!is_writable($uploadDir)) {
                echo json_encode(['success' => false, 'error' => 'Upload directory not writable']);
                exit;
            }

            $uniqueFilename = uniqid() . '_' . time() . '.' . strtolower($fileExtension);
            $filePath = $uploadDir . $uniqueFilename;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $message = $file_message ?: 'Shared a file: ' . $file['name'];

                try {
                    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, file_path, file_name, file_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $result = $stmt->execute([
                        $current_user_id, 
                        $receiver_id, 
                        $message,
                        $uniqueFilename,
                        $file['name'],
                        $mimeType,
                        $file['size']
                    ]);
                    
                    if ($result) {
                        echo json_encode(['success' => true]);
                    } else {
                        unlink($filePath);
                        echo json_encode(['success' => false, 'error' => 'Database error']);
                    }
                } catch (Exception $e) {
                    unlink($filePath);
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save file']);
            }
            exit;
            
        case 'get_messages':
            $other_user_id = (int)$_POST['other_user_id'];
            $last_message_id = (int)($_POST['last_message_id'] ?? 0);
            
            try {
                $stmt = $pdo->prepare("
                    SELECT m.*, u.full_name, u.username 
                    FROM messages m 
                    JOIN users u ON m.sender_id = u.user_id 
                    WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                    AND m.message_id > ?
                    ORDER BY m.created_at ASC
                ");
                $stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id, $last_message_id]);
                $messages = $stmt->fetchAll();
                
                $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0")
                    ->execute([$other_user_id, $current_user_id]);
                
                echo json_encode(['messages' => $messages]);
            } catch (Exception $e) {
                logError("Get messages exception: " . $e->getMessage());
                echo json_encode(['messages' => []]);
            }
            exit;
            
        case 'get_contacts':
            $search = trim($_POST['search'] ?? '');
            $searchParam = '%' . $search . '%';
            
            try {
                if ($is_admin) {
                    if ($search) {
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT u.user_id, u.full_name, u.username, u.user_type, u.role,
                                   (SELECT COUNT(*) FROM messages WHERE sender_id = u.user_id AND receiver_id = ? AND is_read = 0) as unread_count,
                                   (SELECT MAX(created_at) FROM messages 
                                    WHERE (sender_id = ? AND receiver_id = u.user_id) 
                                    OR (sender_id = u.user_id AND receiver_id = ?)) as last_message_time
                            FROM users u 
                            WHERE u.user_id != ? AND (u.full_name LIKE ? OR u.username LIKE ? OR u.role LIKE ?)
                            ORDER BY last_message_time DESC, u.full_name ASC
                        ");
                        $stmt->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id, $searchParam, $searchParam, $searchParam]);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT u.user_id, u.full_name, u.username, u.user_type, u.role,
                                   (SELECT COUNT(*) FROM messages WHERE sender_id = u.user_id AND receiver_id = ? AND is_read = 0) as unread_count,
                                   (SELECT MAX(created_at) FROM messages 
                                    WHERE (sender_id = ? AND receiver_id = u.user_id) 
                                    OR (sender_id = u.user_id AND receiver_id = ?)) as last_message_time
                            FROM users u 
                            WHERE u.user_id != ?
                            ORDER BY last_message_time DESC, u.full_name ASC
                        ");
                        $stmt->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id]);
                    }
                } else {
                    if ($search) {
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT u.user_id, u.full_name, u.username, u.user_type, u.role,
                                   (SELECT COUNT(*) FROM messages WHERE sender_id = u.user_id AND receiver_id = ? AND is_read = 0) as unread_count,
                                   (SELECT MAX(created_at) FROM messages 
                                    WHERE (sender_id = ? AND receiver_id = u.user_id) 
                                    OR (sender_id = u.user_id AND receiver_id = ?)) as last_message_time
                            FROM users u 
                            WHERE u.user_id != ? AND (u.role != 'user' OR u.is_admin = 1) 
                            AND (u.full_name LIKE ? OR u.username LIKE ? OR u.role LIKE ?)
                            ORDER BY last_message_time DESC, u.full_name ASC
                        ");
                        $stmt->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id, $searchParam, $searchParam, $searchParam]);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT u.user_id, u.full_name, u.username, u.user_type, u.role,
                                   (SELECT COUNT(*) FROM messages WHERE sender_id = u.user_id AND receiver_id = ? AND is_read = 0) as unread_count,
                                   (SELECT MAX(created_at) FROM messages 
                                    WHERE (sender_id = ? AND receiver_id = u.user_id) 
                                    OR (sender_id = u.user_id AND receiver_id = ?)) as last_message_time
                            FROM users u 
                            WHERE u.user_id != ? AND (u.role != 'user' OR u.is_admin = 1)
                            ORDER BY last_message_time DESC, u.full_name ASC
                        ");
                        $stmt->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id]);
                    }
                }
                $contacts = $stmt->fetchAll();
                echo json_encode(['contacts' => $contacts]);
            } catch (Exception $e) {
                logError("Get contacts exception: " . $e->getMessage());
                echo json_encode(['contacts' => []]);
            }
            exit;

        case 'get_unread_count':
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0");
                $stmt->execute([$current_user_id]);
                $result = $stmt->fetch();
                echo json_encode(['count' => (int)$result['count']]);
            } catch (Exception $e) {
                logError("Get unread count exception: " . $e->getMessage());
                echo json_encode(['count' => 0]);
            }
            exit;
    }
}

if (isset($_GET['download']) && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filePath = __DIR__ . '/../uploads/chat_files/' . $filename;
    
    if (file_exists($filePath)) {
        $stmt = $pdo->prepare("SELECT file_name, file_type FROM messages WHERE file_path = ? LIMIT 1");
        $stmt->execute([$filename]);
        $fileInfo = $stmt->fetch();
        
        if ($fileInfo) {
            header('Content-Type: ' . $fileInfo['file_type']);
            header('Content-Disposition: attachment; filename="' . $fileInfo['file_name'] . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    
    http_response_code(404);
    exit('File not found');
}

if (isset($_GET['image']) && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filePath = __DIR__ . '/../uploads/chat_files/' . $filename;
    
    if (file_exists($filePath)) {
        $stmt = $pdo->prepare("SELECT file_name, file_type FROM messages WHERE file_path = ? LIMIT 1");
        $stmt->execute([$filename]);
        $fileInfo = $stmt->fetch();
        
        if ($fileInfo && strpos($fileInfo['file_type'], 'image/') === 0) {
            header('Content-Type: ' . $fileInfo['file_type']);
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=3600');
            readfile($filePath);
            exit;
        }
    }
    
    header('Content-Type: image/svg+xml');
    echo '<svg width="200" height="150" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#f0f0f0"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" font-family="Arial" font-size="14" fill="#666">Image not found</text></svg>';
    exit;
}
?>

<div id="chatWidget" class="chat-widget">
    <div id="chatButton" class="chat-button">
        <i class="fas fa-comments"></i>
        <span id="unreadBadge" class="unread-badge" style="display: none;">0</span>
    </div>

    <div id="chatWindow" class="chat-window">
        <div class="chat-header">
            <div class="chat-title">
                <i class="fas fa-comments"></i>
                <span>Messages</span>
            </div>
            <button id="closeChat" class="close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="chat-body">
            <div id="contactsView" class="contacts-view">
                <div class="contacts-header">
                    <h4>Contacts</h4>
                    <button id="refreshContacts" class="refresh-btn">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                
                <div class="search-container">
                    <div class="search-input-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="contactSearch" placeholder="Search contacts..." class="search-input">
                        <button id="clearSearch" class="clear-search-btn" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="contacts-list" id="contactsList"></div>
            </div>

            <div id="chatView" class="chat-view" style="display: none;">
                <div class="chat-view-header">
                    <button id="backToContacts" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <div class="chat-user-info">
                        <div class="chat-avatar" id="chatAvatar">U</div>
                        <div class="chat-user-name" id="chatUserName">User</div>
                    </div>
                </div>

                <div class="messages-area" id="messagesArea"></div>

                <div class="message-input-area">
                    <div class="input-wrapper">
                        <button id="attachFileBtn" class="attach-btn" title="Attach file">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <textarea id="messageInput" placeholder="Type a message..." rows="1"></textarea>
                        <button id="sendMessage" class="send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                    
                    <div id="filePreview" class="file-preview" style="display: none;">
                        <div class="file-info">
                            <i class="fas fa-file file-icon"></i>
                            <span class="file-name"></span>
                            <span class="file-size"></span>
                            <button id="removeFile" class="remove-file-btn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <input type="text" id="fileMessage" placeholder="Add a message with this file...">
                    </div>
                </div>

                <input type="file" id="fileInput" style="display: none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
            </div>
        </div>
    </div>
</div>

<style>
.chat-widget {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.chat-button {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(52, 152, 219, 0.4);
    transition: all 0.3s ease;
    position: relative;
    border: none;
}

.chat-button:hover {
    background: linear-gradient(135deg, #2980b9 0%, #1f4e79 100%);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(52, 152, 219, 0.5);
}

.chat-button i {
    font-size: 24px;
}

.unread-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #e74c3c;
    color: white;
    border-radius: 50%;
    min-width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: bold;
    border: 2px solid white;
    padding: 0 4px;
    box-shadow: 0 2px 8px rgba(231, 76, 60, 0.3);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
}

.chat-window {
    position: absolute;
    bottom: 80px;
    right: 0;
    width: 360px;
    height: 520px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    display: none;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.chat-header {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    color: white;
    padding: 18px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 16px;
}

.close-btn {
    background: none;
    border: none;
    color: white;
    padding: 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
    opacity: 0.9;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    opacity: 1;
}

.chat-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.contacts-view {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.contacts-header {
    padding: 18px 20px;
    border-bottom: 1px solid #ecf0f1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
}

.contacts-header h4 {
    margin: 0;
    color: #2c3e50;
    font-size: 16px;
}

.refresh-btn {
    background: none;
    border: none;
    color: #7f8c8d;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s;
}

.refresh-btn:hover {
    color: #3498db;
    background: rgba(52, 152, 219, 0.1);
}

.search-container {
    padding: 15px 20px;
    border-bottom: 1px solid #ecf0f1;
    background: white;
}

.search-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.search-icon {
    position: absolute;
    left: 12px;
    color: #7f8c8d;
    font-size: 14px;
    z-index: 1;
}

.search-input {
    width: 100%;
    border: 2px solid #ecf0f1;
    border-radius: 20px;
    padding: 10px 40px 10px 35px;
    outline: none;
    font-size: 14px;
    font-family: inherit;
    transition: all 0.2s ease;
    background: #f8f9fa;
}

.search-input:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    background: white;
}

.search-input::placeholder {
    color: #95a5a6;
}

.clear-search-btn {
    position: absolute;
    right: 8px;
    background: none;
    border: none;
    color: #7f8c8d;
    cursor: pointer;
    padding: 6px;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.clear-search-btn:hover {
    color: #e74c3c;
    background: rgba(231, 76, 60, 0.1);
}

.contacts-list {
    flex: 1;
    overflow-y: auto;
}

.contact-item {
    padding: 15px 20px;
    border-bottom: 1px solid #f1f2f3;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 12px;
}

.contact-item:hover {
    background: #f8f9fa;
}

.contact-item.has-messages {
    background: #f8fbff;
    border-left: 3px solid #3498db;
}

.contact-avatar {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 16px;
    flex-shrink: 0;
}

.contact-info {
    flex: 1;
    min-width: 0;
}

.contact-name {
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 4px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.recent-indicator {
    font-size: 12px;
    opacity: 0.7;
}

.contact-role {
    font-size: 12px;
    color: #7f8c8d;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.contact-unread {
    background: #e74c3c;
    color: white;
    border-radius: 50%;
    min-width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: bold;
    padding: 0 4px;
}

.no-contacts-found {
    padding: 40px 20px;
    text-align: center;
    color: #7f8c8d;
    font-style: italic;
}

.search-results-info {
    padding: 10px 20px;
    background: #f8f9fa;
    border-bottom: 1px solid #ecf0f1;
    font-size: 12px;
    color: #7f8c8d;
    text-align: center;
}

.chat-view {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.chat-view-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ecf0f1;
    display: flex;
    align-items: center;
    gap: 15px;
    background: #f8f9fa;
}

.back-btn {
    background: none;
    border: none;
    color: #7f8c8d;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s;
}

.back-btn:hover {
    color: #3498db;
    background: rgba(52, 152, 219, 0.1);
}

.chat-user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.chat-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 16px;
}

.chat-user-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 15px;
}

.messages-area {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    min-height: 0;
}

.message {
    margin-bottom: 16px;
    display: flex;
    gap: 10px;
}

.message.sent {
    flex-direction: row-reverse;
}

.message-avatar {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}

.message.sent .message-avatar {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
}

.message-content {
    max-width: 75%;
    min-width: 0;
}

.message-bubble {
    background: white;
    padding: 12px 16px;
    border-radius: 18px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    word-wrap: break-word;
    font-size: 14px;
    line-height: 1.5;
}

.message.sent .message-bubble {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    color: white;
}

.file-attachment {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: rgba(52, 152, 219, 0.1);
    border-radius: 12px;
    margin-top: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.file-attachment:hover {
    background: rgba(52, 152, 219, 0.15);
}

.file-attachment-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
}

.file-attachment-info {
    flex: 1;
    min-width: 0;
}

.file-attachment-name {
    font-weight: 600;
    margin-bottom: 4px;
    color: #2c3e50;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-attachment-size {
    font-size: 12px;
    color: #7f8c8d;
}

.image-attachment {
    max-width: 250px;
    margin-top: 8px;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.2s;
}

.image-attachment:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.image-attachment img {
    width: 100%;
    height: auto;
    display: block;
}

.message-time {
    font-size: 11px;
    color: #95a5a6;
    margin-top: 6px;
    text-align: center;
}

.message-input-area {
    border-top: 1px solid #ecf0f1;
    background: white;
    padding: 20px;
}

.input-wrapper {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}

.attach-btn {
    background: none;
    border: none;
    color: #7f8c8d;
    cursor: pointer;
    padding: 12px;
    border-radius: 50%;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
}

.attach-btn:hover {
    color: #3498db;
    background: rgba(52, 152, 219, 0.1);
}

#messageInput {
    flex: 1;
    border: 2px solid #ecf0f1;
    border-radius: 22px;
    padding: 12px 18px;
    outline: none;
    font-size: 14px;
    font-family: inherit;
    resize: none;
    overflow-y: auto;
    min-height: 20px;
    max-height: 100px;
    line-height: 1.4;
}

#messageInput:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1);
}

.send-btn {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    border: none;
    color: white;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
}

.send-btn:hover {
    background: linear-gradient(135deg, #2980b9 0%, #1f4e79 100%);
    transform: translateY(-2px);
}

.send-btn:disabled {
    background: #bdc3c7;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.file-preview {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 15px;
    margin-top: 15px;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.file-icon {
    color: #3498db;
    font-size: 20px;
}

.file-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-size {
    color: #7f8c8d;
    font-size: 12px;
}

.remove-file-btn {
    background: none;
    border: none;
    color: #e74c3c;
    cursor: pointer;
    padding: 4px;
    border-radius: 50%;
    margin-left: 8px;
    transition: all 0.2s;
}

.remove-file-btn:hover {
    background: rgba(231, 76, 60, 0.1);
}

#fileMessage {
    width: 100%;
    border: 2px solid #e1e8ed;
    border-radius: 20px;
    padding: 10px 16px;
    font-size: 14px;
    outline: none;
    background: white;
}

#fileMessage:focus {
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
}

.loading-spinner {
    text-align: center;
    padding: 40px;
    color: #7f8c8d;
}

.messages-empty {
    text-align: center;
    padding: 40px;
    color: #7f8c8d;
}

.new-messages-notification {
    position: absolute;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    background: #3498db;
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    cursor: pointer;
    z-index: 10;
    box-shadow: 0 2px 10px rgba(52, 152, 219, 0.3);
    transition: all 0.2s ease;
    display: none;
}

.new-messages-notification:hover {
    background: #2980b9;
    transform: translateX(-50%) translateY(-2px);
}

.chat-window.show {
    display: flex;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@media (max-width: 768px) {
    .chat-window {
        width: calc(100vw - 20px);
        height: calc(100vh - 100px);
        bottom: 80px;
        right: -10px;
    }
}

@media (max-width: 480px) {
    .chat-window {
        width: 100vw;
        height: 100vh;
        bottom: 0;
        right: 0;
        border-radius: 0;
    }
    
    .chat-button {
        bottom: 10px;
        right: 10px;
    }
}
</style>

<script>
class SimpleChatWidget {
    constructor() {
        this.isOpen = false;
        this.currentChatUserId = null;
        this.lastMessageId = 0;
        this.currentUserId = <?= $current_user_id ?>;
        this.selectedFile = null;
        this.searchTimeout = null;
        this.currentSearchTerm = '';
        this.messagePollingInterval = null;
        this.contactsPollingInterval = null;
        this.unreadPollingInterval = null;
        this.notificationPermission = 'default';
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadContacts();
        this.requestNotificationPermission();
        this.startUnreadPolling();
    }

    async requestNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            try {
                const permission = await Notification.requestPermission();
                this.notificationPermission = permission;
            } catch (error) {
                console.log('Notification permission denied');
            }
        } else if ('Notification' in window) {
            this.notificationPermission = Notification.permission;
        }
    }

    showDesktopNotification(senderName, message) {
        if (this.notificationPermission === 'granted' && !document.hasFocus()) {
            const notification = new Notification(`New message from ${senderName}`, {
                body: message.length > 50 ? message.substring(0, 50) + '...' : message,
                icon: '/favicon.ico',
                tag: 'chat-message',
                requireInteraction: false
            });

            notification.onclick = () => {
                window.focus();
                if (!this.isOpen) {
                    this.toggleChat();
                }
                notification.close();
            };

            setTimeout(() => notification.close(), 5000);
        }
    }

    startUnreadPolling() {
        this.updateUnreadBadge();
        
        this.unreadPollingInterval = setInterval(() => {
            this.updateUnreadBadge();
        }, 3000);
    }

    async updateUnreadBadge() {
        try {
            const response = await fetch('chat_widget.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_unread_count'
            });
            const result = await response.json();
            
            const badge = document.getElementById('unreadBadge');
            if (result.count > 0) {
                badge.textContent = result.count > 99 ? '99+' : result.count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        } catch (error) {
            console.error('Error updating unread badge:', error);
        }
    }

    startMessagePolling() {
        this.stopMessagePolling();
        
        this.messagePollingInterval = setInterval(() => {
            if (this.currentChatUserId && this.isOpen) {
                this.loadMessages();
            }
        }, 1500);
    }

    stopMessagePolling() {
        if (this.messagePollingInterval) {
            clearInterval(this.messagePollingInterval);
            this.messagePollingInterval = null;
        }
    }

    startContactsPolling() {
        this.contactsPollingInterval = setInterval(() => {
            if (this.isOpen && !this.currentSearchTerm) {
                this.loadContacts();
            }
        }, 5000);
    }

    stopContactsPolling() {
        if (this.contactsPollingInterval) {
            clearInterval(this.contactsPollingInterval);
            this.contactsPollingInterval = null;
        }
    }

    bindEvents() {
        document.getElementById('chatButton').addEventListener('click', () => this.toggleChat());
        document.getElementById('closeChat').addEventListener('click', () => this.closeChat());
        document.getElementById('backToContacts').addEventListener('click', () => this.showContactsView());
        document.getElementById('refreshContacts').addEventListener('click', () => this.loadContacts());
        document.getElementById('sendMessage').addEventListener('click', () => this.sendMessage());
        
        const searchInput = document.getElementById('contactSearch');
        const clearSearchBtn = document.getElementById('clearSearch');
        
        searchInput.addEventListener('input', (e) => this.handleSearch(e.target.value));
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.performSearch(e.target.value);
            }
        });
        
        clearSearchBtn.addEventListener('click', () => this.clearSearch());
        
        const messageInput = document.getElementById('messageInput');
        messageInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        document.getElementById('attachFileBtn').addEventListener('click', () => {
            document.getElementById('fileInput').click();
        });
        document.getElementById('fileInput').addEventListener('change', (e) => this.handleFileSelect(e));
        document.getElementById('removeFile').addEventListener('click', () => this.removeSelectedFile());

        document.addEventListener('click', (e) => {
            const contactItem = e.target.closest('.contact-item');
            if (contactItem) {
                this.selectContact(contactItem);
                return;
            }

            const fileAttachment = e.target.closest('.file-attachment');
            if (fileAttachment && fileAttachment.dataset.filePath) {
                this.downloadFile(fileAttachment.dataset.filePath, fileAttachment.dataset.fileName);
                return;
            }

            const imageAttachment = e.target.closest('.image-attachment');
            if (imageAttachment) {
                this.previewImage(imageAttachment.querySelector('img').src);
                return;
            }
        });
    }

    handleSearch(searchTerm) {
        this.currentSearchTerm = searchTerm.trim();
        const clearBtn = document.getElementById('clearSearch');
        
        if (this.currentSearchTerm.length > 0) {
            clearBtn.style.display = 'flex';
        } else {
            clearBtn.style.display = 'none';
        }
        
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            this.performSearch(this.currentSearchTerm);
        }, 300);
    }

    performSearch(searchTerm) {
        this.loadContacts(searchTerm);
    }

    clearSearch() {
        const searchInput = document.getElementById('contactSearch');
        const clearBtn = document.getElementById('clearSearch');
        
        searchInput.value = '';
        clearBtn.style.display = 'none';
        this.currentSearchTerm = '';
        this.loadContacts();
        searchInput.focus();
    }

    toggleChat() {
        const chatWindow = document.getElementById('chatWindow');
        this.isOpen = !this.isOpen;
        
        if (this.isOpen) {
            chatWindow.style.display = 'flex';
            setTimeout(() => chatWindow.classList.add('show'), 10);
            this.loadContacts();
            this.startContactsPolling();
        } else {
            chatWindow.classList.remove('show');
            setTimeout(() => chatWindow.style.display = 'none', 300);
            this.stopMessagePolling();
            this.stopContactsPolling();
        }
    }

    closeChat() {
        this.isOpen = false;
        const chatWindow = document.getElementById('chatWindow');
        chatWindow.classList.remove('show');
        setTimeout(() => chatWindow.style.display = 'none', 300);
        this.stopMessagePolling();
        this.stopContactsPolling();
    }

    showContactsView() {
        document.getElementById('contactsView').style.display = 'flex';
        document.getElementById('chatView').style.display = 'none';
        this.currentChatUserId = null;
        this.stopMessagePolling();
        if (this.selectedFile) this.removeSelectedFile();
        if (this.currentSearchTerm) {
            document.getElementById('contactSearch').value = this.currentSearchTerm;
            document.getElementById('clearSearch').style.display = 'flex';
        }
        this.loadContacts();
    }

    showChatView() {
        document.getElementById('contactsView').style.display = 'none';
        document.getElementById('chatView').style.display = 'flex';
        setTimeout(() => this.scrollToBottom(), 100);
        this.startMessagePolling();
    }

    async loadContacts(searchTerm = '') {
        try {
            const body = searchTerm ? 
                `action=get_contacts&search=${encodeURIComponent(searchTerm)}` : 
                'action=get_contacts';
            
            const response = await fetch('chat_widget.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            const result = await response.json();
            
            if (result.contacts) {
                this.renderContacts(result.contacts, searchTerm);
            }
        } catch (error) {
            console.error('Error loading contacts:', error);
        }
    }

    renderContacts(contacts, searchTerm = '') {
        const contactsList = document.getElementById('contactsList');
        contactsList.innerHTML = '';

        if (searchTerm) {
            const searchInfo = document.createElement('div');
            searchInfo.className = 'search-results-info';
            searchInfo.textContent = `Found ${contacts.length} contact${contacts.length !== 1 ? 's' : ''} for "${searchTerm}"`;
            contactsList.appendChild(searchInfo);
        }

        if (contacts.length === 0) {
            const noContactsMsg = searchTerm ? 
                `No contacts found for "${searchTerm}"` : 
                'No contacts available';
            contactsList.innerHTML += `<div class="no-contacts-found">${noContactsMsg}</div>`;
            return;
        }

        contacts.forEach(contact => {
            const contactDiv = document.createElement('div');
            contactDiv.className = 'contact-item';
            if (contact.last_message_time) {
                contactDiv.classList.add('has-messages');
            }
            contactDiv.dataset.userId = contact.user_id;
            contactDiv.dataset.userName = contact.full_name;

            const roleText = contact.role === 'user' ? 'User' : 'Admin';
            const avatarColor = contact.role === 'user' ? '#95a5a6' : '#e74c3c';
            const hasMessages = contact.last_message_time !== null;

            contactDiv.innerHTML = `
                <div class="contact-avatar" style="background: linear-gradient(135deg, ${avatarColor} 0%, ${avatarColor}dd 100%);">
                    ${contact.full_name.charAt(0).toUpperCase()}
                </div>
                <div class="contact-info">
                    <div class="contact-name">${this.escapeHtml(contact.full_name)} ${hasMessages ? '<span class="recent-indicator">💬</span>' : ''}</div>
                    <div class="contact-role">${roleText}</div>
                </div>
                ${contact.unread_count > 0 ? `<div class="contact-unread">${contact.unread_count > 99 ? '99+' : contact.unread_count}</div>` : ''}
            `;

            contactsList.appendChild(contactDiv);
        });
    }

    selectContact(contactElement) {
        const userId = parseInt(contactElement.dataset.userId);
        const userName = contactElement.dataset.userName;

        this.currentChatUserId = userId;
        this.lastMessageId = 0;

        document.getElementById('chatUserName').textContent = userName;
        document.getElementById('chatAvatar').textContent = userName.charAt(0).toUpperCase();

        this.showChatView();
        this.loadMessages(true);

        const unreadBadge = contactElement.querySelector('.contact-unread');
        if (unreadBadge) unreadBadge.remove();
    }

    async loadMessages(isInitialLoad = false) {
        if (!this.currentChatUserId) return;

        try {
            const response = await fetch('chat_widget.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_messages&other_user_id=${this.currentChatUserId}&last_message_id=${this.lastMessageId}`
            });

            const result = await response.json();
            
            if (result.messages) {
                if (isInitialLoad) {
                    document.getElementById('messagesArea').innerHTML = '';
                    
                    if (result.messages.length === 0) {
                        document.getElementById('messagesArea').innerHTML = '<div class="messages-empty">No messages yet. Start a conversation!</div>';
                        return;
                    }
                }

                if (result.messages.length > 0) {
                    const wasAtBottom = this.isScrolledToBottom();
                    
                    result.messages.forEach(message => {
                        this.appendMessage(message);
                        this.lastMessageId = Math.max(this.lastMessageId, message.message_id);
                        
                        if (message.sender_id != this.currentUserId && !isInitialLoad) {
                            this.showDesktopNotification(message.full_name, message.message);
                        }
                    });
                    
                    if (wasAtBottom || isInitialLoad) {
                        this.scrollToBottom();
                    } else {
                        this.showNewMessageNotification(result.messages.length);
                    }
                    
                    this.updateUnreadBadge();
                }
            }
        } catch (error) {
            console.error('Error loading messages:', error);
        }
    }

    isScrolledToBottom() {
        const messagesArea = document.getElementById('messagesArea');
        const threshold = 50;
        return messagesArea.scrollHeight - messagesArea.clientHeight <= messagesArea.scrollTop + threshold;
    }

    showNewMessageNotification(count) {
        let notification = document.querySelector('.new-messages-notification');
        if (!notification) {
            notification = document.createElement('div');
            notification.className = 'new-messages-notification';
            notification.addEventListener('click', () => {
                this.scrollToBottom();
                notification.remove();
            });
            document.getElementById('messagesArea').parentNode.appendChild(notification);
        }
        
        notification.textContent = `${count} new message${count > 1 ? 's' : ''} ↓`;
        notification.style.display = 'block';
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    appendMessage(message) {
        const messagesArea = document.getElementById('messagesArea');
        const isCurrentUser = message.sender_id == this.currentUserId;

        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${isCurrentUser ? 'sent' : 'received'}`;

        const messageDate = new Date(message.created_at);
        const time = messageDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const avatarColor = isCurrentUser ? '#3498db' : '#95a5a6';

        let messageText = message.message;
        let fileAttachment = '';
        
        if (message.file_path && message.file_name) {
            const isImage = message.file_type && message.file_type.startsWith('image/');
            
            if (isImage) {
                const imagePath = `chat_widget.php?image=1&file=${encodeURIComponent(message.file_path)}`;
                fileAttachment = `
                    <div class="image-attachment" data-file-path="${message.file_path}" data-file-name="${message.file_name}">
                        <img src="${imagePath}" alt="${this.escapeHtml(message.file_name)}" 
                             loading="lazy" 
                             onerror="this.style.display='none'; this.parentNode.innerHTML='<div style=\\'padding:20px; text-align:center; background:#f0f0f0; border-radius:8px; color:#666;\\'>Image not available<br><small>${this.escapeHtml(message.file_name)}</small></div>';">
                    </div>
                `;
            } else {
                const fileSize = this.formatFileSize(message.file_size || 0);
                fileAttachment = `
                    <div class="file-attachment" data-file-path="${message.file_path}" data-file-name="${message.file_name}">
                        <div class="file-attachment-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="file-attachment-info">
                            <div class="file-attachment-name">${this.escapeHtml(message.file_name)}</div>
                            <div class="file-attachment-size">${fileSize}</div>
                        </div>
                        <i class="fas fa-download"></i>
                    </div>
                `;
            }
        }

        messageDiv.innerHTML = `
            <div class="message-avatar" style="background: linear-gradient(135deg, ${avatarColor} 0%, ${avatarColor}dd 100%);">
                ${message.full_name.charAt(0).toUpperCase()}
            </div>
            <div class="message-content">
                ${messageText ? `<div class="message-bubble">${this.escapeHtml(messageText)}</div>` : ''}
                ${fileAttachment}
                <div class="message-time">${time}</div>
            </div>
        `;

        messagesArea.appendChild(messageDiv);
    }

    async sendMessage() {
        const input = document.getElementById('messageInput');
        const message = input.value.trim();
        const fileMessage = document.getElementById('fileMessage').value.trim();

        if ((!message && !this.selectedFile) || !this.currentChatUserId) return;

        const sendBtn = document.getElementById('sendMessage');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            if (this.selectedFile) {
                await this.uploadFile(this.selectedFile, fileMessage || message);
            } else {
                await this.sendTextMessage(message);
            }

            input.value = '';
            if (this.selectedFile) this.removeSelectedFile();
            
            setTimeout(() => this.loadMessages(), 100);
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Failed to send message: ' + error.message);
        } finally {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            input.focus();
        }
    }

    async sendTextMessage(message) {
        const response = await fetch('chat_widget.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=send_message&receiver_id=${this.currentChatUserId}&message=${encodeURIComponent(message)}`
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Failed to send message');
        }
    }

    async uploadFile(file, message) {
        const formData = new FormData();
        formData.append('action', 'upload_file');
        formData.append('receiver_id', this.currentChatUserId);
        formData.append('file', file);
        formData.append('file_message', message);

        try {
            const response = await fetch('chat_widget.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Upload failed');
            }
            
            return result;
        } catch (error) {
            console.error('Upload error:', error);
            throw error;
        }
    }

    handleFileSelect(event) {
        const file = event.target.files[0];
        if (!file) return;

        if (file.size > 10 * 1024 * 1024) {
            alert('File too large. Maximum size is 10MB.');
            return;
        }

        this.selectedFile = file;
        this.showFilePreview(file);
        event.target.value = '';
    }

    showFilePreview(file) {
        const filePreview = document.getElementById('filePreview');
        const fileName = filePreview.querySelector('.file-name');
        const fileSize = filePreview.querySelector('.file-size');
        const fileIcon = filePreview.querySelector('.file-icon');

        fileName.textContent = file.name;
        fileSize.textContent = this.formatFileSize(file.size);
        
        if (file.type.startsWith('image/')) {
            fileIcon.className = 'fas fa-image file-icon';
        } else if (file.type === 'application/pdf') {
            fileIcon.className = 'fas fa-file-pdf file-icon';
        } else if (file.type.includes('word')) {
            fileIcon.className = 'fas fa-file-word file-icon';
        } else if (file.type.includes('excel') || file.type.includes('sheet')) {
            fileIcon.className = 'fas fa-file-excel file-icon';
        } else {
            fileIcon.className = 'fas fa-file file-icon';
        }

        filePreview.style.display = 'block';
        setTimeout(() => document.getElementById('fileMessage').focus(), 100);
    }

    removeSelectedFile() {
        this.selectedFile = null;
        document.getElementById('filePreview').style.display = 'none';
        document.getElementById('fileMessage').value = '';
        document.getElementById('messageInput').focus();
    }

    downloadFile(filePath, fileName) {
        const link = document.createElement('a');
        link.href = `chat_widget.php?download=1&file=${encodeURIComponent(filePath)}`;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    previewImage(imageSrc) {
        const newWindow = window.open('', '_blank');
        newWindow.document.write(`
            <html>
                <head><title>Image Preview</title></head>
                <body style="margin:0; padding:20px; background:#000; display:flex; justify-content:center; align-items:center; min-height:100vh;">
                    <img src="${imageSrc}" style="max-width:100%; max-height:100%; object-fit:contain;">
                </body>
            </html>
        `);
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    scrollToBottom() {
        const messagesArea = document.getElementById('messagesArea');
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new SimpleChatWidget();
});
</script>