<?php
// /user/schedule.php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

$userEmail = $_SESSION['email'] ?? '';
$username = current_username();
$pdo = $GLOBALS['pdo'];
$attMessage = '';

// Define the major services in the same order as admin panel
$majorServices = [
    'Health Service',
    'Safety Service',
    'Welfare Service',
    'Disaster Management Service',
    'Red Cross Youth'
];


$stmt = $pdo->prepare("
    SELECT ts.*, 
           COALESCE((
               SELECT 1 FROM session_registrations 
               WHERE session_id = ts.session_id 
               AND email = ?
           ), 0) AS is_registered,
           COALESCE((
               SELECT COUNT(*) FROM session_registrations 
               WHERE session_id = ts.session_id
           ), 0) AS registered_count
    FROM training_sessions ts
    WHERE ts.session_date >= CURDATE()
    ORDER BY ts.major_service, ts.session_date, ts.start_time
");
$stmt->execute([$userEmail]);
$allSessions = $stmt->fetchAll();


$sessionsByService = [];
foreach ($allSessions as $session) {
    $service = $session['major_service'];
    if (!isset($sessionsByService[$service])) {
        $sessionsByService[$service] = [];
    }
    $sessionsByService[$service][] = $session;
}


$upcoming = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()")->fetchColumn();
$past = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date < CURDATE()")->fetchColumn();


$stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE email = ?");
$stmt->execute([$userEmail]);
$registered = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Training Schedule - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/schedule.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="header-content">
    <?php include 'header.php'; ?>
  <div class="sessions-container">
    <div class="page-header">
      <h1>Training Schedule</h1>
      <p>Welcome, <?= htmlspecialchars($username) ?></p>
      <p>View and register for upcoming training sessions</p>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
      <div class="alert success">
        <i class="fas fa-check-circle"></i>
        You have successfully registered for the training session!
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] == 'full'): ?>
      <div class="alert error">
        <i class="fas fa-exclamation-circle"></i>
        The session you tried to register for is already full.
      </div>
    <?php endif; ?>

    <div class="sessions-sections">

      <div class="sessions-stats">
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-calendar"></i>
          </div>
          <div class="stat-content">
            <h3>Total Sessions</h3>
            <p><?= $upcoming ?></p>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="stat-content">
            <h3>Upcoming</h3>
            <p><?= $upcoming ?></p>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-user-check"></i>
          </div>
          <div class="stat-content">
            <h3>Registered</h3>
            <p><?= $registered ?></p>
          </div>
        </div>
      </div>


      <?php foreach ($majorServices as $service): ?>
        <?php if (!empty($sessionsByService[$service])): ?>
          <section class="service-section">
            <div class="section-header">
              <h2><i class="fas fa-first-aid"></i> <?= htmlspecialchars($service) ?></h2>
            </div>
            
            <div class="sessions-table-container">
              <table class="sessions-table">
                <thead>
                  <tr>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Venue</th>
                    <th>Availability</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sessionsByService[$service] as $s): 
                    $sessionDate = strtotime($s['session_date']);
                    $today = strtotime('today');
                    $statusClass = '';
                    
                    if ($sessionDate < $today) {
                      $statusClass = 'past';
                      $statusText = 'Completed';
                    } else {
                      $statusClass = 'upcoming';
                      $statusText = 'Upcoming';
                    }
                    
                    $isFull = $s['capacity'] > 0 && $s['registered_count'] >= $s['capacity'];
                    $fee = $s['fee'] > 0 ? '₱' . number_format($s['fee'], 2) : 'Free';
                  ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($s['title']) ?></strong>
                      </td>
                      <td><?= date('M j, Y', strtotime($s['session_date'])) ?></td>
                      <td>
                        <?= date('g:i a', strtotime($s['start_time'])) ?> - <?= date('g:i a', strtotime($s['end_time'])) ?>
                      </td>
                      <td><?= htmlspecialchars($s['venue']) ?></td>
                      <td>
                        <?= $s['capacity'] ? $s['registered_count'] . '/' . $s['capacity'] : 'Unlimited' ?>
                        <?php if ($isFull): ?>
                          <span class="full-badge">FULL</span>
                        <?php endif; ?>
                      </td>
                      <td><?= $fee ?></td>
                      <td>
                        <?php if ($s['is_registered']): ?>
                          <span class="status-badge attending">
                            <i class="fas fa-check-circle"></i> Registered
                          </span>
                        <?php else: ?>
                          <span class="status-badge not-attended">
                            <i class="fas fa-clock"></i> Available
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="actions-cell">
                        <?php if ($s['is_registered']): ?>
                          <button class="action-btn attending-btn" disabled>
                            <i class="fas fa-check"></i> Registered
                          </button>
                        <?php elseif ($isFull): ?>
                          <button class="action-btn full-btn" disabled>
                            <i class="fas fa-times"></i> Full
                          </button>
                        <?php else: ?>
                          <a href="register_session.php?session_id=<?= $s['session_id'] ?>" class="action-btn primary-btn">
                            <i class="fas fa-user-plus"></i> Register
                          </a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if (empty($allSessions)): ?>
        <div class="empty-state">
          <i class="fas fa-calendar-times"></i>
          <h3>No Upcoming Sessions</h3>
          <p>There are currently no training sessions available.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
    <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="js/darkmode.js?v=<?php echo time(); ?>"></script>
    <script src="js/header.js?v=<?php echo time(); ?>"></script>
</body>
</html>