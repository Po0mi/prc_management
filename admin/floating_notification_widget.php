<!-- Floating Notification Widget (Add after chat widget in your pages) -->
<div id="notificationWidget" class="notification-widget">
    <div id="notificationButton" class="notification-button">
        <i class="fas fa-bell"></i>
        <span id="notificationBadge" class="notification-badge" style="display: none;">0</span>
    </div>

    <div id="notificationWindow" class="notification-window">
        <div class="notification-header">
            <div class="notification-title">
                <i class="fas fa-bell"></i>
                <span>Admin Notifications</span>
                <span id="notificationCount" class="notification-count">0</span>
            </div>
            <button id="closeNotifications" class="close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="notification-body">
            <div class="notification-actions-bar">
                <button id="markAllReadBtn" class="mark-all-read-btn">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            </div>
            
            <div class="notification-list" id="notificationList">
                <div class="notification-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading notifications...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Floating Notification Widget */
.notification-widget {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9998;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.notification-button {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(231, 76, 60, 0.4);
    transition: all 0.3s ease;
    position: relative;
    border: none;
}

.notification-button:hover {
    background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(231, 76, 60, 0.5);
}

.notification-button.has-new {
    animation: bellRing 0.6s ease-in-out;
}

@keyframes bellRing {
    0%, 100% { transform: rotate(0deg); }
    10%, 30%, 50%, 70%, 90% { transform: rotate(-15deg); }
    20%, 40%, 60%, 80% { transform: rotate(15deg); }
}

.notification-button i {
    font-size: 24px;
}

.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #ffffff;
    color: #e74c3c;
    border-radius: 50%;
    min-width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: bold;
    border: 2px solid #e74c3c;
    padding: 0 4px;
    box-shadow: 0 2px 8px rgba(231, 76, 60, 0.3);
}

.notification-badge.critical {
    background: linear-gradient(45deg, #dc3545, #c82333);
    color: white;
    border-color: white;
    animation: criticalBadgePulse 1s infinite;
}

@keyframes criticalBadgePulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}

.notification-window {
    position: absolute;
    top: 80px;
    right: 0;
    width: 380px;
    max-height: 600px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    display: none;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.notification-window.show {
    display: flex;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.notification-header {
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    color: white;
    padding: 18px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 16px;
}

.notification-count {
    background: rgba(255, 255, 255, 0.3);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
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

.notification-body {
    display: flex;
    flex-direction: column;
    max-height: 520px;
    overflow: hidden;
}

.notification-actions-bar {
    padding: 12px 20px;
    border-bottom: 1px solid #ecf0f1;
    background: #f8f9fa;
}

.mark-all-read-btn {
    background: none;
    border: none;
    color: #e74c3c;
    cursor: pointer;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-weight: 500;
}

.mark-all-read-btn:hover {
    background: rgba(231, 76, 60, 0.1);
    color: #c0392b;
}

.notification-list {
    flex: 1;
    overflow-y: auto;
    min-height: 200px;
}

.notification-loading {
    padding: 60px 20px;
    text-align: center;
    color: #7f8c8d;
}

.notification-loading i {
    font-size: 2rem;
    margin-bottom: 15px;
    display: block;
}

.notification-item {
    display: flex;
    align-items: stretch;
    padding: 0;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background-color 0.2s ease;
    position: relative;
    overflow: hidden;
}

.notification-item:hover {
    background-color: #f8f9fa;
}

.notification-item.unread {
    background-color: #fff5f5;
}

.notification-item.critical {
    background-color: #ffebee;
    border-left: 4px solid #dc3545;
}

.notification-item.high {
    background-color: #fff8e1;
    border-left: 4px solid #fd7e14;
}

.notification-item.medium {
    background-color: #fffde7;
    border-left: 4px solid #ffc107;
}

.notification-item.low {
    background-color: #f1f8e9;
    border-left: 4px solid #28a745;
}

.notification-priority {
    width: 4px;
    flex-shrink: 0;
}

.notification-priority.critical {
    background: #dc3545;
}

.notification-priority.high {
    background: #fd7e14;
}

.notification-priority.medium {
    background: #ffc107;
}

.notification-priority.low {
    background: #28a745;
}

.notification-content {
    display: flex;
    align-items: center;
    flex: 1;
    padding: 12px;
    gap: 12px;
}

.notification-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    background: #f8f9fa;
    color: #666;
}

.notification-icon.inventory {
    background: #e3f2fd;
    color: #1976d2;
}

.notification-icon.donation {
    background: #fce4ec;
    color: #c2185b;
}

.notification-icon.training {
    background: #f3e5f5;
    color: #7b1fa2;
}

.notification-icon.new_user {
    background: #e8f5e8;
    color: #2e7d32;
}

.notification-text {
    flex: 1;
    min-width: 0;
}

.notification-title-text {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
    font-size: 0.9rem;
    line-height: 1.3;
}

.notification-message {
    color: #666;
    font-size: 0.85rem;
    line-height: 1.4;
    margin-bottom: 6px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.notification-time {
    font-size: 0.75rem;
    color: #999;
    display: flex;
    align-items: center;
    gap: 4px;
}

.notification-actions {
    display: flex;
    align-items: center;
    padding: 12px;
}

.mark-read-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    border-radius: 4px;
    color: #666;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.mark-read-btn:hover {
    background: #28a745;
    color: white;
}

.notification-empty {
    padding: 60px 20px;
    text-align: center;
    color: #666;
}

.notification-empty i {
    font-size: 3rem;
    color: #28a745;
    margin-bottom: 15px;
    display: block;
}

.notification-empty h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 1.1rem;
}

.notification-empty p {
    margin: 0;
    font-size: 0.9rem;
    color: #666;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .notification-widget {
        top: 20px;
        right: 20px;
    }
    
    .notification-button {
        width: 55px;
        height: 55px;
    }
    
    .notification-button i {
        font-size: 22px;
    }
    
    .notification-window {
        width: calc(100vw - 40px);
        max-height: 500px;
        right: -10px;
    }
}

@media (max-width: 480px) {
    .notification-widget {
        top: 15px;
        right: 15px;
    }
    
    .notification-window {
        width: calc(100vw - 30px);
        max-height: 400px;
        right: -5px;
    }
}
</style>

<script>
// Initialize notification widget (this works with the existing notifications_admin.js)
document.addEventListener('DOMContentLoaded', function() {
    const notificationButton = document.getElementById('notificationButton');
    const notificationWindow = document.getElementById('notificationWindow');
    const closeNotificationsBtn = document.getElementById('closeNotifications');
    const markAllReadBtn = document.getElementById('markAllReadBtn');
    let isNotificationOpen = false;

    // Toggle notification window
    function toggleNotifications() {
        if (isNotificationOpen) {
            // Close the panel
            notificationWindow.classList.remove('show');
            setTimeout(() => {
                notificationWindow.style.display = 'none';
            }, 300);
        } else {
            // Open the panel
            notificationWindow.style.display = 'flex';
            setTimeout(() => {
                notificationWindow.classList.add('show');
            }, 10);
            // Re-render notifications when opening
            if (window.adminNotifications) {
                window.adminNotifications.renderNotifications();
            }
        }
    }

    notificationButton.addEventListener('click', function(e) {
        e.stopPropagation(); // Prevent click from bubbling to document
        e.preventDefault(); // Prevent default action
        isNotificationOpen = !isNotificationOpen;
        toggleNotifications();
    });
    
    closeNotificationsBtn.addEventListener('click', function(e) {
        e.stopPropagation(); // Prevent click from bubbling
        isNotificationOpen = false;
        notificationWindow.classList.remove('show');
        setTimeout(() => {
            notificationWindow.style.display = 'none';
        }, 300);
    });

    // Mark all as read
    markAllReadBtn.addEventListener('click', function(e) {
        e.stopPropagation(); // Prevent click from bubbling
        if (window.adminNotifications) {
            window.adminNotifications.markAllAsRead();
        }
    });

    // Close when clicking outside
    document.addEventListener('click', function(e) {
        // Check if click is outside both the window and button
        if (isNotificationOpen && 
            !notificationWindow.contains(e.target) && 
            !notificationButton.contains(e.target)) {
            isNotificationOpen = false;
            notificationWindow.classList.remove('show');
            setTimeout(() => {
                notificationWindow.style.display = 'none';
            }, 300);
        }
    });

    // Update the notification system to use the floating widget elements
    if (window.adminNotifications) {
        // Update references to use floating widget elements
        window.adminNotifications.bellElement = notificationButton;
        window.adminNotifications.badgeElement = document.getElementById('notificationBadge');
        window.adminNotifications.panelElement = notificationWindow;
        
        // Sync the isOpen state between both systems
        window.adminNotifications.togglePanel = function() {
            isNotificationOpen = !isNotificationOpen;
            this.isOpen = isNotificationOpen;
            toggleNotifications();
        };
        
        window.adminNotifications.openPanel = function() {
            isNotificationOpen = true;
            this.isOpen = true;
            notificationWindow.classList.add('show');
            this.renderNotifications();
        };
        
        window.adminNotifications.closePanel = function() {
            isNotificationOpen = false;
            this.isOpen = false;
            notificationWindow.classList.remove('show');
            setTimeout(() => {
                notificationWindow.style.display = 'none';
            }, 300);
        };

        // Trigger initial check
        window.adminNotifications.checkForNotifications();
    }
});
</script>