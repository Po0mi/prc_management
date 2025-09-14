<?php
// Admin header with dark mode functionality
?>

<div class="header-content"> 
  <header class="main-header">
    <div class="header-container">
      <div class="header-left">
        <!-- Logo or brand space -->
      </div>
      
      <div class="header-center">
        <!-- Navigation or title space -->
      </div>
      
      <div class="header-right">
        <!-- TRANSFERRED NOTIFICATION BELL FROM HEADER -->
        <div class="sidebar-notification-section">
          <div class="notification-bell" role="button" tabindex="0" aria-label="Notifications" aria-expanded="false">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>
            <span class="notification-badge hidden">0</span>
            
            <!-- Notification Panel -->
            <div class="notification-panel" role="menu" aria-hidden="true">
              <div class="notification-header">
                <h3>
                  <i class="fas fa-bell"></i>
                  Admin Notifications
                  <span class="notification-count">0</span>
                </h3>
                <button class="mark-all-read" aria-label="Mark all as read">
                  <i class="fas fa-check-double"></i> Mark all read
                </button>
              </div>
              <div class="notification-list">
                <div class="notification-loading">
                  <i class="fas fa-spinner fa-spin"></i>
                  <p>Loading notifications...</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
  <div class="toast-container"></div>
</div>

<style>
/* Main Header Styles */
.main-header {
  background-color: #ffffff;
  border-bottom: 1px solid #e5e7eb;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  position: sticky;
  top: 0;
  z-index: 1000;
  width: calc(98vw - var(--sidebar-width, 250px));
  transform: translateX(1px);
}

.header-container {
  display: flex;
  align-items: center;
  justify-content: space-between;
  max-width: 100%;
  padding: 1rem 2rem;
  height: 70px;
}

.header-left,
.header-center,
.header-right {
  flex: 1;
  display: flex;
  align-items: center;
}

.header-center {
  justify-content: center;
}

.header-right {
  justify-content: flex-end;
}

/* Notification section in header */
.sidebar-notification-section {
  position: relative;
}

/* Notification Bell Styles */
.notification-bell {
  position: relative;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #374151;
  text-decoration: none;
  padding: 0.75rem 1rem;
  transition: all 0.3s ease;
  font-weight: 500;
  font-size: 0.9rem;
  border: none;
  background: transparent;
  border-radius: 8px;
  min-width: 140px;
}

.notification-bell:hover {
  background-color: #f3f4f6;
  color: #111827;
}

.notification-bell:focus {
  outline: 2px solid #3b82f6;
  outline-offset: 2px;
}

.notification-bell i {
  font-size: 1.1rem;
  transition: transform 0.2s ease;
}

.notification-bell:hover i {
  transform: scale(1.1);
}

/* Notification Badge */
.notification-badge {
  position: absolute;
  top: 4px;
  right: 8px;
  background-color: #ef4444;
  color: white;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: bold;
  z-index: 1;
}

.notification-badge.hidden {
  display: none;
}

/* Notification Panel */
.notification-panel {
  display: none;
  position: absolute;
  top: 100%;
  right: 0;
  width: 350px;
  background-color: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
  z-index: 1001;
  margin-top: 0.5rem;
  color: #374151;
}

.notification-panel.active {
  display: block;
  animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Notification Header */
.notification-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-bottom: 1px solid #e5e7eb;
  background-color: #f9fafb;
  border-radius: 8px 8px 0 0;
}

.notification-header h3 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1rem;
  font-weight: 600;
  color: #111827;
}

.notification-count {
  background-color: #dc2626;
  color: white;
  border-radius: 12px;
  padding: 0.15rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
}

.mark-all-read {
  background: none;
  border: none;
  color: #dc2626;
  cursor: pointer;
  font-size: 0.8rem;
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  transition: all 0.2s ease;
  font-weight: 500;
}

.mark-all-read:hover {
  background-color: rgba(220, 38, 38, 0.1);
  color: #991b1b;
}

/* Notification List */
.notification-list {
  max-height: 300px;
  overflow-y: auto;
  padding: 0.5rem 0;
}

.notification-loading {
  padding: 2rem;
  text-align: center;
  color: #6b7280;
}

.notification-loading i {
  font-size: 1.5rem;
  margin-bottom: 0.5rem;
}

/* Toast Container */
.toast-container {
  position: fixed;
  top: 80px;
  right: 20px;
  z-index: 2000;
  pointer-events: none;
}

/* Responsive Design */
@media (max-width: 768px) {
  .main-header {
    width: 100%;
    margin-left: 0;
  }
  
  .header-container {
    padding: 1rem;
    height: 60px;
  }
  
  .notification-bell {
    min-width: auto;
    padding: 0.5rem;
  }
  
  .notification-bell span {
    display: none;
  }
  
  .notification-panel {
    width: 280px;
    right: -20px;
  }
}

@media (max-width: 480px) {
  .notification-panel {
    width: 260px;
    right: -40px;
  }
  
  .notification-header {
    padding: 0.75rem;
  }
  
  .notification-header h3 {
    font-size: 0.9rem;
  }
}


</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Dark mode toggle functionality
  const darkModeToggle = document.getElementById('darkModeToggle');
  const body = document.body;
  
  // Load saved dark mode preference
  const isDarkMode = localStorage.getItem('darkMode') === 'true';
  if (isDarkMode) {
    body.classList.add('dark-mode');
    updateDarkModeIcon(true);
  }
  
  if (darkModeToggle) {
    darkModeToggle.addEventListener('click', function() {
      body.classList.toggle('dark-mode');
      const isDark = body.classList.contains('dark-mode');
      localStorage.setItem('darkMode', isDark);
      updateDarkModeIcon(isDark);
      
      // Animation feedback
      this.style.transform = 'scale(1.2) rotate(180deg)';
      setTimeout(() => {
        this.style.transform = '';
      }, 200);
    });
  }
  
  function updateDarkModeIcon(isDark) {
    const icon = darkModeToggle?.querySelector('i');
    if (icon) {
      icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
  }
  
  // Notification bell functionality
  const notificationBell = document.querySelector('.notification-bell');
  const notificationPanel = document.querySelector('.notification-panel');
  
  if (notificationBell && notificationPanel) {
    // Toggle notification panel
    notificationBell.addEventListener('click', function(e) {
      e.stopPropagation();
      const isActive = notificationPanel.classList.contains('active');
      
      notificationPanel.classList.toggle('active');
      
      // Update ARIA attributes
      this.setAttribute('aria-expanded', !isActive);
      notificationPanel.setAttribute('aria-hidden', isActive);
      
      // Focus management
      if (!isActive) {
        // Panel is now open, focus first interactive element
        const firstButton = notificationPanel.querySelector('button');
        if (firstButton) {
          setTimeout(() => firstButton.focus(), 100);
        }
      }
    });
    
    // Close panel when clicking outside
    document.addEventListener('click', function(e) {
      if (!notificationBell.contains(e.target) && !notificationPanel.contains(e.target)) {
        closeNotificationPanel();
      }
    });
    
    // Handle "Mark all read" button
    const markAllReadBtn = document.querySelector('.mark-all-read');
    if (markAllReadBtn) {
      markAllReadBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        // Add your mark all read logic here
        console.log('Mark all notifications as read');
        closeNotificationPanel();
      });
    }
  }
  
  // Keyboard navigation
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (notificationPanel && notificationPanel.classList.contains('active')) {
        closeNotificationPanel();
        notificationBell.focus();
      }
    }
  });
  
  function closeNotificationPanel() {
    if (notificationPanel) {
      notificationPanel.classList.remove('active');
      notificationBell.setAttribute('aria-expanded', 'false');
      notificationPanel.setAttribute('aria-hidden', 'true');
    }
  }
  
  // Smooth hover effects
  const interactiveElements = document.querySelectorAll('.notification-bell, .mark-all-read');
  interactiveElements.forEach(element => {
    element.addEventListener('mouseenter', function() {
      this.style.transition = 'all 0.3s ease';
    });
  });
});
</script>