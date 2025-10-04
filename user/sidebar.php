<?php
// User sidebar with mobile FAB
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo-title">
            <a href="../index.php">
                <img src="../assets/images/logo.png" alt="PRC Logo" class="prc-logo">
            </a>
            <div class="title-text">
                <h1>Philippine Red Cross</h1>
                <p>User Portal</p>
            </div>
        </div>
        
        <div class="user-info">
            <i class="fas fa-user-circle"></i>
            <span class="user-name"><?= htmlspecialchars(current_username()) ?></span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-home"></i>
            <span class="link-text">Dashboard</span>
        </a>
        
        <a href="registration.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'registration.php' ? 'active' : '' ?>">
            <i class="fas fa-calendar-check"></i>
            <span class="link-text">Event Registration</span>
        </a>
        
        <a href="schedule.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'schedule.php' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt"></i>
            <span class="link-text">Training Schedule</span>
        </a>
        
        <a href="merch.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'merch.php' ? 'active' : '' ?>">
            <i class="fas fa-store"></i>
            <span class="link-text">Merch</span>
        </a>
        
        <a href="donate.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'donate.php' ? 'active' : '' ?>">
            <i class="fas fa-hand-holding-heart"></i>
            <span class="link-text">Donate</span>
        </a>
        
        <a href="announcements.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'announcements.php' ? 'active' : '' ?>">
            <i class="fas fa-bullhorn"></i>
            <span class="link-text">Announcements</span>
        </a>
        
        <a href="../logout.php" class="nav-link logout-link">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </a>
    </nav>

    <!-- Desktop Collapse button -->
    <button class="collapse-btn" id="sidebarCollapseBtn">
        <i class="fas fa-chevron-left"></i>
        <i class="fas fa-bars"></i>
    </button>
</aside>

<!-- Mobile Menu FAB -->
<button class="mobile-menu-fab" id="mobileMenuFab">
  <i class="fas fa-bars"></i>
  <span class="fab-notification-badge hidden" id="fabNotificationBadge">3</span>
</button>

<!-- Mobile Overlay -->
<div class="sidebar-mobile-overlay" id="sidebarOverlay"></div>

<script>
// Mobile menu toggle functionality
document.addEventListener('DOMContentLoaded', function() {
  const sidebar = document.querySelector('.sidebar');
  const mobileFab = document.getElementById('mobileMenuFab');
  const overlay = document.getElementById('sidebarOverlay');
  
  function toggleSidebar() {
    sidebar.classList.toggle('expanded');
    document.body.classList.toggle('sidebar-expanded');
  }
  
  mobileFab?.addEventListener('click', toggleSidebar);
  overlay?.addEventListener('click', toggleSidebar);
  
  // Close sidebar when clicking a nav link on mobile
  const navLinks = document.querySelectorAll('.nav-link');
  navLinks.forEach(link => {
    link.addEventListener('click', function() {
      if (window.innerWidth <= 768) {
        toggleSidebar();
      }
    });
  });
  
  // Update FAB notification badge from actual notifications
  // This should be connected to your notification system
  function updateFabBadge(count) {
    const badge = document.getElementById('fabNotificationBadge');
    if (badge) {
      if (count > 0) {
        badge.textContent = count > 9 ? '9+' : count;
        badge.classList.remove('hidden');
      } else {
        badge.classList.add('hidden');
      }
    }
  }
  
  // Example: Update badge based on your notification system
  // updateFabBadge(3);
});
</script>