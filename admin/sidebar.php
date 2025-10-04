<aside class="sidebar">   
  <div class="sidebar-header">     
    <div class="logo-title">       
      <a href="../index.php">       
        <img src="../assets/images/logo.png" alt="PRC Logo" class="prc-logo">       
      </a>       
      <div>         
        <h1>PRC Admin</h1>         
        <p>Management Portal</p>       
      </div>     
    </div>          
    <div class="user-info">       
      <i class="fas fa-user-shield"></i>        
      <span><?= htmlspecialchars(current_username()) ?></span>     
    </div>   
  </div>     
  <nav class="sidebar-nav">     
    <a href="dashboard.php" class="nav-link">       
      <i class="fas fa-tachometer-alt"></i>       
      <span>Dashboard</span>     
    </a>     
    <a href="manage_users.php" class="nav-link">       
      <i class="fas fa-users-cog"></i>       
      <span>User Management</span>     
    </a>     
    <a href="manage_volunteers.php" class="nav-link">
      <i class="fas fa-hands-helping"></i>
      <span>Volunteer Management</span>
    </a>
    <a href="manage_events.php" class="nav-link">       
      <i class="fas fa-calendar-alt"></i>       
      <span>Events</span>     
    </a>     
    <div class="nav-group">       
      <a href="manage_sessions.php" class="nav-link">         
        <i class="fas fa-chalkboard-teacher"></i>         
        <span>Training Sessions</span>       
      </a>     
    </div>
    <a href="training_request.php" class="nav-link">  
      <i class="fa-solid fa-clipboard"></i>
      <span class="link-text">Training Request</span>
    </a>
    <a href="manage_merch.php" class="nav-link">  
      <i class="fas fa-store"></i>
      <span class="link-text">Manage Merch</span>
    </a>
    <a href="manage_donations.php" class="nav-link">       
      <i class="fas fa-donate"></i>       
      <span>Donations</span>     
    </a>     
    <a href="manage_inventory.php" class="nav-link">       
      <i class="fas fa-warehouse"></i>       
      <span>Inventory</span>     
    </a>     
    <a href="manage_announcements.php" class="nav-link">       
      <i class="fas fa-bullhorn"></i>       
      <span>Announcements</span>     
    </a>     
    <a href="../logout.php" class="nav-link logout-link">       
      <i class="fas fa-sign-out-alt"></i>       
      <span>Logout</span>     
    </a>   
  </nav>    
  <button class="collapse-btn">       
    <i class="fas fa-chevron-left"></i>       
    <i class="fas fa-bars"></i>     
  </button>   
  <script src="js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
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