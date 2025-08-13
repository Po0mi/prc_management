<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

$pdo = $GLOBALS['pdo'];
$userId = current_user_id();
$username = current_username();

$regStmt = $pdo->prepare("
    SELECT r.status, e.title 
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    WHERE r.user_id = ?
    ORDER BY r.registration_date DESC LIMIT 1
");
$regStmt->execute([$userId]);
$latestReg = $regStmt->fetch();

$trainStmt = $pdo->prepare("
    SELECT * FROM training_sessions 
    WHERE session_date >= CURDATE() 
    ORDER BY session_date ASC 
    LIMIT 1
");
$trainStmt->execute();
$trainingNotif = $trainStmt->fetch();
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
    
    <div class="dashboard-content">
      <div class="welcome-section">
        <h1>Welcome, <?= htmlspecialchars($username) ?>!</h1>
        <p>Thank you for being a valuable member of the Philippine Red Cross community. Your contributions save lives every day.</p>
      </div>
      
      <div class="notification-grid">
        <?php if ($latestReg): ?>
          <div class="notification-card">
            <h3><i class="fas fa-calendar-check"></i> Event Registration</h3>
            <p><strong>Event:</strong> <?= htmlspecialchars($latestReg['title']) ?></p>
            <p><strong>Status:</strong> 
              <?php 
                $status = $latestReg['status'];
                $badgeClass = 'pending';
                if ($status === 'approved') $badgeClass = 'approved';
                if ($status === 'rejected') $badgeClass = 'rejected';
              ?>
              <span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
            </p>
            <p><small>Last updated: Today</small></p>
          </div>
        <?php endif; ?>
        
        <?php if ($trainingNotif): ?>
          <div class="notification-card train">
            <h3><i class="fas fa-chalkboard-teacher"></i> Upcoming Training</h3>
            <p><strong>Session:</strong> <?= htmlspecialchars($trainingNotif['title']) ?></p>
            <p><strong>Date:</strong> <?= htmlspecialchars(date('F j, Y', strtotime($trainingNotif['session_date']))) ?></p>
            <p><strong>Time:</strong> <?= htmlspecialchars($trainingNotif['start_time']) ?> - <?= htmlspecialchars($trainingNotif['end_time']) ?></p>
            <p><strong>Venue:</strong> <?= htmlspecialchars($trainingNotif['venue']) ?></p>
          </div>
        <?php endif; ?>
        
        <div class="notification-card">
          <h3><i class="fas fa-tint"></i> Blood Donation</h3>
          <p>Your last donation was 60 days ago. You're eligible to donate again.</p>
          <p><strong>Next eligible date:</strong> <?= date('F j, Y', strtotime('+7 days')) ?></p>
          <p><small>Thank you for your life-saving contributions!</small></p>
        </div>
      </div>
      
      <div class="dashboard-actions">
        <div class="action-card" onclick="location.href='registration.php'">
          <i class="fas fa-calendar-plus"></i>
          <h3>Register for Events</h3>
          <p>Sign up for upcoming blood drives and community events</p>
        </div>
        
        <div class="action-card" onclick="location.href='schedule.php'">
          <i class="fas fa-user-check"></i>
          <h3>Mark Attendance</h3>
          <p>Confirm your participation in training sessions</p>
        </div>
        
        <div class="action-card" onclick="location.href='donate.php'">
          <i class="fas fa-hand-holding-usd"></i>
          <h3>Submit Donation</h3>
          <p>Support our life-saving mission financially</p>
        </div>
        
        <div class="action-card" onclick="location.href='inventory.php'">
          <i class="fas fa-warehouse"></i>
          <h3>View Inventory</h3>
          <p>Check current blood supply levels</p>
        </div>
        
        <div class="action-card" onclick="location.href='blood_map.php'">
          <i class="fas fa-map-marked-alt"></i>
          <h3>Blood Availability</h3>
          <p>Find nearby blood banks and their stock</p>
        </div>
        
        <div class="action-card" onclick="location.href='announcements.php'">
          <i class="fas fa-bullhorn"></i>
          <h3>Read Announcements</h3>
          <p>Stay updated with the latest news</p>
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