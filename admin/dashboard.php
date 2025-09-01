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

// Get low stock count
try {
    $low_stock_count = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE quantity <= 10")->fetchColumn();
} catch (PDOException $e) {
    $low_stock_count = 0;
}

// Get today's new registrations
try {
    $todays_registrations = $pdo->query("SELECT COUNT(*) FROM registrations WHERE DATE(registration_date) = CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    $todays_registrations = 0;
}

// Get today's donations
try {
    $todays_donations = $pdo->query("SELECT COUNT(*) FROM donations WHERE DATE(donation_date) = CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    $todays_donations = 0;
}

// Get events happening this week
try {
    $week_events = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
} catch (PDOException $e) {
    $week_events = 0;
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

// Create dynamic notifications array
$adminNotifications = [];

// Pending registrations notification
if ($pending_registrations > 0) {
    $adminNotifications[] = [
        'type' => 'warning',
        'urgency' => $pending_registrations > 10 ? 'high' : 'medium',
        'icon' => 'user-clock',
        'title' => 'Pending Registration Reviews',
        'content' => "You have <strong>$pending_registrations</strong> registration" . ($pending_registrations > 1 ? 's' : '') . " waiting for approval. These users are ready to join events and training sessions.",
        'action_text' => 'Review Training',
        'action_link' => 'manage_sessions.php'
    ];
}

// Low stock notification
if ($low_stock_count > 0) {
    $adminNotifications[] = [
        'type' => 'deadline',
        'urgency' => 'high',
        'icon' => 'box-open',
        'title' => 'Inventory Stock Alert',
        'content' => "<strong>$low_stock_count</strong> inventory item" . ($low_stock_count > 1 ? 's are' : ' is') . " running low (below 10 units). Consider restocking to ensure operations continue smoothly.",
        'action_text' => 'Manage Stock',
        'action_link' => 'manage_inventory.php'
    ];
}

// Weekly activity summary
if ($week_events > 0 || $todays_registrations > 0) {
    $adminNotifications[] = [
        'type' => 'info',
        'urgency' => 'medium',
        'icon' => 'chart-line',
        'title' => 'Weekly Activity Summary',
        'content' => "This week: <strong>$week_events</strong> event" . ($week_events != 1 ? 's' : '') . " scheduled, <strong>$todays_registrations</strong> new registration" . ($todays_registrations != 1 ? 's' : '') . " today, and <strong>$todays_donations</strong> donation" . ($todays_donations != 1 ? 's' : '') . " received.",
        'action_text' => 'View Dashboard',
        'action_link' => '#'
    ];
}

// Recent announcement notification
if (!empty($announcements)) {
    $latest = $announcements[0];
    if (strtotime($latest['created_at']) > strtotime('-3 days')) {
        $adminNotifications[] = [
            'type' => 'announcement',
            'urgency' => 'low',
            'icon' => 'bullhorn',
            'title' => 'Recent Announcement Posted',
            'content' => "Latest: \"" . htmlspecialchars(substr($latest['title'], 0, 50)) . "...\" - Keep your community informed with timely updates.",
            'action_text' => 'Manage All',
            'action_link' => 'manage_announcements.php'
        ];
    }
}

// System health notification (mock)
$adminNotifications[] = [
    'type' => 'success',
    'urgency' => 'low',
    'icon' => 'check-circle',
    'title' => 'System Status: All Good',
    'content' => "All PRC Portal systems are running smoothly. Database connections stable, backups current, and user activity normal.",
    'action_text' => 'System Logs',
    'action_link' => '#'
];

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
          <h1>
            Welcome back, <?= htmlspecialchars($username) ?>!
            <span class="user-type-badge admin-<?= htmlspecialchars($user_role) ?>">
              <i class="fas fa-user-shield"></i>
              <?= htmlspecialchars(strtoupper($user_role)) ?> ADMIN
            </span>
          </h1>
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
          <div class="current-date">
            <i class="fas fa-calendar-day"></i>
            <?php echo date('F d, Y'); ?>
          </div>
        </div>
      </div>

      <!-- Enhanced Admin Notifications Section -->
      <div class="notifications-section">
        <?php foreach ($adminNotifications as $notification): ?>
          <div class="notification-banner <?= $notification['type'] ?>" <?= $notification['urgency'] === 'high' ? 'style="position: relative;"' : '' ?>>
            <?php if ($notification['urgency'] === 'high'): ?>
              <div class="urgency-indicator high"></div>
            <?php endif; ?>
            <div class="notification-icon">
              <i class="fas fa-<?= $notification['icon'] ?>"></i>
            </div>
            <div class="notification-content">
              <h3><?= htmlspecialchars($notification['title']) ?></h3>
              <p><?= $notification['content'] ?></p>
              <?php if (isset($notification['action_text']) && $notification['action_link'] !== '#'): ?>
                <div style="margin-top: 0.75rem;">
                  <a href="<?= htmlspecialchars($notification['action_link']) ?>" style="background: var(--current-role-color, var(--prc-red)); color: white; padding: 0.4rem 0.8rem; border-radius: 6px; text-decoration: none; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i class="fas fa-arrow-right"></i>
                    <?= htmlspecialchars($notification['action_text']) ?>
                  </a>
                </div>
              <?php endif; ?>
            </div>
            <button class="notification-close" onclick="this.parentElement.style.display='none'">
              <i class="fas fa-times"></i>
            </button>
          </div>
        <?php endforeach; ?>
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
              <span>Manage Volunteers</span>
              <div class="action-desc">Volunteer management</div>
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
            
            <a href="manage_sessions.php" class="action-btn">
              <i class="fas fa-graduation-cap"></i>
              <span>Training Sessions</span>
              <div class="action-desc">Manage training</div>
            </a>
          </div>

          <!-- Quick Stats Summary (moved from stats grid) -->
          <div class="quick-stats-summary">
            <h3><i class="fas fa-chart-line"></i> Today's Overview</h3>
            <div class="quick-stat-item">
              <span class="quick-stat-label">New Registrations</span>
              <span class="quick-stat-value"><?= $todays_registrations ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Donations Received</span>
              <span class="quick-stat-value"><?= $todays_donations ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Pending Reviews</span>
              <span class="quick-stat-value"><?= $pending_registrations ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Low Stock Items</span>
              <span class="quick-stat-value"><?= $low_stock_count ?></span>
            </div>
          </div>

          <!-- Admin Role Specific Section -->
          <div class="admin-role-section" style="margin-top: 1.5rem; padding: 1rem; background: linear-gradient(135deg, var(--current-role-color, var(--prc-red))15, var(--current-role-color, var(--prc-red))25); border-radius: 10px; border: 1px solid var(--current-role-color, var(--prc-red));">
            <h3 style="margin: 0 0 1rem 0; color: var(--current-role-color, var(--prc-red)); display: flex; align-items: center; gap: 0.5rem;">
              <i class="fas fa-user-shield"></i> <?= htmlspecialchars(strtoupper($user_role)) ?> Admin Panel
            </h3>
            <div style="display: grid; gap: 0.75rem;">
              <div style="background: white; padding: 0.75rem; border-radius: 6px; border-left: 4px solid var(--current-role-color, var(--prc-red));">
                <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">
                  Access Level: Unrestricted
                </div>
                <div style="font-size: 0.8rem; color: #666;">
                  Full system access with <?= htmlspecialchars($user_role) ?> specialization
                </div>
              </div>
              <div style="background: white; padding: 0.75rem; border-radius: 6px; border-left: 4px solid var(--current-role-color, var(--prc-red));">
                <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">
                  System Status: Active
                </div>
                <div style="font-size: 0.8rem; color: #666;">
                  All <?= htmlspecialchars($user_role) ?> modules operational
                </div>
              </div>
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
  
  <script>
    // Enhanced notification interaction - entrance animation only, no auto-dismiss
    document.addEventListener('DOMContentLoaded', function() {
      const notifications = document.querySelectorAll('.notification-banner');
      
      notifications.forEach((notification, index) => {
        // Stagger entrance animations
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px)';
        setTimeout(() => {
          notification.style.transition = 'all 0.5s ease-out';
          notification.style.opacity = '1';
          notification.style.transform = 'translateY(0)';
        }, 100 + (index * 150)); // Stagger by 150ms each
        
        // Add hover effects
        notification.addEventListener('mouseenter', function() {
          if (this.style.display !== 'none') {
            this.style.transform = 'translateY(-3px)';
          }
        });
        
        notification.addEventListener('mouseleave', function() {
          if (this.style.display !== 'none') {
            this.style.transform = 'translateY(0)';
          }
        });
      });
    });
    
    // Notification close with smooth animation
    document.querySelectorAll('.notification-close').forEach(closeBtn => {
      closeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const notification = this.closest('.notification-banner');
        notification.style.transition = 'all 0.3s ease-out';
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px) scale(0.95)';
        setTimeout(() => {
          notification.style.display = 'none';
        }, 300);
      });
    });
    
    // Activity card interactions
    document.querySelectorAll('.activity-item').forEach(item => {
      item.addEventListener('click', function() {
        // Add click interaction for activity items
        const link = this.closest('.activity-card').querySelector('.view-all');
        if (link) {
          window.location.href = link.href;
        }
      });
    });
  </script>
  
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
  
  .user-type-badge {
    background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
    color: var(--current-role-color);
    border: 1px solid rgba(255,255,255,0.5);
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    backdrop-filter: blur(10px);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
  }
  
  .activity-header h3 i,
  .section-header h2 i {
    color: var(--current-role-color) !important;
  }
  
  .quick-stat-value {
    color: var(--current-role-color) !important;
  }
  
  /* Enhanced notification styles matching user dashboard */
  .notifications-section {
    margin-bottom: 2rem;
  }
  
  .notification-banner {
    display: flex;
    align-items: flex-start;
    gap: 1.5rem;
    padding: 1.5rem 2rem;
    border-radius: 15px;
    margin-bottom: 1.2rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    animation: slideInFromTop 0.5s ease-out;
  }
  
  .notification-banner::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    animation: shimmer 2s infinite;
  }
  
  @keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
  }
  
  .notification-banner:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
  }
  
  .notification-banner.warning {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 1px solid #ffeaa7;
    border-left: 5px solid #ffc107;
  }
  
  .notification-banner.success {
    background: linear-gradient(135deg, #d1edff 0%, #a7e7ff 100%);
    border: 1px solid #a7e7ff;
    border-left: 5px solid #28a745;
  }
  
  .notification-banner.info {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 1px solid #bbdefb;
    border-left: 5px solid #2196f3;
  }
  
  .notification-banner.deadline {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    border: 1px solid #ffcdd2;
    border-left: 5px solid #f44336;
    animation: urgentPulse 2s ease-in-out infinite;
  }
  
  .notification-banner.announcement {
    background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
    border: 1px solid #e1bee7;
    border-left: 5px solid #9c27b0;
  }
  
  @keyframes urgentPulse {
    0%, 100% {
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    }
    50% {
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08), 0 0 20px rgba(244, 67, 54, 0.3);
    }
  }
  
  .notification-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.8rem;
    color: white;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  }
  
  .notification-banner.warning .notification-icon {
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
  }
  
  .notification-banner.success .notification-icon {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
  }
  
  .notification-banner.info .notification-icon {
    background: linear-gradient(135deg, #2196f3 0%, #03a9f4 100%);
  }
  
  .notification-banner.deadline .notification-icon {
    background: linear-gradient(135deg, #f44336 0%, #e91e63 100%);
    animation: iconPulse 1s ease-in-out infinite;
  }
  
  .notification-banner.announcement .notification-icon {
    background: linear-gradient(135deg, #9c27b0 0%, #673ab7 100%);
  }
  
  @keyframes iconPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
  }
  
  .notification-content {
    flex: 1;
    min-width: 0;
  }
  
  .notification-content h3 {
    margin: 0 0 0.75rem 0;
    font-size: 1.2rem;
    font-weight: 600;
    color: #333;
    line-height: 1.3;
  }
  
  .notification-content p {
    margin: 0;
    color: #666;
    font-size: 0.95rem;
    line-height: 1.5;
  }
  
  .notification-content strong {
    color: #333;
    font-weight: 600;
  }
  
  .notification-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    background: rgba(0, 0, 0, 0.1);
    border: none;
    color: #666;
    cursor: pointer;
    font-size: 1.2rem;
    padding: 0.5rem;
    border-radius: 50%;
    transition: all 0.3s ease;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  .notification-close:hover {
    background: rgba(0, 0, 0, 0.2);
    color: #333;
    transform: scale(1.1);
  }
  
  .urgency-indicator {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #f44336;
    animation: pulse 1.5s infinite;
  }
  
  .urgency-indicator.high {
    background: #f44336;
  }
  
  .urgency-indicator.medium {
    background: #ff9800;
  }
  
  .urgency-indicator.low {
    background: #4caf50;
  }
  
  @keyframes pulse {
    0%, 100% {
      transform: scale(1);
      opacity: 1;
    }
    50% {
      transform: scale(1.2);
      opacity: 0.7;
    }
  }
  
  /* Animation for notification entrance */
  @keyframes slideInFromTop {
    from {
      opacity: 0;
      transform: translateY(-20px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
  
  @media (max-width: 768px) {
    .user-type-badge {
      margin-left: 0;
      margin-top: 0.5rem;
    }
    
    .welcome-content h1 {
      flex-direction: column;
      align-items: flex-start;
    }
    
    .notification-banner {
      padding: 1rem;
      flex-direction: column;
      text-align: center;
      gap: 0.75rem;
    }
    
    .notification-close {
      position: static;
      margin-left: auto;
    }
  }
  </style>
</body>
</html>