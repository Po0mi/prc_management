<!-- User Floating Notification Widget -->
<div id="userNotificationWidget" class="user-notification-widget">
    <div id="userNotificationButton" class="user-notification-button">
        <i class="fas fa-bell"></i>
        <span id="userNotificationBadge" class="user-notification-badge" style="display: none;">0</span>
    </div>

    <div id="userNotificationWindow" class="user-notification-window">
        <div class="user-notification-header">
            <div class="user-notification-title">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <span id="userNotificationCount" class="user-notification-count">0</span>
            </div>
            <button id="closeUserNotifications" class="user-close-btn">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="user-notification-body">
            <div class="user-notification-actions-bar">
                <button id="markAllUserReadBtn" class="mark-all-user-read-btn">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            </div>
            
            <div class="user-notification-list" id="userNotificationList">
                <div class="user-notification-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading notifications...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* User Floating Notification Widget */
.user-notification-widget {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9998;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.user-notification-button {
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

.user-notification-button:hover {
    background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(231, 76, 60, 0.5);
}

.user-notification-button.has-new {
    animation: bellRing 0.6s ease-in-out;
}

@keyframes bellRing {
    0%, 100% { transform: rotate(0deg); }
    10%, 30%, 50%, 70%, 90% { transform: rotate(-15deg); }
    20%, 40%, 60%, 80% { transform: rotate(15deg); }
}

.user-notification-button i {
    font-size: 24px;
}

.user-notification-badge {
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

.user-notification-window {
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

.user-notification-window.show {
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

.user-notification-header {
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    color: white;
    padding: 18px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.user-notification-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    font-size: 16px;
}

.user-notification-count {
    background: rgba(255, 255, 255, 0.3);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.user-close-btn {
    background: none;
    border: none;
    color: white;
    padding: 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
    opacity: 0.9;
}

.user-close-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    opacity: 1;
}

.user-notification-body {
    display: flex;
    flex-direction: column;
    max-height: 520px;
    overflow: hidden;
}

.user-notification-actions-bar {
    padding: 12px 20px;
    border-bottom: 1px solid #ecf0f1;
    background: #f8f9fa;
}

.mark-all-user-read-btn {
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

.mark-all-user-read-btn:hover {
    background: rgba(231, 76, 60, 0.1);
    color: #c0392b;
}

.user-notification-list {
    flex: 1;
    overflow-y: auto;
    min-height: 200px;
}

.user-notification-loading {
    padding: 60px 20px;
    text-align: center;
    color: #7f8c8d;
}

.user-notification-loading i {
    font-size: 2rem;
    margin-bottom: 15px;
    display: block;
}

.user-notification-item {
    display: flex;
    align-items: stretch;
    padding: 0;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background-color 0.2s ease;
    position: relative;
    overflow: hidden;
}

.user-notification-item:hover {
    background-color: #f8f9fa;
}

.user-notification-item.unread {
    background-color: #fff5f5;
}

.user-notification-item.success {
    border-left: 4px solid #28a745;
    background-color: #f1f8f3;
}

.user-notification-item.error {
    border-left: 4px solid #dc3545;
    background-color: #fff5f5;
}

.user-notification-item.info {
    border-left: 4px solid #007bff;
    background-color: #f0f7ff;
}

.user-notification-content {
    display: flex;
    align-items: center;
    flex: 1;
    padding: 12px;
    gap: 12px;
}

.user-notification-icon {
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

.user-notification-icon.success {
    background: #d4edda;
    color: #28a745;
}

.user-notification-icon.error {
    background: #f8d7da;
    color: #dc3545;
}

.user-notification-icon.info {
    background: #d1ecf1;
    color: #0c5460;
}

.user-notification-text {
    flex: 1;
    min-width: 0;
}

.user-notification-title-text {
    font-weight: 600;
    color: #333;
    margin-bottom: 4px;
    font-size: 0.9rem;
    line-height: 1.3;
}

.user-notification-message {
    color: #666;
    font-size: 0.85rem;
    line-height: 1.4;
    margin-bottom: 6px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.user-notification-time {
    font-size: 0.75rem;
    color: #999;
    display: flex;
    align-items: center;
    gap: 4px;
}

.user-notification-empty {
    padding: 60px 20px;
    text-align: center;
    color: #666;
}

.user-notification-empty i {
    font-size: 3rem;
    color: #28a745;
    margin-bottom: 15px;
    display: block;
}

.user-notification-empty h4 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 1.1rem;
}

.user-notification-empty p {
    margin: 0;
    font-size: 0.9rem;
    color: #666;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .user-notification-widget {
        top: 20px;
        right: 20px;
    }
    
    .user-notification-button {
        width: 55px;
        height: 55px;
    }
    
    .user-notification-button i {
        font-size: 22px;
    }
    
    .user-notification-window {
        width: calc(100vw - 40px);
        max-height: 500px;
        right: -10px;
    }
}

@media (max-width: 480px) {
    .user-notification-widget {
        top: 15px;
        right: 15px;
    }
    
    .user-notification-window {
        width: calc(100vw - 30px);
        max-height: 400px;
        right: -5px;
    }
}
</style>

<script>
// User Notification System
class UserNotificationSystem {
    constructor() {
        this.apiUrl = 'notifications_api.php';
        this.checkInterval = 30000; // 30 seconds
        this.notifications = [];
        this.unreadCount = 0;
        this.isOpen = false;
        this.checkTimer = null;
        this.shownIds = new Set();
        
        this.init();
    }
    
    init() {
        this.loadShownIds();
        this.bindEvents();
        this.startPolling();
        this.checkNotifications();
        
        console.log('User notification system initialized');
    }
    
    loadShownIds() {
        try {
            const stored = localStorage.getItem('user_notification_shown');
            if (stored) {
                const ids = JSON.parse(stored);
                const oneDayAgo = Date.now() - (24 * 60 * 60 * 1000);
                this.shownIds = new Set(
                    ids.filter(item => item.timestamp > oneDayAgo).map(item => item.id)
                );
            }
        } catch (error) {
            console.warn('Could not load shown notifications:', error);
        }
    }
    
    saveShownIds() {
        try {
            const idsWithTimestamp = Array.from(this.shownIds).map(id => ({
                id: id,
                timestamp: Date.now()
            }));
            localStorage.setItem('user_notification_shown', JSON.stringify(idsWithTimestamp));
        } catch (error) {
            console.warn('Could not save shown notifications:', error);
        }
    }
    
    bindEvents() {
        const button = document.getElementById('userNotificationButton');
        const window = document.getElementById('userNotificationWindow');
        const closeBtn = document.getElementById('closeUserNotifications');
        const markAllBtn = document.getElementById('markAllUserReadBtn');
        
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            this.togglePanel();
        });
        
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.closePanel();
        });
        
        markAllBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.markAllAsRead();
        });
        
        document.addEventListener('click', (e) => {
            if (this.isOpen && !window.contains(e.target) && !button.contains(e.target)) {
                this.closePanel();
            }
        });
        
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.checkNotifications();
            }
        });
    }
    
    startPolling() {
        if (this.checkTimer) clearInterval(this.checkTimer);
        this.checkTimer = setInterval(() => this.checkNotifications(), this.checkInterval);
    }
    
    async checkNotifications() {
        try {
            const response = await fetch(`${this.apiUrl}?action=check&t=${Date.now()}`, {
                credentials: 'same-origin',
                headers: { 'Cache-Control': 'no-cache' }
            });
            
            if (!response.ok) throw new Error('Failed to fetch notifications');
            
            const data = await response.json();
            if (data.success) {
                this.updateNotifications(data.notifications);
            }
        } catch (error) {
            console.error('Error checking notifications:', error);
        }
    }
    
    updateNotifications(newNotifications) {
        const previousIds = new Set(this.notifications.map(n => n.id));
        const reallyNew = newNotifications.filter(n => !previousIds.has(n.id) && !this.shownIds.has(n.id));
        
        this.notifications = newNotifications;
        this.unreadCount = newNotifications.length;
        
        this.updateBadge();
        
        if (this.isOpen) {
            this.renderNotifications();
        }
        
        if (reallyNew.length > 0) {
            reallyNew.forEach(n => this.shownIds.add(n.id));
            this.saveShownIds();
            
            const button = document.getElementById('userNotificationButton');
            button.classList.add('has-new');
            setTimeout(() => button.classList.remove('has-new'), 800);
            
            this.playSound();
        }
    }
    
    updateBadge() {
        const badge = document.getElementById('userNotificationBadge');
        const count = document.getElementById('userNotificationCount');
        
        if (this.unreadCount > 0) {
            badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
            badge.style.display = 'flex';
            count.textContent = this.unreadCount;
        } else {
            badge.style.display = 'none';
            count.textContent = '0';
        }
    }
    
    togglePanel() {
        if (this.isOpen) {
            this.closePanel();
        } else {
            this.openPanel();
        }
    }
    
    openPanel() {
        const window = document.getElementById('userNotificationWindow');
        window.style.display = 'flex';
        setTimeout(() => window.classList.add('show'), 10);
        this.isOpen = true;
        this.renderNotifications();
    }
    
    closePanel() {
        const window = document.getElementById('userNotificationWindow');
        window.classList.remove('show');
        setTimeout(() => window.style.display = 'none', 300);
        this.isOpen = false;
    }
    
    renderNotifications() {
        const list = document.getElementById('userNotificationList');
        
        if (this.notifications.length === 0) {
            list.innerHTML = `
                <div class="user-notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    <h4>No new notifications</h4>
                    <p>You're all caught up!</p>
                </div>
            `;
            return;
        }
        
        list.innerHTML = this.notifications.map(n => `
            <div class="user-notification-item unread ${n.type}" 
                 data-id="${n.id}"
                 data-url="${n.url || '#'}">
                <div class="user-notification-content">
                    <div class="user-notification-icon ${n.type}">
                        <i class="${n.icon}"></i>
                    </div>
                    <div class="user-notification-text">
                        <div class="user-notification-title-text">${this.escapeHtml(n.title)}</div>
                        <div class="user-notification-message">${this.escapeHtml(n.message)}</div>
                        <div class="user-notification-time">
                            <i class="fas fa-clock"></i>
                            ${this.formatTime(n.created_at)}
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
        
        list.querySelectorAll('.user-notification-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.dataset.id;
                const url = item.dataset.url;
                this.markAsRead(id);
                if (url && url !== '#') {
                    this.closePanel();
                    window.location.href = url;
                }
            });
        });
    }
    
    async markAsRead(notificationId) {
        try {
            await fetch(`${this.apiUrl}?action=mark_read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            });
            
            this.notifications = this.notifications.filter(n => n.id !== notificationId);
            this.unreadCount = this.notifications.length;
            this.updateBadge();
            
            if (this.isOpen) {
                this.renderNotifications();
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }
    
    async markAllAsRead() {
        try {
            await fetch(`${this.apiUrl}?action=mark_all_read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'all' })
            });
            
            this.notifications.forEach(n => this.shownIds.add(n.id));
            this.saveShownIds();
            
            this.notifications = [];
            this.unreadCount = 0;
            this.updateBadge();
            this.renderNotifications();
        } catch (error) {
            console.error('Error marking all as read:', error);
        }
    }
    
    playSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
            gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);
        } catch (error) {
            console.log('Sound not available:', error);
        }
    }
    
    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (seconds < 60) return 'Just now';
        if (minutes < 60) return `${minutes}m ago`;
        if (hours < 24) return `${hours}h ago`;
        if (days < 7) return `${days}d ago`;
        return date.toLocaleDateString();
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.userNotifications = new UserNotificationSystem();
});
</script>