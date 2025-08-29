<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$username = current_username();
$pdo = $GLOBALS['pdo'];

// Get user role from database
$user_role = get_user_role();
if (!$user_role) {
    $user_role = 'super'; // Default fallback
}

// All admin roles are now unrestricted
$is_unrestricted = true;

// Get role-specific stats using the config function - always use 'super' for unrestricted access
$stats = get_role_dashboard_stats('super');

// Get role-specific recent activity
function getRoleActivity($pdo, $role, $is_unrestricted) {
    $activity = [];
    
    try {
        // All admins now have unrestricted access - show everything
        $stmt = $pdo->query("SELECT title, event_date, location FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3");
        $activity['events'] = $stmt ? $stmt->fetchAll() : [];
        
        $stmt = $pdo->query("SELECT title, major_service, session_date, start_time, venue FROM training_sessions WHERE session_date >= CURDATE() ORDER BY session_date ASC LIMIT 5");
        $activity['sessions'] = $stmt ? $stmt->fetchAll() : [];
        
        $stmt = $pdo->query("SELECT username, created_at FROM users ORDER BY created_at DESC LIMIT 5");
        $activity['users'] = $stmt ? $stmt->fetchAll() : [];
        
        try {
            $stmt = $pdo->query("SELECT d.amount, r.name AS donor_name, d.donation_date FROM donations d JOIN donors r ON d.donor_id = r.donor_id ORDER BY d.donation_date DESC LIMIT 5");
            $activity['donations'] = $stmt ? $stmt->fetchAll() : [];
            
            $stmt = $pdo->query("SELECT i.item_name, i.quantity, i.expiry_date, c.category_name, i.location FROM inventory_items i LEFT JOIN categories c ON i.category_id = c.category_id ORDER BY i.expiry_date ASC LIMIT 5");
            $activity['inventory'] = $stmt ? $stmt->fetchAll() : [];
        } catch (PDOException $e) {
            $activity['donations'] = [];
            $activity['inventory'] = [];
        }
    } catch (PDOException $e) {
        error_log("Error in getRoleActivity: " . $e->getMessage());
        $activity = [
            'events' => [],
            'sessions' => [],
            'users' => [],
            'donations' => [],
            'inventory' => []
        ];
    }
    
    return $activity;
}

$activity = getRoleActivity($pdo, $user_role, $is_unrestricted);

// Get pending registrations count
try {
    $pending_registrations = $pdo->query("SELECT COUNT(*) FROM registrations WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    $pending_registrations = 0;
}

// Announcements - show all for all admins
try {
    $stmt = $pdo->query("
        SELECT title, content, created_at 
        FROM announcements 
        WHERE posted_at IS NOT NULL
        ORDER BY posted_at DESC 
        LIMIT 3
    ");
    $announcements = $stmt ? $stmt->fetchAll() : [];
} catch (PDOException $e) {
    error_log("Error fetching announcements: " . $e->getMessage());
    $announcements = [];
}

// Role display information
$role_info = get_role_info($user_role);
$role_display = $role_info['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($role_display) ?> Dashboard - PRC Portal</title>

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
  <link rel="stylesheet" href="../assets/admin_dashboard_redesigned.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/role_based_admin.css?v=<?php echo time(); ?>">
</head>
<body class="admin-<?= htmlspecialchars($user_role) ?>">
  <?php include 'sidebar.php'; ?>
  
  <div class="header-content">

    
    <div class="dashboard-container">
      <!-- Welcome Section -->
      <div class="welcome-section">
        <div class="welcome-content">
          <h1>Welcome back, <?= htmlspecialchars($username) ?>!</h1>
          <?php if ($user_role === 'safety'): ?>
            <p>Manage Safety Services operations, training programs, and ensure workplace safety compliance across all PRC activities.</p>
          <?php elseif ($user_role === 'welfare'): ?>
            <p>Oversee Welfare Services programs, community outreach, and social welfare initiatives to support vulnerable populations.</p>
          <?php elseif ($user_role === 'health'): ?>
            <p>Coordinate Health Services, blood bank operations, and medical emergency response programs for community health.</p>
          <?php elseif ($user_role === 'disaster'): ?>
            <p>Lead Disaster Management operations, emergency preparedness, and coordinate relief efforts during critical situations.</p>
          <?php elseif ($user_role === 'youth'): ?>
            <p>Guide Red Cross Youth programs, volunteer development, and youth leadership initiatives for future humanitarian leaders.</p>
          <?php else: ?>
            <p>Manage your Philippine Red Cross portal efficiently. Monitor activities, oversee operations, and ensure seamless service delivery.</p>
          <?php endif; ?>
        </div>
        <div class="date-display">
          <div class="role-badge"><?= htmlspecialchars(strtoupper($user_role)) ?> ADMIN</div>
          <div class="current-date">
            <i class="fas fa-calendar-day"></i>
            <?php echo date('F d, Y'); ?>
          </div>
        </div>
      </div>

      <!-- Role-specific notifications -->
      <?php if ($pending_registrations > 0): ?>
        <div class="notification-banner warning role-common">
          <div class="notification-icon">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="notification-content">
            <h3>Pending Registrations</h3>
            <p>You have <strong><?= $pending_registrations ?></strong> registration<?= $pending_registrations > 1 ? 's' : '' ?> waiting for approval.</p>
          </div>
          <button class="notification-close" onclick="this.parentElement.style.display='none'">
            <i class="fas fa-times"></i>
          </button>
        </div>
      <?php endif; ?>

      <?php if (isset($stats['inventory']) && $stats['inventory'] > 0): ?>
        <div class="notification-banner warning">
          <div class="notification-icon">
            <i class="fas fa-box-open"></i>
          </div>
          <div class="notification-content">
            <h3>Low Stock Alert</h3>
            <p><strong><?= $stats['inventory'] ?></strong> inventory item<?= $stats['inventory'] > 1 ? 's' : '' ?> are running low (below 10 units). Consider restocking soon.</p>
          </div>
          <button class="notification-close" onclick="this.parentElement.style.display='none'">
            <i class="fas fa-times"></i>
          </button>
        </div>
      <?php endif; ?>

      <!-- Stats Grid -->
      <div class="stats-grid">
        <!-- Common Stats -->
        <div class="stat-card">
          <div class="stat-icon users">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['users'] ?? 0) ?></div>
            <div class="stat-label">System Users</div>
            <div class="stat-desc">Total registered</div>
          </div>
          <a href="manage_users.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <!-- Events Stats -->
        <?php if (isset($stats['events'])): ?>
        <div class="stat-card">
          <div class="stat-icon events">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['events']) ?></div>
            <div class="stat-label">All Events</div>
            <div class="stat-desc">Upcoming</div>
          </div>
          <a href="manage_events.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>
        <?php endif; ?>

        <!-- Donations Stats -->
        <?php if (isset($stats['donations'])): ?>
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
        <?php endif; ?>

        <!-- Blood Banks Stats -->
        <?php if (isset($stats['blood_banks'])): ?>
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
        <?php endif; ?>

        <!-- Inventory Stats -->
        <?php if (isset($stats['inventory'])): ?>
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
        <?php endif; ?>

        <!-- Training Sessions Stats -->
        <?php if (isset($stats['training_sessions'])): ?>
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
        <?php endif; ?>

        <!-- Registrations Stats -->
        <?php if (isset($stats['registrations'])): ?>
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
        <?php endif; ?>
      </div>

      <!-- REDESIGNED LAYOUT: Recent Activity First (Left), Quick Actions Second (Right) -->
      <div class="dashboard-main">
        <!-- Recent Activity Section - All admins now see all content -->
        <div class="recent-activity-section priority-section">
          <div class="section-header">
            <h2><i class="fas fa-history"></i> Recent Activity</h2>
            <span class="section-badge"><?= strtoupper($user_role) ?></span>
          </div>
          
          <!-- Events -->
          <?php if (!empty($activity['events'])): ?>
          <div class="activity-card featured">
            <div class="activity-header">
              <h3><i class="fas fa-calendar"></i> Upcoming Events</h3>
              <a href="manage_events.php" class="view-all">Manage All</a>
            </div>
            <div class="activity-body">
              <ul class="activity-list">
                <?php foreach ($activity['events'] as $event): ?>
                  <li class="activity-item enhanced">
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
                    <div class="activity-action">
                      <i class="fas fa-chevron-right"></i>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
          <?php endif; ?>
          
          <!-- Training Sessions -->
          <?php if (!empty($activity['sessions'])): ?>
          <div class="activity-card featured">
            <div class="activity-header">
              <h3><i class="fas fa-graduation-cap"></i> Training Sessions</h3>
              <a href="manage_sessions.php" class="view-all">Manage All</a>
            </div>
            <div class="activity-body">
              <ul class="activity-list">
                <?php foreach ($activity['sessions'] as $session): ?>
                  <li class="activity-item enhanced">
                    <div class="activity-icon training-icon">
                      <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="activity-details">
                      <div class="activity-main"><?= htmlspecialchars($session['title']) ?></div>
                      <div class="activity-meta">
                        <span class="service"><i class="fas fa-cog"></i> <?= htmlspecialchars($session['major_service'] ?? 'Training') ?></span>
                        <span class="date"><i class="fas fa-calendar-day"></i> <?= date('M d, Y', strtotime($session['session_date'])) ?> at <?= date('g:i A', strtotime($session['start_time'])) ?></span>
                      </div>
                    </div>
                    <div class="activity-action">
                      <i class="fas fa-chevron-right"></i>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
          <?php endif; ?>

          <!-- Recent Users -->
          <?php if (!empty($activity['users'])): ?>
          <div class="activity-card">
            <div class="activity-header">
              <h3><i class="fas fa-users"></i> Latest Users</h3>
              <a href="manage_users.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <ul class="activity-list">
                <?php foreach ($activity['users'] as $user): ?>
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
            </div>
          </div>
          <?php endif; ?>

          <!-- Recent Donations -->
          <?php if (!empty($activity['donations'])): ?>
          <div class="activity-card">
            <div class="activity-header">
              <h3><i class="fas fa-hand-holding-heart"></i> Recent Donations</h3>
              <a href="manage_donations.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <ul class="activity-list">
                <?php foreach ($activity['donations'] as $donation): ?>
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
            </div>
          </div>
          <?php endif; ?>

          <!-- Inventory Items -->
          <?php if (!empty($activity['inventory'])): ?>
          <div class="activity-card">
            <div class="activity-header">
              <h3><i class="fas fa-boxes"></i> Inventory Status</h3>
              <a href="manage_inventory.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <ul class="activity-list">
                <?php foreach ($activity['inventory'] as $item): ?>
                  <li class="activity-item">
                    <div class="activity-icon inventory-icon" style="background: linear-gradient(135deg, #fd7e14 0%, #e83e8c 100%);">
                      <i class="fas fa-box"></i>
                    </div>
                    <div class="activity-details">
                      <div class="activity-main"><?= htmlspecialchars($item['item_name']) ?></div>
                      <div class="activity-meta">
                        <span class="quantity"><i class="fas fa-cubes"></i> Qty: <?= $item['quantity'] ?></span>
                        <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location']) ?></span>
                      </div>
                      <div class="activity-time">Expires: <?= date('M d, Y', strtotime($item['expiry_date'])) ?></div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
          <?php endif; ?>

          <!-- Recent Announcements -->
          <?php if (!empty($announcements)): ?>
          <div class="activity-card">
            <div class="activity-header">
              <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
              <a href="manage_announcements.php" class="view-all">Manage All</a>
            </div>
            <div class="activity-body">
              <ul class="activity-list">
                <?php foreach ($announcements as $announcement): ?>
                  <li class="activity-item">
                    <div class="activity-icon announcement-icon">
                      <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="activity-details">
                      <div class="activity-main"><?= htmlspecialchars($announcement['title']) ?></div>
                      <div class="activity-content">
                        <?= htmlspecialchars(substr($announcement['content'], 0, 80)) ?><?= strlen($announcement['content']) > 80 ? '...' : '' ?>
                      </div>
                      <div class="activity-time"><?= date('M d, Y', strtotime($announcement['created_at'])) ?></div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
          <?php endif; ?>

          <!-- No Activity Message -->
          <?php if (empty($activity['events']) && empty($activity['sessions']) && empty($activity['users']) && empty($activity['donations']) && empty($activity['inventory']) && empty($announcements)): ?>
          <div class="activity-card">
            <div class="activity-body">
              <div class="no-data">
                <i class="fas fa-calendar-alt"></i>
                <p>No recent activity to display</p>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Quick Actions Section -->
        <div class="quick-actions-section secondary-section">
          <div class="section-header">
            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
          </div>
          <div class="action-buttons">
            <!-- Common Actions -->
            <a href="manage_users.php" class="action-btn">
              <i class="fas fa-user-plus"></i>
              <span>Create User</span>
              <div class="action-desc">Add user account</div>
            </a>
            
            <a href="manage_events.php" class="action-btn primary">
              <i class="fas fa-plus-circle"></i>
              <span>Add New Event</span>
              <div class="action-desc">Create event</div>
            </a>
            
            <a href="manage_announcements.php?action=create" class="action-btn">
              <i class="fas fa-bullhorn"></i>
              <span>Post Announcement</span>
              <div class="action-desc">Publish news</div>
            </a>
            
            <a href="manage_donations.php?action=create" class="action-btn">
              <i class="fas fa-donate"></i>
              <span>Record Donation</span>
              <div class="action-desc">Log donation</div>
            </a>
            
            <a href="manage_volunteers.php?action=add" class="action-btn">
              <i class="fas fa-hands-helping"></i>
              <span>Manage volunteers</span>
              <div class="action-desc">Manage Volunteers</div>
            </a>
                <a href="manage_inventory.php?action=add" class="action-btn">
              <i class="fas fa-box-open"></i>
              <span>Add Inventory</span>
              <div class="action-desc">Stock items</div>
            </a>
              <a href="manage_merch.php?action=add" class="action-btn">
              <i class="fas fa-store"></i>
              <span>Manage Merch</span>
              <div class="action-desc">Manage Merch</div>
            </a>
            
            <a href="view_registrations.php" class="action-btn">
              <i class="fas fa-clipboard-list"></i>
              <span>View Registrations</span>
              <div class="action-desc">Review requests</div>
            </a>
            
            <a href="manage_sessions.php" class="action-btn">
              <i class="fas fa-graduation-cap"></i>
              <span>Training Sessions</span>
              <div class="action-desc">Manage training</div>
            </a>
          </div>

          <!-- Quick Stats -->
          <div class="quick-stats-summary">
            <h3><i class="fas fa-chart-line"></i> Today's Overview</h3>
            <div class="quick-stat-item">
              <span class="quick-stat-label">New Registrations</span>
              <span class="quick-stat-value"><?= $stats['registrations'] ?? 0 ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Donations Received</span>
              <span class="quick-stat-value"><?= $stats['donations'] ?? 0 ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Pending Reviews</span>
              <span class="quick-stat-value"><?= $pending_registrations ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/darkmode.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/header.js?v=<?php echo time(); ?>"></script>
  
  <style>
  /* Dynamic role color application */
  :root {
    --current-role-color: <?= get_role_color($user_role) ?>;
  }
  
  .welcome-section {
    background: linear-gradient(135deg, var(--current-role-color) 0%, <?= get_role_color($user_role) ?>dd 100%) !important;
  }
  
  .section-badge {
    background: linear-gradient(135deg, var(--current-role-color) 0%, <?= get_role_color($user_role) ?>dd 100%) !important;
  }
  
  .role-badge {
    background: var(--current-role-color);
    color: white;
    padding: 0.2rem 0.6rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    display: inline-block;
  }
  
  .activity-header h3 i,
  .section-header h2 i {
    color: var(--current-role-color) !important;
  }
  
  .stat-icon {
    background: linear-gradient(135deg, var(--current-role-color) 0%, <?= get_role_color($user_role) ?>dd 100%) !important;
  }
  
  .quick-stat-value {
    color: var(--current-role-color) !important;
  }
  </style>
</body>
</html>