<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();

if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

$userId = current_user_id();
$pdo = $GLOBALS['pdo'];
$regMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_event'])) {
    $eventId = (int)$_POST['event_id'];
    $fullName = trim($_POST['full_name']);
    $email = trim($_POST['email']);

   
    $check = $pdo->prepare("SELECT * FROM registrations WHERE event_id = ? AND user_id = ?");
    $check->execute([$eventId, $userId]);

    if ($check->rowCount() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO registrations (event_id, user_id, registration_date, full_name, email, status)
            VALUES (?, ?, NOW(), ?, ?, 'pending')
        ");
        $stmt->execute([$eventId, $userId, $fullName, $email]);
        $regMessage = "You have successfully registered. Awaiting confirmation.";
    } else {
        $regMessage = "You are already registered for this event.";
    }
}

$stmt = $pdo->query("SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC");
$events = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Event Registration - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/registration.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
  
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="header-content">
    <?php include 'header.php'; ?>
    
    <div class="admin-content">
        <div class="users-container">
            <div class="page-header">
                <h1>Event Registration</h1>
                <p>Register for upcoming PRC events</p>
            </div>

            <?php if ($regMessage): ?>
                <div class="alert <?= strpos($regMessage, 'successfully') !== false ? 'success' : 'error' ?>">
                    <i class="fas <?= strpos($regMessage, 'successfully') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($regMessage) ?>
                </div>
            <?php endif; ?>

            <div class="user-sections">
                <?php if (empty($events)): ?>
                    <section class="card">
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Upcoming Events</h3>
                            <p>There are currently no events available for registration.</p>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="existing-users">
                        <div class="section-header">
                            <h2><i class="fas fa-calendar-alt"></i> Upcoming Events</h2>
                        </div>

                        <div class="stats-cards">
                            <div class="stat-card">
                                <div class="stat-icon blue">
                                    <i class="fas fa-calendar"></i>
                                </div>
                                <div class="stat-content">
                                    <h3>Total Events</h3>
                                    <p><?= count($events) ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Event</th>
                                        <th>Date</th>
                                        <th>Location</th>
                                        <th>Description</th>
                                        <th>Register</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $e): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($e['title']) ?></td>
                                        <td><?= htmlspecialchars($e['event_date']) ?></td>
                                        <td><?= htmlspecialchars($e['location']) ?></td>
                                        <td><?= htmlspecialchars($e['description']) ?></td>
                                        <td class="actions">
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="event_id" value="<?= $e['event_id'] ?>">
                                                <div class="form-group">
                                                    <input type="text" name="full_name" placeholder="Full Name" required>
                                                </div>
                                                <div class="form-group">
                                                    <input type="email" name="email" placeholder="Email" required>
                                                </div>
                                                <button type="submit" name="register_event" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-user-plus"></i> Register
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="/js/register.js"></script>
      <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
      <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
</body>
</html>