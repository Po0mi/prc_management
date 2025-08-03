<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$username = current_username();
$pdo = $GLOBALS['pdo'];


$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'events' => $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn(),
    'donations' => $pdo->query("SELECT COUNT(*) FROM donations WHERE donation_date = CURDATE()")->fetchColumn(),
    'inventory' => $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity < 10")->fetchColumn()
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css">
  <link rel="stylesheet" href="../assets/sidebar.css">
  <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="dashboard-container">
      <div class="welcome-section">
        <h1>Administrator Dashboard</h1>
        <p>Welcome back, <strong><?= htmlspecialchars($username) ?></strong>. Here's what's happening today.</p>
      </div>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <h3>Users</h3>
            <span class="stat-value"><?= $stats['users'] ?></span>
          </div>
          <a href="manage_users.php" class="stat-link">View All <i class="fas fa-arrow-right"></i></a>
        </div>

        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="stat-info">
            <h3>Upcoming Events</h3>
            <span class="stat-value"><?= $stats['events'] ?></span>
          </div>
          <a href="manage_events.php" class="stat-link">View All <i class="fas fa-arrow-right"></i></a>
        </div>

        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-hand-holding-heart"></i>
          </div>
          <div class="stat-info">
            <h3>Today's Donations</h3>
            <span class="stat-value"><?= $stats['donations'] ?></span>
          </div>
          <a href="manage_donations.php" class="stat-link">View All <i class="fas fa-arrow-right"></i></a>
        </div>

        <div class="stat-card warning">
          <div class="stat-icon">
            <i class="fas fa-boxes"></i>
          </div>
          <div class="stat-info">
            <h3>Low Stock Items</h3>
            <span class="stat-value"><?= $stats['inventory'] ?></span>
          </div>
          <a href="manage_inventory.php" class="stat-link">View All <i class="fas fa-arrow-right"></i></a>
        </div>
      </div>

      <div class="quick-actions">
        <h2>Quick Actions</h2>
        <div class="action-buttons">
          <a href="manage_events.php" class="action-btn">
            <i class="fas fa-plus-circle"></i>
            <span>Add New Event</span>
          </a>
          <a href="manage_user.php" class="action-btn">
            <i class="fas fa-user-plus"></i>
            <span>Create User</span>
          </a>
          <a href="manage_announcements.php?action=create" class="action-btn">
            <i class="fas fa-bullhorn"></i>
            <span>Post Announcement</span>
          </a>
          <a href="manage_inventory.php?action=add" class="action-btn">
            <i class="fas fa-box-open"></i>
            <span>Add Inventory</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>