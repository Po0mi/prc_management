<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

$user_role = get_user_role();
if ($user_role) {
    header("Location: /admin/dashboard.php");
    exit;
}

$pdo = $GLOBALS['pdo'];
$userId = current_user_id();
$username = current_username();

// Get user information
$userStmt = $pdo->prepare("SELECT user_type, services, created_at, full_name FROM users WHERE user_id = ?");
$userStmt->execute([$userId]);
$userInfo = $userStmt->fetch();

$userType = $userInfo['user_type'] ?? 'guest';
$userServices = $userInfo['services'] ? json_decode($userInfo['services'], true) : [];
$userCreatedAt = $userInfo['created_at'];
$fullName = $userInfo['full_name'] ?? $username;

$isNewUser = (strtotime($userCreatedAt) > strtotime('-7 days'));

// Get user's selected services
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

// Get user statistics
$userStats = [];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ?");
$stmt->execute([$userId]);
$userStats['events_registered'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = ? AND status = 'approved'");
$stmt->execute([$userId]);
$userStats['events_attended'] = $stmt->fetchColumn();

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userStats['training_sessions'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $userStats['training_sessions'] = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE donor_id IN (SELECT donor_id FROM donors WHERE user_id = ?)");
    $stmt->execute([$userId]);
    $userStats['donations_made'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $userStats['donations_made'] = 0;
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

// Get upcoming events
$eventsStmt = $pdo->prepare("
    SELECT e.*, r.status as registration_status
    FROM events e
    LEFT JOIN registrations r ON e.event_id = r.event_id AND r.user_id = ?
    WHERE e.event_date >= CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 5
");
$eventsStmt->execute([$userId]);
$upcomingEvents = $eventsStmt->fetchAll();

// Get upcoming training sessions
try {
    $trainStmt = $pdo->prepare("
        SELECT ts.*, sr.registration_date as user_registered
        FROM training_sessions ts
        LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id AND sr.user_id = ?
        WHERE ts.session_date >= CURDATE() 
        AND ts.archived = 0
        ORDER BY ts.session_date ASC 
        LIMIT 5
    ");
    $trainStmt->execute([$userId]);
    $upcomingTraining = $trainStmt->fetchAll();
} catch (PDOException $e) {
    $upcomingTraining = [];
}

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
    $announcements = [];
}
// Get user's training requests
try {
    $userRequestsStmt = $pdo->prepare("
        SELECT tr.*, tp.program_name
        FROM training_requests tr
        LEFT JOIN training_programs tp ON tr.training_program = tp.program_code 
            AND tr.service_type = tp.service_type
        WHERE tr.user_id = ?
        ORDER BY tr.created_at DESC
        LIMIT 5
    ");
    $userRequestsStmt->execute([$userId]);
    $userTrainingRequests = $userRequestsStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching training requests: " . $e->getMessage());
    $userTrainingRequests = [];
}
// Get user notifications
function getUserNotifications($latestReg, $upcomingEvents, $isNewUser, $userType) {
    $notifications = [];
    
    if ($userType === 'rcy_member' && $isNewUser) {
        $notifications[] = [
            'type' => 'success',
            'icon' => 'hands-helping',
            'title' => 'Welcome to Red Cross Youth!',
            'message' => 'Your orientation meetings are scheduled. Check your services for details.'
        ];
    }
    
    if ($latestReg && $latestReg['status'] === 'pending') {
        $notifications[] = [
            'type' => 'warning',
            'icon' => 'clock',
            'title' => 'Registration Pending',
            'message' => "Your registration for \"{$latestReg['title']}\" is awaiting approval."
        ];
    }
    
    if ($latestReg && $latestReg['status'] === 'approved') {
        $notifications[] = [
            'type' => 'success',
            'icon' => 'check-circle',
            'title' => 'Registration Approved!',
            'message' => "You're confirmed for \"{$latestReg['title']}\" on " . date('M d', strtotime($latestReg['event_date']))
        ];
    }
    
    foreach ($upcomingEvents as $event) {
        $daysUntil = ceil((strtotime($event['event_date']) - time()) / 86400);
        if ($daysUntil <= 3 && $daysUntil > 0 && !$event['registration_status']) {
            $notifications[] = [
                'type' => 'urgent',
                'icon' => 'exclamation-triangle',
                'title' => 'Event Deadline Soon',
                'message' => "Only {$daysUntil} days left to register for \"{$event['title']}\"!"
            ];
            break;
        }
    }
    
    return $notifications;
}

$userNotifications = getUserNotifications($latestReg, $upcomingEvents, $isNewUser, $userType);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="header-content">

    <div class="dashboard-container">
      
      <!-- Compact Hero Section -->
      <section class="dashboard-hero-compact">
        <div class="hero-background">
          <div class="hero-overlay"></div>
        </div>
        <div class="hero-content-compact">
          <div class="hero-left">
            <div class="hero-badge">
              <?php 
              switch($userType) {
                case 'rcy_member': 
                  echo '<i class="fas fa-user-shield"></i><span>RCY MEMBER</span>'; 
                  break;
                case 'non_rcy_member': 
                  echo '<i class="fas fa-user"></i><span>MEMBER</span>'; 
                  break;
                default: 
                  echo '<i class="fas fa-user-friends"></i><span>GUEST</span>'; 
                  break;
              }
              ?>
            </div>
            <h1>Welcome, <span class="title-highlight"><?= htmlspecialchars($fullName) ?></span></h1>
            <p class="hero-subtitle">Your Red Cross Journey Dashboard</p>
            
            <?php if ($userType === 'rcy_member' && !empty($userServices)): ?>
            <div class="hero-services">
              <?php foreach (array_slice($userServices, 0, 3) as $service): ?>
                <span class="service-badge">
                  <?= htmlspecialchars($serviceNames[$service] ?? ucfirst(str_replace('_', ' ', $service))) ?>
                </span>
              <?php endforeach; ?>
              <?php if (count($userServices) > 3): ?>
                <span class="service-badge">+<?= count($userServices) - 3 ?> more</span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="hero-right">
            <div class="hero-stats-compact">
              <div class="stat-compact">
                <div class="stat-number"><?= $userStats['events_registered'] ?></div>
                <div class="stat-label">Registered</div>
              </div>
              <div class="stat-compact">
                <div class="stat-number"><?= $userStats['events_attended'] ?></div>
                <div class="stat-label">Attended</div>
              </div>
              <div class="stat-compact">
                <div class="stat-number"><?= $userStats['training_sessions'] ?></div>
                <div class="stat-label">Training</div>
              </div>
            </div>
            <div class="live-indicator">
              <i class="fas fa-circle" id="liveIndicator"></i>
              <span><?php echo date('M d, Y'); ?></span>
            </div>
          </div>
        </div>
      </section>

      <!-- Notifications - Compact -->
      <?php if (!empty($userNotifications)): ?>
      <section class="notifications-compact">
        <div class="section-header-inline">
          <div class="header-left">
            <h2><i class="fas fa-bell"></i> Notifications</h2>
            <div class="summary-badges-inline">
              <span class="badge-mini total"><?= count($userNotifications) ?></span>
            </div>
          </div>
          <div class="header-actions">
            <button class="btn-icon" onclick="clearNotifications()" title="Clear All">
              <i class="fas fa-check-double"></i>
            </button>
          </div>
        </div>
        
        <div class="notifications-scroll">
          <?php foreach ($userNotifications as $notification): ?>
            <div class="notification-compact notification-<?= $notification['type'] ?>">
              <div class="notification-left">
                <div class="notification-icon-small <?= $notification['type'] ?>">
                  <i class="fas fa-<?= $notification['icon'] ?>"></i>
                </div>
                <div class="notification-content-compact">
                  <h4><?= htmlspecialchars($notification['title']) ?></h4>
                  <p><?= htmlspecialchars($notification['message']) ?></p>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <!-- Main Dashboard Grid -->
      <div class="dashboard-grid-compact">
        
        <!-- Left Column -->
        <div class="dashboard-column">
          
          <!-- Quick Actions Card -->
          <div class="card-compact actions-grid">
            <div class="card-header-compact">
              <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
            </div>
            <div class="actions-lists">
              <a href="registration.php" class="action-item-compact">
                <div class="action-icon-small events"><i class="fas fa-calendar-plus"></i></div>
                <span>Register Events</span>
              </a>
              <a href="schedule.php" class="action-item-compact">
                <div class="action-icon-small training"><i class="fas fa-user-check"></i></div>
                <span>Attendance</span>
              </a>
              <a href="donate.php" class="action-item-compact">
                <div class="action-icon-small donations"><i class="fas fa-donate"></i></div>
                <span>Donate</span>
              </a>
              <a href="merch.php" class="action-item-compact">
                <div class="action-icon-small inventory"><i class="fas fa-store"></i></div>
                <span>Merchandise</span>
              </a>
              <a href="announcements.php" class="action-item-compact">
                <div class="action-icon-small volunteers"><i class="fas fa-bullhorn"></i></div>
                <span>Announcements</span>
              </a>
            </div>
          </div>

          <!-- Upcoming Events Table -->
          <div class="card-compact">
            <div class="card-header-compact" style="background: linear-gradient(135deg, var(--success), #20c997);">
              <h3 style="color: white;"><i class="fas fa-calendar" style="color: white;"></i> Upcoming Events</h3>
              <a href="registration.php" class="link-small">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="table-container">
              <?php if (!empty($upcomingEvents)): ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th><i class="fas fa-calendar-alt"></i> Event</th>
                    <th><i class="fas fa-tag"></i> Service</th>
                    <th><i class="fas fa-map-marker-alt"></i> Location</th>
                    <th><i class="fas fa-calendar-day"></i> Date</th>
                    <th><i class="fas fa-info-circle"></i> Status</th>
                    <th><i class="fas fa-cog"></i> Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($upcomingEvents as $event): ?>
                    <?php
                    $daysUntil = ceil((strtotime($event['event_date']) - time()) / 86400);
                    $urgencyClass = '';
                    if ($daysUntil <= 3 && $daysUntil > 0) $urgencyClass = 'row-urgent';
                    elseif ($event['event_date'] === date('Y-m-d')) $urgencyClass = 'row-today';
                    ?>
                    <tr class="<?= $urgencyClass ?>">
                      <td class="td-title">
                        <div class="table-title">
                          <i class="fas fa-calendar-check"></i>
                          <?= htmlspecialchars($event['title']) ?>
                        </div>
                      </td>
                      <td>
                        <span class="service-tag-small"><?= htmlspecialchars($event['major_service']) ?></span>
                      </td>
                      <td class="td-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?= htmlspecialchars(substr($event['location'], 0, 25)) ?><?= strlen($event['location']) > 25 ? '...' : '' ?>
                      </td>
                      <td class="td-date">
                        <?= date('M d, Y', strtotime($event['event_date'])) ?>
                        <?php if ($daysUntil <= 7 && $daysUntil > 0): ?>
                          <span class="days-until">(<?= $daysUntil ?> days)</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($event['registration_status']): ?>
                          <span class="badge-status <?= $event['registration_status'] ?>">
                            <?= ucfirst($event['registration_status']) ?>
                          </span>
                        <?php else: ?>
                          <span class="badge-status open">Open</span>
                        <?php endif; ?>
                      </td>
                      <td class="td-actions">
                        <?php if (!$event['registration_status']): ?>
                          <a href="registration.php?event_id=<?= $event['event_id'] ?>" class="btn-table primary">
                            <i class="fas fa-user-plus"></i> Register
                          </a>
                        <?php else: ?>
                          <a href="registration.php?event_id=<?= $event['event_id'] ?>" class="btn-table secondary">
                            <i class="fas fa-eye"></i> View
                          </a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php else: ?>
              <div class="empty-table">
                <i class="fas fa-calendar-alt"></i>
                <h4>No Upcoming Events</h4>
                <p>Check back later for new events and activities</p>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Training Sessions Table -->
          <div class="card-compact">
            <div class="card-header-compact" style="background: linear-gradient(135deg, var(--info), #6610f2);">
              <h3 style="color: white;"><i class="fas fa-graduation-cap" style="color: white;"></i> Training Sessions</h3>
              <a href="schedule.php" class="link-small">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="table-container">
              <?php if (!empty($upcomingTraining)): ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th><i class="fas fa-book"></i> Training Program</th>
                    <th><i class="fas fa-cog"></i> Service</th>
                    <th><i class="fas fa-map-pin"></i> Venue</th>
                    <th><i class="fas fa-calendar-day"></i> Date & Time</th>
                    <th><i class="fas fa-info-circle"></i> Status</th>
                    <th><i class="fas fa-cog"></i> Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($upcomingTraining as $training): ?>
                    <?php
                    $daysUntil = ceil((strtotime($training['session_date']) - time()) / 86400);
                    $urgencyClass = '';
                    if ($daysUntil <= 3 && $daysUntil > 0) $urgencyClass = 'row-urgent';
                    elseif ($training['session_date'] === date('Y-m-d')) $urgencyClass = 'row-today';
                    ?>
                    <tr class="<?= $urgencyClass ?>">
                      <td class="td-title">
                        <div class="table-title">
                          <i class="fas fa-graduation-cap"></i>
                          <?= htmlspecialchars($training['title']) ?>
                        </div>
                      </td>
                      <td>
                        <span class="service-tag-small training"><?= htmlspecialchars($training['major_service']) ?></span>
                      </td>
                      <td class="td-location">
                        <i class="fas fa-map-pin"></i>
                        <?= htmlspecialchars(substr($training['venue'], 0, 25)) ?><?= strlen($training['venue']) > 25 ? '...' : '' ?>
                      </td>
                      <td class="td-date">
                        <?= date('M d, Y', strtotime($training['session_date'])) ?>
                        <span class="time-display"><?= date('g:i A', strtotime($training['start_time'])) ?></span>
                        <?php if ($daysUntil <= 7 && $daysUntil > 0): ?>
                          <span class="days-until">(<?= $daysUntil ?> days)</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($training['user_registered']): ?>
                          <span class="badge-status registered">Registered</span>
                        <?php else: ?>
                          <span class="badge-status open">Open</span>
                        <?php endif; ?>
                      </td>
                      <td class="td-actions">
                        <?php if (!$training['user_registered']): ?>
                          <a href="schedule.php?session_id=<?= $training['session_id'] ?>" class="btn-table primary">
                            <i class="fas fa-user-plus"></i> Enroll
                          </a>
                        <?php else: ?>
                          <a href="schedule.php?session_id=<?= $training['session_id'] ?>" class="btn-table secondary">
                            <i class="fas fa-eye"></i> View
                          </a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php else: ?>
              <div class="empty-table">
                <i class="fas fa-graduation-cap"></i>
                <h4>No Training Sessions</h4>
                <p>Check back for new training opportunities</p>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <!-- My Training Requests -->
<div class="card-compact">
            <div class="card-header-compact" style="background: linear-gradient(135deg, var(--info), #6610f2);">
        <h3 style="color: white;"><i class="fas fa-chalkboard-teacher" style="color: white;"></i> My Training Requests</h3>
        <a href="schedule.php#training-requests" class="link-small">View All <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="table-container">
        <?php if (!empty($userTrainingRequests)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th><i class="fas fa-hashtag"></i> Request</th>
                    <th><i class="fas fa-graduation-cap"></i> Program</th>
                    <th><i class="fas fa-users"></i> Participants</th>
                    <th><i class="fas fa-calendar"></i> Requested Date</th>
                    <th><i class="fas fa-info-circle"></i> Status</th>
                    <th><i class="fas fa-clock"></i> Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($userTrainingRequests as $request): ?>
                <tr>
                    <td class="td-title">
                        <div class="table-title">
                            <i class="fas fa-file-alt"></i>
                            #<?= $request['request_id'] ?>
                        </div>
                    </td>
                    <td>
                        <span class="service-tag-small"><?= htmlspecialchars($request['service_type']) ?></span>
                        <div style="font-size: 0.8rem; margin-top: 0.2rem;">
                            <?= htmlspecialchars($request['program_name'] ?: $request['training_program']) ?>
                        </div>
                    </td>
                    <td>
                        <span class="count-badge"><?= $request['participant_count'] ?></span>
                    </td>
                    <td class="td-date">
                        <?php if ($request['preferred_start_date']): ?>
                            <?= date('M d, Y', strtotime($request['preferred_start_date'])) ?>
                            <?php if ($request['preferred_end_date'] && $request['preferred_end_date'] !== $request['preferred_start_date']): ?>
                                <span style="font-size: 0.75rem; color: var(--gray);">
                                    to <?= date('M d', strtotime($request['preferred_end_date'])) ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-style: italic; color: var(--gray);">Flexible</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge-status <?= $request['status'] ?>">
                            <i class="fas <?= 
                                $request['status'] === 'pending' ? 'fa-clock' : 
                                ($request['status'] === 'under_review' ? 'fa-search' :
                                ($request['status'] === 'approved' ? 'fa-check-circle' :
                                ($request['status'] === 'scheduled' ? 'fa-calendar-check' :
                                ($request['status'] === 'completed' ? 'fa-graduation-cap' : 'fa-times-circle')))) ?>"></i>
                            <?= ucwords(str_replace('_', ' ', $request['status'])) ?>
                        </span>
                    </td>
                    <td class="td-date">
                        <?= date('M d, Y', strtotime($request['created_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-table">
            <i class="fas fa-clipboard-list"></i>
            <h4>No Training Requests</h4>
            <p>You haven't submitted any training requests yet</p>
            <a href="schedule.php" class="btn-table primary" style="margin-top: 1rem;">
                <i class="fas fa-plus"></i> Request Training
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
        </div>

        <!-- Right Column -->
        <div class="dashboard-column-right">
          
          <!-- Activity Stats -->
          <div class="stats-grid-compact">
            <div class="stat-card-mini">
              <div class="stat-icon events"><i class="fas fa-calendar-check"></i></div>
              <div class="stat-info">
                <div class="stat-value"><?= $userStats['events_registered'] ?></div>
                <div class="stat-name">Events Joined</div>
              </div>
            </div>
            <div class="stat-card-mini">
              <div class="stat-icon users"><i class="fas fa-clipboard-check"></i></div>
              <div class="stat-info">
                <div class="stat-value"><?= $userStats['events_attended'] ?></div>
                <div class="stat-name">Attended</div>
              </div>
            </div>
            <div class="stat-card-mini">
              <div class="stat-icon training"><i class="fas fa-graduation-cap"></i></div>
              <div class="stat-info">
                <div class="stat-value"><?= $userStats['training_sessions'] ?></div>
                <div class="stat-name">Trainings</div>
              </div>
            </div>
            <div class="stat-card-mini">
              <div class="stat-icon donations"><i class="fas fa-heart"></i></div>
              <div class="stat-info">
                <div class="stat-value"><?= $userStats['donations_made'] ?></div>
                <div class="stat-name">Donations</div>
              </div>
            </div>
          </div>

          <!-- Compact Calendar -->
          <div class="card-compact calendar-card">
            <div class="card-header-compact">
              <h3><i class="fas fa-calendar-alt"></i> Calendar</h3>
              <button class="btn-icon" onclick="openCalendar()"><i class="fas fa-expand"></i></button>
            </div>
            
            <div class="calendar-mini">
              <div class="calendar-nav">
                <button class="btn-icon-small" onclick="previousMonth()"><i class="fas fa-chevron-left"></i></button>
                <span class="month-year"><?= date('F Y') ?></span>
                <button class="btn-icon-small" onclick="nextMonth()"><i class="fas fa-chevron-right"></i></button>
              </div>
              
              <div class="calendar-grid-mini" id="miniCalendarGrid">
                <div class="day-label">S</div>
                <div class="day-label">M</div>
                <div class="day-label">T</div>
                <div class="day-label">W</div>
                <div class="day-label">T</div>
                <div class="day-label">F</div>
                <div class="day-label">S</div>
              </div>
            </div>
          </div>

          <!-- Upcoming This Week -->
          <div class="card-compact">
            <div class="card-header-compact">
              <h3><i class="fas fa-clock"></i> Upcoming This Week</h3>
            </div>
            <div class="upcoming-list">
              <?php
              try {
                $stmt = $pdo->prepare("
                  SELECT title, event_date as date, location, major_service, 'event' as type
                  FROM events 
                  WHERE event_date >= CURDATE()
                  AND event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  UNION ALL
                  SELECT title, session_date as date, venue as location, major_service, 'training' as type
                  FROM training_sessions 
                  WHERE session_date >= CURDATE()
                  AND session_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  AND archived = 0
                  ORDER BY date ASC
                  LIMIT 5
                ");
                $stmt->execute();
                $upcomingItems = $stmt->fetchAll();
                
                if (!empty($upcomingItems)):
                  foreach ($upcomingItems as $item):
                    $isTraining = $item['type'] === 'training';
              ?>
              <div class="upcoming-item <?= $isTraining ? 'training' : '' ?>">
                <div class="upcoming-date">
                  <span class="day"><?= date('j', strtotime($item['date'])) ?></span>
                  <span class="month"><?= date('M', strtotime($item['date'])) ?></span>
                </div>
                <div class="upcoming-details">
                  <span class="event-type-badge"><?= $isTraining ? 'Training' : 'Event' ?></span>
                  <h5><?= htmlspecialchars($item['title']) ?></h5>
                  <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(substr($item['location'], 0, 25)) ?>...</p>
                </div>
              </div>
              <?php
                  endforeach;
                else:
              ?>
              <div class="empty-small">
                <i class="fas fa-calendar-alt"></i>
                <p>No upcoming events this week</p>
              </div>
              <?php
                endif;
              } catch (Exception $e) {
                echo '<div class="empty-small"><p>Unable to load events</p></div>';
              }
              ?>
            </div>
          </div>

          <!-- Recent Announcements -->
          <?php if (!empty($announcements)): ?>
          <div class="card-compact">
            <div class="card-header-compact">
              <h3><i class="fas fa-bullhorn"></i> Announcements</h3>
              <a href="announcements.php" class="link-small">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="upcoming-list">
              <?php foreach ($announcements as $announcement): ?>
              <div class="upcoming-item announcement">
                <div class="upcoming-date">
                  <span class="day"><?= date('j', strtotime($announcement['created_at'])) ?></span>
                  <span class="month"><?= date('M', strtotime($announcement['created_at'])) ?></span>
                </div>
                <div class="upcoming-details">
                  <span class="event-type-badge">Announcement</span>
                  <h5><?= htmlspecialchars($announcement['title']) ?></h5>
                  <p><?= htmlspecialchars(substr($announcement['content'], 0, 60)) ?>...</p>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>

      </div>
    </div>
  </div>

  <!-- Calendar Modal -->
  <div id="calendarModal" class="calendar-modal" style="display: none;">
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
      </div>
    </div>
  </div>

  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
    <?php include 'floating_notification_widget.php'; ?>
  
  <script>
    // ========================================
    // CALENDAR FUNCTIONALITY
    // ========================================
    
    let currentMonth = <?= date('n') - 1 ?>; // JavaScript months are 0-indexed
    let currentYear = <?= date('Y') ?>;
    let events = [];

    // Fetch events and training sessions from database
    async function fetchEvents() {
      try {
        const response= await fetch('get_user_calendar_events.php');
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
        const existingDays = grid.querySelectorAll('.day-cell');
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
      today.setHours(0, 0, 0, 0);
      
      for (let i = 0; i < 42; i++) {
        const cellDate = new Date(startDate);
        cellDate.setDate(startDate.getDate() + i);
        
        const day = document.createElement('div');
        day.className = isMini ? 'day-cell' : 'day';
        day.textContent = cellDate.getDate();
        
        if (cellDate.getMonth() !== currentMonth) {
          day.classList.add('other-month');
        }
        
        if (cellDate.toDateString() === today.toDateString()) {
          day.classList.add('today');
        }
        
        const dateStr = cellDate.getFullYear() + '-' + 
                       String(cellDate.getMonth() + 1).padStart(2, '0') + '-' + 
                       String(cellDate.getDate()).padStart(2, '0');
        
        const dayEvents = events.filter(event => {
          if (event.event_date && event.event_date === dateStr) {
            return true;
          }
          if (event.session_date && event.session_end_date) {
            return dateStr >= event.session_date && dateStr <= event.session_end_date;
          }
          if (event.session_date && event.session_date === dateStr) {
            return true;
          }
          return false;
        });
        
        if (dayEvents.length > 0) {
          const regularEvents = dayEvents.filter(event => event.event_date);
          const trainingSessions = dayEvents.filter(event => event.session_date);
          
          if (regularEvents.length > 0 && trainingSessions.length > 0) {
            day.classList.add('has-both');
            if (!isMini) {
              day.innerHTML += '<div class="event-dots"><span class="dot dot-event"></span><span class="dot dot-training"></span></div>';
            }
          } else if (trainingSessions.length > 0) {
            day.classList.add('has-training');
            if (!isMini) {
              day.innerHTML += '<div class="event-dots"><span class="dot dot-training"></span></div>';
            }
          } else {
            day.classList.add('has-event');
            if (!isMini) {
              day.innerHTML += '<div class="event-dots"><span class="dot dot-event"></span></div>';
            }
          }
          
          if (dayEvents.length > 1 && !isMini) {
            const countBadge = document.createElement('span');
            countBadge.className = 'event-count';
            countBadge.textContent = dayEvents.length;
            day.appendChild(countBadge);
          }
          
          day.style.cursor = 'pointer';
          day.setAttribute('data-events', JSON.stringify(dayEvents));
          
          if (!isMini) {
            day.addEventListener('click', function() {
              showEventDetails(dayEvents);
            });
          }
        }
        
        grid.appendChild(day);
      }
    }

    function showEventDetails(dayEvents) {
      const detailsPanel = document.getElementById('selectedEventDetails');
      
      if (!detailsPanel) return;
      
      if (!dayEvents || dayEvents.length === 0) {
        detailsPanel.innerHTML = `
          <div class="empty-details" style="text-align: center; padding: 2rem; color: #6b7280;">
            <i class="fas fa-calendar-alt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
            <h5>No Events Selected</h5>
            <p>Click on a date with events to view details</p>
          </div>
        `;
        return;
      }
      
      let html = '';
      
      dayEvents.forEach(event => {
        const isTraining = !!event.session_date;
        const icon = isTraining ? 'graduation-cap' : 'calendar';
        const typeClass = isTraining ? 'training-event' : 'regular-event';
        const eventType = isTraining ? 'Training Session' : 'Event';
        const date = isTraining ? event.session_date : event.event_date;
        const location = isTraining ? event.venue : event.location;
        
        html += `
          <div class="selected-event ${typeClass}" style="border-left: 4px solid ${isTraining ? '#6366f1' : '#a00000'}; padding: 1rem; margin-bottom: 1rem; background: #f9fafb; border-radius: 8px;">
            <div class="event-type-badge" style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; background: ${isTraining ? 'rgba(99, 102, 241, 0.1)' : 'rgba(160, 0, 0, 0.1)'}; color: ${isTraining ? '#6366f1' : '#a00000'};">
              ${eventType}
            </div>
            <h5 style="margin: 0.5rem 0; color: #111827;"><i class="fas fa-${icon}"></i> ${event.title || 'Untitled Event'}</h5>
            <p style="margin: 0.25rem 0; color: #6b7280; font-size: 0.875rem;">
              <i class="fas fa-calendar"></i> ${new Date(date).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
              })}
              ${event.session_end_date && event.session_end_date !== event.session_date ? 
                ' - ' + new Date(event.session_end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : ''}
            </p>
            ${location ? `<p style="margin: 0.25rem 0; color: #6b7280; font-size: 0.875rem;"><i class="fas fa-map-marker-alt"></i> ${location.substring(0, 100)}${location.length > 100 ? '...' : ''}</p>` : ''}
            <p style="margin: 0.25rem 0; color: #6b7280; font-size: 0.875rem;"><i class="fas fa-tags"></i> ${event.major_service || 'General Service'}</p>
            ${event.description ? `<p style="margin: 0.5rem 0 0 0; color: #4b5563; font-size: 0.875rem;"><i class="fas fa-info-circle"></i> ${event.description.substring(0, 150)}${event.description.length > 150 ? '...' : ''}</p>` : ''}
            ${isTraining && event.duration_days > 1 ? `<p style="margin: 0.5rem 0 0 0; color: #6366f1; font-weight: 600; font-size: 0.875rem;"><i class="fas fa-clock"></i> ${event.duration_days} days</p>` : ''}
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

    // ========================================
    // INITIALIZE ON PAGE LOAD
    // ========================================
    
    document.addEventListener('DOMContentLoaded', function() {
      // Fetch calendar events
      fetchEvents();
      
      // Close modal on outside click
      const calendarModal = document.getElementById('calendarModal');
      if (calendarModal) {
        calendarModal.addEventListener('click', function(e) {
          if (e.target === this) {
            closeCalendar();
          }
        });
      }
      
      // Live indicator animation
      const liveIndicator = document.getElementById('liveIndicator');
      setInterval(() => {
        if (liveIndicator) {
          liveIndicator.style.color = liveIndicator.style.color === 'rgb(16, 185, 129)' ? '#dc3545' : '#10b981';
        }
      }, 2000);

      console.log('User dashboard with calendar initialized');
    });

    // Clear notifications function
    function clearNotifications() {
      const section = document.querySelector('.notifications-compact');
      if (section) {
        section.style.opacity = '0';
        section.style.transform = 'translateY(-10px)';
        setTimeout(() => section.remove(), 300);
      }
    }
  </script>
</body>
</html>