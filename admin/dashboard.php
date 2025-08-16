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
    'inventory' => $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity < 10")->fetchColumn(),
    'blood_banks' => $pdo->query("SELECT COUNT(*) FROM blood_banks")->fetchColumn(),
    'registrations' => $pdo->query("SELECT COUNT(*) FROM registrations WHERE registration_date = CURDATE()")->fetchColumn(),
    'training_sessions' => $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()")->fetchColumn(),
];

// Get recent activity for the dashboard
$recent_users = $pdo->query("SELECT username, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();
$upcoming_events = $pdo->query("SELECT title, event_date, location FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3")->fetchAll();
$recent_donations = $pdo->query("SELECT d.amount, r.name AS donor_name, d.donation_date 
                               FROM donations d
                               JOIN donors r ON d.donor_id = r.donor_id
                               ORDER BY d.donation_date DESC LIMIT 5")->fetchAll();
$recent_sessions = $pdo->query("SELECT title, major_service, session_date, start_time, venue FROM training_sessions WHERE session_date >= CURDATE() ORDER BY session_date ASC, start_time ASC LIMIT 5")->fetchAll();

// Get recent inventory items for demonstration table
$recent_inventory = $pdo->query("SELECT i.item_name, i.quantity, i.expiry_date, c.category_name, i.location 
                               FROM inventory_items i
                               LEFT JOIN categories c ON i.category_id = c.category_id
                               ORDER BY i.expiry_date ASC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard - PRC Portal</title>

  <!-- Apply saved sidebar state BEFORE CSS -->
  <?php $collapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true'; ?>
  <script>
    // Set sidebar width early to prevent flicker
    (function() {
      var collapsed = document.cookie.split('; ').find(row => row.startsWith('sidebarCollapsed='));
      var root = document.documentElement;
      if (collapsed && collapsed.split('=')[1] === 'true') {
        root.style.setProperty('--sidebar-width', '70px');
      } else {
        root.style.setProperty('--sidebar-width', '250px');
      }
    })();
  </script>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/compact_dashboard.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="dashboard-container">
      <div class="welcome-section">
        <div class="welcome-content">
          <h1>Welcome Back, <?= htmlspecialchars($username) ?></h1>
          <p>Here's what's happening today at your Philippine Red Cross portal.</p>
        </div>
        <div class="date-display">
          <div class="current-date">
            <i class="fas fa-calendar-day"></i>
            <?php echo date('F d, Y'); ?>
          </div>
        </div>
      </div>

      <!-- Compact Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon users">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['users']) ?></div>
            <div class="stat-label">System Users</div>
            <div class="stat-desc">Total registered</div>
          </div>
          <a href="manage_users.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon events">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['events']) ?></div>
            <div class="stat-label">Upcoming Events</div>
            <div class="stat-desc">This month</div>
          </div>
          <a href="manage_events.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon donations">
            <i class="fas fa-hand-holding-heart"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['donations']) ?></div>
            <div class="stat-label">Today's Donations</div>
            <div class="stat-desc">Received today</div>
          </div>
          <a href="manage_donations.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card warning">
          <div class="stat-icon inventory warning">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value warning"><?= number_format($stats['inventory']) ?></div>
            <div class="stat-label">Low Stock Alert</div>
            <div class="stat-desc">Below 10 units</div>
          </div>
          <a href="manage_inventory.php" class="stat-link urgent">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon blood-banks">
            <i class="fas fa-hospital"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['blood_banks']) ?></div>
            <div class="stat-label">Blood Banks</div>
            <div class="stat-desc">Active locations</div>
          </div>
          <a href="manage_blood_banks.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon registrations">
            <i class="fas fa-clipboard-list"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['registrations']) ?></div>
            <div class="stat-label">New Registrations</div>
            <div class="stat-desc">Today's sign-ups</div>
          </div>
          <a href="view_registrations.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon training">
            <i class="fas fa-graduation-cap"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['training_sessions']) ?></div>
            <div class="stat-label">Training Sessions</div>
            <div class="stat-desc">Upcoming</div>
          </div>
          <a href="manage_sessions.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>
      </div>

      <div class="dashboard-main">
        <!-- Quick Actions Section -->
        <div class="quick-actions-section">
          <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
          <div class="action-buttons">
            <a href="manage_events.php" class="action-btn">
              <i class="fas fa-plus-circle"></i>
              <span>Add New Event</span>
            </a>
            <a href="manage_users.php" class="action-btn">
              <i class="fas fa-user-plus"></i>
              <span>Create User</span>
            </a>
            <a href="manage_announcements.php?action=create" class="action-btn">
              <i class="fas fa-bullhorn"></i>
              <span>Post Announcement</span>
            </a>
            <a href="manage_donations.php?action=create" class="action-btn">
              <i class="fas fa-donate"></i>
              <span>Record Donation</span>
            </a>
            <a href="manage_inventory.php?action=add" class="action-btn">
              <i class="fas fa-box-open"></i>
              <span>Add Inventory</span>
            </a>
            <a href="manage_blood_banks.php" class="action-btn">
              <i class="fas fa-hospital"></i>
              <span>Blood Banks</span>
            </a>
            <a href="view_registrations.php" class="action-btn">
              <i class="fas fa-clipboard-list"></i>
              <span>View Registrations</span>
            </a>
            <a href="manage_sessions.php" class="action-btn">
              <i class="fas fa-graduation-cap"></i>
              <span>Training Sessions</span>
            </a>
          </div>
        </div>

        <!-- Recent Activity Section -->
        <div class="recent-activity-section">
          <h2><i class="fas fa-history"></i> Recent Activity</h2>
          
          <!-- Recent Users -->
          <div class="activity-card">
            <div class="activity-header">
              <h3>Latest Users</h3>
              <a href="manage_users.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($recent_users)): ?>
                <ul class="activity-list">
                  <?php foreach ($recent_users as $user): ?>
                    <li class="activity-item">
                      <div class="activity-icon user-icon">
                        <i class="fas fa-user"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($user['username']) ?></div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($user['created_at'])) ?></div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="no-data">No recent users</p>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Upcoming Events -->
          <div class="activity-card">
            <div class="activity-header">
              <h3>Upcoming Events</h3>
              <a href="manage_events.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($upcoming_events)): ?>
                <ul class="activity-list">
                  <?php foreach ($upcoming_events as $event): ?>
                    <li class="activity-item">
                      <div class="activity-icon event-icon">
                        <i class="fas fa-calendar"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($event['title']) ?></div>
                        <div class="activity-meta">
                          <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['location']) ?></span>
                          <span class="date"><i class="fas fa-calendar-day"></i> <?= date('M d, Y', strtotime($event['event_date'])) ?></span>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="no-data">No upcoming events</p>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Recent Donations -->
          <div class="activity-card">
            <div class="activity-header">
              <h3>Recent Donations</h3>
              <a href="manage_donations.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($recent_donations)): ?>
                <ul class="activity-list">
                  <?php foreach ($recent_donations as $donation): ?>
                    <li class="activity-item">
                      <div class="activity-icon donation-icon">
                        <i class="fas fa-hand-holding-heart"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main">₱<?= number_format($donation['amount'], 2) ?> from <?= htmlspecialchars($donation['donor_name']) ?></div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($donation['donation_date'])) ?></div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="no-data">No recent donations</p>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Recent Training Sessions -->
          <div class="activity-card">
            <div class="activity-header">
              <h3>Upcoming Training Sessions</h3>
              <a href="manage_sessions.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($recent_sessions)): ?>
                <ul class="activity-list">
                  <?php foreach ($recent_sessions as $session): ?>
                    <li class="activity-item">
                      <div class="activity-icon session-icon">
                        <i class="fas fa-graduation-cap"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($session['title']) ?></div>
                        <div class="activity-meta">
                          <span class="service"><i class="fas fa-cog"></i> <?= htmlspecialchars($session['major_service']) ?></span>
                          <span class="date"><i class="fas fa-calendar-day"></i> <?= date('M d, Y', strtotime($session['session_date'])) ?> at <?= date('g:i A', strtotime($session['start_time'])) ?></span>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="no-data">No upcoming training sessions</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>