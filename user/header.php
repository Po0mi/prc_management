<?php
// Simple header without notifications functionality
?>

<header class="top-header">
  <div class="header-left">
    <div class="page-title">
      <h2>User Dashboard</h2>
    </div>
  </div>
  
  <div class="header-center">
    <div class="date-display">
      <div class="current-date">
        <i class="fas fa-calendar-day"></i>
        <?php echo date('F d, Y'); ?>
      </div>
      <div class="live-indicator">
        <i class="fas fa-circle" id="liveIndicator"></i>
        <span>Live Dashboard</span>
      </div>
    </div>
  </div>
  
  <div class="header-right">
    <!-- Notification Bell -->
    <div class="notification-bell" role="button" tabindex="0" aria-label="Notifications" aria-expanded="false">
      <i class="fas fa-bell"></i>
      <span class="notification-badge hidden">0</span>
      
      <!-- Notification Panel -->
      <div class="notification-panel" role="menu" aria-hidden="true">
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
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading notifications...</p>
          </div>
        </div>
      </div>
    </div>
    <div class="user-actions">
      <button title="Toggle Dark Mode" id="darkModeToggle">
        <i class="fas fa-moon"></i>
      </button>
    </div>
  </div>
  <div class="toast-container"></div>
</header>

<style>
/* Header Styles */
:root {
  --bg-color: #ffffff;
  --text-color: #333333;
  --header-bg: #f8f9fa;
  --prc-red: #a00000;
  --card-bg: white;
  --spacing-lg: 1rem;
  --spacing-2xl: 2rem;
  --spacing-base: 0.5rem;
  --spacing-xl: 1.5rem;
  --spacing-sm: 0.25rem;
  --font-size-2xl: 1.5rem;
  --font-size-xl: 1.25rem;
  --font-size-sm: 0.875rem;
  --white: #ffffff;
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
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
}

.top-header {
  background-color: var(--header-bg);
  padding: 0.75rem 1.5rem;
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  align-items: center;
  border-bottom: 1px solid #e0e0e0;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
  width: 100%;
  box-sizing: border-box;
  position: relative;
}

.dark-mode .top-header {
  border-bottom-color: #444;
}

.header-left {
  display: flex;
  justify-content: flex-start;
  align-items: center;
}

.header-center {
  display: flex;
  justify-content: center;
  align-items: center;
}

.header-right {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 1rem;
}

.page-title h2 {
  margin: 0;
  color: var(--prc-red);
  font-size: 1.5rem;
  font-weight: 600;
}

.date-display {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
}

.current-date {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  color: var(--text-color);
  font-weight: 600;
}

.live-indicator {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.8rem;
  color: var(--prc-red);
  justify-content: center;
}

#liveIndicator {
  color: var(--prc-red);
  font-size: 0.5rem;
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0% { opacity: 1; }
  50% { opacity: 0.5; }
  100% { opacity: 1; }
}

.notification-bell {
  position: relative;
  cursor: pointer;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s ease;
  color: var(--text-color);
}

.notification-bell:hover {
  background-color: rgba(160, 0, 0, 0.1);
  color: var(--prc-red);
}

.notification-badge {
  position: absolute;
  top: 3px;
  right: 3px;
  background-color: var(--prc-red);
  color: white;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: bold;
}

.notification-badge.hidden {
  display: none;
}

.notification-panel {
  display: none;
  position: absolute;
  top: 100%;
  right: 0;
  background-color: var(--card-bg);
  border: 1px solid #ddd;
  border-radius: 8px;
  width: 320px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  z-index: 1000;
  margin-top: 0.5rem;
}

.notification-bell:focus-within .notification-panel,
.notification-bell:hover .notification-panel {
  display: block;
}

.notification-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  border-bottom: 1px solid #eee;
}

.notification-header h3 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1rem;
  color: var(--text-color);
}

.notification-count {
  background-color: var(--prc-red);
  color: white;
  border-radius: 10px;
  padding: 0.15rem 0.5rem;
  font-size: 0.8rem;
}

.mark-all-read {
  background: none;
  border: none;
  color: var(--prc-red);
  cursor: pointer;
  font-size: 0.8rem;
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  transition: background-color 0.2s;
}

.mark-all-read:hover {
  background-color: rgba(160, 0, 0, 0.1);
}

.notification-list {
  max-height: 300px;
  overflow-y: auto;
}

.notification-loading {
  padding: 2rem;
  text-align: center;
  color: #777;
}

.user-actions {
  display: flex;
  gap: 0.75rem;
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
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.user-actions button:hover {
  background-color: rgba(160, 0, 0, 0.1);
  color: var(--prc-red);
  transform: scale(1.05);
}

/* Dark mode toggle specific styles */
#darkModeToggle {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  border: 2px solid transparent;
}

#darkModeToggle:hover {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  transform: scale(1.1) rotate(15deg);
}

.dark-mode #darkModeToggle {
  background: linear-gradient(135deg, #4a5568, #2d3748);
  color: #ffd700;
}

.dark-mode #darkModeToggle:hover {
  background: linear-gradient(135deg, #2d3748, #4a5568);
  color: #ffed4e;
}

.toast-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 2000;
}

/* Responsive Design */
@media (max-width: 768px) {
  .top-header {
    padding: 0.75rem 1rem;
    grid-template-columns: auto 1fr auto;
    gap: 1rem;
  }

  .page-title h2 {
    font-size: 1.25rem;
  }

  .header-center {
    order: -1;
    grid-column: 1 / -1;
    margin-bottom: 0.5rem;
  }

  .date-display {
    flex-direction: row;
    gap: 1rem;
  }

  .user-actions button {
    font-size: 1rem;
    width: 36px;
    height: 36px;
  }
  
  .notification-panel {
    width: 280px;
    right: -10px;
  }
}

@media (max-width: 576px) {
  .top-header {
    grid-template-columns: 1fr auto;
    gap: 0.5rem;
  }
  
  .header-center {
    display: none;
  }
  
  .notification-bell {
    width: 36px;
    height: 36px;
    font-size: 1.1rem;
  }
}
</style>

<script>
// Dark mode toggle functionality
document.addEventListener('DOMContentLoaded', function() {
  const darkModeToggle = document.getElementById('darkModeToggle');
  const body = document.body;
  
  // Check for saved dark mode preference or default to light mode
  const savedTheme = localStorage.getItem('theme');
  if (savedTheme === 'dark') {
    body.classList.add('dark-mode');
    darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
  }
  
  darkModeToggle.addEventListener('click', function() {
    body.classList.toggle('dark-mode');
    
    if (body.classList.contains('dark-mode')) {
      localStorage.setItem('theme', 'dark');
      this.innerHTML = '<i class="fas fa-sun"></i>';
    } else {
      localStorage.setItem('theme', 'light');
      this.innerHTML = '<i class="fas fa-moon"></i>';
    }
  });
});
</script>