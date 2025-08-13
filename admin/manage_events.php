<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $regId = (int)$_POST['registration_id'];
    $newStatus = $_POST['status'];

    try {
        $stmt = $pdo->prepare("UPDATE registrations SET status = ? WHERE registration_id = ?");
        $stmt->execute([$newStatus, $regId]);
        $successMessage = "Registration status updated successfully!";
    } catch (PDOException $e) {
        $errorMessage = "Error updating status: " . $e->getMessage();
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_event'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $event_date = $_POST['event_date'];
    $location = trim($_POST['location']);

    if ($title && $event_date && $location) {
        try {
            $stmt = $pdo->prepare("INSERT INTO events (title, description, event_date, location) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $description, $event_date, $location]);
            $successMessage = "Event created successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error creating event: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Title, date, and location are required.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event'])) {
    $event_id = (int)$_POST['event_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $event_date = $_POST['event_date'];
    $location = trim($_POST['location']);

    if ($event_id && $title && $event_date && $location) {
        try {
            $stmt = $pdo->prepare("UPDATE events SET title = ?, description = ?, event_date = ?, location = ? WHERE event_id = ?");
            $stmt->execute([$title, $description, $event_date, $location, $event_id]);
            $successMessage = "Event updated successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error updating event: " . $e->getMessage();
        }
    } else {
        $errorMessage = "All fields are required for update.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event'])) {
    $event_id = (int)$_POST['event_id'];
    if ($event_id) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM registrations WHERE event_id = ?")->execute([$event_id]);
            $pdo->prepare("DELETE FROM events WHERE event_id = ?")->execute([$event_id]);
            $pdo->commit();
            $successMessage = "Event deleted successfully.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = "Error deleting event: " . $e->getMessage();
        }
    }
}


$stmt = $pdo->query("SELECT COUNT(*) FROM events");
if ($stmt->fetchColumn() == 0) {
    $pdo->prepare("INSERT INTO events (title, description, event_date, location) VALUES (?, ?, ?, ?)")
        ->execute(['Tech Conference 2025', 'Join us for the annual tech gathering.', date('Y-m-d', strtotime('+1 month')), 'Tech Park Hall']);
}


$events = $pdo->query("SELECT * FROM events ORDER BY event_date DESC")->fetchAll();
$registrations = $pdo->query("
    SELECT r.registration_id, r.event_id, r.full_name, r.email, r.status, r.registration_date, e.title
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    ORDER BY r.registration_date DESC
")->fetchAll();

$upcoming_events = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn();
$past_events = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date < CURDATE()")->fetchColumn();
$total_events = $upcoming_events + $past_events;
$total_registrations = $pdo->query("SELECT COUNT(*) FROM registrations")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Events - PRC Admin</title>
    <!-- Apply saved sidebar state BEFORE CSS -->
  <?php $collapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true'; ?>
  <script>
    // Option 1: Set sidebar width early to prevent flicker
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
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/events.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="events-container">
      <div class="page-header">
        <h1>Event Management</h1>
        <p>Create, update, and manage events and registrations</p>
      </div>

      <?php if ($errorMessage): ?>
        <div class="alert error">
          <i class="fas fa-exclamation-circle"></i>
          <?= htmlspecialchars($errorMessage) ?>
        </div>
      <?php endif; ?>
      
      <?php if ($successMessage): ?>
        <div class="alert success">
          <i class="fas fa-check-circle"></i>
          <?= htmlspecialchars($successMessage) ?>
        </div>
      <?php endif; ?>

      <div class="event-sections">
        <!-- Create Event Section -->
        <section class="create-event card">
          <h2><i class="fas fa-calendar-plus"></i> Create New Event</h2>
          <form method="POST" class="event-form">
            <input type="hidden" name="create_event" value="1">
            
            <div class="form-row">
              <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" required>
              </div>
              
              <div class="form-group">
                <label for="event_date">Date</label>
                <input type="date" id="event_date" name="event_date" required>
              </div>
              
              <div class="form-group">
                <label for="location">Location</label>
                <input type="text" id="location" name="location" required>
              </div>
            </div>
            
            <div class="form-group">
              <label for="description">Description</label>
              <textarea id="description" name="description"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i> Create Event
            </button>
          </form>
        </section>

        <!-- Existing Events Section -->
        <section class="existing-events">
          <div class="section-header">
            <h2><i class="fas fa-calendar-alt"></i> All Events</h2>
            <div class="search-box">
              <input type="text" placeholder="Search events...">
              <button type="submit"><i class="fas fa-search"></i></button>
            </div>
          </div>
          
          <div class="stats-cards">
            <div class="stat-card">
              <div class="stat-icon blue">
                <i class="fas fa-calendar"></i>
              </div>
              <div class="stat-content">
                <h3>Total Events</h3>
                <p><?= $total_events ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon green">
                <i class="fas fa-calendar-check"></i>
              </div>
              <div class="stat-content">
                <h3>Upcoming</h3>
                <p><?= $upcoming_events ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon purple">
                <i class="fas fa-history"></i>
              </div>
              <div class="stat-content">
                <h3>Past Events</h3>
                <p><?= $past_events ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon orange">
                <i class="fas fa-users"></i>
              </div>
              <div class="stat-content">
                <h3>Total Registrations</h3>
                <p><?= $total_registrations ?></p>
              </div>
            </div>
          </div>
          
          <?php if (empty($events)): ?>
            <div class="empty-state">
              <i class="fas fa-calendar-times"></i>
              <h3>No Events Found</h3>
              <p>There are no events to display.</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Description</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($events as $e): ?>
                  <tr>
                    <td><?= htmlspecialchars($e['event_id']) ?></td>
                    <td>
                      <input type="text" name="title" value="<?= htmlspecialchars($e['title']) ?>" 
                             form="update-form-<?= $e['event_id'] ?>" required>
                    </td>
                    <td>
                      <input type="date" name="event_date" value="<?= htmlspecialchars($e['event_date']) ?>" 
                             form="update-form-<?= $e['event_id'] ?>" required>
                    </td>
                    <td>
                      <input type="text" name="location" value="<?= htmlspecialchars($e['location']) ?>" 
                             form="update-form-<?= $e['event_id'] ?>" required>
                    </td>
                    <td>
                      <textarea name="description" form="update-form-<?= $e['event_id'] ?>"><?= htmlspecialchars($e['description']) ?></textarea>
                    </td>
                    <td class="actions">
                      <form method="POST" id="update-form-<?= $e['event_id'] ?>" class="inline-form">
                        <input type="hidden" name="update_event" value="1">
                        <input type="hidden" name="event_id" value="<?= $e['event_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-update">
                          <i class="fas fa-save"></i> Update
                        </button>
                      </form>
                      
                      <form method="POST" class="inline-form" 
                            onsubmit="return confirm('Are you sure you want to delete <?= htmlspecialchars($e['title']) ?>?')">
                        <input type="hidden" name="delete_event" value="1">
                        <input type="hidden" name="event_id" value="<?= $e['event_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-delete">
                          <i class="fas fa-trash-alt"></i> Delete
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

     
        <section class="event-registrations">
          <div class="section-header">
            <h2><i class="fas fa-users"></i> Event Registrations</h2>
            <div class="search-box">
              <input type="text" placeholder="Search registrations...">
              <button type="submit"><i class="fas fa-search"></i></button>
            </div>
          </div>
          
          <?php if (empty($registrations)): ?>
            <div class="empty-state">
              <i class="fas fa-user-slash"></i>
              <h3>No Registrations Found</h3>
              <p>There are no registrations to display.</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Event</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Registered On</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($registrations as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['title']) ?></td>
                    <td><?= htmlspecialchars($r['full_name']) ?></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td><?= date('M d, Y', strtotime($r['registration_date'])) ?></td>
                    <td>
                      <form method="POST" class="status-form">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="registration_id" value="<?= $r['registration_id'] ?>">
                        <select name="status" class="status-select">
                          <option value="pending" <?= $r['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                          <option value="approved" <?= $r['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
                          <option value="rejected" <?= $r['status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </td>
                    <td>
                        <button type="submit" class="btn btn-sm btn-update">
                          <i class="fas fa-sync-alt"></i> Update
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>