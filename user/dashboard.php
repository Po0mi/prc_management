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

// Get user statistics
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
    // If table doesn't exist or column is different, set to 0
    $userStats['training_sessions'] = 0;
}

// Donations made - check if donors table exists
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE donor_id IN (SELECT donor_id FROM donors WHERE user_id = ?)");
    $stmt->execute([$userId]);
    $userStats['donations_made'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    // If table structure is different, try alternative approach
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
    // If session_registrations table doesn't exist or has different structure
    $trainStmt = $pdo->prepare("
        SELECT * FROM training_sessions 
        WHERE session_date >= CURDATE() 
        ORDER BY session_date ASC 
        LIMIT 3
    ");
    $trainStmt->execute();
    $upcomingTraining = $trainStmt->fetchAll();
    // Add user_registered as null for each session
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
    // Try alternative column names for announcements
    try {
        $announcements = $pdo->query("
            SELECT title, content, announcement_date as created_at 
            FROM announcements 
            WHERE status = 'published'
            ORDER BY announcement_date DESC 
            LIMIT 3
        ")->fetchAll();
    } catch (PDOException $e2) {
        // Try without status filter
        try {
            $announcements = $pdo->query("
                SELECT title, content, announcement_date as created_at 
                FROM announcements 
                ORDER BY announcement_date DESC 
                LIMIT 3
            ")->fetchAll();
        } catch (PDOException $e3) {
            // Try basic query without date ordering
            try {
                $announcements = $pdo->query("
                    SELECT title, content, announcement_id as created_at 
                    FROM announcements 
                    LIMIT 3
                ")->fetchAll();
            } catch (PDOException $e4) {
                // If announcements table doesn't exist, set empty array
                $announcements = [];
            }
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
          <h1>Welcome back, <?= htmlspecialchars($username) ?>!</h1>
          <p>Thank you for being a valuable member of the Philippine Red Cross community. Your contributions save lives every day.</p>
        </div>
        <div class="date-display">
          <div class="current-date">
            <i class="fas fa-calendar-day"></i>
            <?php echo date('F d, Y'); ?>
          </div>
        </div>
      </div>

      <!-- Important Notifications -->
      <?php if ($latestReg && $latestReg['status'] === 'pending'): ?>
        <div class="notification-banner warning">
          <div class="notification-icon">
            <i class="fas fa-clock"></i>
          </div>
          <div class="notification-content">
            <h3>Registration Pending Review</h3>
            <p>Your registration for "<strong><?= htmlspecialchars($latestReg['title']) ?></strong>" is awaiting approval. We'll notify you once it's processed.</p>
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
            <p>Great news! Your registration for "<strong><?= htmlspecialchars($latestReg['title']) ?></strong>" has been approved. Event date: <?= date('M d, Y', strtotime($latestReg['event_date'])) ?></p>
          </div>
          <button class="notification-close" onclick="this.parentElement.style.display='none'">
            <i class="fas fa-times"></i>
          </button>
        </div>
      <?php endif; ?>

      <!-- Stats Grid -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon events">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($userStats['events_registered']) ?></div>
            <div class="stat-label">Events Registered</div>
            <div class="stat-desc">Total registrations</div>
          </div>
          <a href="registration.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon attended">
            <i class="fas fa-user-check"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($userStats['events_attended']) ?></div>
            <div class="stat-label">Events Attended</div>
            <div class="stat-desc">Approved attendance</div>
          </div>
          <a href="schedule.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon training">
            <i class="fas fa-graduation-cap"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($userStats['training_sessions']) ?></div>
            <div class="stat-label">Training Sessions</div>
            <div class="stat-desc">Enrolled sessions</div>
          </div>
          <a href="schedule.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>

        <div class="stat-card">
          <div class="stat-icon donations">
            <i class="fas fa-hand-holding-heart"></i>
          </div>
          <div class="stat-info">
            <div class="stat-value"><?= number_format($userStats['donations_made']) ?></div>
            <div class="stat-label">Donations Made</div>
            <div class="stat-desc">Your contributions</div>
          </div>
          <a href="donate.php" class="stat-link">
            <i class="fas fa-arrow-right"></i>
          </a>
        </div>
      </div>

      <!-- REDESIGNED LAYOUT: Recent Activity First (Left), Quick Actions Second (Right) -->
      <div class="dashboard-main">
        <!-- Recent Activity Section - NOW ON THE LEFT (Primary Focus) -->
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

        <!-- Quick Actions Section - NOW ON THE RIGHT (Secondary) -->
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
            <a href="inventory.php" class="action-btn">
              <i class="fas fa-warehouse"></i>
              <span>View Inventory</span>
              <div class="action-desc">Check supplies</div>
            </a>
            <a href="blood_map.php" class="action-btn">
              <i class="fas fa-map-marked-alt"></i>
              <span>Blood Banks</span>
              <div class="action-desc">Find locations</div>
            </a>
            <a href="announcements.php" class="action-btn">
              <i class="fas fa-bullhorn"></i>
              <span>Announcements</span>
              <div class="action-desc">Latest updates</div>
            </a>
          </div>

          <!-- Additional Quick Stats -->
          <div class="quick-stats-summary">
            <h3><i class="fas fa-chart-line"></i> This Month</h3>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Events Joined</span>
              <span class="quick-stat-value">3</span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Training Hours</span>
              <span class="quick-stat-value">8</span>
            </div>
            <div class="quick-stat-item">
              <span class="quick-stat-label">Volunteer Hours</span>
              <span class="quick-stat-value">12</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/darkmode.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
</body>
</html>