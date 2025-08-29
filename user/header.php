<?php
// Get notification counts for current user
$userId = current_user_id();

// Get unread notifications count
$notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$notifStmt->execute([$userId]);
$notificationCount = $notifStmt->fetchColumn();

// Get unread messages count (you can create a messages table or use announcements)
$messageCount = 0; // Placeholder - you can implement actual message system
?>

<header class="top-header">
  <div class="page-title">
    <h2>User Dashboard</h2>
  </div>
  
  <div class="user-actions">
    <button class="notification-btn" title="Notifications" onclick="openNotifications()">
      <i class="fas fa-bell"></i>
      <?php if ($notificationCount > 0): ?>
        <span class="badge-count"><?= $notificationCount ?></span>
      <?php endif; ?>
    </button>
    
    <button title="Toggle Dark Mode" id="darkModeToggle">
      <i class="fas fa-moon"></i>
    </button>
  </div>
</header>

<!-- Notifications Modal -->
<div id="notificationModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-bell"></i> Notifications</h3>
      <button class="close-btn" onclick="closeModal('notificationModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body" id="notificationBody">
      <div class="loading">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading notifications...</p>
      </div>
    </div>
  </div>
</div>

<!-- Messages Modal -->
<div id="messagesModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-envelope"></i> Messages</h3>
      <button class="close-btn" onclick="closeModal('messagesModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body" id="messagesBody">
      <div class="loading">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading messages...</p>
      </div>
    </div>
  </div>
</div>

<style>
/* Header Styles */
:root {
  --bg-color: #ffffff;
  --text-color: #333333;
  --header-bg: #f8f9fa;
  --prc-red: #a00000;
  --card-bg: white;
}

.dark-mode {
  --bg-color: #1a1a1a;
  --text-color: #f0f0f0;
  --header-bg: #2d2d2d;
  --card-bg: #2d2d2d;
}

body {
  background-color: var(--bg-color);
  color: var(--text-color);
  transition: background-color 0.3s, color 0.3s;
}

.top-header {
  background-color: var(--header-bg);
  padding: 1rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #eee;
}

.dark-mode .top-header {
  border-bottom-color: #444;
}

.user-actions {
  display: flex;
  gap: 1rem;
  align-items: center;
}

.user-actions button {
  position: relative;
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.2rem;
  color: var(--text-color);
  padding: 0.5rem;
  border-radius: 50%;
  transition: all 0.3s ease;
}

.user-actions button:hover {
  background-color: rgba(160, 0, 0, 0.1);
  color: var(--prc-red);
}

/* Badge Styles */
.badge-count {
  position: absolute;
  top: -5px;
  right: -5px;
  background: var(--prc-red);
  color: white;
  border-radius: 50%;
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: bold;
  animation: pulse 1s infinite;
}

@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.1); }
  100% { transform: scale(1); }
}

/* Modal Styles */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(5px);
}

.modal-content {
  background-color: var(--card-bg);
  margin: 5% auto;
  border-radius: 10px;
  width: 90%;
  max-width: 500px;
  max-height: 80vh;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  display: flex;
  flex-direction: column;
}

.modal-header {
  padding: 1.5rem;
  border-bottom: 1px solid #eee;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--header-bg);
  border-radius: 10px 10px 0 0;
}

.dark-mode .modal-header {
  border-bottom-color: #444;
}

.modal-header h3 {
  margin: 0;
  color: var(--text-color);
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.2rem;
  cursor: pointer;
  color: var(--text-color);
  padding: 0.5rem;
  border-radius: 50%;
  transition: all 0.3s ease;
}

.close-btn:hover {
  background-color: rgba(160, 0, 0, 0.1);
  color: var(--prc-red);
}

.modal-body {
  padding: 1.5rem;
  overflow-y: auto;
  flex: 1;
  max-height: 400px;
}

/* Loading Styles */
.loading {
  text-align: center;
  padding: 2rem;
  color: var(--text-color);
}

.loading i {
  font-size: 2rem;
  margin-bottom: 1rem;
  color: var(--prc-red);
}

/* Notification/Message Item Styles */
.notification-item,
.message-item {
  padding: 1rem;
  border-bottom: 1px solid #eee;
  cursor: pointer;
  transition: all 0.3s ease;
  border-radius: 8px;
  margin-bottom: 0.5rem;
}

.dark-mode .notification-item,
.dark-mode .message-item {
  border-bottom-color: #444;
}

.notification-item:hover,
.message-item:hover {
  background-color: rgba(160, 0, 0, 0.05);
}

.notification-item.unread,
.message-item.unread {
  background-color: rgba(160, 0, 0, 0.1);
  border-left: 3px solid var(--prc-red);
}

.notification-title,
.message-subject {
  font-weight: bold;
  color: var(--text-color);
  margin-bottom: 0.5rem;
}

.notification-content,
.message-preview {
  color: var(--text-color);
  opacity: 0.8;
  margin-bottom: 0.5rem;
  line-height: 1.4;
}

.notification-time,
.message-time {
  font-size: 0.8rem;
  color: var(--text-color);
  opacity: 0.6;
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-color);
}

.empty-state i {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
  color: var(--prc-red);
}

.empty-state h3 {
  margin-bottom: 0.5rem;
  color: var(--text-color);
}

.empty-state p {
  opacity: 0.7;
  color: var(--text-color);
}

/* Responsive */
@media (max-width: 768px) {
  .modal-content {
    margin: 10% auto;
    width: 95%;
    max-height: 70vh;
  }
  
  .modal-header,
  .modal-body {
    padding: 1rem;
  }
  
  .user-actions {
    gap: 0.5rem;
  }
  
  .user-actions button {
    font-size: 1rem;
    padding: 0.4rem;
  }
}
</style>

<script>
// Pass PHP variables to JavaScript
const notificationCount = <?= $notificationCount ?>;
const messageCount = <?= $messageCount ?>;
</script>