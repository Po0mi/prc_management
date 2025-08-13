<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle registration deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_registration'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Security error: Invalid form submission.";
        header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
        exit;
    }
    
    $registrationId = (int)$_POST['registration_id'];
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("DELETE FROM payments WHERE registration_id = ?");
        $stmt->execute([$registrationId]);
        
        $stmt = $pdo->prepare("DELETE FROM session_registrations WHERE registration_id = ?");
        $stmt->execute([$registrationId]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "Registration deleted successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error deleting registration: " . $e->getMessage();
    }
    
    header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
    exit;
}

// Handle payment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Security error: Invalid form submission.";
        header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
        exit;
    }
    
    $paymentId = (int)$_POST['payment_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE payments SET status = ? WHERE payment_id = ?");
        $stmt->execute([$newStatus, $paymentId]);
        
        $_SESSION['success_message'] = "Payment status updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating payment status: " . $e->getMessage();
    }
    
    header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
    exit;
}

// Handle attendance update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Security error: Invalid form submission.";
        header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
        exit;
    }
    
    $registrationId = (int)$_POST['registration_id'];
    $newStatus = $_POST['new_attendance'];
    
    try {
        $stmt = $pdo->prepare("UPDATE session_registrations SET attendance_status = ? WHERE registration_id = ?");
        $stmt->execute([$newStatus, $registrationId]);
        
        $_SESSION['success_message'] = "Attendance updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating attendance: " . $e->getMessage();
    }
    
    header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
    exit;
}

// Get filter parameters
$serviceFilter = isset($_GET['service']) ? trim($_GET['service']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Get all major services for tabs
$majorServices = [
    'Health Service',
    'Safety Service',
    'Welfare Service',
    'Disaster Management Service',
    'Red Cross Youth'
];

// Get sessions with registration counts, filtered by service if specified
$sessionQuery = "
    SELECT ts.*, COUNT(sr.registration_id) AS registrations_count
    FROM training_sessions ts
    LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
";

$sessionParams = [];
if ($serviceFilter) {
    $sessionQuery .= " WHERE ts.major_service = ?";
    $sessionParams[] = $serviceFilter;
}

$sessionQuery .= "
    GROUP BY ts.session_id
    ORDER BY ts.session_date DESC
";

$allSessions = $pdo->prepare($sessionQuery);
$allSessions->execute($sessionParams);
$sessions = $allSessions->fetchAll();

// Get statistics for each service
$serviceStats = [];
foreach ($majorServices as $service) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as session_count,
               SUM(reg_count.registrations) as total_registrations
        FROM training_sessions ts
        LEFT JOIN (
            SELECT session_id, COUNT(*) as registrations
            FROM session_registrations
            GROUP BY session_id
        ) reg_count ON ts.session_id = reg_count.session_id
        WHERE ts.major_service = ?
    ");
    $stmt->execute([$service]);
    $serviceStats[$service] = $stmt->fetch();
}

// Get total statistics
$totalStats = $pdo->query("
    SELECT COUNT(*) as session_count,
           SUM(reg_count.registrations) as total_registrations
    FROM training_sessions ts
    LEFT JOIN (
        SELECT session_id, COUNT(*) as registrations
        FROM session_registrations
        GROUP BY session_id
    ) reg_count ON ts.session_id = reg_count.session_id
")->fetch();

// Get selected session details
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$session = null;
$registrations = [];
$totalRegistrations = 0;
$attendedCount = 0;
$paidCount = 0;
$pendingCount = 0;

if ($sessionId > 0) {
    // Get session details
    $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if ($session) {
        // Get registrations with payment info
        $stmt = $pdo->prepare("
            SELECT 
                sr.registration_id,
                sr.session_id,
                sr.major_service,
                sr.user_id,
                u.email,
                sr.name,
                sr.purpose,
                sr.emergency_contact,
                sr.medical_info,
                sr.payment_method,
                sr.registration_date,
                sr.attendance_status,
                p.payment_id,
                p.status as payment_status, 
                p.amount,
                p.payment_method as actual_payment_method,
                p.transaction_reference
            FROM session_registrations sr
            JOIN users u ON sr.user_id = u.user_id
            LEFT JOIN payments p ON sr.registration_id = p.registration_id
            WHERE sr.session_id = ?
            ORDER BY sr.registration_date DESC
        ");
        $stmt->execute([$sessionId]);
        $registrations = $stmt->fetchAll();

        // Calculate statistics
        $totalRegistrations = count($registrations);
        
        foreach ($registrations as $reg) {
            if ($reg['attendance_status'] === 'attended') {
                $attendedCount++;
            }
            if (isset($reg['payment_status']) && $reg['payment_status'] === 'completed') {
                $paidCount++;
            }
            if (isset($reg['payment_status']) && $reg['payment_status'] === 'pending') {
                $pendingCount++;
            }
        }

        // Apply filters
        if ($search || $statusFilter) {
            $filteredRegistrations = array_filter($registrations, function($reg) use ($search, $statusFilter) {
                $matchesSearch = !$search || 
                    stripos($reg['name'], $search) !== false || 
                    stripos($reg['email'], $search) !== false ||
                    stripos($reg['purpose'], $search) !== false;
                    
                $matchesStatus = !$statusFilter || 
                    ($statusFilter === 'attended' && $reg['attendance_status'] === 'attended') ||
                    ($statusFilter === 'registered' && $reg['attendance_status'] === 'registered') ||
                    ($statusFilter === 'paid' && isset($reg['payment_status']) && $reg['payment_status'] === 'completed') ||
                    ($statusFilter === 'pending' && isset($reg['payment_status']) && $reg['payment_status'] === 'pending');
                    
                return $matchesSearch && $matchesStatus;
            });
            $registrations = array_values($filteredRegistrations);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Registrations - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/view_registrations.css?v=<?php echo time(); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="registrations-container">
    <div class="page-header">
      <h1><i class="fas fa-user-friends"></i> Session Registrations</h1>
      <p>View and manage participant registrations across all training sessions</p>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
      <div class="alert success">
        <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
      <div class="alert error">
        <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error_message'] ?>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Service Filter Tabs -->
    <div class="service-tabs">
      <a href="?service=&session_id=<?= $sessionId ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= htmlspecialchars($search) ?>" 
         class="service-tab all-services <?= !$serviceFilter ? 'active' : '' ?>">
        <div class="service-name">All Services</div>
        <div class="service-count"><?= $totalStats['session_count'] ?> sessions</div>
      </a>
      <?php foreach ($majorServices as $service): ?>
        <a href="?service=<?= urlencode($service) ?>&session_id=<?= $sessionId ?>&status=<?= htmlspecialchars($statusFilter) ?>&search=<?= htmlspecialchars($search) ?>" 
           class="service-tab <?= $serviceFilter === $service ? 'active' : '' ?>">
          <div class="service-name"><?= htmlspecialchars($service) ?></div>
          <div class="service-count"><?= $serviceStats[$service]['session_count'] ?? 0 ?> sessions</div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Session Selection Card -->
    <div class="session-selector-card">
      <div class="card-header">
        <h2><i class="fas fa-calendar-alt"></i> Select Training Session</h2>
      </div>
      <div class="card-body">
        <?php if (empty($sessions)): ?>
          <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>No Sessions Available</h3>
            <p><?= $serviceFilter ? "No sessions found for " . htmlspecialchars($serviceFilter) : "There are no training sessions scheduled yet." ?></p>
            <a href="manage_sessions.php" class="btn-create">Create New Session</a>
          </div>
        <?php else: ?>
          <div class="sessions-grid">
            <?php foreach ($sessions as $s): 
              $sessionDate = strtotime($s['session_date']);
              $today = strtotime('today');
              $isUpcoming = $sessionDate >= $today;
              $isActive = $sessionId == $s['session_id'];
            ?>
              <div class="session-card <?= $isActive ? 'active' : '' ?>" 
                   onclick="selectSession(<?= $s['session_id'] ?>)">
                <div class="session-card-header">
                  <div class="session-title"><?= htmlspecialchars($s['title']) ?></div>
                  <div class="session-service"><?= htmlspecialchars($s['major_service']) ?></div>
                </div>
                <div class="session-card-body">
                  <div class="session-meta">
                    <div class="meta-item">
                      <i class="fas fa-calendar-day"></i>
                      <span><?= date('M j, Y', $sessionDate) ?></span>
                    </div>
                    <div class="meta-item">
                      <i class="fas fa-clock"></i>
                      <span><?= date('g:i A', strtotime($s['start_time'])) ?></span>
                    </div>
                    <div class="meta-item">
                      <i class="fas fa-map-marker-alt"></i>
                      <span><?= htmlspecialchars($s['venue']) ?></span>
                    </div>
                  </div>
                  <div class="session-stats">
                    <div class="stat-item">
                      <i class="fas fa-users"></i>
                      <span><?= $s['registrations_count'] ?> registered</span>
                    </div>
                    <div class="session-status <?= $isUpcoming ? 'upcoming' : 'past' ?>">
                      <?= $isUpcoming ? 'Upcoming' : 'Completed' ?>
                    </div>
                  </div>
                </div>
                <?php if ($isActive): ?>
                  <div class="active-indicator">
                    <i class="fas fa-check-circle"></i>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($sessionId > 0 && !$session): ?>
      <div class="alert error">
        <i class="fas fa-exclamation-circle"></i> The requested session was not found.
      </div>
    <?php elseif ($session): ?>
      
      <!-- Session Details Card -->
      <div class="session-details-card">
        <div class="card-header">
          <h2><i class="fas fa-info-circle"></i> Session Details</h2>
          <div class="session-actions">
            <a href="export_registrations.php?session_id=<?= $sessionId ?>&format=csv" class="btn-export btn-success">
              <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <a href="export_registrations.php?session_id=<?= $sessionId ?>&format=pdf" class="btn-export btn-danger">
              <i class="fas fa-file-pdf"></i> Export PDF
            </a>
          </div>
        </div>
        <div class="card-body">
          <div class="session-info">
            <div class="session-title">
              <h3><?= htmlspecialchars($session['title']) ?></h3>
              <span class="session-service"><?= htmlspecialchars($session['major_service']) ?></span>
            </div>
            <div class="session-meta">
              <div class="meta-item">
                <i class="fas fa-calendar-day"></i>
                <span><?= date('F j, Y', strtotime($session['session_date'])) ?></span>
              </div>
              <div class="meta-item">
                <i class="fas fa-clock"></i>
                <span><?= date('g:i A', strtotime($session['start_time'])) ?> - <?= date('g:i A', strtotime($session['end_time'])) ?></span>
              </div>
              <div class="meta-item">
                <i class="fas fa-map-marker-alt"></i>
                <span><?= htmlspecialchars($session['venue']) ?></span>
              </div>
              <?php if ($session['fee'] > 0): ?>
                <div class="meta-item fee">
                  <i class="fas fa-money-bill-wave"></i>
                  <span>₱<?= number_format($session['fee'], 2) ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Statistics Overview -->
          <div class="stats-overview">
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-users"></i>
              </div>
              <div class="stat-content">
                <div class="stat-number"><?= $totalRegistrations ?></div>
                <div class="stat-label">Total Registrations</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
                <i class="fas fa-user-check"></i>
              </div>
              <div class="stat-content">
                <div class="stat-number"><?= $attendedCount ?></div>
                <div class="stat-label">Attended</div>
              </div>
            </div>
            
            <?php if ($session['fee'] > 0): ?>
              <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
                  <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                  <div class="stat-number"><?= $paidCount ?></div>
                  <div class="stat-label">Paid</div>
                </div>
              </div>
              
              <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
                  <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                  <div class="stat-number"><?= $pendingCount ?></div>
                  <div class="stat-label">Pending Payment</div>
                </div>
              </div>
            <?php endif; ?>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6 0%, #6f42c1 100%);">
                <i class="fas fa-chair"></i>
              </div>
              <div class="stat-content">
                <div class="stat-number"><?= $session['capacity'] ?: '∞' ?></div>
                <div class="stat-label">Capacity</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Registrations Management -->
      <div class="registrations-table-wrapper">
        <div class="table-header">
          <div class="table-title-section">
            <h2><i class="fas fa-list"></i> Participant Registrations</h2>
            <span class="results-count"><?= count($registrations) ?> results</span>
          </div>
          
          <!-- Filter and Search Bar -->
          <div class="table-filters">
            <div class="search-box">
              <form method="GET" style="display: flex; width: 100%;">
                <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <input type="hidden" name="service" value="<?= htmlspecialchars($serviceFilter) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search participants..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit"><i class="fas fa-arrow-right"></i></button>
              </form>
            </div>
            
            <div class="status-filter">
              <button onclick="filterStatus('all')" class="<?= !$statusFilter ? 'active' : '' ?>">All</button>
              <button onclick="filterStatus('attended')" class="<?= $statusFilter === 'attended' ? 'active' : '' ?>">Attended</button>
              <button onclick="filterStatus('registered')" class="<?= $statusFilter === 'registered' ? 'active' : '' ?>">Registered</button>
              <?php if ($session['fee'] > 0): ?>
                <button onclick="filterStatus('paid')" class="<?= $statusFilter === 'paid' ? 'active' : '' ?>">Paid</button>
                <button onclick="filterStatus('pending')" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (empty($registrations)): ?>
          <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h3>No Registrations Found</h3>
            <p><?= $search || $statusFilter ? 'Try adjusting your filters' : 'No participants have registered for this session yet.' ?></p>
          </div>
        <?php else: ?>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Participant</th>
                  <th>Contact Info</th>
                  <th>Registration Details</th>
                  <th>Attendance</th>
                  <?php if ($session['fee'] > 0): ?>
                    <th>Payment</th>
                  <?php endif; ?>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($registrations as $reg): ?>
                  <tr>
                    <td>
                      <div class="participant-info">
                        <div class="participant-avatar">
                          <i class="fas fa-user-circle"></i>
                        </div>
                        <div class="participant-details">
                          <div class="participant-name"><?= htmlspecialchars($reg['name']) ?></div>
                          <div class="participant-id">ID: #<?= $reg['registration_id'] ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="contact-info">
                        <div class="email">
                          <i class="fas fa-envelope"></i>
                          <?= htmlspecialchars($reg['email']) ?>
                        </div>
                        <?php if ($reg['emergency_contact']): ?>
                          <div class="emergency">
                            <i class="fas fa-phone-alt"></i>
                            <?= htmlspecialchars($reg['emergency_contact']) ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="registration-details">
                        <div class="reg-date">
                          <i class="fas fa-calendar"></i>
                          <?= date('M j, Y', strtotime($reg['registration_date'])) ?>
                        </div>
                        <div class="reg-purpose">
                          <i class="fas fa-tag"></i>
                          <?= htmlspecialchars($reg['purpose']) ?>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="attendance-section">
                        <span class="status-badge <?= $reg['attendance_status'] === 'attended' ? 'attended' : 'registered' ?>">
                          <?= $reg['attendance_status'] === 'attended' ? 'Attended' : 'Registered' ?>
                        </span>
                        <form method="POST" class="quick-form">
                          <input type="hidden" name="update_attendance" value="1">
                          <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <?php if ($reg['attendance_status'] !== 'attended'): ?>
                            <input type="hidden" name="new_attendance" value="attended">
                            <button type="submit" class="btn-quick btn-success">
                              <i class="fas fa-check"></i> Mark Attended
                            </button>
                          <?php else: ?>
                            <input type="hidden" name="new_attendance" value="registered">
                            <button type="submit" class="btn-quick btn-warning">
                              <i class="fas fa-undo"></i> Revert
                            </button>
                          <?php endif; ?>
                        </form>
                      </div>
                    </td>
                    <?php if ($session['fee'] > 0): ?>
                      <td>
                        <div class="payment-section">
                          <?php if (isset($reg['payment_id'])): ?>
                            <span class="status-badge <?= $reg['payment_status'] === 'completed' ? 'paid' : 'pending' ?>">
                              <?= $reg['payment_status'] === 'completed' ? 'Paid' : 'Pending' ?>
                            </span>
                            <form method="POST" class="quick-form">
                              <input type="hidden" name="update_payment_status" value="1">
                              <input type="hidden" name="payment_id" value="<?= $reg['payment_id'] ?>">
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                              <?php if ($reg['payment_status'] !== 'completed'): ?>
                                <input type="hidden" name="new_status" value="completed">
                                <button type="submit" class="btn-quick btn-success">
                                  <i class="fas fa-check-circle"></i> Mark Paid
                                </button>
                              <?php else: ?>
                                <input type="hidden" name="new_status" value="pending">
                                <button type="submit" class="btn-quick btn-warning">
                                  <i class="fas fa-undo"></i> Revert
                                </button>
                              <?php endif; ?>
                            </form>
                          <?php else: ?>
                            <span class="status-badge no-payment">No Payment</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    <?php endif; ?>
                    <td class="actions">
                      <button class="btn-action btn-view" onclick="viewDetails(<?= htmlspecialchars(json_encode($reg)) ?>)">
                        <i class="fas fa-eye"></i> View
                      </button>
                      <form method="POST" class="inline-form" onsubmit="return confirmDelete();">
                        <input type="hidden" name="delete_registration" value="1">
                        <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <button type="submit" class="btn-action btn-delete">
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
      </div>
    <?php endif; ?>
  </div>

  <!-- Participant Details Modal -->
  <div class="modal" id="detailsModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title">Participant Details</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="modal-body">
        <div class="detail-section">
          <h3><i class="fas fa-user"></i> Personal Information</h3>
          <div class="detail-row">
            <label>Full Name:</label>
            <span id="detail-name"></span>
          </div>
          <div class="detail-row">
            <label>Email:</label>
            <span id="detail-email"></span>
          </div>
          <div class="detail-row">
            <label>User ID:</label>
            <span id="detail-user-id"></span>
          </div>
        </div>
        
        <div class="detail-section">
          <h3><i class="fas fa-info-circle"></i> Registration Information</h3>
          <div class="detail-row">
            <label>Purpose:</label>
            <span id="detail-purpose"></span>
          </div>
          <div class="detail-row">
            <label>Registration Date:</label>
            <span id="detail-reg-date"></span>
          </div>
          <div class="detail-row">
            <label>Major Service:</label>
            <span id="detail-service"></span>
          </div>
        </div>
        
        <div class="detail-section">
          <h3><i class="fas fa-phone"></i> Emergency Contact</h3>
          <div class="detail-row">
            <label>Contact:</label>
            <span id="detail-emergency"></span>
          </div>
        </div>
        
        <div class="detail-section">
          <h3><i class="fas fa-heartbeat"></i> Medical Information</h3>
          <div class="detail-row">
            <label>Medical Info:</label>
            <span id="detail-medical"></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    function selectSession(sessionId) {
      const urlParams = new URLSearchParams(window.location.search);
      urlParams.set('session_id', sessionId);
      window.location.search = urlParams.toString();
    }

    function viewDetails(reg) {
      document.getElementById('detail-name').textContent = reg.name;
      document.getElementById('detail-email').textContent = reg.email;
      document.getElementById('detail-user-id').textContent = reg.user_id;
      document.getElementById('detail-purpose').textContent = reg.purpose;
      document.getElementById('detail-reg-date').textContent = new Date(reg.registration_date).toLocaleDateString();
      document.getElementById('detail-service').textContent = reg.major_service;
      document.getElementById('detail-emergency').textContent = reg.emergency_contact || 'N/A';
      document.getElementById('detail-medical').textContent = reg.medical_info || 'N/A';
      document.getElementById('detailsModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('detailsModal').classList.remove('active');
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
    
    function confirmDelete() {
      return new Promise((resolve) => {
        Swal.fire({
          title: 'Are you sure?',
          text: "This will permanently delete the registration and all associated data!",
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#dc3545',
          cancelButtonColor: '#6c757d',
          confirmButtonText: 'Yes, delete it!',
          cancelButtonText: 'Cancel'
        }).then((result) => {
          resolve(result.isConfirmed);
        });
      });
    }
    
    // Close modal when clicking outside
    document.getElementById('detailsModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
    
    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        setTimeout(() => {
          alert.style.opacity = '0';
          setTimeout(() => alert.remove(), 300);
        }, 5000);
      });
    });
  </script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>