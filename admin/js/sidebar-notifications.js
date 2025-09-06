// sidebar-notifications.js - Extends existing notification system for sidebar badges
class SidebarNotificationBadges {
    constructor(options = {}) {
        this.options = {
            apiUrl: 'notifications_api_admin.php',
            checkInterval: 30000, // Check every 30 seconds
            ...options
        };
        
        this.badgeCounts = {};
        this.sidebarNav = null;
        this.checkTimer = null;
        
        this.init();
    }
    
    init() {
        this.sidebarNav = document.querySelector('.sidebar-nav');
        if (!this.sidebarNav) {
            console.warn('Sidebar navigation not found');
            return;
        }
        
        this.createBadgeElements();
        this.startPolling();
        this.checkForUpdates();
        
        console.log('Sidebar notification badges initialized');
    }
    
    createBadgeElements() {
        // Define which nav items should have badges and their mapping to notification types
        const badgeMapping = {
            'manage_events.php': {
                types: ['registration', 'urgent_action', 'upcoming'],
                icon: 'calendar'
            },
            'manage_sessions.php': {
                types: ['training', 'training_sessions'],
                icon: 'graduation-cap'
            },
            'training_request.php': {
                types: ['requests', 'training_requests'],
                icon: 'clipboard'
            },
            'manage_donations.php': {
                types: ['donation', 'blood_donation'],
                icon: 'donate'
            },
            'manage_inventory.php': {
                types: ['inventory', 'critical_stock'],
                icon: 'warehouse'
            },
            'manage_volunteers.php': {
                types: ['volunteers', 'volunteer_applications'],
                icon: 'hands-helping'
            },
            'manage_users.php': {
                types: ['user_activity', 'new_users'],
                icon: 'users-cog'
            },
            'manage_announcements.php': {
                types: ['announcements', 'announcement'],
                icon: 'bullhorn'
            }
        };
        
        // Add badge elements to navigation items
        Object.keys(badgeMapping).forEach(page => {
            const navLink = this.sidebarNav.querySelector(`a[href="${page}"], a[href*="${page}"]`);
            if (navLink && !navLink.querySelector('.nav-badge')) {
                const badge = document.createElement('span');
                badge.className = 'nav-badge hidden';
                badge.setAttribute('aria-label', 'New notifications');
                navLink.appendChild(badge);
                
                // Store mapping for this nav item
                navLink.dataset.notificationTypes = JSON.stringify(badgeMapping[page].types);
            }
        });
    }
    
    startPolling() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
        }
        
        this.checkTimer = setInterval(() => {
            this.checkForUpdates();
        }, this.options.checkInterval);
    }
    
    stopPolling() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
            this.checkTimer = null;
        }
    }
    
    async checkForUpdates() {
        try {
            const response = await fetch(`${this.options.apiUrl}?action=get_sidebar_counts&t=${Date.now()}`, {
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
                this.updateBadges(data.counts);
            }
        } catch (error) {
            console.error('Error checking sidebar notification counts:', error);
        }
    }
    
    updateBadges(counts) {
        // Clear existing counts
        this.badgeCounts = {};
        
        // Calculate counts for each page based on notification types
        const navLinks = this.sidebarNav.querySelectorAll('a[data-notification-types]');
        
        navLinks.forEach(navLink => {
            const types = JSON.parse(navLink.dataset.notificationTypes || '[]');
            let totalCount = 0;
            
            // Sum up counts for all relevant notification types
            types.forEach(type => {
                if (counts[type]) {
                    totalCount += counts[type];
                }
            });
            
            const badge = navLink.querySelector('.nav-badge');
            if (badge) {
                this.updateBadge(badge, totalCount);
            }
        });
    }
    
    updateBadge(badgeElement, count) {
        if (count > 0) {
            badgeElement.textContent = count > 99 ? '99+' : count.toString();
            badgeElement.classList.remove('hidden');
            
            // Add priority class based on count
            badgeElement.classList.remove('low', 'medium', 'high', 'critical');
            if (count >= 20) {
                badgeElement.classList.add('critical');
            } else if (count >= 10) {
                badgeElement.classList.add('high');
            } else if (count >= 5) {
                badgeElement.classList.add('medium');
            } else {
                badgeElement.classList.add('low');
            }
            
            // Add pulse animation for new notifications
            badgeElement.classList.add('pulse');
            setTimeout(() => {
                badgeElement.classList.remove('pulse');
            }, 2000);
        } else {
            badgeElement.classList.add('hidden');
            badgeElement.classList.remove('low', 'medium', 'high', 'critical', 'pulse');
        }
    }
    
    // Public method to manually refresh badges
    refresh() {
        this.checkForUpdates();
    }
    
    // Cleanup method
    destroy() {
        this.stopPolling();
        
        // Remove all badge elements
        const badges = this.sidebarNav.querySelectorAll('.nav-badge');
        badges.forEach(badge => {
            badge.remove();
        });
    }
}

// CSS styles for sidebar notification badges
const sidebarBadgeStyles = `
<style>
/* Sidebar Notification Badge Styles */
.nav-badge {
    position: absolute;
    top: 8px;
    right: 12px;
    background: #dc3545;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1;
    min-width: 18px;
    text-align: center;
    z-index: 10;
    transition: all 0.3s ease;
    border: 2px solid var(--sidebar-bg, #2c3e50);
}

.nav-badge.hidden {
    display: none;
}

.nav-badge.low {
    background: #28a745;
}

.nav-badge.medium {
    background: #ffc107;
    color: #333;
}

.nav-badge.high {
    background: #fd7e14;
}

.nav-badge.critical {
    background: #dc3545;
    animation: criticalBadge 2s infinite;
}

.nav-badge.pulse {
    animation: badgePulse 0.6s ease-out;
}

@keyframes criticalBadge {
    0%, 100% { 
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
    }
    50% { 
        transform: scale(1.1);
        box-shadow: 0 0 0 4px rgba(220, 53, 69, 0);
    }
}

@keyframes badgePulse {
    0% { 
        transform: scale(1);
    }
    50% { 
        transform: scale(1.3);
    }
    100% { 
        transform: scale(1);
    }
}

/* Ensure nav links have relative positioning for badge placement */
.sidebar-nav .nav-link {
    position: relative;
}

/* Adjust badge position when sidebar is collapsed */
.sidebar.collapsed .nav-badge {
    top: 6px;
    right: 6px;
    font-size: 0.6rem;
    padding: 1px 4px;
    min-width: 14px;
}

/* Badge visibility in collapsed state */
.sidebar.collapsed .nav-link:hover .nav-badge {
    opacity: 0.8;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .nav-badge {
        top: 6px;
        right: 8px;
        font-size: 0.65rem;
        padding: 1px 5px;
        min-width: 16px;
    }
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .nav-badge {
        border-width: 3px;
        font-weight: 700;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .nav-badge {
        animation: none !important;
        transition: none;
    }
}
</style>
`;

// Initialize the sidebar badge system when DOM is ready
if (typeof document !== 'undefined') {
    // Inject styles
    document.head.insertAdjacentHTML('beforeend', sidebarBadgeStyles);
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.sidebarBadges = new SidebarNotificationBadges();
        });
    } else {
        window.sidebarBadges = new SidebarNotificationBadges();
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SidebarNotificationBadges;
}