<aside class="sidebar">
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

    <nav class="sidebar-nav">
      <a href="dashboard.php" class="nav-link">
        <i class="fas fa-home"></i>
        <span class="link-text">Dashboard</span>
      </a>
      <a href="registration.php" class="nav-link">
        <i class="fas fa-calendar-check"></i>
        <span class="link-text">Event Registration</span>
      </a>
      <a href="schedule.php" class="nav-link">
        <i class="fas fa-calendar-alt"></i>
        <span class="link-text">Training Schedule</span>
      </a>
      <a href="blood_map.php" class="nav-link">
        <i class="fas fa-map-marker-alt"></i>
        <span class="link-text">Blood Map</span>
      </a>
      <a href="donate.php" class="nav-link">
        <i class="fas fa-hand-holding-heart"></i>
        <span class="link-text">Donate</span>
      </a>
      <a href="announcements.php" class="nav-link">
        <i class="fas fa-bullhorn"></i>
        <span class="link-text">Announcements</span>
      </a>
      <a href="../logout.php" class="nav-link logout-link">
        <i class="fas fa-sign-out-alt"></i>
        <span class="link-text">Logout</span>
      </a>
    </nav>

    <!-- Collapse button with both icons -->
    <button class="collapse-btn">
      <i class="fas fa-chevron-left"></i>
      <i class="fas fa-bars"></i>
    </button>
  </div>
</aside>