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

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

date_default_timezone_set('Asia/Kuala_Lumpur');

$majorServices = [
    'Health Service',
    'Safety Service',
    'Welfare Service',
    'Disaster Management Service',
    'Red Cross Youth'
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateSessionData($data) {
    $errors = [];
    
    $required = ['title', 'session_date', 'start_time', 'end_time', 'venue', 'major_service'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = "Please fill all required fields";
            break;
        }
    }
    
    // Simple validation - just check if times exist
    if (empty($data['start_time'])) {
        $errors[] = "Start time is required";
    }
    
    if (empty($data['end_time'])) {
        $errors[] = "End time is required";
    }
    
    try {
        $date = $data['session_date'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            $errors[] = "Invalid date format. Please use YYYY-MM-DD.";
        }
        
        // Simple time comparison using strtotime
        if (!empty($startTime) && !empty($endTime) && empty($errors)) {
            $startTimestamp = strtotime($date . ' ' . $startTime);
            $endTimestamp = strtotime($date . ' ' . $endTime);
            
            if ($startTimestamp === false) {
                $errors[] = "Invalid start time format.";
            } elseif ($endTimestamp === false) {
                $errors[] = "Invalid end time format.";
            } elseif ($endTimestamp <= $startTimestamp) {
                $errors[] = "End time must be after start time.";
            }
        }
    } catch (Exception $e) {
        $errors[] = "Invalid date/time values: " . $e->getMessage();
    }
    
    // Ensure proper data types
    $capacity = isset($data['capacity']) && $data['capacity'] !== '' ? (int)$data['capacity'] : 0;
    $fee = isset($data['fee']) && $data['fee'] !== '' ? (float)$data['fee'] : 0.00;
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'title' => trim($data['title'] ?? ''),
            'major_service' => trim($data['major_service'] ?? ''),
            'session_date' => $data['session_date'] ?? '',
            'start_time' => trim($data['start_time'] ?? ''),
            'end_time' => trim($data['end_time'] ?? ''),
            'venue' => trim($data['venue'] ?? ''),
            'capacity' => $capacity,
            'fee' => $fee
        ]
    ];
}

function handleDatabaseError($e) {
    error_log("Database error: " . $e->getMessage());
    // Temporary: Show actual error for debugging
    return "Database error: " . $e->getMessage();
}

// Handle CREATE session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $validation = validateSessionData($_POST);
        
        if ($validation['valid']) {
            try {
                // Debug: Show what we're inserting
                error_log("Inserting data: " . json_encode($validation['data']));
                
                $stmt = $pdo->prepare("
                    INSERT INTO training_sessions 
                    (title, major_service, session_date, start_time, end_time, venue, capacity, fee)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $params = [
                    $validation['data']['title'],
                    $validation['data']['major_service'],
                    $validation['data']['session_date'],
                    $validation['data']['start_time'],
                    $validation['data']['end_time'],
                    $validation['data']['venue'],
                    $validation['data']['capacity'],
                    $validation['data']['fee']
                ];
                
                error_log("Parameters: " . json_encode($params));
                
                $result = $stmt->execute($params);
                
                if ($result) {
                    $successMessage = "Training session created successfully!";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } else {
                    $errorInfo = $stmt->errorInfo();
                    $errorMessage = "SQL Error: " . $errorInfo[2];
                }
                
            } catch (PDOException $e) {
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            $errorMessage = implode("<br>", $validation['errors']);
        }
    }
}

// Handle UPDATE session - FIXED: Remove updated_at reference
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $validation = validateSessionData($_POST);
        
        if ($validation['valid'] && $session_id > 0) {
            try {
                // FIXED: Removed updated_at = NOW() since the column doesn't exist
                $stmt = $pdo->prepare("
                    UPDATE training_sessions
                    SET title = ?, major_service = ?, session_date = ?, 
                        start_time = ?, end_time = ?, venue = ?, 
                        capacity = ?, fee = ?
                    WHERE session_id = ?
                ");
                
                $params = [
                    $validation['data']['title'],
                    $validation['data']['major_service'],
                    $validation['data']['session_date'],
                    $validation['data']['start_time'],
                    $validation['data']['end_time'],
                    $validation['data']['venue'],
                    $validation['data']['capacity'],
                    $validation['data']['fee'],
                    $session_id
                ];
                
                $stmt->execute($params);
                $successMessage = "Session updated successfully!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            $errorMessage = $validation['valid'] ? "Invalid session ID" : implode("<br>", $validation['errors']);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        
        if ($session_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE session_id = ?");
                $stmt->execute([$session_id]);
                $registrations = $stmt->fetchColumn();
                
                if ($registrations > 0) {
                    $errorMessage = "Cannot delete session with existing registrations.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM training_sessions WHERE session_id = ?");
                    $stmt->execute([$session_id]);
                    $successMessage = "Session deleted successfully.";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            } catch (PDOException $e) {
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            $errorMessage = "Invalid session ID";
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$serviceFilter = isset($_GET['service']) ? trim($_GET['service']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query with filters
try {
    $whereConditions = [];
    $params = [];
    
    if ($search) {
        $whereConditions[] = "(ts.title LIKE :search OR ts.venue LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if ($serviceFilter) {
        $whereConditions[] = "ts.major_service = :service";
        $params[':service'] = $serviceFilter;
    }
    
    if ($statusFilter === 'upcoming') {
        $whereConditions[] = "ts.session_date >= CURDATE()";
    } elseif ($statusFilter === 'past') {
        $whereConditions[] = "ts.session_date < CURDATE()";
    }
    
    $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $query = "
        SELECT SQL_CALC_FOUND_ROWS ts.*, 
               COUNT(sr.registration_id) AS registrations_count
        FROM training_sessions ts
        LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
        $whereClause
        GROUP BY ts.session_id
        ORDER BY ts.session_date ASC, ts.start_time ASC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sessions = $stmt->fetchAll();
    
    $totalStmt = $pdo->query("SELECT FOUND_ROWS()");
    $totalSessions = $totalStmt->fetchColumn();
    $totalPages = ceil($totalSessions / $limit);
    
    // Get statistics
    $stats = [];
    foreach ($majorServices as $service) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
                SUM(CASE WHEN session_date < CURDATE() THEN 1 ELSE 0 END) as past
            FROM training_sessions 
            WHERE major_service = ?
        ");
        $stmt->execute([$service]);
        $stats[$service] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $totalStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN session_date < CURDATE() THEN 1 ELSE 0 END) as past
        FROM training_sessions
    ")->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errorMessage = handleDatabaseError($e);
    $sessions = [];
    $totalSessions = 0;
    $totalPages = 1;
    $stats = [];
    $totalStats = ['total' => 0, 'upcoming' => 0, 'past' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Training Sessions - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/styles.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/sessions.css?v=<?= time() ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="sessions-container">
    <div class="page-header">
      <h1><i class="fas fa-graduation-cap"></i> Training Sessions Management</h1>
      <p>Schedule and manage training sessions across all major services</p>
    </div>

    <?php if ($errorMessage): ?>
      <div class="alert error">
        <i class="fas fa-exclamation-circle"></i>
        <?= $errorMessage ?>
      </div>
    <?php endif; ?>
    
    <?php if ($successMessage): ?>
      <div class="alert success">
        <i class="fas fa-check-circle"></i>
        <?= $successMessage ?>
      </div>
    <?php endif; ?>

    <!-- Service Filter Tabs -->
    <div class="service-tabs">
      <a href="?service=&status=<?= htmlspecialchars($statusFilter) ?>" class="service-tab all-services <?= !$serviceFilter ? 'active' : '' ?>">
        <div class="service-name">All Services</div>
        <div class="service-count"><?= $totalStats['total'] ?> sessions</div>
      </a>
      <?php foreach ($majorServices as $service): ?>
        <a href="?service=<?= urlencode($service) ?>&status=<?= htmlspecialchars($statusFilter) ?>" 
           class="service-tab <?= $serviceFilter === $service ? 'active' : '' ?>">
          <div class="service-name"><?= htmlspecialchars($service) ?></div>
          <div class="service-count"><?= $stats[$service]['total'] ?? 0 ?> sessions</div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Action Bar -->
    <div class="action-bar">
      <div class="action-bar-left">
        <form method="GET" class="search-box">
          <input type="hidden" name="service" value="<?= htmlspecialchars($serviceFilter) ?>">
          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search sessions..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
        </form>
        
        <div class="status-filter">
          <button onclick="filterStatus('all')" class="<?= !$statusFilter ? 'active' : '' ?>">All</button>
          <button onclick="filterStatus('upcoming')" class="<?= $statusFilter === 'upcoming' ? 'active' : '' ?>">Upcoming</button>
          <button onclick="filterStatus('past')" class="<?= $statusFilter === 'past' ? 'active' : '' ?>">Past</button>
        </div>
      </div>
      
      <button class="btn-create" onclick="openCreateModal()">
        <i class="fas fa-plus-circle"></i> Create New Session
      </button>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-calendar-alt"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalStats['total'] ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Total Sessions</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-clock"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalStats['upcoming'] ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Upcoming</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-history"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalStats['past'] ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Completed</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
          <i class="fas fa-users"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= array_sum(array_column($sessions, 'registrations_count')) ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Total Registrations</div>
        </div>
      </div>
    </div>

    <!-- Sessions Table -->
    <div class="sessions-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">
          <?php if ($serviceFilter): ?>
            <?= htmlspecialchars($serviceFilter) ?> Sessions
          <?php else: ?>
            All Training Sessions
          <?php endif; ?>
        </h2>
      </div>
      
      <?php if (empty($sessions)): ?>
        <div class="empty-state">
          <i class="fas fa-inbox"></i>
          <h3>No sessions found</h3>
          <p><?= $search ? 'Try adjusting your search criteria' : 'Click "Create New Session" to get started' ?></p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Session Details</th>
              <th>Service</th>
              <th>Date & Time</th>
              <th>Venue</th>
              <th>Fee</th>
              <th>Registrations</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $session): 
              $sessionDate = strtotime($session['session_date']);
              $today = strtotime('today');
              $isUpcoming = $sessionDate >= $today;
              $isFull = $session['capacity'] > 0 && $session['registrations_count'] >= $session['capacity'];
            ?>
              <tr>
                <td>
                  <div class="session-title"><?= htmlspecialchars($session['title']) ?></div>
                  <div style="font-size: 0.75rem; color: var(--gray); margin-top: 0.2rem;">ID: #<?= $session['session_id'] ?></div>
                </td>
                <td>
                  <span class="session-service"><?= htmlspecialchars($session['major_service']) ?></span>
                </td>
                <td>
                  <div class="session-datetime">
                    <span class="session-date"><?= date('M d, Y', $sessionDate) ?></span>
                    <span class="session-time"><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
                  </div>
                </td>
                <td><?= htmlspecialchars($session['venue']) ?></td>
                <td>
                  <div class="fee-display">
                    <?php if ($session['fee'] > 0): ?>
                      <span class="fee-amount">₱<?= number_format($session['fee'], 2) ?></span>
                    <?php else: ?>
                      <span class="fee-free">FREE</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <a href="view_registrations.php?session_id=<?= $session['session_id'] ?>" 
                     class="registrations-badge <?= $isFull ? 'full' : '' ?>">
                    <i class="fas fa-users"></i>
                    <?= $session['registrations_count'] ?> / <?= $session['capacity'] ?: '∞' ?>
                    <?php if ($isFull): ?>
                      <span style="font-size: 0.7rem; background: var(--prc-red); color: white; padding: 0.2rem 0.4rem; border-radius: 4px;">FULL</span>
                    <?php endif; ?>
                  </a>
                </td>
                <td>
                  <span class="status-badge <?= $isUpcoming ? 'upcoming' : 'past' ?>">
                    <?= $isUpcoming ? 'Upcoming' : 'Completed' ?>
                  </span>
                </td>
                <td class="actions">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($session)) ?>)">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this session?');">
                    <input type="hidden" name="delete_session" value="1">
                    <input type="hidden" name="session_id" value="<?= $session['session_id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <button type="submit" class="btn-action btn-delete">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        
        <?php if ($totalPages > 1): ?>
          <div class="pagination">
            <?php if ($page > 1): ?>
              <a href="?page=<?= $page-1 ?>&service=<?= urlencode($serviceFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= urlencode($search) ?>" class="page-link">
                <i class="fas fa-chevron-left"></i> Previous
              </a>
            <?php endif; ?>
            
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            
            <?php if ($page < $totalPages): ?>
              <a href="?page=<?= $page+1 ?>&service=<?= urlencode($serviceFilter) ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= urlencode($search) ?>" class="page-link">
                Next <i class="fas fa-chevron-right"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Create/Edit Modal -->
  <div class="modal" id="sessionModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Create New Session</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="sessionForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="create_session" value="1" id="formAction">
        <input type="hidden" name="session_id" id="sessionId">
        
        <div class="form-group">
          <label for="title">Session Title *</label>
          <input type="text" id="title" name="title" required placeholder="Enter session title">
        </div>
        
        <div class="form-group">
          <label for="major_service">Major Service *</label>
          <select id="major_service" name="major_service" required>
            <option value="">Select a service</option>
            <?php foreach ($majorServices as $service): ?>
              <option value="<?= htmlspecialchars($service) ?>"><?= htmlspecialchars($service) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="session_date">Date *</label>
            <input type="date" id="session_date" name="session_date" required min="<?= date('Y-m-d') ?>">
          </div>
          
          <div class="form-group">
            <label for="venue">Venue *</label>
            <input type="text" id="venue" name="venue" required placeholder="Location">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="start_time">Start Time *</label>
            <input type="time" id="start_time" name="start_time" required value="09:00">
          </div>
          
          <div class="form-group">
            <label for="end_time">End Time *</label>
            <input type="time" id="end_time" name="end_time" required value="17:00">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="capacity">Capacity</label>
            <input type="number" id="capacity" name="capacity" min="0" placeholder="0 for unlimited">
          </div>
          
          <div class="form-group">
            <label for="fee">Fee (₱)</label>
            <input type="number" id="fee" name="fee" min="0" step="0.01" placeholder="0.00">
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Session
        </button>
      </form>
    </div>
  </div>

  <script>
    function openCreateModal() {
      document.getElementById('modalTitle').textContent = 'Create New Session';
      document.getElementById('formAction').name = 'create_session';
      document.getElementById('sessionForm').reset();
      document.getElementById('sessionModal').classList.add('active');
    }
    
    function openEditModal(session) {
      document.getElementById('modalTitle').textContent = 'Edit Session';
      document.getElementById('formAction').name = 'update_session';
      document.getElementById('sessionId').value = session.session_id;
      document.getElementById('title').value = session.title;
      document.getElementById('major_service').value = session.major_service;
      document.getElementById('session_date').value = session.session_date;
      document.getElementById('start_time').value = session.start_time;
      document.getElementById('end_time').value = session.end_time;
      document.getElementById('venue').value = session.venue;
      document.getElementById('capacity').value = session.capacity;
      document.getElementById('fee').value = session.fee;
      document.getElementById('sessionModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('sessionModal').classList.remove('active');
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
    
    // Close modal when clicking outside
    document.getElementById('sessionModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
    
    // Form validation
    document.getElementById('sessionForm').addEventListener('submit', function(e) {
      const startTime = document.getElementById('start_time').value;
      const endTime = document.getElementById('end_time').value;
      
      if (endTime <= startTime) {
        e.preventDefault();
        alert('End time must be after start time');
      }
    });
  </script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>