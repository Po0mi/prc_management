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
</head>
<body class="admin-<?= htmlspecialchars($user_role) ?>">
  <?php include 'sidebar.php'; ?>
  
  <div class="header-content">
    <div class="dashboard-container">
      
      <!-- Hero Welcome Section -->
      <section class="dashboard-hero">
        <div class="hero-background">
          <div class="hero-overlay"></div>
          <div class="hero-particles">
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
            <div class="particle"></div>
          </div>
        </div>
        <div class="hero-content">
          <div class="hero-badge">
            <i class="fas fa-user-shield"></i>
            <span><?= htmlspecialchars(strtoupper($user_role)) ?> ADMIN</span>
          </div>
          <h1 class="hero-title">
            Welcome back, 
            <span class="title-highlight"><?= htmlspecialchars($username) ?></span>
          </h1>
          <p class="hero-subtitle">
            Philippine Red Cross Administration Portal - Streamlined operations management for efficient service delivery.
          </p>

          <div class="live-indicator">
            <i class="fas fa-circle" id="liveIndicator"></i>
            <span>Live Dashboard - <?php echo date('F d, Y'); ?></span>
          </div>
        </div>
      </section>

      <!-- Priority Notifications Section -->
      <?php if (!empty($adminNotifications)): ?>
      <section class="notifications-section">
        <div class="section-header">
          <div class="header-badge">
            <i class="fas fa-bell"></i>
            <span>System Alerts</span>
          </div>
          <h2>Priority Notifications</h2>
          <div class="notification-summary">
            <?php 
            $criticalCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'critical'; }));
            $highCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'high'; }));
            $mediumCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'medium'; }));
            $lowCount = count(array_filter($adminNotifications, function($n) { return $n['priority'] === 'low'; }));
            ?>
            <div class="summary-badges">
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
              <span class="total-count"><?= count($adminNotifications) ?> total notifications</span>
            </div>
          </div>
        </div>
        
        <div class="notifications-grid">
          <?php foreach ($adminNotifications as $notification): ?>
            <div class="notification-card priority-<?= $notification['priority'] ?> <?= $notification['type'] ?>">
              <div class="notification-priority">
                <div class="priority-indicator priority-<?= $notification['priority'] ?>">
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
              </div>
              
              <div class="notification-icon">
                <i class="fas fa-<?= $notification['icon'] ?>"></i>
              </div>
              
              <div class="notification-content">
                <h3><?= htmlspecialchars($notification['title']) ?></h3>
                <p><?= $notification['message'] ?></p>
                
                <?php if (isset($notification['action'])): ?>
                  <div class="notification-action">
                    <a href="<?= htmlspecialchars($notification['action']['link']) ?>" class="action-btn">
                      <span><?= htmlspecialchars($notification['action']['text']) ?></span>
                      <i class="fas fa-arrow-right"></i>
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
        
        <div class="notification-controls">
          <div class="filter-toggles">
            <button class="filter-btn active" data-priority="all">
              <i class="fas fa-list"></i> All
            </button>
            <?php if ($criticalCount > 0): ?>
              <button class="filter-btn" data-priority="critical">
                <i class="fas fa-exclamation-triangle"></i> Critical
              </button>
            <?php endif; ?>
            <?php if ($highCount > 0): ?>
              <button class="filter-btn" data-priority="high">
                <i class="fas fa-exclamation-circle"></i> High
              </button>
            <?php endif; ?>
          </div>
          
          <div class="control-actions">
            <button class="control-btn" onclick="refreshNotifications()">
              <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button class="control-btn" onclick="markAllNotificationsRead()">
              <i class="fas fa-check-double"></i> Mark All Read
            </button>
          </div>
        </div>
      </section>
      <?php else: ?>
      <section class="notifications-section empty">
        <div class="empty-state">
          <div class="empty-icon">
            <i class="fas fa-bell-slash"></i>
          </div>
          <h3>All Clear</h3>
          <p>No active notifications. All systems are running smoothly.</p>
          <div class="empty-actions">
            <button class="control-btn primary" onclick="refreshNotifications()">
              <i class="fas fa-sync-alt"></i> Check for Updates
            </button>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <!-- Main Content Grid -->
      <div class="dashboard-main">
        
        <!-- Activity Feed -->
        <section class="activity-section">
          <div class="section-header">
            <div class="header-badge">
              <i class="fas fa-activity"></i>
              <span>Recent Activity</span>
            </div>
            <h2>System Activity Feed</h2>
            <div class="activity-controls">
              <button class="control-btn" onclick="refreshActivity()">
                <i class="fas fa-sync-alt"></i>
              </button>
              <button class="control-btn" onclick="openCalendar()">
                <i class="fas fa-calendar"></i>
              </button>
              <div class="auto-refresh-status">
                <i class="fas fa-circle" id="activityIndicator"></i>
                <span>Auto-refresh: ON</span>
              </div>
            </div>
          </div>
          
          <div class="activity-feed">
            
            <!-- Events Activity -->
            <?php if (!empty($activity['events'])): ?>
            <div class="activity-card featured">
              <div class="card-header">
                <div class="card-icon events">
                  <i class="fas fa-calendar"></i>
                </div>
                <div class="card-title">
                  <h3>Recent Events</h3>
                  <span class="card-count"><?= count($activity['events']) ?> items</span>
                </div>
                <a href="manage_events.php" class="card-action">
                  <span>Manage All</span>
                  <i class="fas fa-arrow-right"></i>
                </a>
              </div>
              <div class="card-content">
                <?php foreach ($activity['events'] as $event): ?>
                  <div class="activity-item" data-status="<?= $event['status'] ?>">
                    <div class="item-status status-<?= $event['status'] ?>"></div>
                    <div class="item-content">
                      <h4><?= htmlspecialchars($event['title']) ?></h4>
                      <div class="item-meta">
                        <span class="service-tag"><?= htmlspecialchars($event['major_service']) ?></span>
                        <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(substr($event['location'], 0, 30)) ?>...</span>
                        <span class="registrations"><i class="fas fa-users"></i> <?= $event['registration_count'] ?> registered</span>
                      </div>
                      <div class="item-date"><?= date('M d, Y', strtotime($event['event_date'])) ?></div>
                    </div>
                    <div class="item-badge badge-<?= $event['status'] ?>">
                      <?= ucfirst($event['status']) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Training Requests -->
            <?php if (!empty($activity['training_requests'])): ?>
            <div class="activity-card">
              <div class="card-header">
                <div class="card-icon training">
                  <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="card-title">
                  <h3>Training Requests</h3>
                  <span class="card-count"><?= count($activity['training_requests']) ?> items</span>
                </div>
                <a href="manage_training_requests.php" class="card-action">
                  <span>Manage All</span>
                  <i class="fas fa-arrow-right"></i>
                </a>
              </div>
              <div class="card-content">
                <?php foreach ($activity['training_requests'] as $request): ?>
                  <div class="activity-item" data-status="<?= $request['status'] ?>">
                    <div class="item-status status-<?= $request['status'] ?>"></div>
                    <div class="item-content">
                      <h4><?= htmlspecialchars($request['training_program']) ?></h4>
                      <div class="item-meta">
                        <span class="service-tag"><?= htmlspecialchars($request['service_type']) ?></span>
                        <span class="organization"><i class="fas fa-building"></i> <?= htmlspecialchars($request['organization_name'] ?: 'Individual') ?></span>
                        <span class="participants"><i class="fas fa-users"></i> <?= $request['participant_count'] ?> participants</span>
                      </div>
                      <div class="item-date"><?= date('M d, Y g:i A', strtotime($request['created_at'])) ?></div>
                    </div>
                    <div class="item-badge badge-<?= $request['status'] ?>">
                      <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Inventory Alerts -->
            <?php if (!empty($activity['inventory'])): ?>
            <div class="activity-card alert">
              <div class="card-header">
                <div class="card-icon inventory">
                  <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="card-title">
                  <h3>Inventory Alerts</h3>
                  <span class="card-count"><?= count($activity['inventory']) ?> items</span>
                </div>
                <a href="manage_inventory.php" class="card-action">
                  <span>Manage Stock</span>
                  <i class="fas fa-arrow-right"></i>
                </a>
              </div>
              <div class="card-content">
                <?php foreach ($activity['inventory'] as $item): ?>
                  <div class="activity-item inventory-alert" data-status="<?= $item['stock_status'] ?>">
                    <div class="item-status status-<?= $item['stock_status'] ?>"></div>
                    <div class="item-content">
                      <h4><?= htmlspecialchars($item['item_name']) ?></h4>
                      <div class="item-meta">
                        <span class="quantity stock-<?= $item['stock_status'] ?>">
                          <i class="fas fa-cubes"></i> <?= $item['current_stock'] ?> remaining
                        </span>
                        <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location'] ?? 'N/A') ?></span>
                        <span class="minimum"><i class="fas fa-level-down-alt"></i> Min: <?= $item['minimum_stock'] ?></span>
                      </div>
                    </div>
                    <div class="item-badge badge-<?= $item['stock_status'] ?>">
                      <?= ucfirst(str_replace('_', ' ', $item['stock_status'])) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Additional activity cards for other types -->
            <?php if (!empty($activity['users']) || !empty($activity['donations']) || !empty($activity['volunteers'])): ?>
            <div class="activity-grid">
              
              <!-- Users -->
              <?php if (!empty($activity['users'])): ?>
              <div class="activity-card compact">
                <div class="card-header">
                  <div class="card-icon users">
                    <i class="fas fa-users"></i>
                  </div>
                  <div class="card-title">
                    <h3>New Users</h3>
                    <span class="card-count"><?= count($activity['users']) ?> items</span>
                  </div>
                </div>
                <div class="card-content">
                  <?php foreach (array_slice($activity['users'], 0, 3) as $user): ?>
                    <div class="activity-item compact">
                      <div class="item-content">
                        <h4><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></h4>
                        <div class="item-meta">
                          <span class="registrations"><i class="fas fa-calendar-check"></i> <?= $user['total_registrations'] ?> registrations</span>
                        </div>
                        <div class="item-date"><?= date('M d, Y', strtotime($user['created_at'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>

              <!-- Donations -->
              <?php if (!empty($activity['donations'])): ?>
              <div class="activity-card compact">
                <div class="card-header">
                  <div class="card-icon donations">
                    <i class="fas fa-hand-holding-heart"></i>
                  </div>
                  <div class="card-title">
                    <h3>Recent Donations</h3>
                    <span class="card-count"><?= count($activity['donations']) ?> items</span>
                  </div>
                </div>
                <div class="card-content">
                  <?php foreach (array_slice($activity['donations'], 0, 3) as $donation): ?>
                    <div class="activity-item compact">
                      <div class="item-content">
                        <h4>₱<?= number_format($donation['amount'], 2) ?></h4>
                        <div class="item-meta">
                          <span class="donor"><?= htmlspecialchars($donation['donor_name']) ?></span>
                          <span class="status-badge status-<?= $donation['status'] ?>"><?= ucfirst($donation['status']) ?></span>
                        </div>
                        <div class="item-date"><?= date('M d, Y', strtotime($donation['donation_date'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>

              <!-- Volunteers -->
              <?php if (!empty($activity['volunteers'])): ?>
              <div class="activity-card compact">
                <div class="card-header">
                  <div class="card-icon volunteers">
                    <i class="fas fa-hands-helping"></i>
                  </div>
                  <div class="card-title">
                    <h3>New Volunteers</h3>
                    <span class="card-count"><?= count($activity['volunteers']) ?> items</span>
                  </div>
                </div>
                <div class="card-content">
                  <?php foreach (array_slice($activity['volunteers'], 0, 3) as $volunteer): ?>
                    <div class="activity-item compact">
                      <div class="item-content">
                        <h4><?= htmlspecialchars($volunteer['full_name']) ?></h4>
                        <div class="item-meta">
                          <span class="service-tag"><?= htmlspecialchars($volunteer['service']) ?></span>
                          <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($volunteer['location']) ?></span>
                        </div>
                        <div class="item-date"><?= date('M d, Y', strtotime($volunteer['created_at'])) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>

            </div>
            <?php endif; ?>

            <!-- No Activity State -->
            <?php if (empty($activity['events']) && empty($activity['sessions']) && empty($activity['training_requests']) && 
                       empty($activity['users']) && empty($activity['donations']) && empty($activity['volunteers']) && 
                       empty($activity['inventory'])): ?>
            <div class="empty-state">
              <div class="empty-icon">
                <i class="fas fa-calendar-alt"></i>
              </div>
              <h3>No Recent Activity</h3>
              <p>System is ready for new operations</p>
            </div>
            <?php endif; ?>

          </div>
        </section>

        <!-- Management Hub -->
        <section class="management-section">
          <div class="section-header">
            <div class="header-badge">
              <i class="fas fa-cogs"></i>
              <span>Quick Actions</span>
            </div>
            <h2>Management Hub</h2>
          </div>

          <div class="management-grid">
            
            <!-- Primary Actions -->
            <div class="action-group">
              <h3>
                <i class="fas fa-star"></i>
                Primary Operations
              </h3>
              <div class="action-cards">
                <a href="manage_events.php" class="action-card primary">
                  <div class="action-icon">
                    <i class="fas fa-calendar-plus"></i>
                  </div>
                  <div class="action-content">
                    <h4>Manage Events</h4>
                    <p>Create and oversee community events</p>
                  </div>
                  <div class="action-arrow">
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>

                <a href="manage_sessions.php" class="action-card primary">
                  <div class="action-icon">
                    <i class="fas fa-graduation-cap"></i>
                  </div>
                  <div class="action-content">
                    <h4>Training Sessions</h4>
                    <p>Manage educational programs</p>
                  </div>
                  <div class="action-arrow">
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>

                <a href="manage_users.php" class="action-card primary">
                  <div class="action-icon">
                    <i class="fas fa-users-cog"></i>
                  </div>
                  <div class="action-content">
                    <h4>User Management</h4>
                    <p>Oversee user accounts and permissions</p>
                  </div>
                  <div class="action-arrow">
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>
              </div>
            </div>

            <!-- Secondary Actions -->
            <div class="action-group">
              <h3>
                <i class="fas fa-tools"></i>
                System Tools
              </h3>
              <div class="action-cards">
                <a href="manage_donations.php" class="action-card">
                  <div class="action-icon">
                    <i class="fas fa-donate"></i>
                  </div>
                  <div class="action-content">
                    <h4>Donations</h4>
                    <p>Track and manage contributions</p>
                  </div>
                  <div class="action-arrow">
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>

                <a href="manage_inventory.php" class="action-card">
                  <div class="action-icon">
                    <i class="fas fa-boxes"></i>
                  </div>
                  <div class="action-content">
                    <h4>Inventory</h4>
                    <p>Monitor stock and supplies</p>
                  </div>
                  <div class="action-arrow">
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>

                <a href="manage_volunteers.php" class="action-card">
                  <div class="action-icon">
                    <i class="fas fa-hands-helping"></i>
                  </div>
                  <div class="action-content">
                    <h4>Volunteers</h4>
                    <p>Coordinate volunteer activities</p>
                  </div>
                  <div class="action-arrow">
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>
              </div>
            </div>

            <!-- Calendar Widget -->
            <div class="calendar-widget">
              <div class="widget-header">
                <h3>
                  <i class="fas fa-calendar-alt"></i>
                  Event Calendar
                </h3>
                <button class="expand-btn" onclick="openCalendar()">
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
                <h4>Upcoming This Week</h4>
                <div class="event-list">
                  <?php
                  try {
                    $stmt = $pdo->prepare("
                      SELECT title, event_date as date, location, major_service, 'event' as type, description
                      FROM events 
                      WHERE event_date >= CURDATE()
                      UNION ALL
                      SELECT title, session_date as date, venue as location, major_service, 'training' as type, description
                      FROM training_sessions 
                      WHERE session_date >= CURDATE()
                      ORDER BY date ASC
                      LIMIT 4
                    ");
                    $stmt->execute();
                    $upcomingItems = $stmt->fetchAll();
                    
                    if (!empty($upcomingItems)):
                      foreach ($upcomingItems as $item):
                        $isTraining = $item['type'] === 'training' || 
                                     (isset($item['major_service']) && (
                                       stripos($item['major_service'], 'training') !== false ||
                                       stripos($item['major_service'], 'safety') !== false ||
                                       stripos($item['major_service'], 'education') !== false
                                     ));
                  ?>
                  <div class="event-item <?= $isTraining ? 'training-event' : '' ?>">
                    <div class="event-date">
                      <span class="day"><?= date('j', strtotime($item['date'])) ?></span>
                      <span class="month"><?= date('M', strtotime($item['date'])) ?></span>
                    </div>
                    <div class="event-details">
                      <div class="event-type"><?= $isTraining ? 'Training' : 'Event' ?></div>
                      <h5><?= htmlspecialchars($item['title']) ?></h5>
                      <div class="event-meta">
                        <span class="location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($item['location']) ?></span>
                        <span class="time"><i class="fas fa-clock"></i> <?= date('M j, Y', strtotime($item['date'])) ?></span>
                      </div>
                    </div>
                  </div>
                  <?php
                      endforeach;
                    else:
                  ?>
                  <div class="no-events">
                    <i class="fas fa-calendar-alt"></i>
                    <h5>No Upcoming Events</h5>
                    <p>Schedule new events to see them here</p>
                  </div>
                  <?php
                    endif;
                  } catch (Exception $e) {
                    echo '<div class="no-events"><h5>Unable to load events</h5></div>';
                  }
                  ?>
                </div>
              </div>
            </div>

          </div>
        </section>

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
   // ========================================
    // GLOBAL VARIABLES
    // ========================================
    let currentMonth = <?= date('n') - 1 ?>; // JavaScript months are 0-indexed
    let currentYear = <?= date('Y') ?>;
    let events = []; // Will be populated with events from database

    // ========================================
    // CALENDAR FUNCTIONALITY
    // ========================================
    
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

    // Calendar modal controls
    function openCalendar() {
      document.getElementById('calendarModal').style.display = 'flex';
      document.body.style.overflow = 'hidden';
      updateModalCalendar();
    }

    function closeCalendar() {
      document.getElementById('calendarModal').style.display = 'none';
      document.body.style.overflow = 'auto';
    }

    // Navigation functions
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

    // Calendar update functions
    function updateMiniCalendar() {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
      const monthYearElement = document.querySelector('.month-year');
      if (monthYearElement) {
        monthYearElement.textContent = monthNames[currentMonth] + ' ' + currentYear;
      }
      
      const grid = document.getElementById('miniCalendarGrid');
      if (grid) {
        // Clear existing days
        const existingDays = grid.querySelectorAll('.day');
        existingDays.forEach(day => day.remove());
        generateCalendarDays(grid, true);
      }
    }

    function updateModalCalendar() {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
      const modalMonthElement = document.querySelector('.modal-month-year');
      if (modalMonthElement) {
        modalMonthElement.textContent = monthNames[currentMonth] + ' ' + currentYear;
      }
      
      const grid = document.getElementById('modalCalendarGrid');
      if (grid) {
        // Clear existing days
        const existingDays = grid.querySelectorAll('.day');
        existingDays.forEach(day => day.remove());
        generateCalendarDays(grid, false);
      }
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
      
      if (!detailsPanel) return;
      
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
      if (document.getElementById('calendarModal') && document.getElementById('calendarModal').style.display === 'flex') {
        updateModalCalendar();
      }
    }

    function addNewEvent() {
      window.location.href = 'manage_events.php?action=add';
    }

    // ========================================
    // NOTIFICATION FUNCTIONALITY
    // ========================================
    
    function refreshNotifications() {
      const btn = event.target.closest('button');
      const icon = btn ? btn.querySelector('i') : event.target;
      
      if (icon) {
        icon.style.animation = 'spin 1s linear';
        setTimeout(() => {
          icon.style.animation = '';
        }, 1000);
      }
      
      // Trigger refresh indicator
      const indicator = document.getElementById('liveIndicator');
      if (indicator) {
        indicator.style.color = '#10b981';
        setTimeout(() => {
          indicator.style.color = '';
        }, 500);
      }
      
      console.log('Refreshing notifications...');
    }

    function markAllNotificationsRead() {
      const cards = document.querySelectorAll('.notification-card');
      
      if (cards.length === 0) return;
      
      // Hide all notification cards with animation
      cards.forEach((card, index) => {
        setTimeout(() => {
          card.style.opacity = '0.5';
          card.style.transform = 'translateX(20px)';
          setTimeout(() => {
            card.style.display = 'none';
          }, 300);
        }, index * 100);
      });
      
      // Update summary badges
      setTimeout(() => {
        const summaryBadges = document.querySelector('.summary-badges');
        if (summaryBadges) {
          summaryBadges.innerHTML = '<span class="total-count">All notifications cleared</span>';
        }
        
        // Show empty state after a delay
        setTimeout(() => {
          const notificationsSection = document.querySelector('.notifications-section');
          if (notificationsSection && !notificationsSection.classList.contains('empty')) {
            notificationsSection.classList.add('empty');
            notificationsSection.innerHTML = `
              <div class="empty-state">
                <div class="empty-icon">
                  <i class="fas fa-bell-slash"></i>
                </div>
                <h3>All Clear</h3>
                <p>No active notifications. All systems are running smoothly.</p>
                <div class="empty-actions">
                  <button class="control-btn primary" onclick="refreshNotifications()">
                     Check for Updates
                  </button>
                </div>
              </div>
            `;
          }
        }, 1000);
      }, cards.length * 100 + 300);
    }

    function initializeNotificationFilters() {
      const filterButtons = document.querySelectorAll('.filter-btn');
      const notificationCards = document.querySelectorAll('.notification-card');
      
      filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
          // Remove active class from all buttons
          filterButtons.forEach(b => b.classList.remove('active'));
          
          // Add active class to clicked button
          this.classList.add('active');
          
          const priority = this.getAttribute('data-priority');
          
          // Filter notification cards
          notificationCards.forEach(card => {
            if (priority === 'all') {
              // Show all cards
              card.style.display = 'flex';
              card.style.opacity = '1';
              card.style.transform = 'translateX(0)';
            } else if (card.classList.contains(`priority-${priority}`)) {
              // Show cards matching the priority
              card.style.display = 'flex';
              card.style.opacity = '1';
              card.style.transform = 'translateX(0)';
            } else {
              // Hide cards that don't match
              card.style.opacity = '0.3';
              card.style.transform = 'translateX(-10px)';
              setTimeout(() => {
                if (!card.classList.contains(`priority-${priority}`) && priority !== 'all') {
                  card.style.display = 'none';
                }
              }, 200);
            }
          });
          
          // Update visible count
          setTimeout(() => {
            const visibleCards = Array.from(notificationCards).filter(card => 
              card.style.display !== 'none' && card.style.opacity !== '0.3'
            );
            
            console.log(`Showing ${visibleCards.length} notifications for priority: ${priority}`);
          }, 300);
        });
      });
    }

    function initializeNotificationInteractions() {
      const notificationCards = document.querySelectorAll('.notification-card');
      
      notificationCards.forEach(card => {
        // Add hover effects
        card.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-2px)';
          this.style.boxShadow = '0 8px 25px rgba(0, 0, 0, 0.15)';
        });
        
        card.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
          this.style.boxShadow = '';
        });
        
        // Add click to dismiss functionality
        const priorityIndicator = card.querySelector('.priority-indicator');
        if (priorityIndicator) {
          priorityIndicator.addEventListener('click', function(e) {
            e.stopPropagation();
            
            // Animate card removal
            card.style.opacity = '0';
            card.style.transform = 'translateX(100px)';
            
            setTimeout(() => {
              card.remove();
              updateNotificationCounts();
            }, 300);
          });
          
          // Add tooltip
          priorityIndicator.title = 'Click to dismiss this notification';
        }
      });
    }

    function updateNotificationCounts() {
      const remainingCards = document.querySelectorAll('.notification-card');
      const totalCount = remainingCards.length;
      
      // Count by priority
      const criticalCount = document.querySelectorAll('.notification-card.priority-critical').length;
      const highCount = document.querySelectorAll('.notification-card.priority-high').length;
      const mediumCount = document.querySelectorAll('.notification-card.priority-medium').length;
      const lowCount = document.querySelectorAll('.notification-card.priority-low').length;
      
      // Update summary badges
      const summaryBadges = document.querySelector('.summary-badges');
      if (summaryBadges && totalCount > 0) {
        let badgesHTML = '';
        
        if (criticalCount > 0) {
          badgesHTML += `<span class="priority-badge critical">${criticalCount} Critical</span>`;
        }
        if (highCount > 0) {
          badgesHTML += `<span class="priority-badge high">${highCount} High</span>`;
        }
        if (mediumCount > 0) {
          badgesHTML += `<span class="priority-badge medium">${mediumCount} Medium</span>`;
        }
        if (lowCount > 0) {
          badgesHTML += `<span class="priority-badge low">${lowCount} Low</span>`;
        }
        
        badgesHTML += `<span class="total-count">${totalCount} total notifications</span>`;
        summaryBadges.innerHTML = badgesHTML;
      } else if (totalCount === 0) {
        // Show empty state
        const notificationsSection = document.querySelector('.notifications-section');
        if (notificationsSection) {
          notificationsSection.classList.add('empty');
          notificationsSection.innerHTML = `
            <div class="empty-state">
              <div class="empty-icon">
                <i class="fas fa-bell-slash"></i>
              </div>
              <h3>All Clear</h3>
              <p>No active notifications. All systems are running smoothly.</p>
              <div class="empty-actions">
                <button class="control-btn primary" onclick="refreshNotifications()">
                  <i class="fas fa-sync-alt"></i> Check for Updates
                </button>
              </div>
            </div>
          `;
        }
      }
      
      // Update filter button counts
      const filterButtons = document.querySelectorAll('.filter-btn');
      filterButtons.forEach(btn => {
        const priority = btn.getAttribute('data-priority');
        
        if (priority === 'all') {
          btn.innerHTML = `<i class="fas fa-list"></i> All (${totalCount})`;
        } else if (priority === 'critical' && criticalCount > 0) {
          btn.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Critical (${criticalCount})`;
        } else if (priority === 'high' && highCount > 0) {
          btn.innerHTML = `<i class="fas fa-exclamation-circle"></i> High (${highCount})`;
        } else if (priority === 'medium' && mediumCount > 0) {
          btn.innerHTML = `<i class="fas fa-info-circle"></i> Medium (${mediumCount})`;
        } else if (priority === 'low' && lowCount > 0) {
          btn.innerHTML = `<i class="fas fa-check-circle"></i> Low (${lowCount})`;
        }
      });
    }

    // ========================================
    // ACTIVITY & REFRESH FUNCTIONALITY
    // ========================================
    
    function refreshActivity() {
      const activityIndicator = document.getElementById('activityIndicator');
      
      if (activityIndicator) {
        activityIndicator.style.color = '#10b981';
        setTimeout(() => {
          activityIndicator.style.color = '';
        }, 500);
      }
      
      // Refresh events
      fetchEvents();
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
          liveIndicator.style.color = liveIndicator.style.color === 'rgb(16, 185, 129)' ? '#dc3545' : '#10b981';
        }
        if (activityIndicator) {
          activityIndicator.style.color = '#10b981';
        }
      }, 2000);
    }

    // ========================================
    // ANIMATION & UI ENHANCEMENTS
    // ========================================
    
    function animateCounters() {
      const counters = document.querySelectorAll('.stat-number');
      counters.forEach(counter => {
        const target = parseInt(counter.getAttribute('data-target')) || parseInt(counter.textContent);
        const duration = 2000;
        const steps = 60;
        const increment = target / steps;
        
        let current = 0;
        let step = 0;

        const timer = setInterval(() => {
          current += increment;
          step++;
          
          if (step >= steps) {
            current = target;
            clearInterval(timer);
          }
          
          counter.textContent = Math.floor(current);
        }, duration / steps);
      });
    }

    function initializeActivityInteractions() {
      const activityItems = document.querySelectorAll('.activity-item');
      activityItems.forEach(item => {
        item.addEventListener('click', function() {
          const link = this.closest('.activity-card').querySelector('.card-action');
          if (link) {
            window.location.href = link.href;
          }
        });
      });

      // Action button enhancements
      const notificationActions = document.querySelectorAll('.notification-action');
      notificationActions.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-1px)';
        });
        
        btn.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });
    }

    // ========================================
    // KEYBOARD SHORTCUTS
    // ========================================
    
    function initializeKeyboardShortcuts() {
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
    }

    // ========================================
    // INITIALIZATION
    // ========================================
    
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize auto-refresh and fetch initial data
      startAutoRefresh();
      fetchEvents();
      
      // Animate counters on load
      setTimeout(animateCounters, 500);
      
      // Initialize all functionality
      initializeNotificationFilters();
      initializeNotificationInteractions();
      initializeActivityInteractions();
      initializeKeyboardShortcuts();
      
      // Close modal on outside click
      const calendarModal = document.getElementById('calendarModal');
      if (calendarModal) {
        calendarModal.addEventListener('click', function(e) {
          if (e.target === this) {
            closeCalendar();
          }
        });
      }
      
      console.log('Modern dashboard initialized successfully');
    });

    // ========================================
    // DYNAMIC STYLES
    // ========================================
    
    const dashboardStyles = document.createElement('style');
    dashboardStyles.textContent = `
      .notification-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
      
      .priority-indicator {
        cursor: pointer;
        transition: all 0.2s ease;
      }
      
      .priority-indicator:hover {
        transform: scale(1.1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
      }
      
      .filter-btn {
        transition: all 0.2s ease;
      }
      
      @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
      }
      
      .activity-item {
        cursor: pointer;
        transition: all 0.2s ease;
      }
      
      .activity-item:hover {
        background-color: rgba(160, 0, 0, 0.05);
      }
    `;
    document.head.appendChild(dashboardStyles);
  </script>
  <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>">numeric',
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
      if (document.getElementById('calendarModal') && document.getElementById('calendarModal').style.display === 'flex') {
        updateModalCalendar();
      }
    }

    function addNewEvent() {
      window.location.href = 'manage_events.php?action=add';
    }

    // ========================================
    // NOTIFICATION FUNCTIONALITY
    // ========================================
    
    function refreshNotifications() {
      const btn = event.target.closest('button');
      const icon = btn ? btn.querySelector('i') : event.target;
      
      if (icon) {
        icon.style.animation = 'spin 1s linear';
        setTimeout(() => {
          icon.style.animation = '';
        }, 1000);
      }
      
      // Trigger refresh indicator
      const indicator = document.getElementById('liveIndicator');
      if (indicator) {
        indicator.style.color = '#10b981';
        setTimeout(() => {
          indicator.style.color = '';
        }, 500);
      }
      
      console.log('Refreshing notifications...');
    }

    function markAllNotificationsRead() {
      const cards = document.querySelectorAll('.notification-card');
      
      if (cards.length === 0) return;
      
      // Hide all notification cards with animation
      cards.forEach((card, index) => {
        setTimeout(() => {
          card.style.opacity = '0.5';
          card.style.transform = 'translateX(20px)';
          setTimeout(() => {
            card.style.display = 'none';
          }, 300);
        }, index * 100);
      });
      
      // Update summary badges
      setTimeout(() => {
        const summaryBadges = document.querySelector('.summary-badges');
        if (summaryBadges) {
          summaryBadges.innerHTML = '<span class="total-count">All notifications cleared</span>';
        }
        
        // Show empty state after a delay
        setTimeout(() => {
          const notificationsSection = document.querySelector('.notifications-section');
          if (notificationsSection && !notificationsSection.classList.contains('empty')) {
            notificationsSection.classList.add('empty');
            notificationsSection.innerHTML = `
              <div class="empty-state">
                <div class="empty-icon">
                  <i class="fas fa-bell-slash"></i>
                </div>
                <h3>All Clear</h3>
                <p>No active notifications. All systems are running smoothly.</p>
                <div class="empty-actions">
                  <button class="control-btn primary" onclick="refreshNotifications()">
                     Check for Updates
                  </button>
                </div>
              </div>
            `;
          }
        }, 1000);
      }, cards.length * 100 + 300);
    }

    function initializeNotificationFilters() {
      const filterButtons = document.querySelectorAll('.filter-btn');
      const notificationCards = document.querySelectorAll('.notification-card');
      
      filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
          // Remove active class from all buttons
          filterButtons.forEach(b => b.classList.remove('active'));
          
          // Add active class to clicked button
          this.classList.add('active');
          
          const priority = this.getAttribute('data-priority');
          
          // Filter notification cards
          notificationCards.forEach(card => {
            if (priority === 'all') {
              // Show all cards
              card.style.display = 'flex';
              card.style.opacity = '1';
              card.style.transform = 'translateX(0)';
            } else if (card.classList.contains(`priority-${priority}`)) {
              // Show cards matching the priority
              card.style.display = 'flex';
              card.style.opacity = '1';
              card.style.transform = 'translateX(0)';
            } else {
              // Hide cards that don't match
              card.style.opacity = '0.3';
              card.style.transform = 'translateX(-10px)';
              setTimeout(() => {
                if (!card.classList.contains(`priority-${priority}`) && priority !== 'all') {
                  card.style.display = 'none';
                }
              }, 200);
            }
          });
          
          // Update visible count
          setTimeout(() => {
            const visibleCards = Array.from(notificationCards).filter(card => 
              card.style.display !== 'none' && card.style.opacity !== '0.3'
            );
            
            console.log(`Showing ${visibleCards.length} notifications for priority: ${priority}`);
          }, 300);
        });
      });
    }

    function initializeNotificationInteractions() {
      const notificationCards = document.querySelectorAll('.notification-card');
      
      notificationCards.forEach(card => {
        // Add hover effects
        card.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-2px)';
          this.style.boxShadow = '0 8px 25px rgba(0, 0, 0, 0.15)';
        });
        
        card.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
          this.style.boxShadow = '';
        });
        
        // Add click to dismiss functionality
        const priorityIndicator = card.querySelector('.priority-indicator');
        if (priorityIndicator) {
          priorityIndicator.addEventListener('click', function(e) {
            e.stopPropagation();
            
            // Animate card removal
            card.style.opacity = '0';
            card.style.transform = 'translateX(100px)';
            
            setTimeout(() => {
              card.remove();
              updateNotificationCounts();
            }, 300);
          });
          
          // Add tooltip
          priorityIndicator.title = 'Click to dismiss this notification';
        }
      });
    }

    function updateNotificationCounts() {
      const remainingCards = document.querySelectorAll('.notification-card');
      const totalCount = remainingCards.length;
      
      // Count by priority
      const criticalCount = document.querySelectorAll('.notification-card.priority-critical').length;
      const highCount = document.querySelectorAll('.notification-card.priority-high').length;
      const mediumCount = document.querySelectorAll('.notification-card.priority-medium').length;
      const lowCount = document.querySelectorAll('.notification-card.priority-low').length;
      
      // Update summary badges
      const summaryBadges = document.querySelector('.summary-badges');
      if (summaryBadges && totalCount > 0) {
        let badgesHTML = '';
        
        if (criticalCount > 0) {
          badgesHTML += `<span class="priority-badge critical">${criticalCount} Critical</span>`;
        }
        if (highCount > 0) {
          badgesHTML += `<span class="priority-badge high">${highCount} High</span>`;
        }
        if (mediumCount > 0) {
          badgesHTML += `<span class="priority-badge medium">${mediumCount} Medium</span>`;
        }
        if (lowCount > 0) {
          badgesHTML += `<span class="priority-badge low">${lowCount} Low</span>`;
        }
        
        badgesHTML += `<span class="total-count">${totalCount} total notifications</span>`;
        summaryBadges.innerHTML = badgesHTML;
      } else if (totalCount === 0) {
        // Show empty state
        const notificationsSection = document.querySelector('.notifications-section');
        if (notificationsSection) {
          notificationsSection.classList.add('empty');
          notificationsSection.innerHTML = `
            <div class="empty-state">
              <div class="empty-icon">
                <i class="fas fa-bell-slash"></i>
              </div>
              <h3>All Clear</h3>
              <p>No active notifications. All systems are running smoothly.</p>
              <div class="empty-actions">
                <button class="control-btn primary" onclick="refreshNotifications()">
                  <i class="fas fa-sync-alt"></i> Check for Updates
                </button>
              </div>
            </div>
          `;
        }
      }
      
      // Update filter button counts
      const filterButtons = document.querySelectorAll('.filter-btn');
      filterButtons.forEach(btn => {
        const priority = btn.getAttribute('data-priority');
        
        if (priority === 'all') {
          btn.innerHTML = `<i class="fas fa-list"></i> All (${totalCount})`;
        } else if (priority === 'critical' && criticalCount > 0) {
          btn.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Critical (${criticalCount})`;
        } else if (priority === 'high' && highCount > 0) {
          btn.innerHTML = `<i class="fas fa-exclamation-circle"></i> High (${highCount})`;
        } else if (priority === 'medium' && mediumCount > 0) {
          btn.innerHTML = `<i class="fas fa-info-circle"></i> Medium (${mediumCount})`;
        } else if (priority === 'low' && lowCount > 0) {
          btn.innerHTML = `<i class="fas fa-check-circle"></i> Low (${lowCount})`;
        }
      });
    }

    // ========================================
    // ACTIVITY & REFRESH FUNCTIONALITY
    // ========================================
    
    function refreshActivity() {
      const activityIndicator = document.getElementById('activityIndicator');
      
      if (activityIndicator) {
        activityIndicator.style.color = '#10b981';
        setTimeout(() => {
          activityIndicator.style.color = '';
        }, 500);
      }
      
      // Refresh events
      fetchEvents();
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
          liveIndicator.style.color = liveIndicator.style.color === 'rgb(16, 185, 129)' ? '#dc3545' : '#10b981';
        }
        if (activityIndicator) {
          activityIndicator.style.color = '#10b981';
        }
      }, 2000);
    }

    // ========================================
    // ANIMATION & UI ENHANCEMENTS
    // ========================================
    
    function animateCounters() {
      const counters = document.querySelectorAll('.stat-number');
      counters.forEach(counter => {
        const target = parseInt(counter.getAttribute('data-target')) || parseInt(counter.textContent);
        const duration = 2000;
        const steps = 60;
        const increment = target / steps;
        
        let current = 0;
        let step = 0;

        const timer = setInterval(() => {
          current += increment;
          step++;
          
          if (step >= steps) {
            current = target;
            clearInterval(timer);
          }
          
          counter.textContent = Math.floor(current);
        }, duration / steps);
      });
    }

    function initializeActivityInteractions() {
      const activityItems = document.querySelectorAll('.activity-item');
      activityItems.forEach(item => {
        item.addEventListener('click', function() {
          const link = this.closest('.activity-card').querySelector('.card-action');
          if (link) {
            window.location.href = link.href;
          }
        });
      });

      // Action button enhancements
      const notificationActions = document.querySelectorAll('.notification-action');
      notificationActions.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-1px)';
        });
        
        btn.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });
    }

    // ========================================
    // KEYBOARD SHORTCUTS
    // ========================================
    
    function initializeKeyboardShortcuts() {
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
    }

    // ========================================
    // INITIALIZATION
    // ========================================
    
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize auto-refresh and fetch initial data
      startAutoRefresh();
      fetchEvents();
      
      // Animate counters on load
      setTimeout(animateCounters, 500);
      
      // Initialize all functionality
      initializeNotificationFilters();
      initializeNotificationInteractions();
      initializeActivityInteractions();
      initializeKeyboardShortcuts();
      
      // Close modal on outside click
      const calendarModal = document.getElementById('calendarModal');
      if (calendarModal) {
        calendarModal.addEventListener('click', function(e) {
          if (e.target === this) {
            closeCalendar();
          }
        });
      }
      
      console.log('Modern dashboard initialized successfully');
    });

    // ========================================
    // DYNAMIC STYLES
    // ========================================
    
    const dashboardStyles = document.createElement('style');
    dashboardStyles.textContent = `
      .notification-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
      
      .priority-indicator {
        cursor: pointer;
        transition: all 0.2s ease;
      }
      
      .priority-indicator:hover {
        transform: scale(1.1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
      }
      
      .filter-btn {
        transition: all 0.2s ease;
      }
      
      @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
      }
      
      .activity-item {
        cursor: pointer;
        transition: all 0.2s ease;
      }
      
      .activity-item:hover {
        background-color: rgba(160, 0, 0, 0.05);
      }
    `;
    document.head.appendChild(dashboardStyles);
  </script>
  <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>">criticalCount} Critical</span>`;
        }
        if (highCount > 0) {
          badgesHTML += `<span class="priority-badge high">${highCount} High</span>`;
        }
        if (mediumCount > 0) {
          badgesHTML += `<span class="priority-badge medium">${mediumCount} Medium</span>`;
        }
        if (lowCount > 0) {
          badgesHTML += `<span class="priority-badge low">${lowCount} Low</span>`;
        }
        
        badgesHTML += `<span class="total-count">${totalCount} total notifications</span>`;
        summaryBadges.innerHTML = badgesHTML;
      } else if (totalCount === 0) {
        // Show empty state
        const notificationsSection = document.querySelector('.notifications-section');
        if (notificationsSection) {
          notificationsSection.classList.add('empty');
          notificationsSection.innerHTML = `
            <div class="empty-state">
              <div class="empty-icon">
                <i class="fas fa-bell-slash"></i>
              </div>
              <h3>All Clear</h3>
              <p>No active notifications. All systems are running smoothly.</p>
              <div class="empty-actions">
                <button class="control-btn primary" onclick="refreshNotifications()">
                  <i class="fas fa-sync-alt"></i> Check for Updates
                </button>
              </div>
            </div>
          `;
        }
      }
      
      // Update filter button counts
      const filterButtons = document.querySelectorAll('.filter-btn');
      filterButtons.forEach(btn => {
        const priority = btn.getAttribute('data-priority');
        const icon = btn.querySelector('i');
        const text = btn.textContent.trim();
        
        if (priority === 'all') {
          btn.innerHTML = `<i class="fas fa-list"></i> All (${totalCount})`;
        } else if (priority === 'critical' && criticalCount > 0) {
          btn.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Critical (${criticalCount})`;
        } else if (priority === 'high' && highCount > 0) {
          btn.innerHTML = `<i class="fas fa-exclamation-circle"></i> High (${highCount})`;
        } else if (priority === 'medium' && mediumCount > 0) {
          btn.innerHTML = `<i class="fas fa-info-circle"></i> Medium (${mediumCount})`;
        } else if (priority === 'low' && lowCount > 0) {
          btn.innerHTML = `<i class="fas fa-check-circle"></i> Low (${lowCount})`;
        }
      });
    }

    // Initialize dashboard
    document.addEventListener('DOMContentLoaded', function() {
      // Animate counters on load
      setTimeout(animateCounters, 500);
      
      // Initialize notification functionality
      initializeNotificationFilters();
      initializeNotificationInteractions();

      // Add hover effects to activity items
      const activityItems = document.querySelectorAll('.activity-item');
      activityItems.forEach(item => {
        item.addEventListener('click', function() {
          const link = this.closest('.activity-card').querySelector('.card-action');
          if (link) {
            window.location.href = link.href;
          }
        });
      });

      console.log('Modern dashboard initialized with enhanced notifications');
    });

    // Add CSS for smooth transitions
    const style = document.createElement('style');
    style.textContent = `
      .notification-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
      
      .priority-indicator {
        cursor: pointer;
        transition: all 0.2s ease;
      }
      
      .priority-indicator:hover {
        transform: scale(1.1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
      }
      
      .filter-btn {
        transition: all 0.2s ease;
      }
      
      @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
      }
    `;
    document.head.appendChild(style);
  </script>
</body>
</html>