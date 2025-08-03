<aside class="sidebar">
  <div class="sidebar-header">
     <div class="logo-title">
      <a href="../index.php">
            <img src="../assets/logo.png" alt="PRC Logo" class="prc-logo">
            </a>
      <div>
        <h1>Philippine Red Cross</h1>
        <p>User Portal</p>
      </div>
    </div>
    
    <div class="user-info">
      <i class="fas fa-user-circle"></i>
      <span><?= htmlspecialchars(current_username()) ?></span>
    </div>
  </div>
  
  <nav class="sidebar-nav">
    <a href="dashboard.php" class="nav-link">
      <i class="fas fa-home"></i>
      <span>Dashboard</span>
    </a>
    <a href="registration.php" class="nav-link">
      <i class="fas fa-calendar-check"></i>
      <span>Event Registration</span>
    </a>
    <a href="schedule.php" class="nav-link">
      <i class="fas fa-calendar-alt"></i>
      <span>Training Schedule</span>
    </a>
    <a href="blood_map.php" class="nav-link">
      <i class="fas fa-map-marker-alt"></i>
      <span>Blood Map</span>
    </a>
    <a href="donate.php" class="nav-link">
      <i class="fas fa-hand-holding-heart"></i>
      <span>Donate</span>
    </a>
    <a href="announcements.php" class="nav-link">
      <i class="fas fa-bullhorn"></i>
      <span>Announcements</span>
    </a>
    <a href="../logout.php" class="nav-link logout-link">
      <i class="fas fa-sign-out-alt"></i>
      <span>Logout</span>
    </a>
  </nav>
</aside>