<?php

?>
<aside class="sidebar">
  <div class="sidebar-header">
    <div class="logo-title">
      <a href="../index.php">
      <img src="../assets/logo.png" alt="PRC Logo" class="prc-logo">
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
    <a href="manage_events.php" class="nav-link">
      <i class="fas fa-calendar-alt"></i>
      <span>Events</span>
    </a>
    <div class="nav-group">
      <a href="manage_sessions.php" class="nav-link">
        <i class="fas fa-chalkboard-teacher"></i>
        <span>Training Sessions</span>
      </a>
      <a href="view_registrations.php" class="nav-link sub-item">
        <i class="fas fa-user-check"></i>
        <span>View Registrations</span>
      </a>
    </div>
    <a href="manage_donations.php" class="nav-link">
      <i class="fas fa-donate"></i>
      <span>Donations</span>
    </a>
    <a href="manage_inventory.php" class="nav-link">
      <i class="fas fa-warehouse"></i>
      <span>Inventory</span>
    </a>
    <a href="manage_blood_banks.php" class="nav-link">
      <i class="fas fa-tint"></i>
      <span>Blood Banks</span>
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
  </div>
</aside>