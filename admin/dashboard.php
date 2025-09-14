<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

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

// Enhanced notification system with priority levels
function getAdminNotifications($pdo) {
    $notifications = [];
    
    try {
        // CRITICAL: System health issues
        $systemIssues = checkSystemHealth($pdo);
        if (!empty($systemIssues)) {
            foreach ($systemIssues as $issue) {
                $notifications[] = [
                    'priority' => 'critical',
                    'type' => 'system',
                    'icon' => 'exclamation-triangle',
                    'title' => $issue['title'],
                    'message' => $issue['message'],
                    'action' => $issue['action'] ?? null,
                    'timestamp' => time()
                ];
            }
        }

        // HIGH PRIORITY: Overdue registrations (48+ hours)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, 
                   MIN(registration_date) as oldest_date
            FROM registrations 
            WHERE status = 'pending' 
            AND registration_date < DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ");
        $stmt->execute();
        $urgentRegs = $stmt->fetch();
        
        if ($urgentRegs && $urgentRegs['count'] > 0) {
            $notifications[] = [
                'priority' => 'high',
                'type' => 'urgent_action',
                'icon' => 'user-clock',
                'title' => 'Overdue Registrations',
                'message' => "{$urgentRegs['count']} registration(s) pending for over 48 hours. Oldest from " . date('M j, Y', strtotime($urgentRegs['oldest_date'])),
                'action' => ['text' => 'Review Now', 'link' => 'manage_events.php'],
                'timestamp' => strtotime($urgentRegs['oldest_date'])
            ];
        }

        // HIGH PRIORITY: Critical inventory levels (≤5 items) - Updated for new table structure
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as critical_count,
                   GROUP_CONCAT(DISTINCT item_name ORDER BY current_stock LIMIT 3) as items
            FROM inventory_items 
            WHERE current_stock <= 5 AND current_stock > 0
        ");
        $stmt->execute();
        $criticalInventory = $stmt->fetch();
        
        if ($criticalInventory && $criticalInventory['critical_count'] > 0) {
            $notifications[] = [
                'priority' => 'high',
                'type' => 'inventory',
                'icon' => 'box-open',
                'title' => 'Critical Stock Levels',
                'message' => "{$criticalInventory['critical_count']} items critically low. Including: {$criticalInventory['items']}",
                'action' => ['text' => 'Restock Now', 'link' => 'manage_inventory.php'],
                'timestamp' => time() - 3600
            ];
        }

        // MEDIUM PRIORITY: All pending registrations (within 48 hours)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MAX(registration_date) as latest_date
            FROM registrations 
            WHERE status = 'pending' 
            AND registration_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ");
        $stmt->execute();
        $pendingRegs = $stmt->fetch();
        
        if ($pendingRegs && $pendingRegs['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'registration',
                'icon' => 'user-plus',
                'title' => 'Pending Registrations',
                'message' => "{$pendingRegs['count']} new registration(s) awaiting approval.",
                'action' => ['text' => 'Review Registrations', 'link' => 'manage_events.php'],
                'timestamp' => strtotime($pendingRegs['latest_date'])
            ];
        }

        // MEDIUM PRIORITY: All training requests
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MAX(created_at) as latest_date
            FROM training_requests 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $pendingRequests = $stmt->fetch();
        
        if ($pendingRequests && $pendingRequests['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'requests',
                'icon' => 'clipboard-list',
                'title' => 'Pending Training Requests',
                'message' => "{$pendingRequests['count']} training request(s) need review and approval.",
                'action' => ['text' => 'Review Requests', 'link' => 'training_request.php'],
                'timestamp' => strtotime($pendingRequests['latest_date'])
            ];
        }

        // MEDIUM PRIORITY: New users registered recently
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MAX(created_at) as latest_date
            FROM users 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND role = 'user'
        ");
        $stmt->execute();
        $newUsers = $stmt->fetch();
        
        if ($newUsers && $newUsers['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'new_users',
                'icon' => 'users',
                'title' => 'New User Registrations',
                'message' => "{$newUsers['count']} new user(s) registered in the past week.",
                'action' => ['text' => 'Manage Users', 'link' => 'manage_users.php'],
                'timestamp' => strtotime($newUsers['latest_date'])
            ];
        }

        // MEDIUM PRIORITY: Upcoming events needing attention
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MIN(event_date) as next_date
            FROM events 
            WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND event_date > CURDATE()
        ");
        $stmt->execute();
        $upcomingEvents = $stmt->fetch();
        
        if ($upcomingEvents && $upcomingEvents['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'upcoming',
                'icon' => 'calendar-check',
                'title' => 'Upcoming Events This Week',
                'message' => "{$upcomingEvents['count']} event(s) scheduled. Next event: " . date('M j, Y', strtotime($upcomingEvents['next_date'])),
                'action' => ['text' => 'View Events', 'link' => 'manage_events.php'],
                'timestamp' => strtotime($upcomingEvents['next_date'])
            ];
        }

        // MEDIUM PRIORITY: Training sessions needing preparation
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MIN(session_date) as next_date
            FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            AND session_date > CURDATE()
        ");
        $stmt->execute();
        $upcomingSessions = $stmt->fetch();
        
        if ($upcomingSessions && $upcomingSessions['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'training',
                'icon' => 'graduation-cap',
                'title' => 'Training Sessions This Week',
                'message' => "{$upcomingSessions['count']} training session(s) starting soon. Next: " . date('M j, Y', strtotime($upcomingSessions['next_date'])),
                'action' => ['text' => 'View Sessions', 'link' => 'manage_sessions.php'],
                'timestamp' => strtotime($upcomingSessions['next_date'])
            ];
        }

        // MEDIUM PRIORITY: New donations needing approval
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MAX(created_at) as latest_date
            FROM donations 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $pendingDonations = $stmt->fetch();
        
        if ($pendingDonations && $pendingDonations['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'donations',
                'icon' => 'heart',
                'title' => 'Pending Donations',
                'message' => "{$pendingDonations['count']} donation(s) awaiting approval.",
                'action' => ['text' => 'Review Donations', 'link' => 'manage_donations.php'],
                'timestamp' => strtotime($pendingDonations['latest_date'])
            ];
        }

        // MEDIUM PRIORITY: Recent volunteer applications
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MAX(created_at) as latest_date
            FROM volunteers 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $newVolunteers = $stmt->fetch();
        
        if ($newVolunteers && $newVolunteers['count'] > 0) {
            $notifications[] = [
                'priority' => 'medium',
                'type' => 'volunteers',
                'icon' => 'hands-helping',
                'title' => 'New Volunteer Applications',
                'message' => "{$newVolunteers['count']} new volunteer(s) applied this week.",
                'action' => ['text' => 'Manage Volunteers', 'link' => 'manage_volunteers.php'],
                'timestamp' => strtotime($newVolunteers['latest_date'])
            ];
        }

        // LOW PRIORITY: Low inventory levels (6-15 items) - Updated for new table structure
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   GROUP_CONCAT(DISTINCT item_name ORDER BY current_stock LIMIT 3) as items
            FROM inventory_items 
            WHERE current_stock > 5 AND current_stock <= 15
        ");
        $stmt->execute();
        $lowInventory = $stmt->fetch();
        
        if ($lowInventory && $lowInventory['count'] > 0) {
            $notifications[] = [
                'priority' => 'low',
                'type' => 'inventory_low',
                'icon' => 'boxes',
                'title' => 'Low Stock Levels',
                'message' => "{$lowInventory['count']} items running low. Including: {$lowInventory['items']}",
                'action' => ['text' => 'View Inventory', 'link' => 'manage_inventory.php'],
                'timestamp' => time() - 7200
            ];
        }

        // LOW PRIORITY: Recent achievements/positive updates
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as new_donations,
                   SUM(amount) as total_amount
            FROM donations 
            WHERE donation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            AND status = 'approved'
        ");
        $stmt->execute();
        $recentDonations = $stmt->fetch();
        
        if ($recentDonations && $recentDonations['new_donations'] > 3) {
            $notifications[] = [
                'priority' => 'low',
                'type' => 'success',
                'icon' => 'heart',
                'title' => 'Strong Donation Activity',
                'message' => "{$recentDonations['new_donations']} donations received this week totaling ₱" . number_format($recentDonations['total_amount'], 2),
                'action' => ['text' => 'View Donations', 'link' => 'manage_donations.php'],
                'timestamp' => time() - 86400
            ];
        }

        // LOW PRIORITY: Recent announcements
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   MAX(created_at) as latest_date
            FROM announcements 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        ");
        $stmt->execute();
        $recentAnnouncements = $stmt->fetch();
        
        if ($recentAnnouncements && $recentAnnouncements['count'] > 0) {
            $notifications[] = [
                'priority' => 'low',
                'type' => 'announcements',
                'icon' => 'bullhorn',
                'title' => 'Recent Announcements',
                'message' => "{$recentAnnouncements['count']} announcement(s) published recently.",
                'action' => ['text' => 'View Announcements', 'link' => 'manage_announcements.php'],
                'timestamp' => strtotime($recentAnnouncements['latest_date'])
            ];
        }

    } catch (Exception $e) {
        error_log("Error generating notifications: " . $e->getMessage());
    }
    
    // Sort by priority (critical, high, medium, low) then by timestamp
    $priorityOrder = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
    usort($notifications, function($a, $b) use ($priorityOrder) {
        if ($priorityOrder[$a['priority']] !== $priorityOrder[$b['priority']]) {
            return $priorityOrder[$a['priority']] - $priorityOrder[$b['priority']];
        }
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return $notifications;
}

function checkSystemHealth($pdo) {
    $issues = [];
    
    try {
        // Check database performance
        $stmt = $pdo->query("SHOW PROCESSLIST");
        $processes = $stmt->fetchAll();
        if (count($processes) > 50) {
            $issues[] = [
                'title' => 'High Database Load',
                'message' => count($processes) . ' active database connections detected.',
                'action' => ['text' => 'Check System', 'link' => '#']
            ];
        }

        // Check for failed operations (only if system_logs table exists)
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as failed_count 
                FROM system_logs 
                WHERE level = 'ERROR' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result && $result['failed_count'] > 10) {
                $issues[] = [
                    'title' => 'System Errors Detected',
                    'message' => "{$result['failed_count']} errors in the last hour.",
                    'action' => ['text' => 'View Logs', 'link' => '#']
                ];
            }
        } catch (Exception $e) {
            // If system_logs table doesn't exist, skip this check
        }

    } catch (Exception $e) {
        error_log("Error checking system health: " . $e->getMessage());
    }
    
    return $issues;
}

// Get enhanced activity data with real-time updates
function getRoleActivity($pdo, $role, $is_unrestricted) {
    $activity = [];
    
    try {
        // Recent Events with registration counts
        $stmt = $pdo->prepare("
            SELECT e.title, e.event_date, e.location, e.event_id, e.major_service,
                   COUNT(r.registration_id) as registration_count,
                   CASE 
                       WHEN e.event_date > CURDATE() THEN 'upcoming'
                       WHEN e.event_date = CURDATE() THEN 'today'
                       ELSE 'past'
                   END as status
            FROM events e
            LEFT JOIN registrations r ON e.event_id = r.event_id
            WHERE e.event_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY e.event_id
            ORDER BY e.event_date DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $activity['events'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Training Sessions with participant counts
        $stmt = $pdo->prepare("
            SELECT ts.title, ts.major_service, ts.session_date, ts.start_time, ts.venue, ts.session_id,
                   COUNT(sr.registration_id) as participant_count,
                   CASE 
                       WHEN ts.session_date > CURDATE() THEN 'upcoming'
                       WHEN ts.session_date = CURDATE() THEN 'today'
                       ELSE 'completed'
                   END as status
            FROM training_sessions ts
            LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
            WHERE ts.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY ts.session_id
            ORDER BY ts.session_date DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $activity['sessions'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Training Requests
        $stmt = $pdo->prepare("
            SELECT tr.request_id, tr.training_program, tr.organization_name, tr.contact_person, 
                   tr.participant_count, tr.status, tr.service_type, tr.created_at,
                   u.full_name as requester_name
            FROM training_requests tr
            LEFT JOIN users u ON tr.user_id = u.user_id
            WHERE tr.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            ORDER BY tr.created_at DESC 
            LIMIT 8
        ");
        $stmt->execute();
        $activity['training_requests'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Users with registration status
        $stmt = $pdo->prepare("
            SELECT u.username, u.created_at, u.email, u.full_name,
                   COUNT(r.registration_id) as total_registrations
            FROM users u
            LEFT JOIN registrations r ON u.user_id = r.user_id
            WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY u.user_id
            ORDER BY u.created_at DESC 
            LIMIT 8
        ");
        $stmt->execute();
        $activity['users'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Donations with approval status
        $stmt = $pdo->prepare("
            SELECT d.amount, donor.name AS donor_name, d.donation_date, d.status, d.payment_method
            FROM donations d 
            JOIN donors donor ON d.donor_id = donor.donor_id 
            WHERE d.donation_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            ORDER BY d.donation_date DESC 
            LIMIT 8
        ");
        $stmt->execute();
        $activity['donations'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Blood Donations
        $stmt = $pdo->prepare("
            SELECT bd.blood_type, donor.name AS donor_name, bd.donation_date, bd.status
            FROM blood_donations bd
            JOIN donors donor ON bd.donor_id = donor.donor_id 
            WHERE bd.donation_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            ORDER BY bd.donation_date DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $activity['blood_donations'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Volunteer Activities
        $stmt = $pdo->prepare("
            SELECT v.full_name, v.service, v.status, v.created_at, v.location
            FROM volunteers v
            WHERE v.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            ORDER BY v.created_at DESC 
            LIMIT 6
        ");
        $stmt->execute();
        $activity['volunteers'] = $stmt ? $stmt->fetchAll() : [];
        
        // Critical Inventory Items - Updated for new table structure
        $stmt = $pdo->prepare("
            SELECT i.item_name, i.current_stock, i.minimum_stock, c.category_name, i.location,
                   CASE 
                       WHEN i.current_stock = 0 THEN 'out_of_stock'
                       WHEN i.current_stock <= 5 THEN 'critical'
                       WHEN i.current_stock <= i.minimum_stock THEN 'low'
                       ELSE 'normal'
                   END as stock_status
            FROM inventory_items i 
            LEFT JOIN inventory_categories c ON i.category_id = c.category_id 
            WHERE i.current_stock <= 15
            ORDER BY i.current_stock ASC 
            LIMIT 8
        ");
        $stmt->execute();
        $activity['inventory'] = $stmt ? $stmt->fetchAll() : [];
        
        // Recent Announcements with engagement
        $stmt = $pdo->prepare("
            SELECT a.title, a.content, a.posted_at, a.created_at,
                   DATEDIFF(NOW(), a.posted_at) as days_ago
            FROM announcements a
            WHERE a.posted_at IS NOT NULL
            ORDER BY a.posted_at DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $activity['announcements'] = $stmt ? $stmt->fetchAll() : [];
        
    } catch (PDOException $e) {
        error_log("Error in getRoleActivity: " . $e->getMessage());
        $activity = [
            'events' => [], 'sessions' => [], 'training_requests' => [], 'users' => [],
            'donations' => [], 'blood_donations' => [], 'volunteers' => [], 'inventory' => [], 'announcements' => []
        ];
    }
    
    return $activity;
}

$activity = getRoleActivity($pdo, $user_role, $is_unrestricted);

// Get priority notifications
$adminNotifications = getAdminNotifications($pdo);

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
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/calendar-enhanced.css?v=<?php echo time(); ?>">
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
          <p>Philippine Red Cross Administration Portal - Streamlined operations management for efficient service delivery.</p>
        </div>
        <div class="date-display">
          <div class="current-date">
            <i class="fas fa-calendar-day"></i>
            <?php echo date('F d, Y'); ?>
          </div>
          <div class="live-indicator">
            <i class="fas fa-circle" id="liveIndicator"></i>
            <span>Live Dashboard</span>
          </div>
        </div>
      </div>
 <!-- Priority Notifications Section - Now Scrollable -->
      <?php if (!empty($adminNotifications)): ?>
      <div class="notifications-priority-section">
        <div class="notifications-header">
          <h2><i class="fas fa-bell"></i> System Notifications</h2>
          <div class="notification-summary">
            <?php 
            $criticalCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'critical'; }));
            $highCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'high'; }));
            $mediumCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'medium'; }));
            $lowCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'low'; }));
            ?>
            <?php if ($criticalCount > 0): ?>
              <span class="priority-badge critical"><?= $criticalCount ?> Critical</span>
            <?php endif; ?>
            <?php if ($highCount > 0): ?>
              <span class="priority-badge high"><?= $highCount ?> High</span>
            <?php endif; ?>
            <?php if ($mediumCount > 0): ?>
              <span class="priority-badge medium"><?= $mediumCount ?> Medium</span>
            <?php endif; ?>
            <?php if ($lowCount > 0): ?>
              <span class="priority-badge low"><?= $lowCount ?> Low</span>
            <?php endif; ?>
            <span class="notification-count"><?= count($adminNotifications) ?> total</span>
          </div>
        </div>
        
        <div class="notifications-scroll-container">
          <div class="notifications-grid">
            <?php foreach ($adminNotifications as $notification): ?>
              <div class="notification-card priority-<?= $notification['priority'] ?> <?= $notification['type'] ?>">
                <div class="notification-priority-indicator">
                  <?php if ($notification['priority'] === 'critical'): ?>
                    <i class="fas fa-exclamation-triangle"></i>
                  <?php elseif ($notification['priority'] === 'high'): ?>
                    <i class="fas fa-exclamation-circle"></i>
                  <?php elseif ($notification['priority'] === 'medium'): ?>
                    <i class="fas fa-info-circle"></i>
                  <?php else: ?>
                    <i class="fas fa-check-circle"></i>
                  <?php endif; ?>
                </div>
                
                <div class="notification-icon">
                  <i class="fas fa-<?= $notification['icon'] ?>"></i>
                </div>
                
                <div class="notification-content">
                  <h3><?= htmlspecialchars($notification['title']) ?></h3>
                  <p><?= $notification['message'] ?></p>
                  
                  <?php if (isset($notification['action'])): ?>
                    <div class="notification-action">
                      <a href="<?= htmlspecialchars($notification['action']['link']) ?>" class="action-button">
                        <i class="fas fa-arrow-right"></i>
                        <?= htmlspecialchars($notification['action']['text']) ?>
                      </a>
                    </div>
                  <?php endif; ?>
                </div>
                
                <div class="notification-timestamp">
                  <?= date('g:i A', $notification['timestamp']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        
        <!-- Add a filter/toggle for notification priorities -->
        <div class="notification-filters">
          <div class="filter-toggles">
            <button class="filter-btn active" data-priority="all">
              <i class="fas fa-list"></i> All (<?= count($adminNotifications) ?>)
            </button>
            <?php if ($criticalCount > 0): ?>
              <button class="filter-btn" data-priority="critical">
                <i class="fas fa-exclamation-triangle"></i> Critical (<?= $criticalCount ?>)
              </button>
            <?php endif; ?>
            <?php if ($highCount > 0): ?>
              <button class="filter-btn" data-priority="high">
                <i class="fas fa-exclamation-circle"></i> High (<?= $highCount ?>)
              </button>
            <?php endif; ?>
            <?php if ($mediumCount > 0): ?>
              <button class="filter-btn" data-priority="medium">
                <i class="fas fa-info-circle"></i> Medium (<?= $mediumCount ?>)
              </button>
            <?php endif; ?>
            <?php if ($lowCount > 0): ?>
              <button class="filter-btn" data-priority="low">
                <i class="fas fa-check-circle"></i> Low (<?= $lowCount ?>)
              </button>
            <?php endif; ?>
          </div>
          
          <div class="filter-actions">
            <button class="action-btn secondary" onclick="refreshNotifications()">
              <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button class="action-btn secondary" onclick="markAllNotificationsRead()">
              <i class="fas fa-check-double"></i> Mark All Read
            </button>
          </div>
        </div>
      </div>
      <?php else: ?>
      <!-- No Notifications State -->
      <div class="notifications-priority-section">
        <div class="notifications-header">
          <h2><i class="fas fa-bell"></i> System Notifications</h2>
          <div class="notification-summary">
            <span class="notification-count success">All Clear</span>
          </div>
        </div>
        
        <div class="notifications-empty">
          <div class="empty-state">
            <i class="fas fa-bell-slash"></i>
            <h3>No Active Notifications</h3>
            <p>All systems are running smoothly. No immediate attention required.</p>
            <div class="empty-actions">
              <button class="action-btn primary" onclick="refreshNotifications()">
                <i class="fas fa-sync-alt"></i> Check for Updates
              </button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Main Dashboard Content -->
      <div class="dashboard-main">
        <!-- Recent Activity Section -->
        <div class="recent-activity-section">
          <div class="section-header">
            <h2><i class="fas fa-activity"></i> Recent Activity</h2>
            <div class="activity-controls">
              <button class="refresh-btn" onclick="refreshActivity()">
                <i class="fas fa-sync-alt"></i>
              </button>
              <button class="calendar-btn" onclick="openCalendar()">
                <i class="fas fa-calendar"></i>
              </button>
              <div class="auto-refresh-indicator">
                <i class="fas fa-circle" id="activityIndicator"></i>
                <span>Auto-refresh: ON</span>
              </div>
            </div>
          </div>
          
          <div class="activity-scroll-container">
            <!-- Enhanced Activity Cards -->
            <!-- Events Activity -->
            <?php if (!empty($activity['events'])): ?>
            <div class="activity-card featured">
              <div class="activity-header">
                <h3><i class="fas fa-calendar"></i> Recent Events</h3>
                <a href="manage_events.php" class="view-all">Manage All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['events'] as $event): ?>
                    <div class="activity-item enhanced" data-status="<?= $event['status'] ?>">
                      <div class="activity-icon event-icon">
                        <i class="fas fa-calendar"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($event['title']) ?></div>
                        <div class="activity-meta">
                          <span class="service-tag"><?= htmlspecialchars($event['major_service']) ?></span>
                          <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(substr($event['location'], 0, 30)) ?>...</span>
                          <span class="registrations"><i class="fas fa-users"></i> <?= $event['registration_count'] ?> registered</span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($event['event_date'])) ?></div>
                      </div>
                      <div class="activity-status status-<?= $event['status'] ?>">
                        <?= ucfirst($event['status']) ?>
                      </div>
                      <div class="activity-action">
                        <i class="fas fa-chevron-right"></i>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Training Requests Activity -->
            <?php if (!empty($activity['training_requests'])): ?>
            <div class="activity-card featured">
              <div class="activity-header">
                <h3><i class="fas fa-clipboard-list"></i> Training Requests</h3>
                <a href="manage_training_requests.php" class="view-all">Manage All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['training_requests'] as $request): ?>
                    <div class="activity-item enhanced" data-status="<?= $request['status'] ?>">
                      <div class="activity-icon training-icon">
                        <i class="fas fa-clipboard-list"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($request['training_program']) ?></div>
                        <div class="activity-meta">
                          <span class="service-tag"><?= htmlspecialchars($request['service_type']) ?></span>
                          <span class="organization"><i class="fas fa-building"></i> <?= htmlspecialchars($request['organization_name'] ?: 'Individual') ?></span>
                          <span class="participants"><i class="fas fa-users"></i> <?= $request['participant_count'] ?> participants</span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y g:i A', strtotime($request['created_at'])) ?></div>
                      </div>
                      <div class="activity-status status-<?= $request['status'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                      </div>
                      <div class="activity-action">
                        <i class="fas fa-chevron-right"></i>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Training Sessions Activity -->
            <?php if (!empty($activity['sessions'])): ?>
            <div class="activity-card featured">
              <div class="activity-header">
                <h3><i class="fas fa-graduation-cap"></i> Training Sessions</h3>
                <a href="manage_sessions.php" class="view-all">Manage All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['sessions'] as $session): ?>
                    <div class="activity-item enhanced" data-status="<?= $session['status'] ?>">
                      <div class="activity-icon training-icon">
                        <i class="fas fa-graduation-cap"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($session['title']) ?></div>
                        <div class="activity-meta">
                          <span class="service-tag"><?= htmlspecialchars($session['major_service']) ?></span>
                          <span class="venue"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(substr($session['venue'], 0, 30)) ?>...</span>
                          <span class="participants"><i class="fas fa-users"></i> <?= $session['participant_count'] ?> participants</span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y g:i A', strtotime($session['session_date'] . ' ' . $session['start_time'])) ?></div>
                      </div>
                      <div class="activity-status status-<?= $session['status'] ?>">
                        <?= ucfirst($session['status']) ?>
                      </div>
                      <div class="activity-action">
                        <i class="fas fa-chevron-right"></i>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Volunteer Activities -->
            <?php if (!empty($activity['volunteers'])): ?>
            <div class="activity-card">
              <div class="activity-header">
                <h3><i class="fas fa-hands-helping"></i> Recent Volunteers</h3>
                <a href="manage_volunteers.php" class="view-all">Manage All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['volunteers'] as $volunteer): ?>
                    <div class="activity-item" data-type="volunteer">
                      <div class="activity-icon user-icon">
                        <i class="fas fa-hands-helping"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($volunteer['full_name']) ?></div>
                        <div class="activity-meta">
                          <span class="service-tag"><?= htmlspecialchars($volunteer['service']) ?></span>
                          <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($volunteer['location']) ?></span>
                          <span class="status"><i class="fas fa-user-check"></i> <?= ucfirst($volunteer['status']) ?></span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($volunteer['created_at'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Donations Activity -->
            <?php if (!empty($activity['donations']) || !empty($activity['blood_donations'])): ?>
            <div class="activity-card">
              <div class="activity-header">
                <h3><i class="fas fa-hand-holding-heart"></i> Recent Donations</h3>
                <a href="manage_donations.php" class="view-all">View All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['donations'] as $donation): ?>
                    <div class="activity-item" data-type="monetary">
                      <div class="activity-icon donation-icon">
                        <i class="fas fa-donate"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main">₱<?= number_format($donation['amount'], 2) ?> from <?= htmlspecialchars($donation['donor_name']) ?></div>
                        <div class="activity-meta">
                          <span class="payment-method"><i class="fas fa-credit-card"></i> <?= ucfirst($donation['payment_method']) ?></span>
                          <span class="status-badge status-<?= $donation['status'] ?>"><?= ucfirst($donation['status']) ?></span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($donation['donation_date'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  
                  <?php foreach ($activity['blood_donations'] as $blood): ?>
                    <div class="activity-item" data-type="blood">
                      <div class="activity-icon blood-icon">
                        <i class="fas fa-tint"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main">Blood donation from <?= htmlspecialchars($blood['donor_name']) ?></div>
                        <div class="activity-meta">
                          <span class="blood-type"><i class="fas fa-tint"></i> <?= $blood['blood_type'] ?></span>
                          <span class="status-badge status-<?= $blood['status'] ?>"><?= ucfirst($blood['status']) ?></span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($blood['donation_date'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Critical Inventory -->
            <?php if (!empty($activity['inventory'])): ?>
            <div class="activity-card warning">
              <div class="activity-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Inventory Alerts</h3>
                <a href="manage_inventory.php" class="view-all">Manage Stock</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['inventory'] as $item): ?>
                    <div class="activity-item inventory-alert" data-status="<?= $item['stock_status'] ?>">
                      <div class="activity-icon inventory-icon stock-<?= $item['stock_status'] ?>">
                        <i class="fas fa-<?= $item['stock_status'] === 'out_of_stock' ? 'times' : ($item['stock_status'] === 'critical' ? 'exclamation' : 'box') ?>"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($item['item_name']) ?></div>
                        <div class="activity-meta">
                          <span class="quantity quantity-<?= $item['stock_status'] ?>">
                            <i class="fas fa-cubes"></i> <?= $item['current_stock'] ?> remaining
                          </span>
                          <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location'] ?? 'N/A') ?></span>
                          <span class="minimum-stock"><i class="fas fa-level-down-alt"></i> Min: <?= $item['minimum_stock'] ?></span>
                        </div>
                      </div>
                      <div class="stock-status status-<?= $item['stock_status'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $item['stock_status'])) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Recent Users -->
            <?php if (!empty($activity['users'])): ?>
            <div class="activity-card">
              <div class="activity-header">
                <h3><i class="fas fa-users"></i> New Users</h3>
                <a href="manage_users.php" class="view-all">View All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['users'] as $user): ?>
                    <div class="activity-item">
                      <div class="activity-icon user-icon">
                        <i class="fas fa-user"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
                        <div class="activity-meta">
                          <span class="email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></span>
                          <span class="registrations"><i class="fas fa-calendar-check"></i> <?= $user['total_registrations'] ?> registrations</span>
                        </div>
                        <div class="activity-time"><?= date('M d, Y', strtotime($user['created_at'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Recent Announcements -->
            <?php if (!empty($activity['announcements'])): ?>
            <div class="activity-card">
              <div class="activity-header">
                <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                <a href="manage_announcements.php" class="view-all">Manage All</a>
              </div>
              <div class="activity-body">
                <div class="activity-list">
                  <?php foreach ($activity['announcements'] as $announcement): ?>
                    <div class="activity-item">
                      <div class="activity-icon announcement-icon">
                        <i class="fas fa-bullhorn"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($announcement['title']) ?></div>
                        <div class="activity-content">
                          <?= htmlspecialchars(substr($announcement['content'], 0, 100)) ?><?= strlen($announcement['content']) > 100 ? '...' : '' ?>
                        </div>
                        <div class="activity-meta">
                          <span class="age">
                            <i class="fas fa-clock"></i> 
                            <?php if ($announcement['days_ago'] == 0): ?>
                              Today
                            <?php elseif ($announcement['days_ago'] == 1): ?>
                              Yesterday
                            <?php else: ?>
                              <?= $announcement['days_ago'] ?> days ago
                            <?php endif; ?>
                          </span>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- No Activity Message -->
            <?php if (empty($activity['events']) && empty($activity['sessions']) && empty($activity['training_requests']) && 
                       empty($activity['users']) && empty($activity['donations']) && empty($activity['blood_donations']) && 
                       empty($activity['volunteers']) && empty($activity['inventory']) && empty($activity['announcements'])): ?>
            <div class="activity-card">
              <div class="activity-body">
                <div class="no-data">
                  <i class="fas fa-calendar-alt"></i>
                  <h3>No Recent Activity</h3>
                  <p>System is ready for new operations</p>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Calendar Widget -->
        <div class="calendar-widget-section">
          <div class="section-header">
            <h2><i class="fas fa-calendar-alt"></i> Calendar</h2>
            <button class="expand-calendar" onclick="openCalendar()">
              <i class="fas fa-expand"></i>
            </button>
          </div>
          
          <div class="mini-calendar">
            <div class="calendar-header">
              <button class="nav-btn" onclick="previousMonth()">&lt;</button>
              <span class="month-year"><?= date('F Y') ?></span>
              <button class="nav-btn" onclick="nextMonth()">&gt;</button>
            </div>
            
            <div class="calendar-grid" id="miniCalendarGrid">
              <div class="day-header">Sun</div>
              <div class="day-header">Mon</div>
              <div class="day-header">Tue</div>
              <div class="day-header">Wed</div>
              <div class="day-header">Thu</div>
              <div class="day-header">Fri</div>
              <div class="day-header">Sat</div>
              <!-- Days will be generated by JavaScript -->
            </div>
          </div>

         <div class="upcoming-events">
  <h4>Upcoming Events & Training</h4>
  <div class="event-list">
   <?php
try {
  // Get both events and training sessions with better type detection
  $stmt = $pdo->prepare("
    SELECT title, event_date as date, location, major_service, 'event' as type, description
    FROM events 
    WHERE event_date >= CURDATE()
    UNION ALL
    SELECT title, session_date as date, venue as location, major_service, 'training' as type, description
    FROM training_sessions 
    WHERE session_date >= CURDATE()
    ORDER BY date ASC
    LIMIT 6
  ");
  $stmt->execute();
  $upcomingItems = $stmt->fetchAll();
  
  if (!empty($upcomingItems)):
    foreach ($upcomingItems as $item):
      // Better training detection
      $isTraining = $item['type'] === 'training' || 
                   (isset($item['major_service']) && (
                     stripos($item['major_service'], 'training') !== false ||
                     stripos($item['major_service'], 'safety') !== false ||
                     stripos($item['major_service'], 'education') !== false
                   ));
?>
<div class="event-item <?= $isTraining ? 'training-event' : '' ?>">
  <div class="event-date <?= $isTraining ? 'training-date' : '' ?>">
    <div class="day-number"><?= date('j', strtotime($item['date'])) ?></div>
    <div class="month-abbr"><?= date('M', strtotime($item['date'])) ?></div>
  </div>
  <div class="event-details">
    <div class="event-type <?= $isTraining ? 'training' : '' ?>">
      <?= $isTraining ? 'Training' : 'Event' ?>
    </div>
    <div class="event-title"><?= htmlspecialchars($item['title']) ?></div>
    <div class="event-location">
      <i class="fas fa-map-marker-alt"></i> 
      <?= htmlspecialchars($item['location']) ?>
    </div>
    <div class="event-time">
      <i class="fas fa-clock"></i> 
      <?= date('M j, Y', strtotime($item['date'])) ?>
    </div>
  </div>
</div>
<?php
    endforeach;
  else:
?>
<div class="no-upcoming-events">
  <i class="fas fa-calendar-alt"></i>
  <h5>No Upcoming Events</h5>
  <p>Schedule new events or training to see them here</p>
</div>
<?php
  endif;
} catch (Exception $e) {
  echo '<div class="no-upcoming-events"><h5>Unable to load events</h5></div>';
}
?>
  </div>
</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Calendar Modal -->
  <div id="calendarModal" class="calendar-modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2><i class="fas fa-calendar-alt"></i> Event Calendar</h2>
        <button class="close-btn" onclick="closeCalendar()">&times;</button>
      </div>
      
      <div class="modal-body">
        <div class="calendar-controls">
          <button class="nav-btn" onclick="previousMonthModal()">&lt;</button>
          <h3 class="modal-month-year"><?= date('F Y') ?></h3>
          <button class="nav-btn" onclick="nextMonthModal()">&gt;</button>
        </div>
        
        <div class="full-calendar">
          <div class="calendar-grid" id="modalCalendarGrid">
            <div class="day-header">Sunday</div>
            <div class="day-header">Monday</div>
            <div class="day-header">Tuesday</div>
            <div class="day-header">Wednesday</div>
            <div class="day-header">Thursday</div>
            <div class="day-header">Friday</div>
            <div class="day-header">Saturday</div>
            <!-- Full calendar days will be generated by JavaScript -->
          </div>
        </div>
        
        <div class="event-details-panel">
          <h4>Event Details</h4>
          <div id="selectedEventDetails">
            <p>Click on a date with events to view details</p>
          </div>
        </div>
      </div>
      
      <div class="modal-footer">
        <button class="btn secondary" onclick="closeCalendar()">Close</button>
        <button class="btn primary" onclick="addNewEvent()">Add Event</button>
      </div>
    </div>
  </div>

  <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="../user/js/header.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>

  <script>
    // Calendar functionality
    let currentMonth = <?= date('n') - 1 ?>; // JavaScript months are 0-indexed
    let currentYear = <?= date('Y') ?>;
    let events = []; // Will be populated with events from database

    // Fetch events from database
    async function fetchEvents() {
      try {
        const response = await fetch('get_calendar_events.php');
        const data = await response.json();
        events = data;
        updateCalendars();
      } catch (error) {
        console.error('Error fetching events:', error);
      }
    }

    function openCalendar() {
      document.getElementById('calendarModal').style.display = 'flex';
      document.body.style.overflow = 'hidden';
      updateModalCalendar();
    }

    function closeCalendar() {
      document.getElementById('calendarModal').style.display = 'none';
      document.body.style.overflow = 'auto';
    }

    function previousMonth() {
      if (currentMonth === 0) {
        currentMonth = 11;
        currentYear--;
      } else {
        currentMonth--;
      }
      updateMiniCalendar();
    }

    function nextMonth() {
      if (currentMonth === 11) {
        currentMonth = 0;
        currentYear++;
      } else {
        currentMonth++;
      }
      updateMiniCalendar();
    }

    function previousMonthModal() {
      if (currentMonth === 0) {
        currentMonth = 11;
        currentYear--;
      } else {
        currentMonth--;
      }
      updateModalCalendar();
    }

    function nextMonthModal() {
      if (currentMonth === 11) {
        currentMonth = 0;
        currentYear++;
      } else {
        currentMonth++;
      }
      updateModalCalendar();
    }

    function updateMiniCalendar() {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
      document.querySelector('.month-year').textContent = monthNames[currentMonth] + ' ' + currentYear;
      
      const grid = document.getElementById('miniCalendarGrid');
      const dayHeaders = grid.querySelectorAll('.day-header');
      
      // Clear existing days
      const existingDays = grid.querySelectorAll('.day');
      existingDays.forEach(day => day.remove());
      
      generateCalendarDays(grid, true);
    }

    function updateModalCalendar() {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
      document.querySelector('.modal-month-year').textContent = monthNames[currentMonth] + ' ' + currentYear;
      
      const grid = document.getElementById('modalCalendarGrid');
      const dayHeaders = grid.querySelectorAll('.day-header');
      
      // Clear existing days
      const existingDays = grid.querySelectorAll('.day');
      existingDays.forEach(day => day.remove());
      
      generateCalendarDays(grid, false);
    }

function generateCalendarDays(grid, isMini) {
  const firstDay = new Date(currentYear, currentMonth, 1);
  const startDate = new Date(firstDay);
  startDate.setDate(startDate.getDate() - firstDay.getDay());
  
  const today = new Date();
  
  for (let i = 0; i < 42; i++) {
    const cellDate = new Date(startDate);
    cellDate.setDate(startDate.getDate() + i);
    
    const day = document.createElement('div');
    day.className = 'day';
    day.textContent = cellDate.getDate();
    
    // Add classes based on date
    if (cellDate.getMonth() !== currentMonth) {
      day.className += ' other-month';
    }
    
    if (cellDate.toDateString() === today.toDateString()) {
      day.className += ' today';
    }
    
    // Check for events and training on this date
    const dateStr = cellDate.getFullYear() + '-' + 
                   String(cellDate.getMonth() + 1).padStart(2, '0') + '-' + 
                   String(cellDate.getDate()).padStart(2, '0');
    
    const dayEvents = events.filter(event => event.event_date === dateStr);
    
    if (dayEvents.length > 0) {
      // Separate events and training
      const regularEvents = dayEvents.filter(event => event.major_service && !event.major_service.includes('Training'));
      const trainingSessions = dayEvents.filter(event => event.major_service && event.major_service.includes('Training'));
      
      // Apply appropriate classes
      if (regularEvents.length > 0 && trainingSessions.length > 0) {
        day.className += ' has-both';
      } else if (trainingSessions.length > 0) {
        day.className += ' has-training';
      } else {
        day.className += ' has-event';
      }
      
      // Add count indicator for multiple items
      if (dayEvents.length > 1) {
        day.className += ' has-multiple';
        day.setAttribute('data-count', dayEvents.length);
      }
      
      day.setAttribute('data-events', JSON.stringify(dayEvents));
      
      // Add click event for event details
      day.addEventListener('click', function() {
        showEventDetails(dayEvents);
      });
    }
    
    grid.appendChild(day);
  }
}


function showEventDetails(dayEvents) {
  const detailsPanel = document.getElementById('selectedEventDetails');
  
  if (!dayEvents || dayEvents.length === 0) {
    detailsPanel.innerHTML = `
      <div class="empty-details">
        <i class="fas fa-calendar-alt"></i>
        <h5>No Events Selected</h5>
        <p>Click on a date with events to view details</p>
      </div>
    `;
    return;
  }
  
  let html = '';
  
  dayEvents.forEach(event => {
    const isTraining = event.major_service && (
      event.major_service.toLowerCase().includes('training') || 
      event.major_service.toLowerCase().includes('safety') ||
      event.major_service.toLowerCase().includes('education')
    );
    
    const icon = isTraining ? 'graduation-cap' : 'calendar';
    const typeClass = isTraining ? 'training-event' : 'regular-event';
    const eventType = isTraining ? 'Training' : 'Event';
    
    html += `
      <div class="selected-event ${typeClass}">
        <div class="event-type ${isTraining ? 'training' : ''}">${eventType}</div>
        <h5><i class="fas fa-${icon}"></i> ${event.title || 'Untitled Event'}</h5>
        <p><i class="fas fa-calendar"></i> ${new Date(event.event_date).toLocaleDateString('en-US', {
          weekday: 'long',
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        })}</p>
        <p><i class="fas fa-map-marker-alt"></i> ${event.location || event.venue || 'Location TBA'}</p>
        <p><i class="fas fa-tags"></i> ${event.major_service || 'General Service'}</p>
        ${event.description ? `<p class="event-description"><i class="fas fa-info-circle"></i> ${event.description.substring(0, 150)}${event.description.length > 150 ? '...' : ''}</p>` : ''}
      </div>
    `;
  });
  
  detailsPanel.innerHTML = html;
}

    function updateCalendars() {
      updateMiniCalendar();
      if (document.getElementById('calendarModal').style.display === 'flex') {
        updateModalCalendar();
      }
    }

    function refreshActivity() {
      const refreshBtn = document.querySelector('.refresh-btn i');
      const activityIndicator = document.getElementById('activityIndicator');
      
      if (refreshBtn) {
        refreshBtn.style.animation = 'spin 1s linear';
        setTimeout(() => {
          refreshBtn.style.animation = '';
        }, 1000);
      }
      
      if (activityIndicator) {
        activityIndicator.style.color = '#28a745';
        setTimeout(() => {
          activityIndicator.style.color = '';
        }, 500);
      }
      
      // Refresh events
      fetchEvents();
    }

    function addNewEvent() {
      window.location.href = 'manage_events.php?action=add';
    }

    function startAutoRefresh() {
      // Refresh every 30 seconds
      setInterval(() => {
        refreshActivity();
      }, 30000);
      
      // Update live indicators
      const liveIndicator = document.getElementById('liveIndicator');
      const activityIndicator = document.getElementById('activityIndicator');
      
      setInterval(() => {
        if (liveIndicator) {
          liveIndicator.style.color = liveIndicator.style.color === 'rgb(40, 167, 69)' ? '#dc3545' : '#28a745';
        }
        if (activityIndicator) {
          activityIndicator.style.color = '#28a745';
        }
      }, 2000);
    }

    // Event listeners
    document.addEventListener('DOMContentLoaded', function() {
      startAutoRefresh();
      fetchEvents();
      
      // Close modal on outside click
      document.getElementById('calendarModal').addEventListener('click', function(e) {
        if (e.target === this) {
          closeCalendar();
        }
      });
      
      // Activity item interactions
      document.querySelectorAll('.activity-item').forEach(item => {
        item.addEventListener('click', function() {
          const link = this.closest('.activity-card').querySelector('.view-all');
          if (link) {
            window.location.href = link.href;
          }
        });
      });

      // Action button enhancements
      document.querySelectorAll('.notification-action').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-1px)';
        });
        
        btn.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
          case 'e':
            e.preventDefault();
            window.location.href = 'manage_events.php';
            break;
          case 't':
            e.preventDefault();
            window.location.href = 'manage_sessions.php';
            break;
          case 'd':
            e.preventDefault();
            window.location.href = 'manage_donations.php';
            break;
          case 'u':
            e.preventDefault();
            window.location.href = 'manage_users.php';
            break;
          case 'r':
            e.preventDefault();
            refreshActivity();
            break;
          case 'c':
            e.preventDefault();
            openCalendar();
            break;
        }
      }
      
      // ESC to close modal
      if (e.key === 'Escape') {
        closeCalendar();
      }
    });

    // CSS animations
    const style = document.createElement('style');
    style.textContent = `
      @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
      }
    `;
    document.head.appendChild(style);
  </script>
</body>
</html>