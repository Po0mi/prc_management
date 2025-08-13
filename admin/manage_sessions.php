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
    
   
    if (!preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $data['start_time'] ?? '')) {
        $errors[] = "Invalid start time format. Please use HH:MM format (24-hour with leading zeros).";
    }
    
    if (!preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $data['end_time'] ?? '')) {
        $errors[] = "Invalid end time format. Please use HH:MM format (24-hour with leading zeros).";
    }
    
    
    try {
        $date = $data['session_date'] ?? '';
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';
        
        
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            $errors[] = "Invalid date format. Please use YYYY-MM-DD.";
        }
        
        
        if (empty($errors)) {
            $start = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $startTime);
            $end = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $endTime);
            
            if (!$start || !$end) {
                $errors[] = "Invalid date/time combination. Please check your inputs.";
            } elseif ($end <= $start) {
                $errors[] = "End time must be after start time.";
            }
        }
    } catch (Exception $e) {
        $errors[] = "Invalid date/time values: " . $e->getMessage();
    }
    
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
            'capacity' => isset($data['capacity']) ? (int)$data['capacity'] : 0,
            'fee' => isset($data['fee']) ? (float)$data['fee'] : 0.00
        ]
    ];
}

function handleDatabaseError($e) {
    error_log("Database error: " . $e->getMessage());
    return "A database error occurred. Please try again later.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $validation = validateSessionData($_POST);
        
        if ($validation['valid']) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO training_sessions 
                    (title, major_service, session_date, start_time, end_time, venue, capacity, fee)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute(array_values($validation['data']));
                $successMessage = "Training session created successfully!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (PDOException $e) {
                $errorMessage = handleDatabaseError($e);
            }
        } else {
            $errorMessage = implode("<br>", $validation['errors']);
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security error: Invalid form submission. Please try again.";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $validation = validateSessionData($_POST);
        
        if ($validation['valid'] && $session_id > 0) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE training_sessions
                    SET title = ?, major_service = ?, session_date = ?, 
                        start_time = ?, end_time = ?, venue = ?, 
                        capacity = ?, fee = ?, updated_at = NOW()
                    WHERE session_id = ?
                ");
                $params = array_values($validation['data']);
                $params[] = $session_id;
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


$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    if ($search) {
        $stmt = $pdo->prepare("
            SELECT SQL_CALC_FOUND_ROWS ts.*, 
                   COUNT(sr.registration_id) AS registrations_count
            FROM training_sessions ts
            LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
            WHERE MATCH(ts.title, ts.venue, ts.major_service) AGAINST(:search IN BOOLEAN MODE)
            GROUP BY ts.session_id
            ORDER BY ts.session_date ASC, ts.start_time ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':search', $search, PDO::PARAM_STR);
    } else {
        $stmt = $pdo->prepare("
            SELECT SQL_CALC_FOUND_ROWS ts.*, 
                   COUNT(sr.registration_id) AS registrations_count
            FROM training_sessions ts
            LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
            GROUP BY ts.session_id
            ORDER BY ts.session_date ASC, ts.start_time ASC
            LIMIT :limit OFFSET :offset
        ");
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $sessions = $stmt->fetchAll();
    
    
    $totalStmt = $pdo->query("SELECT FOUND_ROWS()");
    $totalSessions = $totalStmt->fetchColumn();
    $totalPages = ceil($totalSessions / $limit);
    
    
    $upcoming = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()")->fetchColumn();
    $past = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date < CURDATE()")->fetchColumn();
    
} catch (PDOException $e) {
    $errorMessage = handleDatabaseError($e);
    $sessions = [];
    $totalSessions = 0;
    $totalPages = 1;
    $upcoming = 0;
    $past = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Training Sessions - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/styles.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/sessions.css?v=<?= time() ?>">
  <style>
    
    .invalid {
        border-color: #ff4444 !important;
        box-shadow: 0 0 0 2px rgba(255, 68, 68, 0.2);
    }
    .error-message {
        color: #ff4444;
        font-size: 0.8em;
        margin-top: 5px;
        display: none;
    }
    .has-error .error-message {
        display: block;
    }
    .form-group {
        position: relative;
        margin-bottom: 15px;
    }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="sessions-container">
      <div class="page-header">
        <h1>Training Sessions Management</h1>
        <p>Schedule and manage training sessions for volunteers</p>
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
        <!-- Create Session Section -->
        <section class="card">
          <div class="section-header">
            <h2><i class="fas fa-calendar-plus"></i> Schedule New Training Session</h2>
          </div>
          <form method="POST" class="session-form" id="create-session-form">
            <input type="hidden" name="create_session" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <div class="form-row">
              <div class="form-group">
                <label for="title">Session Title *</label>
                <input type="text" id="title" name="title" required placeholder="Enter session title">
              </div>
              
              <div class="form-group">
                <label for="session_date">Date *</label>
                <input type="date" id="session_date" name="session_date" required min="<?= date('Y-m-d') ?>">
                <div class="error-message" id="date-error"></div>
              </div>
            </div>
            
            <div class="form-group">
              <label for="major_service">Major Service *</label>
              <select id="major_service" name="major_service" required>
                <option value="">Select a Major Service</option>
                <?php foreach ($majorServices as $service): ?>
                  <option value="<?= htmlspecialchars($service) ?>"><?= htmlspecialchars($service) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="start_time">Start Time *</label>
                <input type="time" id="start_time" name="start_time" required 
                       pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
                       title="Please enter time in HH:MM format (24-hour)">
                <div class="error-message" id="start-time-error"></div>
              </div>
              
              <div class="form-group">
                <label for="end_time">End Time *</label>
                <input type="time" id="end_time" name="end_time" required 
                       pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
                       title="Please enter time in HH:MM format (24-hour)">
                <div class="error-message" id="end-time-error"></div>
              </div>
              
              <div class="form-group">
                <label for="venue">Venue *</label>
                <input type="text" id="venue" name="venue" required placeholder="Training location">
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="capacity">Capacity</label>
                <input type="number" id="capacity" name="capacity" min="1" placeholder="Leave empty for unlimited">
              </div>
              
              <div class="form-group">
                <label for="fee">Fee (₱)</label>
                <input type="number" id="fee" name="fee" min="0" step="0.01" placeholder="0.00 for free">
              </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i> Create Session
            </button>
          </form>
        </section>

      
        <section class="card">
          <div class="section-header">
            <h2><i class="fas fa-calendar-alt"></i> All Training Sessions</h2>
            <form method="GET" class="search-box">
              <input type="text" name="search" placeholder="Search sessions..." 
                     value="<?= htmlspecialchars($search) ?>">
              <button type="submit"><i class="fas fa-search"></i></button>
              <?php if ($search): ?>
                <a href="manage_sessions.php" class="clear-search">
                  <i class="fas fa-times"></i>
                </a>
              <?php endif; ?>
            </form>
          </div>
          
          <div class="stats-cards">
            <div class="stat-card">
              <div class="stat-icon blue">
                <i class="fas fa-calendar"></i>
              </div>
              <div class="stat-content">
                <h3>Total Sessions</h3>
                <p><?= $totalSessions ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon green">
                <i class="fas fa-calendar-check"></i>
              </div>
              <div class="stat-content">
                <h3>Upcoming</h3>
                <p><?= $upcoming ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon orange">
                <i class="fas fa-users"></i>
              </div>
              <div class="stat-content">
                <h3>Total Registrations</h3>
                <p><?= array_sum(array_column($sessions, 'registrations_count')) ?></p>
              </div>
            </div>
          </div>
          
          <?php if (empty($sessions)): ?>
            <div class="empty-state">
              <i class="fas fa-calendar-times"></i>
              <h3>No Training Sessions Found</h3>
              <p><?= $search ? 'Try a different search term' : 'Get started by scheduling your first training session' ?></p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Major Service</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Venue</th>
                    <th>Registrations</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sessions as $s): 
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
                    
                    $isFull = $s['capacity'] > 0 && $s['registrations_count'] >= $s['capacity'];
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($s['session_id']) ?></td>
                      <td>
                        <input type="text" name="title" value="<?= htmlspecialchars($s['title']) ?>" 
                               form="update-form-<?= $s['session_id'] ?>" required>
                      </td>
                      <td>
                        <select name="major_service" form="update-form-<?= $s['session_id'] ?>" required>
                          <?php foreach ($majorServices as $service): ?>
                            <option value="<?= htmlspecialchars($service) ?>" <?= $service === $s['major_service'] ? 'selected' : '' ?>>
                              <?= htmlspecialchars($service) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <input type="date" name="session_date" value="<?= $s['session_date'] ?>"
                               form="update-form-<?= $s['session_id'] ?>" required>
                      </td>
                      <td class="time-inputs">
                        <input type="time" name="start_time" value="<?= $s['start_time'] ?>"
                               form="update-form-<?= $s['session_id'] ?>" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]">
                        <span>to</span>
                        <input type="time" name="end_time" value="<?= $s['end_time'] ?>"
                               form="update-form-<?= $s['session_id'] ?>" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]">
                      </td>
                      <td>
                        <input type="text" name="venue" value="<?= htmlspecialchars($s['venue']) ?>"
                               form="update-form-<?= $s['session_id'] ?>" required>
                      </td>
                      <td>
                        <a href="view_registrations.php?session_id=<?= $s['session_id'] ?>" 
                           class="registrations-link <?= $isFull ? 'full' : '' ?>">
                          <?= $s['registrations_count'] ?> / <?= $s['capacity'] ?: '∞' ?>
                          <?php if ($isFull): ?>
                            <span class="full-badge">FULL</span>
                          <?php endif; ?>
                        </a>
                      </td>
                      <td>
                        <span class="status-badge <?= $statusClass ?>">
                          <?= $statusText ?>
                        </span>
                      </td>
                      <td class="actions">
                        <form method="POST" id="update-form-<?= $s['session_id'] ?>" class="inline-form">
                          <input type="hidden" name="update_session" value="1">
                          <input type="hidden" name="session_id" value="<?= $s['session_id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="capacity" value="<?= $s['capacity'] ?>" form="update-form-<?= $s['session_id'] ?>">
                          <input type="hidden" name="fee" value="<?= $s['fee'] ?>" form="update-form-<?= $s['session_id'] ?>">
                          <button type="submit" class="btn btn-sm btn-update">
                            <i class="fas fa-save"></i> Update
                          </button>
                        </form>
                        
                        <form method="POST" class="inline-form" 
                              onsubmit="return confirm('Are you sure you want to delete this session?')">
                          <input type="hidden" name="delete_session" value="1">
                          <input type="hidden" name="session_id" value="<?= $s['session_id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <button type="submit" class="btn btn-sm btn-delete">
                            <i class="fas fa-trash-alt"></i> Delete
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
                    <a href="?page=<?= $page-1 ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="page-link">
                      <i class="fas fa-chevron-left"></i> Previous
                    </a>
                  <?php endif; ?>
                  
                  <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
                  
                  <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page+1 ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="page-link">
                      Next <i class="fas fa-chevron-right"></i>
                    </a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
      
      document.getElementById('start_time').value = '09:00';
      document.getElementById('end_time').value = '17:00';
      
      
      document.getElementById('session_date').min = new Date().toISOString().split('T')[0];
      
      
      document.getElementById('start_time').addEventListener('blur', validateTimeInput);
      document.getElementById('end_time').addEventListener('blur', validateTimeInput);
      document.getElementById('session_date').addEventListener('blur', validateDate);
      
      
      document.getElementById('create-session-form').addEventListener('submit', function(e) {
          if (!validateForm()) {
              e.preventDefault();
          }
      });
      
      function validateTimeInput(e) {
          const timeInput = e.target;
          const timeValue = timeInput.value;
          const timeRegex = /^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/;
          const errorElement = document.getElementById(timeInput.id + '-error');
          const formGroup = timeInput.closest('.form-group');
          
          if (!timeValue) {
              formGroup.classList.add('has-error');
              errorElement.textContent = 'This field is required';
              return false;
          }
          
          if (!timeRegex.test(timeValue)) {
              formGroup.classList.add('has-error');
              errorElement.textContent = 'Please enter time in HH:MM format (24-hour with leading zeros)';
              return false;
          } else {
              formGroup.classList.remove('has-error');
              errorElement.textContent = '';
              return true;
          }
      }
      
      function validateDate() {
          const dateInput = document.getElementById('session_date');
          const dateValue = dateInput.value;
          const errorElement = document.getElementById('date-error');
          const formGroup = dateInput.closest('.form-group');
          
          if (!dateValue) {
              formGroup.classList.add('has-error');
              errorElement.textContent = 'Please select a date';
              return false;
          }
          
          const date = new Date(dateValue);
          if (isNaN(date.getTime())) {
              formGroup.classList.add('has-error');
              errorElement.textContent = 'Invalid date format';
              return false;
          }
          
          const today = new Date();
          today.setHours(0, 0, 0, 0);
          
          if (date < today) {
              formGroup.classList.add('has-error');
              errorElement.textContent = 'Date cannot be in the past';
              return false;
          }
          
          formGroup.classList.remove('has-error');
          errorElement.textContent = '';
          return true;
      }
      
      function validateForm() {
          
          const dateValid = validateDate();
          const startTimeValid = validateTimeInput({target: document.getElementById('start_time')});
          const endTimeValid = validateTimeInput({target: document.getElementById('end_time')});
          
          
          if (startTimeValid && endTimeValid && dateValid) {
              const startTime = document.getElementById('start_time').value;
              const endTime = document.getElementById('end_time').value;
              const date = document.getElementById('session_date').value;
              
              const start = new Date(`${date}T${startTime}`);
              const end = new Date(`${date}T${endTime}`);
              
              if (end <= start) {
                  const endTimeGroup = document.getElementById('end_time').closest('.form-group');
                  const errorElement = document.getElementById('end-time-error');
                  endTimeGroup.classList.add('has-error');
                  errorElement.textContent = 'End time must be after start time';
                  return false;
              }
          }
          
          
          const invalidFields = document.querySelectorAll('.has-error');
          if (invalidFields.length > 0) {
              invalidFields[0].querySelector('input').focus();
              return false;
          }
          
          return true;
      }
  });
  </script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>