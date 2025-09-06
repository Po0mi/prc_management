// notifications.js - Fixed Real-time Notification System
class NotificationSystem {
    constructor(options = {}) {
        this.options = {
            apiUrl: 'notifications_api.php',
            checkInterval: 30000,
            toastDuration: 8000,
            maxToasts: 3,
            soundEnabled: true,
            ...options
        };
        
        this.notifications = [];
        this.unreadCount = 0;
        this.isOpen = false;
        this.checkTimer = null;
        this.toastContainer = null;
        this.bellElement = null;
        this.panelElement = null;
        this.badgeElement = null;
        
        // Track shown notifications to prevent duplicates
        this.shownNotificationIds = new Set();
        this.lastCheckTimestamp = null;
        
        this.init();
    }
    
    init() {
        this.loadShownNotifications();
        this.createElements();
        this.bindEvents();
        this.startPolling();
        
        // Check immediately on load
        this.checkForNotifications();
        
        console.log('Notification system initialized');
    }
    
    // Load previously shown notifications from localStorage
    loadShownNotifications() {
        try {
            const stored = localStorage.getItem('notification_shown_ids');
            if (stored) {
                const ids = JSON.parse(stored);
                // Only keep IDs from the last 24 hours to prevent unlimited growth
                const oneDayAgo = Date.now() - (24 * 60 * 60 * 1000);
                this.shownNotificationIds = new Set(
                    ids.filter(item => item.timestamp > oneDayAgo).map(item => item.id)
                );
                this.saveShownNotifications();
            }
        } catch (error) {
            console.warn('Could not load shown notifications:', error);
            this.shownNotificationIds = new Set();
        }
    }
    
    // Save shown notification IDs to localStorage
    saveShownNotifications() {
        try {
            const idsWithTimestamp = Array.from(this.shownNotificationIds).map(id => ({
                id: id,
                timestamp: Date.now()
            }));
            localStorage.setItem('notification_shown_ids', JSON.stringify(idsWithTimestamp));
        } catch (error) {
            console.warn('Could not save shown notifications:', error);
        }
    }
    
    createElements() {
        // Create toast container
        this.toastContainer = document.createElement('div');
        this.toastContainer.className = 'toast-container';
        document.body.appendChild(this.toastContainer);
        
        // Find or create notification bell in header
        this.bellElement = document.querySelector('.notification-bell');
        if (!this.bellElement) {
            this.bellElement = this.createNotificationBell();
        }
        
        // Create badge if it doesn't exist
        this.badgeElement = this.bellElement.querySelector('.notification-badge');
        if (!this.badgeElement) {
            this.badgeElement = document.createElement('span');
            this.badgeElement.className = 'notification-badge hidden';
            this.bellElement.appendChild(this.badgeElement);
        }
        
        // Create notification panel
        this.createNotificationPanel();
    }
    
    createNotificationBell() {
        const headerRight = document.querySelector('.header-right, .user-info, .header-actions');
        if (!headerRight) {
            console.warn('No suitable header container found for notification bell');
            return null;
        }
        
        const bell = document.createElement('div');
        bell.className = 'notification-bell';
        bell.innerHTML = '<i class="fas fa-bell"></i>';
        bell.setAttribute('aria-label', 'Notifications');
        bell.setAttribute('aria-expanded', 'false');
        bell.setAttribute('role', 'button');
        bell.setAttribute('tabindex', '0');
        
        headerRight.appendChild(bell);
        return bell;
    }
    
    createNotificationPanel() {
        if (!this.bellElement) return;
        
        const panel = document.createElement('div');
        panel.className = 'notification-panel';
        panel.setAttribute('role', 'menu');
        panel.setAttribute('aria-hidden', 'true');
        
        panel.innerHTML = `
            <div class="notification-header">
                <h3>
                    <i class="fas fa-bell"></i>
                    Notifications
                    <span class="notification-count">0</span>
                </h3>
                <button class="mark-all-read" aria-label="Mark all as read">
                    <i class="fas fa-check-double"></i> Mark all read
                </button>
            </div>
            <div class="notification-list">
                <div class="notification-loading">
                    <i class="fas fa-spinner"></i>
                    <p>Loading notifications...</p>
                </div>
            </div>
        `;
        
        const bellRect = this.bellElement.getBoundingClientRect();
        this.bellElement.parentElement.style.position = 'relative';
        this.bellElement.parentElement.appendChild(panel);
        
        this.panelElement = panel;
        
        panel.querySelector('.mark-all-read').addEventListener('click', () => {
            this.markAllAsRead();
        });
    }
    
    bindEvents() {
        if (!this.bellElement) return;
        
        this.bellElement.addEventListener('click', (e) => {
            e.stopPropagation();
            this.togglePanel();
        });
        
        this.bellElement.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.togglePanel();
            }
        });
        
        document.addEventListener('click', (e) => {
            if (this.isOpen && this.panelElement && 
                !this.panelElement.contains(e.target) && 
                !this.bellElement.contains(e.target)) {
                this.closePanel();
            }
        });
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.closePanel();
                this.bellElement.focus();
            }
        });
        
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopPolling();
            } else {
                this.startPolling();
                this.checkForNotifications();
            }
        });
    }
    
    startPolling() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
        }
        
        this.checkTimer = setInterval(() => {
            this.checkForNotifications();
        }, this.options.checkInterval);
    }
    
    stopPolling() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
            this.checkTimer = null;
        }
    }
    
    async checkForNotifications() {
        try {
            // Add timestamp to prevent caching and get only new notifications
            const url = `${this.options.apiUrl}?action=check&since=${this.lastCheckTimestamp || 0}&t=${Date.now()}`;
            
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                this.updateNotifications(data.notifications);
                this.lastCheckTimestamp = Date.now();
            } else {
                console.error('Failed to fetch notifications:', data.message);
            }
        } catch (error) {
            console.error('Error checking notifications:', error);
            setTimeout(() => {
                this.checkForNotifications();
            }, 60000);
        }
    }
    
    updateNotifications(newNotifications) {
        if (!Array.isArray(newNotifications)) {
            console.warn('Invalid notifications data received');
            return;
        }
        
        const previousIds = new Set(this.notifications.map(n => n.id));
        
        // Filter out notifications we've already shown as toasts
        const reallyNewNotifications = newNotifications.filter(n => {
            return !previousIds.has(n.id) && !this.shownNotificationIds.has(n.id);
        });
        
        // Update notifications array
        this.notifications = newNotifications;
        this.unreadCount = newNotifications.length;
        
        // Update badge
        this.updateBadge();
        
        // Update panel if open
        if (this.isOpen) {
            this.renderNotifications();
        }
        
        // Show toasts for truly new notifications only
        if (reallyNewNotifications.length > 0) {
            console.log(`Showing ${reallyNewNotifications.length} new notifications`);
            
            // Mark these notifications as shown
            reallyNewNotifications.forEach(n => {
                this.shownNotificationIds.add(n.id);
            });
            this.saveShownNotifications();
            
            // Ring the bell
            if (this.bellElement) {
                this.bellElement.classList.add('has-new');
                setTimeout(() => {
                    this.bellElement.classList.remove('has-new');
                }, 800);
            }
            
            // Show toast notifications for new items (limit to prevent spam)
            reallyNewNotifications.slice(0, this.options.maxToasts).forEach((notification, index) => {
                setTimeout(() => {
                    this.showToast(notification);
                }, index * 300);
            });
            
            // Play notification sound
            if (this.options.soundEnabled) {
                this.playNotificationSound();
            }
        }
    }
    
    updateBadge() {
        if (!this.badgeElement) return;
        
        if (this.unreadCount > 0) {
            this.badgeElement.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
            this.badgeElement.classList.remove('hidden');
        } else {
            this.badgeElement.classList.add('hidden');
        }
        
        if (this.panelElement) {
            const countElement = this.panelElement.querySelector('.notification-count');
            if (countElement) {
                countElement.textContent = this.unreadCount;
            }
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
        if (!this.panelElement || !this.bellElement) return;
        
        this.isOpen = true;
        this.panelElement.classList.add('active');
        this.panelElement.setAttribute('aria-hidden', 'false');
        this.bellElement.setAttribute('aria-expanded', 'true');
        
        this.renderNotifications();
        
        setTimeout(() => {
            const firstItem = this.panelElement.querySelector('.notification-item');
            if (firstItem) {
                firstItem.focus();
            }
        }, 100);
    }
    
    closePanel() {
        if (!this.panelElement || !this.bellElement) return;
        
        this.isOpen = false;
        this.panelElement.classList.remove('active');
        this.panelElement.setAttribute('aria-hidden', 'true');
        this.bellElement.setAttribute('aria-expanded', 'false');
    }
    
    renderNotifications() {
        if (!this.panelElement) return;
        
        const listContainer = this.panelElement.querySelector('.notification-list');
        
        if (this.notifications.length === 0) {
            listContainer.innerHTML = `
                <div class="notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    <h4>No new notifications</h4>
                    <p>You're all caught up! New activities will appear here.</p>
                </div>
            `;
            return;
        }
        
        listContainer.innerHTML = this.notifications.map(notification => {
            return `
                <div class="notification-item unread" 
                     data-id="${notification.id}"
                     data-url="${notification.url || '#'}"
                     role="menuitem"
                     tabindex="0">
                    <div class="notification-content">
                        <div class="notification-icon ${notification.type}">
                            <i class="${notification.icon}"></i>
                        </div>
                        <div class="notification-text">
                            <div class="notification-title">${this.escapeHtml(notification.title)}</div>
                            <div class="notification-message">${this.escapeHtml(notification.message)}</div>
                            <div class="notification-time">
                                <i class="fas fa-clock"></i>
                                ${this.formatTime(notification.created_at)}
                            </div>
                        </div>
                    </div>
                    <div class="status-indicator new"></div>
                </div>
            `;
        }).join('');
        
        listContainer.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', () => {
                this.handleNotificationClick(item);
            });
            
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.handleNotificationClick(item);
                }
            });
        });
    }
    
    handleNotificationClick(item) {
        const notificationId = item.dataset.id;
        const url = item.dataset.url;
        
        this.markAsRead(notificationId);
        
        if (url && url !== '#') {
            this.closePanel();
            window.location.href = url;
        }
    }
    
    showToast(notification) {
        const toast = document.createElement('div');
        toast.className = `toast-notification ${notification.type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="${notification.icon}"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${this.escapeHtml(notification.title)}</div>
                <div class="toast-message">${this.escapeHtml(notification.message)}</div>
            </div>
            <button class="toast-close" aria-label="Close notification">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        this.toastContainer.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);
        
        const hideTimeout = setTimeout(() => {
            this.hideToast(toast);
        }, this.options.toastDuration);
        
        toast.querySelector('.toast-close').addEventListener('click', () => {
            clearTimeout(hideTimeout);
            this.hideToast(toast);
        });
        
        toast.addEventListener('click', (e) => {
            if (e.target.closest('.toast-close')) return;
            
            clearTimeout(hideTimeout);
            this.hideToast(toast);
            
            if (notification.url && notification.url !== '#') {
                window.location.href = notification.url;
            }
        });
        
        this.limitToasts();
    }
    
    hideToast(toast) {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentElement) {
                toast.parentElement.removeChild(toast);
            }
        }, 300);
    }
    
    limitToasts() {
        const toasts = this.toastContainer.querySelectorAll('.toast-notification');
        if (toasts.length > this.options.maxToasts) {
            for (let i = 0; i < toasts.length - this.options.maxToasts; i++) {
                this.hideToast(toasts[i]);
            }
        }
    }
    
    async markAsRead(notificationId) {
        try {
            const response = await fetch(`${this.options.apiUrl}?action=mark_read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ notification_id: notificationId })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Remove from local array
                this.notifications = this.notifications.filter(n => n.id !== notificationId);
                this.unreadCount = this.notifications.length;
                this.updateBadge();
                
                // Mark as shown to prevent future toasts
                this.shownNotificationIds.add(notificationId);
                this.saveShownNotifications();
                
                if (this.isOpen) {
                    const item = this.panelElement.querySelector(`[data-id="${notificationId}"]`);
                    if (item) {
                        item.classList.add('read');
                        setTimeout(() => {
                            if (item.parentElement) {
                                item.parentElement.removeChild(item);
                                if (this.notifications.length === 0) {
                                    this.renderNotifications();
                                }
                            }
                        }, 300);
                    }
                }
            } else {
                console.error('Failed to mark notification as read:', data.message);
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }
    
    async markAllAsRead() {
        try {
            const response = await fetch(`${this.options.apiUrl}?action=mark_all_read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ type: 'all' })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Mark all current notifications as shown
                this.notifications.forEach(n => {
                    this.shownNotificationIds.add(n.id);
                });
                this.saveShownNotifications();
                
                this.notifications = [];
                this.unreadCount = 0;
                this.updateBadge();
                
                if (this.isOpen) {
                    this.renderNotifications();
                }
                
                this.showToast({
                    id: 'mark_all_read_' + Date.now(),
                    type: 'general',
                    title: 'All notifications marked as read',
                    message: 'You\'re all caught up!',
                    icon: 'fas fa-check-circle',
                    created_at: new Date().toISOString()
                });
            } else {
                console.error('Failed to mark all as read:', data.message);
            }
        } catch (error) {
            console.error('Error marking all notifications as read:', error);
        }
    }
    
    playNotificationSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
            oscillator.frequency.setValueAtTime(600, audioContext.currentTime + 0.1);
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.1, audioContext.currentTime + 0.05);
            gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.3);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);
        } catch (error) {
            console.log('Notification sound not available:', error);
        }
    }
    
    formatTime(timestamp) {
        const now = new Date();
        const time = new Date(timestamp);
        const diff = Math.abs(now - time);
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (seconds < 60) return 'Just now';
        if (minutes < 60) return `${minutes}m ago`;
        if (hours < 24) return `${hours}h ago`;
        if (days < 7) return `${days}d ago`;
        
        return time.toLocaleDateString();
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Public API methods
    forceCheck() {
        this.checkForNotifications();
    }
    
    setCheckInterval(interval) {
        this.options.checkInterval = interval;
        this.startPolling();
    }
    
    enableSound() {
        this.options.soundEnabled = true;
    }
    
    disableSound() {
        this.options.soundEnabled = false;
    }
    
    // Clear the shown notifications cache (useful for testing)
    clearShownNotifications() {
        this.shownNotificationIds.clear();
        localStorage.removeItem('notification_shown_ids');
        console.log('Shown notifications cache cleared');
    }
    
    destroy() {
        this.stopPolling();
        
        if (this.toastContainer && this.toastContainer.parentElement) {
            this.toastContainer.parentElement.removeChild(this.toastContainer);
        }
        
        if (this.panelElement && this.panelElement.parentElement) {
            this.panelElement.parentElement.removeChild(this.panelElement);
        }
    }
}

// Initialize notification system when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (!document.body.classList.contains('admin-page')) {
        window.notificationSystem = new NotificationSystem({
            checkInterval: 30000,
            toastDuration: 8000,
            maxToasts: 3,
            soundEnabled: true
        });
        
        console.log('Real-time notification system active');
    }
});

if (typeof module !== 'undefined' && module.exports) {
    module.exports = NotificationSystem;
}