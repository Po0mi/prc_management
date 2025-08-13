<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Handle form submissions
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

// Fixed delete event logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event'])) {
    $event_id = (int)$_POST['event_id'];
    
    if ($event_id > 0) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // First, delete all registrations for this event
            $stmt1 = $pdo->prepare("DELETE FROM registrations WHERE event_id = ?");
            $stmt1->execute([$event_id]);
            
            // Then, delete the event itself
            $stmt2 = $pdo->prepare("DELETE FROM events WHERE event_id = ?");
            $result = $stmt2->execute([$event_id]);
            
            if ($stmt2->rowCount() > 0) {
                $pdo->commit();
                $successMessage = "Event deleted successfully.";
            } else {
                $pdo->rollBack();
                $errorMessage = "Event not found or could not be deleted.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = "Error deleting event: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Invalid event ID.";
    }
}

// Initialize sample data if needed
$stmt = $pdo->query("SELECT COUNT(*) FROM events");
if ($stmt->fetchColumn() == 0) {
    $pdo->prepare("INSERT INTO events (title, description, event_date, location) VALUES (?, ?, ?, ?)")
        ->execute(['Tech Conference 2025', 'Join us for the annual tech gathering.', date('Y-m-d', strtotime('+1 month')), 'Tech Park Hall']);
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build query with filters
$whereConditions = [];
$params = [];

if ($search) {
    $whereConditions[] = "(title LIKE :search OR location LIKE :search OR description LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter === 'upcoming') {
    $whereConditions[] = "event_date >= CURDATE()";
} elseif ($statusFilter === 'past') {
    $whereConditions[] = "event_date < CURDATE()";
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get events with registration counts
$query = "
    SELECT e.*, COUNT(r.registration_id) AS registrations_count
    FROM events e
    LEFT JOIN registrations r ON e.event_id = r.event_id
    $whereClause
    GROUP BY e.event_id
    ORDER BY e.event_date DESC
";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$events = $stmt->fetchAll();

// Get registrations
$registrations = $pdo->query("
    SELECT r.registration_id, r.event_id, r.full_name, r.email, r.status, r.registration_date, e.title
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    ORDER BY r.registration_date DESC
")->fetchAll();

// Get statistics
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
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/events.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="events-container">
    <div class="page-header">
      <h1><i class="fas fa-calendar-alt"></i> Event Management</h1>
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

    <!-- Action Bar -->
    <div class="action-bar">
      <div class="action-bar-left">
        <form method="GET" class="search-box">
          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search events..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
        </form>
        
        <div class="status-filter">
          <button onclick="filterStatus('all')" class="<?= !$statusFilter ? 'active' : '' ?>">All</button>
          <button onclick="filterStatus('upcoming')" class="<?= $statusFilter === 'upcoming' ? 'active' : '' ?>">Upcoming</button>
          <button onclick="filterStatus('past')" class="<?= $statusFilter === 'past' ? 'active' : '' ?>">Past</button>
        </div>
      </div>
      
      <button class="btn-create" onclick="openCreateModal()">
        <i class="fas fa-plus-circle"></i> Create New Event
      </button>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-calendar"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $total_events ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Total Events</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-calendar-check"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $upcoming_events ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Upcoming</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-history"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $past_events ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Past Events</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
          <i class="fas fa-users"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $total_registrations ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Total Registrations</div>
        </div>
      </div>
    </div>

    <!-- Events Table -->
    <div class="events-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">All Events</h2>
      </div>
      
      <?php if (empty($events)): ?>
        <div class="empty-state">
          <i class="fas fa-calendar-times"></i>
          <h3>No events found</h3>
          <p><?= $search ? 'Try adjusting your search criteria' : 'Click "Create New Event" to get started' ?></p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Event Details</th>
              <th>Date</th>
              <th>Location</th>
              <th>Registrations</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($events as $e): 
              $eventDate = strtotime($e['event_date']);
              $today = strtotime('today');
              $isUpcoming = $eventDate >= $today;
            ?>
              <tr>
                <td>
                  <div class="event-title"><?= htmlspecialchars($e['title']) ?></div>
                  <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                    <?= htmlspecialchars($e['description']) ?>
                  </div>
                </td>
                <td>
                  <div class="event-date">
                    <span><?= date('M d, Y', $eventDate) ?></span>
                  </div>
                </td>
                <td><?= htmlspecialchars($e['location']) ?></td>
                <td>
                  <div class="registrations-badge">
                    <i class="fas fa-users"></i>
                    <?= $e['registrations_count'] ?>
                  </div>
                </td>
                <td>
                  <span class="status-badge <?= $isUpcoming ? 'upcoming' : 'past' ?>">
                    <?= $isUpcoming ? 'Upcoming' : 'Past' ?>
                  </span>
                </td>
                <td class="actions">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($e)) ?>)">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                  <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($e['title']) ?>', <?= $e['registrations_count'] ?>);">
                    <input type="hidden" name="delete_event" value="1">
                    <input type="hidden" name="event_id" value="<?= $e['event_id'] ?>">
                    <button type="submit" class="btn-action btn-delete">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Event Registrations Section -->
    <div class="registrations-section">
      <div class="section-header">
        <h2><i class="fas fa-users"></i> Event Registrations</h2>
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="regSearch" placeholder="Search registrations...">
        </div>
      </div>
      
      <?php if (empty($registrations)): ?>
        <div class="empty-state">
          <i class="fas fa-user-slash"></i>
          <h3>No registrations found</h3>
          <p>No registrations to display.</p>
        </div>
      <?php else: ?>
        <div class="table-container">
          <table class="data-table" id="registrationsTable">
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
                    <button type="submit" class="btn-action btn-update">
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
    </div>
  </div>

  <!-- Create/Edit Modal -->
  <div class="modal" id="eventModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Create New Event</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="eventForm">
        <input type="hidden" name="create_event" value="1" id="formAction">
        <input type="hidden" name="event_id" id="eventId">
        
        <div class="form-group">
          <label for="title">Event Title *</label>
          <input type="text" id="title" name="title" required placeholder="Enter event title">
        </div>
        
        <div class="form-group">
          <label for="description">Description</label>
          <textarea id="description" name="description" placeholder="Enter event description"></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="event_date">Date *</label>
            <input type="date" id="event_date" name="event_date" required min="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="location">Location *</label>
            <input type="text" id="location" name="location" required placeholder="Event location">
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Event
        </button>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>
    function openCreateModal() {
      document.getElementById('modalTitle').textContent = 'Create New Event';
      document.getElementById('formAction').name = 'create_event';
      document.getElementById('eventForm').reset();
      document.getElementById('eventModal').classList.add('active');
    }
    
    function openEditModal(event) {
      document.getElementById('modalTitle').textContent = 'Edit Event';
      document.getElementById('formAction').name = 'update_event';
      document.getElementById('eventId').value = event.event_id;
      document.getElementById('title').value = event.title;
      document.getElementById('description').value = event.description || '';
      document.getElementById('event_date').value = event.event_date;
      document.getElementById('location').value = event.location;
      document.getElementById('eventModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('eventModal').classList.remove('active');
    }
    
    function filterStatus(status) {
      const urlParams = new URLSearchParams(window.location.search);
      if (status === 'all') {
        urlParams.delete('status');
      } else {
        urlParams.set('status', status);
      }
      window.location.search = urlParams.toString();
    }
    
    // Enhanced delete confirmation with registration count warning
    function confirmDelete(eventTitle, registrationCount) {
      let message = `Are you sure you want to delete the event "${eventTitle}"?`;
      if (registrationCount > 0) {
        message += `\n\nWarning: This event has ${registrationCount} registration(s) that will also be deleted!`;
      }
      message += '\n\nThis action cannot be undone.';
      
      return confirm(message);
    }
    
    // Close modal when clicking outside
    document.getElementById('eventModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
    
    // Registration search functionality
    document.getElementById('regSearch').addEventListener('input', function() {
      const searchTerm = this.value.toLowerCase();
      const rows = document.querySelectorAll('#registrationsTable tbody tr');
      
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
      });
    });

    // AJAX for updating registration status
    document.querySelectorAll('.status-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const row = this.closest('tr');
        const submitButton = this.querySelector('button[type="submit"]');
        
        // Disable the button and show loading state
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        fetch(window.location.href, {
          method: 'POST',
          body: formData
        })
        .then(response => response.text())
        .then(html => {
          // Parse the response to check for success/error messages
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');
          const alert = doc.querySelector('.alert');
          
          if (alert) {
            // Remove any existing alerts
            document.querySelectorAll('.alert').forEach(a => a.remove());
            
            // Show the alert message
            const alertsContainer = document.createElement('div');
            alertsContainer.innerHTML = alert.outerHTML;
            document.querySelector('.page-header').after(alertsContainer.firstChild);
            
            // Highlight the updated row briefly
            row.style.backgroundColor = '#e8f5e9';
            setTimeout(() => {
              row.style.transition = 'background-color 0.5s';
              row.style.backgroundColor = '';
            }, 1000);
            
            // Remove alert after 5 seconds
            setTimeout(() => {
              document.querySelector('.alert')?.remove();
            }, 5000);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          showAlert('An error occurred while updating the status', 'error');
        })
        .finally(() => {
          // Re-enable the button
          submitButton.disabled = false;
          submitButton.innerHTML = '<i class="fas fa-sync-alt"></i> Update';
        });
      });
    });

    // Helper function to show alerts
    function showAlert(message, type) {
      // Remove any existing alerts
      document.querySelectorAll('.alert').forEach(a => a.remove());
      
      const alert = document.createElement('div');
      alert.className = `alert ${type}`;
      alert.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'exclamation'}-circle"></i> ${message}`;
      document.querySelector('.page-header').after(alert);
      setTimeout(() => alert.remove(), 5000);
    }
  </script>
</body>
</html>