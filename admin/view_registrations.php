<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


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
    $paymentId = (int)$_POST['payment_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $stmt = $pdo->prepare("UPDATE payments SET status = ? WHERE payment_id = ?");
        $stmt->execute([$newStatus, $paymentId]);
        
        $_SESSION['success_message'] = "Payment status updated successfully!";
        header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating payment status: " . $e->getMessage();
        header("Location: view_registrations.php?session_id=" . $_GET['session_id']);
        exit;
    }
}


$defaultSession = $pdo->query("
    SELECT session_id 
    FROM training_sessions 
    ORDER BY session_date DESC 
    LIMIT 1
")->fetch();

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : ($defaultSession ? $defaultSession['session_id'] : 0);


$session = null;
$registrations = [];
$totalRegistrations = 0;
$attendedCount = 0;
$paidCount = 0;

if ($sessionId > 0) {
    
    $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if ($session) {
       
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

       
        $totalRegistrations = count($registrations);
        
        foreach ($registrations as $reg) {
            if ($reg['attendance_status'] === 'attended') {
                $attendedCount++;
            }
            if (isset($reg['payment_status']) && $reg['payment_status'] === 'completed') {
                $paidCount++;
            }
        }
    }
}


$allSessions = $pdo->query("
    SELECT session_id, title, session_date, major_service
    FROM training_sessions 
    ORDER BY session_date DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Registrations - PRC Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/view_registrations.css?v=<?php echo time(); ?>">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="registrations-container">
      <div class="page-header">
        <h1><i class="fas fa-user-friends"></i> Session Registrations</h1>
        <p>View and manage participant registrations</p>
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

      <div class="card session-selector">
        <div class="card-body">
          <form method="GET" class="session-select-form">
            <div class="form-group">
              <label for="session_id"><i class="fas fa-calendar-alt"></i> Session:</label>
              <select id="session_id" name="session_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($allSessions as $s): ?>
                  <option value="<?= $s['session_id'] ?>" <?= $sessionId == $s['session_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s['title']) ?> (<?= date('M j, Y', strtotime($s['session_date'])) ?> - <?= htmlspecialchars($s['major_service']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </div>
      </div>

      <?php if ($sessionId > 0 && !$session): ?>
        <div class="alert error">
          <i class="fas fa-exclamation-circle"></i> The requested session was not found.
        </div>
      <?php elseif (empty($allSessions)): ?>
        <div class="card">
          <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>No Sessions Available</h3>
            <p>There are no training sessions scheduled yet.</p>
          </div>
        </div>
      <?php else: ?>
        <?php if ($session): ?>
          <div class="card">
            <div class="card-header">
              <h2><i class="fas fa-info-circle"></i> Session Details</h2>
            </div>
            <div class="card-body">
              <div class="session-details">
                <h3><?= htmlspecialchars($session['title']) ?> - <?= htmlspecialchars($session['major_service']) ?></h3>
                <div class="session-meta">
                  <span class="meta-item">
                    <i class="fas fa-calendar-day"></i>
                    <?= isset($session['session_date']) ? date('F j, Y', strtotime($session['session_date'])) : 'Date not set' ?>
                  </span>
                  <span class="meta-item">
                    <i class="fas fa-clock"></i>
                    <?= isset($session['start_time']) ? date('g:i a', strtotime($session['start_time'])) : 'Time not set' ?> - 
                    <?= isset($session['end_time']) ? date('g:i a', strtotime($session['end_time'])) : 'Time not set' ?>
                  </span>
                  <span class="meta-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <?= isset($session['venue']) ? htmlspecialchars($session['venue']) : 'Venue not specified' ?>
                  </span>
                  <?php if (isset($session['fee']) && $session['fee'] > 0): ?>
                    <span class="meta-item fee">
                      <i class="fas fa-money-bill-wave"></i>
                      ₱<?= number_format($session['fee'], 2) ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="stats-cards">
                <div class="stat-card">
                  <div class="stat-icon blue">
                    <i class="fas fa-users"></i>
                  </div>
                  <div class="stat-content">
                    <h3>Total Registrations</h3>
                    <p><?= $totalRegistrations ?></p>
                  </div>
                </div>
                
                <div class="stat-card">
                  <div class="stat-icon green">
                    <i class="fas fa-user-check"></i>
                  </div>
                  <div class="stat-content">
                    <h3>Attended</h3>
                    <p><?= $attendedCount ?></p>
                  </div>
                </div>
                
                <?php if (isset($session['fee']) && $session['fee'] > 0): ?>
                <div class="stat-card">
                  <div class="stat-icon orange">
                    <i class="fas fa-money-bill-wave"></i>
                  </div>
                  <div class="stat-content">
                    <h3>Paid</h3>
                    <p><?= $paidCount ?></p>
                  </div>
                </div>
                <?php endif; ?>
                
                <div class="stat-card">
                  <div class="stat-icon purple">
                    <i class="fas fa-chair"></i>
                  </div>
                  <div class="stat-content">
                    <h3>Capacity</h3>
                    <p><?= isset($session['capacity']) ? htmlspecialchars($session['capacity']) : '∞' ?></p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-header">
            <div class="section-toolbar">
              <h2><i class="fas fa-users"></i> Participant List</h2>
              <?php if ($totalRegistrations > 0): ?>
                <div class="toolbar-actions">
                  <a href="export_registrations.php?session_id=<?= $sessionId ?>&format=csv" class="btn btn-success">
                    <i class="fas fa-file-csv"></i> Export CSV
                  </a>
                  <a href="export_registrations.php?session_id=<?= $sessionId ?>&format=pdf" class="btn btn-danger">
                    <i class="fas fa-file-pdf"></i> Export PDF
                  </a>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="card-body">
            <?php if (empty($registrations)): ?>
              <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No Registrations Yet</h3>
                <p>No participants have registered for this session yet.</p>
              </div>
            <?php else: ?>
              <div class="table-container">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Registration ID</th>
                      <th>Participant</th>
                      <th>Service</th>
                      <th>User ID</th>
                      <th>Email</th>
                      <th>Purpose</th>
                      <th>Emergency Contact</th>
                      <th>Medical Info</th>
                      <th>Payment Method</th>
                      <th>Registered On</th>
                      <th>Attendance</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($registrations as $reg): ?>
                      <tr>
                        <td><?= $reg['registration_id'] ?></td>
                        <td class="participant-cell">
                          <div class="participant-avatar">
                            <i class="fas fa-user-circle"></i>
                          </div>
                          <div class="participant-info">
                            <strong><?= htmlspecialchars($reg['name']) ?></strong>
                            <?php if (!empty($reg['emergency_contact'])): ?>
                              <span class="emergency-contact">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?= htmlspecialchars($reg['emergency_contact']) ?>
                              </span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td><?= htmlspecialchars($reg['major_service']) ?></td>
                        <td><?= $reg['user_id'] ?></td>
                        <td><?= htmlspecialchars($reg['email']) ?></td>
                        <td><?= htmlspecialchars($reg['purpose']) ?></td>
                        <td><?= htmlspecialchars($reg['emergency_contact']) ?></td>
                        <td><?= htmlspecialchars($reg['medical_info']) ?></td>
                        <td><?= htmlspecialchars($reg['payment_method']) ?></td>
                        <td>
                          <?= date('M j, Y', strtotime($reg['registration_date'])) ?>
                          <span class="text-muted">
                            <?= date('g:i a', strtotime($reg['registration_date'])) ?>
                          </span>
                        </td>
                        <td>
                          <?= ucfirst(str_replace('_', ' ', $reg['attendance_status'])) ?>
                        </td>
                        <td class="actions">
                          <button class="btn btn-sm btn-primary view-details" 
                                  data-name="<?= htmlspecialchars($reg['name']) ?>"
                                  data-email="<?= htmlspecialchars($reg['email']) ?>"
                                  data-purpose="<?= htmlspecialchars($reg['purpose']) ?>"
                                  data-emergency="<?= htmlspecialchars($reg['emergency_contact']) ?>"
                                  data-medical="<?= htmlspecialchars($reg['medical_info']) ?>">
                            <i class="fas fa-eye"></i> Details
                          </button>
                          <?php if (isset($session['fee']) && $session['fee'] > 0 && isset($reg['payment_id'])): ?>
                            <?php if ($reg['payment_status'] !== 'completed'): ?>
                              <form method="POST" class="inline-form">
                                <input type="hidden" name="update_payment_status" value="1">
                                <input type="hidden" name="payment_id" value="<?= $reg['payment_id'] ?>">
                                <input type="hidden" name="new_status" value="completed">
                                <button type="submit" class="btn btn-sm btn-success" title="Mark as Paid">
                                  <i class="fas fa-check-circle"></i> Complete
                                </button>
                              </form>
                            <?php else: ?>
                              <form method="POST" class="inline-form">
                                <input type="hidden" name="update_payment_status" value="1">
                                <input type="hidden" name="payment_id" value="<?= $reg['payment_id'] ?>">
                                <input type="hidden" name="new_status" value="pending">
                                <button type="submit" class="btn btn-sm btn-warning" title="Revert to Pending">
                                  <i class="fas fa-undo"></i> Revert
                                </button>
                              </form>
                            <?php endif; ?>
                          <?php endif; ?>
                          <form method="POST" class="inline-form" onsubmit="return confirmDelete(this);">
                            <input type="hidden" name="delete_registration" value="1">
                            <input type="hidden" name="registration_id" value="<?= $reg['registration_id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete Registration">
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
        </div>
      <?php endif; ?>
    </div>
  </div>

 
  <div id="detailsModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <h3>Participant Details</h3>
      <div class="modal-body">
        <div class="detail-row">
          <label>Name:</label>
          <span id="detail-name"></span>
        </div>
        <div class="detail-row">
          <label>Email:</label>
          <span id="detail-email"></span>
        </div>
        <div class="detail-row">
          <label>Purpose:</label>
          <span id="detail-purpose"></span>
        </div>
        <div class="detail-row">
          <label>Emergency Contact:</label>
          <span id="detail-emergency"></span>
        </div>
        <div class="detail-row">
          <label>Medical Information:</label>
          <span id="detail-medical"></span>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Modal functionality
    document.addEventListener('DOMContentLoaded', function() {
      const modal = document.getElementById('detailsModal');
      const closeBtn = document.querySelector('.close');
      const viewButtons = document.querySelectorAll('.view-details');
      
      viewButtons.forEach(button => {
        button.addEventListener('click', function() {
          document.getElementById('detail-name').textContent = this.getAttribute('data-name');
          document.getElementById('detail-email').textContent = this.getAttribute('data-email');
          document.getElementById('detail-purpose').textContent = this.getAttribute('data-purpose');
          document.getElementById('detail-emergency').textContent = this.getAttribute('data-emergency') || 'N/A';
          document.getElementById('detail-medical').textContent = this.getAttribute('data-medical') || 'N/A';
          modal.style.display = 'block';
        });
      });
      
      closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
      });
      
      window.addEventListener('click', function(event) {
        if (event.target === modal) {
          modal.style.display = 'none';
        }
      });
    });

   
    function confirmDelete(form) {
      Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
      }).then((result) => {
        if (result.isConfirmed) {
          form.submit();
        }
      });
      return false;
    }
  </script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>