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

// Get additional notifications (like upcoming deadlines, reminders, etc.)
$additionalNotifications = [];

// Check for upcoming event deadlines
if (!empty($upcomingEvents)) {
    foreach ($upcomingEvents as $event) {
        $daysUntil = ceil((strtotime($event['event_date']) - time()) / (60 * 60 * 24));
        if ($daysUntil <= 7 && $daysUntil > 0 && !$event['registration_status']) {
            $additionalNotifications[] = [
                'type' => 'deadline',
                'title' => 'Event Registration Deadline Approaching',
                'content' => "Don't miss out on '{$event['title']}' - only {$daysUntil} days left to register!",
                'action_text' => 'Register Now',
                'action_link' => 'registration.php',
                'urgency' => 'high'
            ];
        }
    }
}

// Check for unread announcements (mock - you can implement actual logic)
if (!empty($announcements)) {
    $recentAnnouncement = $announcements[0];
    if (strtotime($recentAnnouncement['created_at']) > strtotime('-3 days')) {
        $additionalNotifications[] = [
            'type' => 'announcement',
            'title' => 'New Announcement Posted',
            'content' => substr($recentAnnouncement['content'], 0, 100) . '...',
            'action_text' => 'Read More',
            'action_link' => 'announcements.php',
            'urgency' => 'medium'
        ];
    }
}

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
  <style>
    /* Enhanced Notification Styles - Replaces Stats Grid */
    
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
    
    /* Dark mode support for notifications */
    .dark-mode .notification-banner {
      border-color: #555;
    }
    
    .dark-mode .notification-content h3 {
      color: #f0f0f0;
    }
    
    .dark-mode .notification-content p {
      color: #b0b0b0;
    }
    
    .dark-mode .notification-content strong {
      color: #f0f0f0;
    }
    
    .dark-mode .notification-close {
      color: #b0b0b0;
    }
    
    .dark-mode .notification-close:hover {
      color: #f0f0f0;
    }
    
    /* Responsive design for notifications */
    @media (max-width: 768px) {
      .notification-banner {
        padding: 1rem;
        flex-direction: column;
        text-align: center;
      }
      
      .notification-icon {
        width: 50px;
        height: 50px;
        font-size: 1.5rem;
      }
      
      .notification-close {
        position: static;
        margin-left: auto;
      }
    }
    
    @media (max-width: 576px) {
      .notification-banner {
        padding: 0.75rem;
        margin-bottom: 0.75rem;
      }
      
      .notification-content h3 {
        font-size: 1rem;
      }
      
      .notification-content p {
        font-size: 0.9rem;
      }
      
      .notification-icon {
        width: 45px;
        height: 45px;
        font-size: 1.3rem;
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
    
    /* Accessibility improvements */
    .notification-banner:focus-within {
      outline: 2px solid #3b82f6;
      outline-offset: 2px;
    }
    
    .notification-close:focus {
      outline: 2px solid #3b82f6;
      outline-offset: 2px;
    }
  </style>
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
        <div class="date-display">
          <div class="current-date">
            <i class="fas fa-calendar-day"></i>
            <?php echo date('F d, Y'); ?>
          </div>
        </div>
      </div>

      <!-- Enhanced Notifications Section (Replaces Stats Grid) -->
      <div class="notifications-section">
        
        <!-- New RCY Member Notifications -->
        <?php if ($userType === 'rcy_member' && $isNewUser && !empty($upcomingMeetings)): ?>
          <div class="notification-banner success">
            <div class="notification-icon">
              <i class="fas fa-hands-helping"></i>
            </div>
            <div class="notification-content">
              <h3>Welcome to Red Cross Youth!</h3>
              <p><strong>Congratulations on joining RCY!</strong> We have scheduled orientation meetings for your selected services. These sessions will cover requirements, training schedules, and volunteer opportunities.</p>
              
              <?php foreach ($upcomingMeetings as $index => $meeting): ?>
                <?php if ($index < 2): // Show only first 2 meetings ?>
                  <div style="background: rgba(255,255,255,0.7); padding: 0.75rem; border-radius: 8px; margin: 0.5rem 0;">
                    <strong><?= htmlspecialchars($meeting['service_name']) ?> - <?= htmlspecialchars($meeting['type']) ?></strong><br>
                    <small><i class="fas fa-calendar"></i> <?= date('F d, Y g:i A', strtotime($meeting['meeting_date'])) ?> | <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($meeting['location']) ?></small>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <button class="notification-close" onclick="this.parentElement.style.display='none'">
              <i class="fas fa-times"></i>
            </button>
          </div>
        <?php endif; ?>

        <!-- Registration Status Notifications -->
        <?php if ($latestReg && $latestReg['status'] === 'pending'): ?>
          <div class="notification-banner warning">
            <div class="notification-icon">
              <i class="fas fa-clock"></i>
            </div>
            <div class="notification-content">
              <h3>Registration Pending Review</h3>
              <p>Your registration for "<strong><?= htmlspecialchars($latestReg['title']) ?></strong>" is currently awaiting approval. We'll notify you once it's processed.</p>
              <small><strong>Event Details:</strong> <?= date('M d, Y', strtotime($latestReg['event_date'])) ?> at <?= htmlspecialchars($latestReg['location']) ?></small>
            </div>
            <button class="notification-close" onclick="this.parentElement.style.display='none'">
              <i class="fas fa-times"></i>
            </button>
          </div>
        <?php endif; ?>

        <?php if ($latestReg && $latestReg['status'] === 'approved'): ?>
          <div class="notification-banner success">
            <div class="notification-icon">
              <i class="fas fa-check-circle"></i>
            </div>
            <div class="notification-content">
              <h3>Registration Approved!</h3>
              <p>Your registration for "<strong><?= htmlspecialchars($latestReg['title']) ?></strong>" has been approved.</p>
              <small><strong>Event Date:</strong> <?= date('M d, Y', strtotime($latestReg['event_date'])) ?> | <strong>Location:</strong> <?= htmlspecialchars($latestReg['location']) ?></small>
            </div>
            <button class="notification-close" onclick="this.parentElement.style.display='none'">
              <i class="fas fa-times"></i>
            </button>
          </div>
        <?php endif; ?>

        <!-- Additional Dynamic Notifications -->
        <?php foreach ($additionalNotifications as $notification): ?>
          <div class="notification-banner <?= $notification['type'] ?>" <?= $notification['urgency'] === 'high' ? 'style="position: relative;"' : '' ?>>
            <?php if ($notification['urgency'] === 'high'): ?>
              <div class="urgency-indicator high"></div>
            <?php endif; ?>
            <div class="notification-icon">
              <i class="fas fa-<?= $notification['type'] === 'deadline' ? 'exclamation-triangle' : ($notification['type'] === 'announcement' ? 'bullhorn' : 'info-circle') ?>"></i>
            </div>
            <div class="notification-content">
              <h3><?= htmlspecialchars($notification['title']) ?></h3>
              <p><?= htmlspecialchars($notification['content']) ?></p>
            </div>
            <button class="notification-close" onclick="this.parentElement.style.display='none'">
              <i class="fas fa-times"></i>
            </button>
          </div>
        <?php endforeach; ?>

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
                  <p>No upcoming events</p>
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
                  <p>No upcoming training sessions</p>
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
                  <p>No recent announcements</p>
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

          <!-- User Statistics Summary (moved from stats grid) -->
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
          <div class="rcy-member-section" style="margin-top: 1.5rem; padding: 1rem; background: linear-gradient(135deg, #f3e5f5, #e1bee7); border-radius: 10px; border: 1px solid #ce93d8;">
            <h3 style="margin: 0 0 1rem 0; color: #7b1fa2; display: flex; align-items: center; gap: 0.5rem;">
              <i class="fas fa-user-shield"></i> RCY Member Dashboard
            </h3>
            <div style="display: grid; gap: 0.75rem;">
              <?php foreach ($userServices as $service): ?>
                <div style="background: white; padding: 0.75rem; border-radius: 6px; border-left: 4px solid #7b1fa2;">
                  <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">
                    <?= htmlspecialchars($serviceNames[$service] ?? ucfirst(str_replace('_', ' ', $service))) ?>
                  </div>
                  <div style="font-size: 0.8rem; color: #666;">
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
            <div style="margin-top: 1rem; text-align: center;">
              <a href="rcy_portal.php" style="background: #7b1fa2; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.5rem;">
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
  
  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
  <script>
    // Enhanced notification interaction - entrance animation only, no auto-dismiss
    document.addEventListener('DOMContentLoaded', function() {
      const notifications = document.querySelectorAll('.notification-banner');
      
      notifications.forEach(notification => {
        // Add entrance animation only
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px)';
        setTimeout(() => {
          notification.style.transition = 'all 0.5s ease-out';
          notification.style.opacity = '1';
          notification.style.transform = 'translateY(0)';
        }, 100);
        
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
    
    // Meeting card interactions
    document.querySelectorAll('.meeting-card').forEach(card => {
      card.addEventListener('click', function() {
        console.log('Meeting card clicked');
      });
    });
  </script>
</body>
</html>