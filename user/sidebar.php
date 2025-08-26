<?php
// Clean sidebar without notification badges
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

    <!-- Collapse button -->
    <button class="collapse-btn" id="sidebarCollapseBtn">
        <i class="fas fa-chevron-left"></i>
        <i class="fas fa-bars"></i>
    </button>
</aside>

