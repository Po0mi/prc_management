<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

$user_role = get_user_role();
if ($user_role) {
    // If user has an admin role, redirect to admin dashboard
    header("Location: /admin/dashboard.php");
    exit;
}

$pdo = $GLOBALS['pdo'];
$userId = current_user_id();
$username = current_username();

// Get user information including user type and services
$userStmt = $pdo->prepare("SELECT user_type, services, created_at FROM users WHERE user_id = ?");
$userStmt->execute([$userId]);
$userInfo = $userStmt->fetch();

$userType = $userInfo['user_type'] ?? 'guest';
$userServices = $userInfo['services'] ? json_decode($userInfo['services'], true) : [];
$userCreatedAt = $userInfo['created_at'];

// Check if user is new (registered within last 7 days)
$isNewUser = (strtotime($userCreatedAt) > strtotime('-7 days'));

// Get user's selected services from user_services table
if ($userType === 'rcy_member') {
    $servicesStmt = $pdo->prepare("SELECT service_type FROM user_services WHERE user_id = ?");
    $servicesStmt->execute([$userId]);
    $dbServices = $servicesStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($dbServices)) {
        $userServices = $dbServices;
    }
}

// Service names mapping
$serviceNames = [
    'health' => 'Health Services',
    'safety' => 'Safety Services',
    'welfare' => 'Welfare Services',
    'disaster_management' => 'Disaster Management',
    'red_cross_youth' => 'Red Cross Youth'
];

// Get user statistics (for sidebar summary only)
$userStats = [];

// Events registered
$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
$stmt->execute([$userId]);
$userStats['events_registered'] = $stmt->fetchColumn();

// Events attended (approved)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$userId]);
$userStats['events_attended'] = $stmt->fetchColumn();

// Training sessions - check if session_registrations table exists and has correct columns
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userStats['training_sessions'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $userStats['training_sessions'] = 0;
}

// Donations made - check if donors table exists
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE donor_id IN (SELECT donor_id FROM donors WHERE user_id = ?)");
    $stmt->execute([$userId]);
    $userStats['donations_made'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE user_id = ?");
        $stmt->execute([$userId]);
        $userStats['donations_made'] = $stmt->fetchColumn();
    } catch (PDOException $e2) {
        $userStats['donations_made'] = 0;
    }
}

// Get latest registration status
$regStmt = $pdo->prepare("
    SELECT r.status, r.registration_date, e.title, e.event_date, e.location
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    WHERE r.user_id = ?
    ORDER BY r.registration_date DESC LIMIT 1
");
$regStmt->execute([$userId]);
$latestReg = $regStmt->fetch();

// Get upcoming training sessions
try {
    $trainStmt = $pdo->prepare("
        SELECT ts.*, sr.registration_date as user_registered
        FROM training_sessions ts
        LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id AND sr.user_id = ?
        WHERE ts.session_date >= CURDATE() 
        ORDER BY ts.session_date ASC 
        LIMIT 3
    ");
    $trainStmt->execute([$userId]);
    $upcomingTraining = $trainStmt->fetchAll();
} catch (PDOException $e) {
    $trainStmt = $pdo->prepare("
        SELECT * FROM training_sessions 
        WHERE session_date >= CURDATE() 
        ORDER BY session_date ASC 
        LIMIT 3
    ");
    $trainStmt->execute();
    $upcomingTraining = $trainStmt->fetchAll();
    foreach ($upcomingTraining as &$session) {
        $session['user_registered'] = null;
    }
}

// Get recent events
$eventsStmt = $pdo->prepare("
    SELECT e.*, r.status as registration_status
    FROM events e
    LEFT JOIN registrations r ON e.event_id = r.event_id AND r.user_id = ?
    WHERE e.event_date >= CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 3
");
$eventsStmt->execute([$userId]);
$upcomingEvents = $eventsStmt->fetchAll();

// Get recent announcements
try {
    $announcements = $pdo->query("
        SELECT title, content, created_at 
        FROM announcements 
        WHERE status = 'published'
        ORDER BY created_at DESC 
        LIMIT 3
    ")->fetchAll();
} catch (PDOException $e) {
    try {
        $announcements = $pdo->query("
            SELECT title, content, announcement_date as created_at 
            FROM announcements 
            WHERE status = 'published'
            ORDER BY announcement_date DESC 
            LIMIT 3
        ")->fetchAll();
    } catch (PDOException $e2) {
        $announcements = [];
    }
}

// Enhanced notification system
function getUserNotifications($latestReg, $upcomingEvents, $announcements, $isNewUser, $userType, $userServices, $serviceNames) {
    $notifications = [];
    
    // New RCY Member Welcome notification
    if ($userType === 'rcy_member' && $isNewUser && !empty($userServices)) {
        $notifications[] = [
            'type' => 'success',
            'icon' => 'hands-helping',
            'title' => 'Welcome to Red Cross Youth!',
            'message' => 'Congratulations on joining RCY! We have scheduled orientation meetings for your selected services: ' . 
                        implode(', ', array_map(function($service) use ($serviceNames) {
                            return $serviceNames[$service] ?? ucfirst(str_replace('_', ' ', $service));
                        }, array_slice($userServices, 0, 2))) . 
                        (count($userServices) > 2 ? ' and ' . (count($userServices) - 2) . ' more' : '') . '.',
            'urgency' => 'medium'
        ];
    }
    
    // Registration status notifications
    if ($latestReg) {
        if ($latestReg['status'] === 'pending') {
            $notifications[] = [
                'type' => 'warning',
                'icon' => 'clock',
                'title' => 'Registration Pending Review',
                'message' => "Your registration for \"{$latestReg['title']}\" is awaiting approval. Event date: " . 
                           date('M d, Y', strtotime($latestReg['event_date'])) . " at {$latestReg['location']}.",
                'urgency' => 'medium'
            ];
        } elseif ($latestReg['status'] === 'approved') {
            $notifications[] = [
                'type' => 'success',
                'icon' => 'check-circle',
                'title' => 'Registration Approved!',
                'message' => "Your registration for \"{$latestReg['title']}\" has been approved. Event date: " . 
                           date('M d, Y', strtotime($latestReg['event_date'])) . " at {$latestReg['location']}.",
                'urgency' => 'low'
            ];
        }
    }
    
    // Event deadline notifications
    if (!empty($upcomingEvents)) {
        foreach ($upcomingEvents as $event) {
            $daysUntil = ceil((strtotime($event['event_date']) - time()) / (60 * 60 * 24));
            if ($daysUntil <= 7 && $daysUntil > 0 && !$event['registration_status']) {
                $notifications[] = [
                    'type' => 'deadline',
                    'icon' => 'exclamation-triangle',
                    'title' => 'Event Registration Deadline Approaching',
                    'message' => "Don't miss out on \"{$event['title']}\" - only {$daysUntil} days left to register!",
                    'urgency' => 'high'
                ];
                break; // Only show one deadline notification
            }
        }
    }
    
    // New announcement notification
    if (!empty($announcements)) {
        $recentAnnouncement = $announcements[0];
        if (strtotime($recentAnnouncement['created_at']) > strtotime('-3 days')) {
            $notifications[] = [
                'type' => 'announcement',
                'icon' => 'bullhorn',
                'title' => 'New Announcement Posted',
                'message' => substr($recentAnnouncement['content'], 0, 100) . '...',
                'urgency' => 'medium'
            ];
        }
    }
    
    // Sort by urgency (high, medium, low)
    $urgencyOrder = ['high' => 1, 'medium' => 2, 'low' => 3];
    usort($notifications, function($a, $b) use ($urgencyOrder) {
        return $urgencyOrder[$a['urgency']] - $urgencyOrder[$b['urgency']];
    });
    
    return $notifications;
}

$userNotifications = getUserNotifications($latestReg, $upcomingEvents, $announcements, $isNewUser, $userType, $userServices, $serviceNames);

// Mock upcoming service meetings for new RCY members
$upcomingMeetings = [];
if ($userType === 'rcy_member' && $isNewUser && !empty($userServices)) {
    $meetingDates = [
        'health' => '2025-09-15 14:00:00',
        'safety' => '2025-09-18 15:30:00',
        'welfare' => '2025-09-20 13:00:00',
        'disaster_management' => '2025-09-22 10:00:00',
        'red_cross_youth' => '2025-09-25 16:00:00'
    ];
    
    foreach ($userServices as $service) {
        if (isset($meetingDates[$service])) {
            $upcomingMeetings[] = [
                'service' => $service,
                'service_name' => $serviceNames[$service],
                'meeting_date' => $meetingDates[$service],
                'location' => 'PRC National Headquarters',
                'type' => 'Orientation Meeting'
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Dashboard - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="header-content">
    <?php include 'header.php'; ?>
    
    <div class="dashboard-container">
      <!-- Welcome Section -->
      <div class="welcome-section">
        <div class="welcome-content">
          <h1>
            Welcome back, <?= htmlspecialchars($username) ?>!
            <span class="user-type-badge <?= $userType ?>">
              <?php 
              switch($userType) {
                case 'rcy_member': 
                  echo '<i class="fas fa-user-shield"></i> RCY Member'; 
                  break;
                case 'non_rcy_member': 
                  echo '<i class="fas fa-user"></i> Non-RCY Member'; 
                  break;
                case 'guest': 
                  echo '<i class="fas fa-user-friends"></i> Guest'; 
                  break;
                case 'member': 
                  echo '<i class="fas fa-user-check"></i> Member'; 
                  break;
                default: 
                  echo '<i class="fas fa-user"></i> ' . ucfirst($userType); 
                  break;
              }
              ?>
            </span>
          </h1>
          <p>Thank you for being a valuable member of the Philippine Red Cross community. Your contributions save lives every day.</p>
          
          <?php if ($userType === 'rcy_member' && !empty($userServices)): ?>
            <div class="services-display">
              <strong>Your Services:</strong>
              <?php foreach ($userServices as $service): ?>
                <span class="service-tag">
                  <i class="fas fa-hands-helping"></i>
                  <?= htmlspecialchars($serviceNames[$service] ?? ucfirst(str_replace('_', ' ', $service))) ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Main Dashboard Layout -->
      <div class="dashboard-main">
        <!-- Recent Activity Section -->
        <div class="recent-activity-section priority-section">
          <div class="section-header">
            <h2><i class="fas fa-history"></i> Recent Activity</h2>
            <div class="section-badge">Priority</div>
          </div>
          
          <!-- Upcoming Events -->
          <div class="activity-card featured">
            <div class="activity-header">
              <h3><i class="fas fa-calendar"></i> Upcoming Events</h3>
              <a href="registration.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($upcomingEvents)): ?>
                <ul class="activity-list">
                  <?php foreach ($upcomingEvents as $event): ?>
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
                        <?php if ($event['registration_status']): ?>
                          <span class="status-badge <?= $event['registration_status'] ?>">
                            <?= ucfirst($event['registration_status']) ?>
                          </span>
                        <?php endif; ?>
                      </div>
                      <div class="activity-action">
                        <i class="fas fa-chevron-right"></i>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="no-data">
                  <i class="fas fa-calendar-alt"></i>
                  <h3>No Upcoming Events</h3>
                  <p>Check back later for new events</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Training Sessions -->
          <div class="activity-card featured">
            <div class="activity-header">
              <h3><i class="fas fa-graduation-cap"></i> Training Sessions</h3>
              <a href="schedule.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($upcomingTraining)): ?>
                <ul class="activity-list">
                  <?php foreach ($upcomingTraining as $training): ?>
                    <li class="activity-item enhanced">
                      <div class="activity-icon training-icon">
                        <i class="fas fa-graduation-cap"></i>
                      </div>
                      <div class="activity-details">
                        <div class="activity-main"><?= htmlspecialchars($training['title']) ?></div>
                        <div class="activity-meta">
                          <span class="service"><i class="fas fa-cog"></i> <?= htmlspecialchars($training['major_service']) ?></span>
                          <span class="date"><i class="fas fa-calendar-day"></i> <?= date('M d, Y', strtotime($training['session_date'])) ?> at <?= date('g:i A', strtotime($training['start_time'])) ?></span>
                        </div>
                        <?php if ($training['user_registered']): ?>
                          <span class="status-badge registered">Registered</span>
                        <?php endif; ?>
                      </div>
                      <div class="activity-action">
                        <i class="fas fa-chevron-right"></i>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="no-data">
                  <i class="fas fa-graduation-cap"></i>
                  <h3>No Training Sessions</h3>
                  <p>Check back for new training opportunities</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Recent Announcements -->
          <div class="activity-card">
            <div class="activity-header">
              <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
              <a href="announcements.php" class="view-all">View All</a>
            </div>
            <div class="activity-body">
              <?php if (!empty($announcements)): ?>
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
              <?php else: ?>
                <div class="no-data">
                  <i class="fas fa-bullhorn"></i>
                  <h3>No Recent Announcements</h3>
                  <p>Check back for updates</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Quick Actions Section -->
        <div class="quick-actions-section secondary-section">
          <div class="section-header">
            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
          </div>
          <div class="action-buttons">
            <a href="registration.php" class="action-btn primary">
              <i class="fas fa-calendar-plus"></i>
              <span>Register for Events</span>
              <div class="action-desc">Join upcoming activities</div>
            </a>
            <a href="schedule.php" class="action-btn">
              <i class="fas fa-user-check"></i>
              <span>Mark Attendance</span>
              <div class="action-desc">Confirm your presence</div>
            </a>
            <a href="donate.php" class="action-btn">
              <i class="fas fa-hand-holding-usd"></i>
              <span>Submit Donation</span>
              <div class="action-desc">Make a contribution</div>
            </a>
            <a href="merch.php" class="action-btn">
              <i class="fas fa-store"></i>
              <span>View Merchandise</span>
              <div class="action-desc">Check supplies</div>
            </a>
            <a href="announcements.php" class="action-btn">
              <i class="fas fa-bullhorn"></i>
              <span>Announcements</span>
              <div class="action-desc">Latest updates</div>
            </a>
          </div>

          <!-- User Statistics Summary -->
          <div class="quick-stats-summary">
            <h3><i class="fas fa-chart-line"></i> Your Activity Summary</h3>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Events Registered</span>
              <span class="quick-stat-value"><?= number_format($userStats['events_registered']) ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Events Attended</span>
              <span class="quick-stat-value"><?= number_format($userStats['events_attended']) ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Training Sessions</span>
              <span class="quick-stat-value"><?= number_format($userStats['training_sessions']) ?></span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Donations Made</span>
              <span class="quick-stat-value"><?= number_format($userStats['donations_made']) ?></span>
            </div>
          </div>
          
          <?php if ($userType === 'rcy_member' && !empty($userServices)): ?>
          <!-- RCY Member Special Section -->
          <div class="rcy-member-section">
            <h3>
              <i class="fas fa-user-shield"></i> RCY Member Dashboard
            </h3>
            <div>
              <?php foreach ($userServices as $service): ?>
                <div>
                  <div>
                    <?= htmlspecialchars($serviceNames[$service] ?? ucfirst(str_replace('_', ' ', $service))) ?>
                  </div>
                  <div>
                    Next activity: 
                    <?php
                    // Mock next activity dates - replace with real data
                    $nextActivities = [
                      'health' => 'Sep 20, 2025',
                      'safety' => 'Sep 25, 2025', 
                      'welfare' => 'Oct 02, 2025',
                      'disaster_management' => 'Sep 30, 2025',
                      'red_cross_youth' => 'Oct 05, 2025'
                    ];
                    echo $nextActivities[$service] ?? 'TBA';
                    ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div>
              <a href="rcy_portal.php">
                <i class="fas fa-external-link-alt"></i>
                RCY Portal
              </a>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script src="js/notifications.js?v=<?php echo time(); ?>"></script>
  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
  <script>
    // Live indicator animation
    document.addEventListener('DOMContentLoaded', function() {
      const liveIndicator = document.getElementById('liveIndicator');
      
      setInterval(() => {
        if (liveIndicator) {
          liveIndicator.style.color = liveIndicator.style.color === 'rgb(40, 167, 69)' ? '#dc3545' : '#28a745';
        }
      }, 2000);
      
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
      document.querySelectorAll('.action-btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-2px)';
        });
        
        btn.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });

      // Initialize notifications with PHP data
      const notifications = <?php echo json_encode($userNotifications); ?>;
      if (typeof initializeNotifications === 'function') {
        initializeNotifications(notifications);
      }
    });
  </script>
</body>
</html>