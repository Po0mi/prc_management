<?php
// /user/schedule.php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
$user_role = get_user_role();
if ($user_role) {
    // If user has an admin role, redirect to admin dashboard
    header("Location: /admin/dashboard.php");
    exit;
}

$userEmail = $_SESSION['email'] ?? '';
$username = current_username();
$userId = current_user_id();
$pdo = $GLOBALS['pdo'];
$regMessage = '';

// Handle training registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_training'])) {
    $sessionId = (int)$_POST['session_id'];
    $registrationType = trim($_POST['registration_type']);
    
    // Get session details to check if it's a paid session
    $sessionQuery = $pdo->prepare("SELECT fee FROM training_sessions WHERE session_id = ?");
    $sessionQuery->execute([$sessionId]);
    $sessionData = $sessionQuery->fetch();
    $sessionFee = $sessionData ? floatval($sessionData['fee']) : 0;
    
    // Handle payment mode with default for free sessions
    $paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'free';
    
    // For free sessions, set payment method to 'free' if not provided
    if ($sessionFee <= 0) {
        $paymentMethod = 'free';
    }
    
    // Check if session exists and is still available
    $sessionCheck = $pdo->prepare("
        SELECT ts.*, 
               COALESCE(COUNT(sr.registration_id), 0) as current_registrations
        FROM training_sessions ts 
        LEFT JOIN session_registrations sr ON ts.session_id = sr.session_id
        WHERE ts.session_id = ? AND ts.session_date >= CURDATE()
        GROUP BY ts.session_id
    ");
    $sessionCheck->execute([$sessionId]);
    $session = $sessionCheck->fetch();
    
    if (!$session) {
        $regMessage = "This training session is no longer available.";
    } else {
        // Check if session is full
        $isFull = $session['capacity'] > 0 && $session['current_registrations'] >= $session['capacity'];
        
        if ($isFull) {
            $regMessage = "This training session is already full.";
        } else {
            // Check if already registered
            $check = $pdo->prepare("SELECT * FROM session_registrations WHERE session_id = ? AND user_id = ?");
            $check->execute([$sessionId, $userId]);
            
            if ($check->rowCount() === 0) {
                // Create user folder if it doesn't exist
                $userFolder = __DIR__ . "/../uploads/training_user_" . $userId;
                if (!file_exists($userFolder)) {
                    mkdir($userFolder, 0755, true);
                }

                $validIdPath = '';
                $documentsPath = '';
                $paymentReceiptPath = '';

                // Handle valid ID upload
                if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                    $fileType = $_FILES['valid_id']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['valid_id']['name'], PATHINFO_EXTENSION);
                        $fileName = 'valid_id_' . time() . '.' . $fileExtension;
                        $validIdPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $validIdPath)) {
                            $validIdPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                        } else {
                            $validIdPath = '';
                        }
                    } else {
                        $regMessage = "Invalid file type for Valid ID. Please upload JPG, PNG, or PDF files only.";
                    }
                }

                // Handle payment receipt upload
                if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                    $fileType = $_FILES['payment_receipt']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION);
                        $fileName = 'payment_receipt_' . time() . '.' . $fileExtension;
                        $paymentReceiptPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $paymentReceiptPath)) {
                            $paymentReceiptPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                        } else {
                            $paymentReceiptPath = '';
                        }
                    } else {
                        $regMessage = "Invalid file type for payment receipt.";
                    }
                }

                // Handle requirements upload
                if (isset($_FILES['requirements']) && $_FILES['requirements']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    $fileType = $_FILES['requirements']['type'];
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileExtension = pathinfo($_FILES['requirements']['name'], PATHINFO_EXTENSION);
                        $fileName = 'requirements_' . time() . '.' . $fileExtension;
                        $documentsPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['requirements']['tmp_name'], $documentsPath)) {
                            $documentsPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                        } else {
                            $documentsPath = '';
                        }
                    } else {
                        $regMessage = "Invalid file type for requirements.";
                    }
                }

                // Only proceed if no file upload errors
                if (empty($regMessage)) {
                    // Validate required valid ID upload
                    if (empty($validIdPath)) {
                        $regMessage = "Valid ID upload is required.";
                    } else {
                        try {
                            // Get payment information
                            $paymentAmount = floatval($_POST['payment_amount'] ?? 0);
                            $paymentReference = trim($_POST['payment_reference'] ?? '');
                            $paymentAccountNumber = trim($_POST['payment_account_number'] ?? '');
                            $paymentAccountName = trim($_POST['payment_account_name'] ?? '');

                            // Insert registration based on type
                            if ($registrationType === 'individual') {
                                $fullName = trim($_POST['full_name']);
                                $location = trim($_POST['location']);
                                $age = (int)$_POST['age'];
                                $rcyStatus = trim($_POST['rcy_status']);
                                $trainingType = trim($_POST['training_type']);
                                $trainingDate = $_POST['training_date'];

                                // Validate required fields
                                if (empty($fullName) || empty($location) || empty($age) || empty($rcyStatus)) {
                                    $regMessage = "Please fill in all required fields.";
                                } else {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO session_registrations (
                                            session_id, user_id, email, registration_type, full_name, location, age, 
                                            rcy_status, training_type, training_date, valid_id_path, requirements_path, 
                                            payment_method, payment_amount, payment_reference, payment_account_number, 
                                            payment_account_name, payment_receipt_path, registration_date, status, payment_status
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?)
                                    ");
                                    $paymentStatus = ($paymentAmount > 0) ? 'pending' : 'not_required';
                                    $stmt->execute([
                                        $sessionId, $userId, $userEmail, $registrationType, $fullName, $location, $age,
                                        $rcyStatus, $trainingType, $trainingDate, $validIdPath, $documentsPath,
                                        $paymentMethod, $paymentAmount, $paymentReference, $paymentAccountNumber,
                                        $paymentAccountName, $paymentReceiptPath, $paymentStatus
                                    ]);
                                    
                                    if ($sessionFee <= 0) {
                                        $regMessage = "You have successfully registered for this free training session. Your documents have been uploaded. Awaiting confirmation.";
                                    } else {
                                        $regMessage = "You have successfully registered for the training session. Your documents and payment information have been uploaded. Awaiting confirmation.";
                                    }
                                }
                            } else { // organization
                                $organizationName = trim($_POST['organization_name']);
                                $trainingType = trim($_POST['training_type']);
                                $trainingDate = $_POST['training_date'];
                                $paxCount = (int)$_POST['pax_count'];

                                // Validate required fields
                                if (empty($organizationName) || empty($paxCount) || $paxCount < 1) {
                                    $regMessage = "Please fill in all required fields.";
                                } else {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO session_registrations (
                                            session_id, user_id, email, registration_type, organization_name, 
                                            training_type, training_date, pax_count, valid_id_path, requirements_path, 
                                            payment_method, payment_amount, payment_reference, payment_account_number, 
                                            payment_account_name, payment_receipt_path, registration_date, status, payment_status
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?)
                                    ");
                                    $paymentStatus = ($paymentAmount > 0) ? 'pending' : 'not_required';
                                    $stmt->execute([
                                        $sessionId, $userId, $userEmail, $registrationType, $organizationName,
                                        $trainingType, $trainingDate, $paxCount, $validIdPath, $documentsPath,
                                        $paymentMethod, $paymentAmount, $paymentReference, $paymentAccountNumber,
                                        $paymentAccountName, $paymentReceiptPath, $paymentStatus
                                    ]);
                                    
                                    if ($sessionFee <= 0) {
                                        $regMessage = "You have successfully registered for this free training session. Your documents have been uploaded. Awaiting confirmation.";
                                    } else {
                                        $regMessage = "You have successfully registered for the training session. Your documents and payment information have been uploaded. Awaiting confirmation.";
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            error_log("Registration error: " . $e->getMessage());
                            $regMessage = "An error occurred during registration. Please try again.";
                        }
                    }
                }
            } else {
                $regMessage = "You are already registered for this training session.";
            }
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$serviceFilter = isset($_GET['service']) ? trim($_GET['service']) : '';

// Define the major services in the same order as admin panel
$majorServices = [
    'Health Service',
    'Safety Service',
    'Welfare Service',
    'Disaster Management Service',
    'Red Cross Youth'
];

// Define color mappings for training types
$trainingColors = [
    'Health Service' => 'health',
    'Safety Service' => 'safety',
    'Welfare Service' => 'welfare',
    'Disaster Management Service' => 'disaster',
    'Red Cross Youth' => 'rcy',
    'General' => 'general'
];

// Build query with filters - NO CREATED_BY RESTRICTIONS
$whereConditions = [];
$params = [];

// Only show upcoming sessions
$whereConditions[] = "ts.session_date >= CURDATE()";

if ($search) {
    $whereConditions[] = "(ts.title LIKE :search OR ts.venue LIKE :search OR ts.major_service LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($serviceFilter && $serviceFilter !== 'all') {
    $whereConditions[] = "ts.major_service = :service";
    $params[':service'] = $serviceFilter;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

try {
    // Main query to get all training sessions - NO CREATED_BY RESTRICTIONS
    $stmt = $pdo->prepare("
        SELECT ts.*, 
               COALESCE((
                   SELECT 1 FROM session_registrations 
                   WHERE session_id = ts.session_id 
                   AND user_id = ?
               ), 0) AS is_registered,
               COALESCE((
                   SELECT COUNT(*) FROM session_registrations 
                   WHERE session_id = ts.session_id
               ), 0) AS registered_count
        FROM training_sessions ts
        $whereClause
        ORDER BY ts.major_service, ts.session_date, ts.start_time
    ");

    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $allSessions = $stmt->fetchAll();

    // Group sessions by service
    $sessionsByService = [];
    foreach ($allSessions as $session) {
        $service = $session['major_service'];
        if (!isset($sessionsByService[$service])) {
            $sessionsByService[$service] = [];
        }
        $sessionsByService[$service][] = $session;
    }

    // Get statistics - ALL sessions, no creator restrictions
    $upcoming = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()")->fetchColumn();
    $past = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date < CURDATE()")->fetchColumn();
    $total_sessions = $upcoming + $past;

    // Get user's registration count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE user_id = ?");
    $stmt->execute([$userId]);
    $registered = $stmt->fetchColumn();

    // Get user's registrations with session details
    $userRegistrations = $pdo->prepare("
        SELECT sr.*, ts.title, ts.session_date, ts.start_time, ts.end_time, ts.venue, ts.major_service
        FROM session_registrations sr
        JOIN training_sessions ts ON sr.session_id = ts.session_id
        WHERE sr.user_id = ?
        ORDER BY ts.session_date DESC
    ");
    $userRegistrations->execute([$userId]);
    $myRegistrations = $userRegistrations->fetchAll();

    // Get statistics for my registrations
    $pending_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'pending'; }));
    $approved_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'approved'; }));

    // Get ALL training sessions for calendar (next 3 months) - NO CREATED_BY FILTER
    $calendarTrainings = $pdo->query("
        SELECT session_id, title, session_date, venue, major_service, start_time, end_time, fee
        FROM training_sessions 
        WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        ORDER BY session_date ASC, start_time ASC
    ")->fetchAll();

    // Get training summary statistics for calendar - NO CREATED_BY FILTER
    $trainingStats = [
        'total_upcoming' => $upcoming,
        'this_week' => $pdo->query("
            SELECT COUNT(*) FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ")->fetchColumn(),
        'this_month' => $pdo->query("
            SELECT COUNT(*) FROM training_sessions 
            WHERE session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ")->fetchColumn(),
        'my_upcoming' => 0 // Will calculate below
    ];
    
    // Calculate my upcoming registrations
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM session_registrations sr
        JOIN training_sessions ts ON sr.session_id = ts.session_id
        WHERE sr.user_id = ? AND ts.session_date >= CURDATE()
    ");
    $stmt->execute([$userId]);
    $trainingStats['my_upcoming'] = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    // Initialize all variables with empty/zero values on error
    $allSessions = [];
    $sessionsByService = [];
    $upcoming = 0;
    $past = 0;
    $total_sessions = 0;
    $registered = 0;
    $myRegistrations = [];
    $pending_registrations = 0;
    $approved_registrations = 0;
    $calendarTrainings = [];
    $trainingStats = [
        'total_upcoming' => 0,
        'this_week' => 0,
        'this_month' => 0,
        'my_upcoming' => 0
    ];
}

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
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/schedule.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/registration.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="header-content">
    <?php include 'header.php'; ?>
    
    <div class="admin-content">
      <div class="events-layout">
        <!-- Main Content Area -->
        <div class="main-content">
          <div class="page-header">
            <h1><i class="fas fa-calendar-alt"></i> Training Schedule</h1>
            <p>Welcome, <?= htmlspecialchars($username) ?> - View and register for upcoming training sessions</p>
          </div>

          <?php if ($regMessage): ?>
            <div class="alert <?= strpos($regMessage, 'successfully') !== false ? 'success' : 'error' ?>">
              <i class="fas <?= strpos($regMessage, 'successfully') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
              <?= htmlspecialchars($regMessage) ?>
            </div>
          <?php endif; ?>

          <!-- Action Bar -->
          <div class="action-bar">
            <div class="action-bar-left">
              <form method="GET" class="search-box">
                <input type="hidden" name="service" value="<?= htmlspecialchars($serviceFilter) ?>">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search training sessions..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit"><i class="fas fa-arrow-right"></i></button>
              </form>
              
              <div class="status-filter">
                <button onclick="filterService('all')" class="<?= !$serviceFilter || $serviceFilter === 'all' ? 'active' : '' ?>">All Services</button>
                <?php foreach ($majorServices as $service): ?>
                  <button onclick="filterService('<?= urlencode($service) ?>')" class="<?= $serviceFilter === $service ? 'active' : '' ?>">
                    <?= htmlspecialchars($service) ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Statistics Overview -->
          <div class="stats-overview">
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <i class="fas fa-calendar"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $total_sessions ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">Total Sessions</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
                <i class="fas fa-calendar-check"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $upcoming ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">Upcoming</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
                <i class="fas fa-user-check"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $registered ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">My Registrations</div>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon" style="background: linear-gradient(135deg, #4caf50 0%, #8bc34a 100%);">
                <i class="fas fa-check-circle"></i>
              </div>
              <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $approved_registrations ?></div>
                <div style="color: var(--gray); font-size: 0.9rem;">Approved</div>
              </div>
            </div>
          </div>

          <!-- Available Training Sessions -->
          <div class="events-table-wrapper">
            <div class="table-header">
              <h2 class="table-title">Available Training Sessions</h2>
            </div>

            <?php if (empty($allSessions)): ?>
              <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No training sessions found</h3>
                <p><?= $search ? 'Try adjusting your search criteria' : 'There are currently no training sessions available.' ?></p>
              </div>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Training Details</th>
                    <th>Date & Time</th>
                    <th>Venue</th>
                    <th>Service</th>
                    <th>Capacity</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allSessions as $s): 
                    $sessionDate = strtotime($s['session_date']);
                    $isFull = $s['capacity'] > 0 && $s['registered_count'] >= $s['capacity'];
                    $isRegistered = $s['is_registered'];
                  ?>
                    <tr>
                      <td>
                        <div class="event-title"><?= htmlspecialchars($s['title']) ?></div>
                        <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                          Session ID: #<?= $s['session_id'] ?>
                        </div>
                      </td>
                      <td>
                        <div class="event-date">
                          <span><?= date('M d, Y', $sessionDate) ?></span><br>
                          <small><?= date('g:i A', strtotime($s['start_time'])) ?> - <?= date('g:i A', strtotime($s['end_time'])) ?></small>
                        </div>
                      </td>
                      <td><?= htmlspecialchars($s['venue']) ?></td>
                      <td>
                        <span class="service-badge <?= strtolower(str_replace(' ', '-', $s['major_service'])) ?>">
                          <?= htmlspecialchars($s['major_service']) ?>
                        </span>
                      </td>
                      <td>
                        <div class="registrations-badge <?= $isFull ? 'full' : '' ?>">
                          <i class="fas fa-users"></i>
                          <?= $s['registered_count'] ?> / <?= $s['capacity'] ?: '∞' ?>
                          <?php if ($isFull): ?>
                            <span style="font-size: 0.7rem; background: var(--prc-red); color: white; padding: 0.2rem 0.4rem; border-radius: 4px;">FULL</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <div class="fee">
                          <?= $s['fee'] > 0 ? '₱' . number_format($s['fee'], 2) : 'Free' ?>
                        </div>
                      </td>
                      <td>
                        <?php if ($isRegistered): ?>
                          <span class="status-badge registered">
                            <i class="fas fa-check-circle"></i> Registered
                          </span>
                        <?php else: ?>
                          <span class="status-badge available">
                            <i class="fas fa-clock"></i> Available
                          </span>
                        <?php endif; ?>
                      </td>
                      <td class="actions">
                        <?php if (!$isRegistered && !$isFull): ?>
                          <button class="btn-action btn-register" onclick="openRegisterModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                            <i class="fas fa-user-plus"></i> Register
                          </button>
                        <?php elseif ($isRegistered): ?>
                          <button class="btn-action btn-registered" disabled>
                            <i class="fas fa-check"></i> Registered
                          </button>
                        <?php else: ?>
                          <button class="btn-action btn-full" disabled>
                            <i class="fas fa-times"></i> Full
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <!-- My Registrations Section -->
          <div class="registrations-section">
            <div class="section-header">
              <h2><i class="fas fa-list-check"></i> My Training Registrations</h2>
            </div>
            
            <?php if (empty($myRegistrations)): ?>
              <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>No registrations found</h3>
                <p>You haven't registered for any training sessions yet.</p>
              </div>
            <?php else: ?>
              <div class="table-container">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Training</th>
                      <th>Service</th>
                      <th>Date & Time</th>
                      <th>Venue</th>
                      <th>Type</th>
                      <th>Payment</th>
                      <th>Documents</th>
                      <th>Registered On</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($myRegistrations as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['title']) ?></td>
                      <td><?= htmlspecialchars($r['major_service']) ?></td>
                      <td>
                        <div class="event-date">
                          <span><?= date('M d, Y', strtotime($r['session_date'])) ?></span><br>
                          <small><?= date('g:i A', strtotime($r['start_time'])) ?> - <?= date('g:i A', strtotime($r['end_time'])) ?></small>
                        </div>
                      </td>
                      <td><?= htmlspecialchars($r['venue']) ?></td>
                      <td>
                        <span class="type-badge <?= $r['registration_type'] ?>">
                          <?= ucfirst($r['registration_type']) ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($r['payment_amount'] > 0): ?>
                          <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <span class="fee-amount" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">₱<?= number_format($r['payment_amount'], 2) ?></span>
                            <span class="payment-status-indicator <?= $r['payment_status'] ?? 'pending' ?>">
                              <?= ucfirst($r['payment_status'] ?? 'pending') ?>
                            </span>
                          </div>
                        <?php else: ?>
                          <span class="fee-free" style="font-size: 0.8rem; padding: 0.2rem 0.5rem;">FREE</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="document-links">
                          <?php if ($r['valid_id_path']): ?>
                            <a href="../<?= htmlspecialchars($r['valid_id_path']) ?>" target="_blank" class="doc-link">
                              <i class="fas fa-id-card"></i> Valid ID
                            </a>
                          <?php endif; ?>
                          <?php if ($r['requirements_path']): ?>
                            <a href="../<?= htmlspecialchars($r['requirements_path']) ?>" target="_blank" class="doc-link">
                              <i class="fas fa-file-alt"></i> Requirements
                            </a>
                          <?php endif; ?>
                          <?php if ($r['payment_receipt_path']): ?>
                            <a href="../<?= htmlspecialchars($r['payment_receipt_path']) ?>" target="_blank" class="doc-link">
                              <i class="fas fa-receipt"></i> Receipt
                            </a>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td><?= date('M d, Y', strtotime($r['registration_date'])) ?></td>
                      <td>
                        <span class="status-badge <?= $r['status'] ?>">
                          <i class="fas <?= 
                            $r['status'] === 'pending' ? 'fa-clock' : 
                            ($r['status'] === 'approved' ? 'fa-check-circle' : 
                            ($r['status'] === 'rejected' ? 'fa-times-circle' : 'fa-info-circle')) ?>"></i>
                          <?= ucfirst($r['status']) ?>
                        </span>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Training Calendar Sidebar -->
        <div class="calendar-sidebar">
          <div class="calendar-header">
            <h2><i class="fas fa-calendar-alt"></i> Training Calendar</h2>
          </div>

          <!-- Training Type Legend -->
          <div class="training-type-legend">
            <div class="legend-title">
              <i class="fas fa-palette"></i> Training Services
            </div>
            <div class="legend-items">
              <div class="legend-item">
                <div class="legend-color health"></div>
                <div class="legend-text">Health Service</div>
              </div>
              <div class="legend-item">
                <div class="legend-color safety"></div>
                <div class="legend-text">Safety Service</div>
              </div>
              <div class="legend-item">
                <div class="legend-color welfare"></div>
                <div class="legend-text">Welfare Service</div>
              </div>
              <div class="legend-item">
                <div class="legend-color disaster"></div>
                <div class="legend-text">Disaster Management</div>
              </div>
              <div class="legend-item">
                <div class="legend-color rcy"></div>
                <div class="legend-text">Red Cross Youth</div>
              </div>
              <div class="legend-item">
                <div class="legend-color general"></div>
                <div class="legend-text">General Training</div>
              </div>
            </div>
          </div>

          <!-- Training Summary -->
          <div class="training-summary">
            <div class="summary-title">
              <i class="fas fa-chart-bar"></i> Training Overview
            </div>
            <div class="summary-stats">
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $trainingStats['total_upcoming'] ?></div>
                <div class="summary-stat-label">Total Upcoming</div>
              </div>
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $trainingStats['this_week'] ?></div>
                <div class="summary-stat-label">This Week</div>
              </div>
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $trainingStats['this_month'] ?></div>
                <div class="summary-stat-label">This Month</div>
              </div>
              <div class="summary-stat">
                <div class="summary-stat-number"><?= $registered ?></div>
                <div class="summary-stat-label">My Registrations</div>
              </div>
            </div>
          </div>

          <!-- Calendar Container -->
          <div class="calendar-container" id="trainingCalendarContainer">
            <!-- Calendar will be generated by JavaScript -->
          </div>
        </div>
      </div>
    </div>

    <!-- Registration Modal -->
    <div class="modal" id="registerModal">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title" id="modalTitle">Register for Training</h2>
          <button class="close-modal" onclick="closeRegisterModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <form method="POST" id="registerForm" enctype="multipart/form-data">
          <input type="hidden" name="register_training" value="1">
          <input type="hidden" name="session_id" id="sessionId">
          <input type="hidden" name="payment_amount" id="hiddenPaymentAmount" value="0">
          
          <div class="event-info" id="trainingInfo">
            <!-- Training details will be populated by JavaScript -->
          </div>
          
          <!-- Registration Type Tabs -->
          <div class="tab-container">
            <div class="tab-buttons">
              <button type="button" class="tab-btn active" onclick="switchTab('individual')">
                <i class="fas fa-user"></i> Individual
              </button>
              <button type="button" class="tab-btn" onclick="switchTab('organization')">
                <i class="fas fa-building"></i> Organization/Company
              </button>
            </div>
            
            <!-- Individual Tab -->
            <div class="tab-content active" id="individual-tab">
              <input type="hidden" name="registration_type" value="individual" id="registration_type_individual">
              
              <div class="form-row">
                <div class="form-group">
                  <label for="full_name">Full Name *</label>
                  <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name">
                </div>
                
                <div class="form-group">
                  <label for="location">Location *</label>
                  <input type="text" id="location" name="location" required placeholder="Enter your location">
                </div>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="age">Age *</label>
                  <input type="number" id="age" name="age" required min="1" max="120" placeholder="Enter your age">
                </div>
                
                <div class="form-group">
                  <label for="rcy_status">RCY Status *</label>
                  <select id="rcy_status" name="rcy_status" required>
                    <option value="">Select status</option>
                    <option value="Non-RCY">Non-RCY</option>
                    <option value="RCY Member">RCY Member</option>
                    <option value="RCY Volunteer">RCY Volunteer</option>
                    <option value="RCY Officer">RCY Officer</option>
                  </select>
                </div>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="training_type_individual">Training Type *</label>
                  <input type="text" id="training_type_individual" name="training_type" required readonly>
                </div>
                
                <div class="form-group">
                  <label for="training_date_individual">Training Date *</label>
                  <input type="date" id="training_date_individual" name="training_date" required readonly>
                </div>
              </div>
            </div>
            
            <!-- Organization Tab -->
            <div class="tab-content" id="organization-tab">
              <input type="hidden" name="registration_type" value="organization" id="registration_type_organization">
              
              <div class="form-group">
                <label for="organization_name">Organization/Company Name *</label>
                <input type="text" id="organization_name" name="organization_name" placeholder="Enter organization/company name">
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="training_type_org">Training Type *</label>
                  <input type="text" id="training_type_org" name="training_type" readonly>
                </div>
                
                <div class="form-group">
                  <label for="training_date_org">Training Date *</label>
                  <input type="date" id="training_date_org" name="training_date" readonly>
                </div>
              </div>
              
              <div class="form-group">
                <label for="pax_count">Number of Participants *</label>
                <input type="number" id="pax_count" name="pax_count" min="1" placeholder="Enter number of participants">
              </div>
            </div>
          </div>

          <!-- Payment Section -->
          <div class="payment-section" id="paymentSection" style="display: none;">
            <div class="payment-header">
              <i class="fas fa-credit-card"></i>
              <h3>Payment Information</h3>
            </div>

            <!-- Fee Summary -->
            <div class="fee-summary">
              <h4><i class="fas fa-calculator"></i> Fee Summary</h4>
              <div class="fee-breakdown">
                <div class="fee-item">
                  <span class="fee-label">Training Fee:</span>
                  <span class="fee-amount" id="trainingFeeAmount">₱0.00</span>
                </div>
                <div class="fee-item">
                  <span class="fee-label">Total Amount:</span>
                  <span class="fee-amount total" id="totalAmountDisplay">₱0.00</span>
                </div>
              </div>
            </div>

            <!-- Payment Methods -->
            <div class="payment-methods">
              <h4><i class="fas fa-money-check-alt"></i> Payment Method</h4>
              <div class="payment-options">
                <div class="payment-option">
                  <input type="radio" name="payment_method" value="bank_transfer" id="bank_transfer">
                  <div class="payment-card">
                    <div class="payment-icon bank">
                      <i class="fas fa-university"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">Bank Transfer</div>
                      <div class="payment-description">Transfer to PRC official bank account</div>
                    </div>
                    <div class="payment-status"></div>
                  </div>
                </div>

                <div class="payment-option">
                  <input type="radio" name="payment_method" value="gcash" id="gcash">
                  <div class="payment-card">
                    <div class="payment-icon gcash">
                      <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">GCash</div>
                      <div class="payment-description">Send money via GCash</div>
                    </div>
                    <div class="payment-status"></div>
                  </div>
                </div>

                <div class="payment-option">
                  <input type="radio" name="payment_method" value="paymaya" id="paymaya">
                  <div class="payment-card">
                    <div class="payment-icon paymaya">
                      <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">PayMaya</div>
                      <div class="payment-description">Send money via PayMaya</div>
                    </div>
                    <div class="payment-status"></div>
                  </div>
                </div>

                <div class="payment-option">
                  <input type="radio" name="payment_method" value="cash" id="cash">
                  <div class="payment-card">
                    <div class="payment-icon cash">
                      <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="payment-details">
                      <div class="payment-name">Cash Payment</div>
                      <div class="payment-description">Pay at PRC office</div>
                    </div>
                    <div class="payment-status"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Payment Details Forms -->
            <div class="payment-details-container">
              <!-- Bank Transfer Form -->
              <div class="payment-form" id="bank_transfer_form">
                <h5><i class="fas fa-university"></i> Bank Transfer Details</h5>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>Bank Name</label>
                      <span>BDO Unibank</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Number</label>
                      <span>1234-5678-9012</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Name</label>
                      <span>Philippine Red Cross - Tacloban Chapter</span>
                    </div>
                    <div class="bank-field">
                      <label>Branch</label>
                      <span>Tacloban City Main Branch</span>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  <label for="bank_reference">Reference Number *</label>
                  <input type="text" id="bank_reference" name="payment_reference" placeholder="Enter transfer reference number">
                </div>
                <div class="payment-instructions">
                  <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                  <ol>
                    <li>Transfer the exact amount to the bank account above</li>
                    <li>Keep your bank receipt/confirmation</li>
                    <li>Enter the reference number from your receipt</li>
                    <li>Upload a clear photo of your receipt below</li>
                  </ol>
                </div>
              </div>

              <!-- GCash Form -->
              <div class="payment-form" id="gcash_form">
                <h5><i class="fas fa-mobile-alt"></i> GCash Payment Details</h5>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>GCash Number</label>
                      <span>+63 917 123 4567</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Name</label>
                      <span>Philippine Red Cross Tacloban</span>
                    </div>
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label for="gcash_reference">Reference Number *</label>
                    <input type="text" id="gcash_reference" name="payment_reference" placeholder="Enter GCash reference number">
                  </div>
                  <div class="form-group">
                    <label for="gcash_sender">Your GCash Number *</label>
                    <div class="phone-input-group">
                      <span class="phone-prefix">+63</span>
                      <input type="tel" id="gcash_sender" name="payment_account_number" placeholder="9XX XXX XXXX">
                    </div>
                  </div>
                </div>
                <div class="payment-instructions">
                  <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                  <ol>
                    <li>Open your GCash app and select "Send Money"</li>
                    <li>Send the exact amount to the number above</li>
                    <li>Take a screenshot of the successful transaction</li>
                    <li>Enter the reference number and your GCash number</li>
                    <li>Upload the screenshot below</li>
                  </ol>
                </div>
              </div>

              <!-- PayMaya Form -->
              <div class="payment-form" id="paymaya_form">
                <h5><i class="fas fa-mobile-alt"></i> PayMaya Payment Details</h5>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>PayMaya Number</label>
                      <span>+63 917 123 4567</span>
                    </div>
                    <div class="bank-field">
                      <label>Account Name</label>
                      <span>Philippine Red Cross Tacloban</span>
                    </div>
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label for="paymaya_reference">Reference Number *</label>
                    <input type="text" id="paymaya_reference" name="payment_reference" placeholder="Enter PayMaya reference number">
                  </div>
                  <div class="form-group">
                    <label for="paymaya_sender">Your PayMaya Number *</label>
                    <div class="phone-input-group">
                      <span class="phone-prefix">+63</span>
                      <input type="tel" id="paymaya_sender" name="payment_account_number" placeholder="9XX XXX XXXX">
                    </div>
                  </div>
                </div>
                <div class="payment-instructions">
                  <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                  <ol>
                    <li>Open your PayMaya app and select "Send Money"</li>
                    <li>Send the exact amount to the number above</li>
                    <li>Take a screenshot of the successful transaction</li>
                    <li>Enter the reference number and your PayMaya number</li>
                    <li>Upload the screenshot below</li>
                  </ol>
                </div>
              </div>

              <!-- Cash Payment Form -->
              <div class="payment-form" id="cash_form">
                <h5><i class="fas fa-money-bill-wave"></i> Cash Payment Details</h5>
                <div class="payment-note">
                  <i class="fas fa-info-circle"></i>
                  <div class="payment-note-content">
                    <strong>Important Note:</strong>
                    <p>You have selected cash payment. Please visit our office during business hours to complete your payment. Your registration will be marked as pending until payment is received.</p>
                  </div>
                </div>
                <div class="bank-details">
                  <div class="bank-info">
                    <div class="bank-field">
                      <label>Office Address</label>
                      <span>Philippine Red Cross Tacloban Chapter<br>123 Remedios Street, Tacloban City</span>
                    </div>
                    <div class="bank-field">
                      <label>Business Hours</label>
                      <span>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</span>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  <label for="cash_name">Contact Person Name *</label>
                  <input type="text" id="cash_name" name="payment_account_name" placeholder="Who should we expect for payment?">
                </div>
              </div>

              <!-- Payment Receipt Upload -->
              <div class="receipt-upload" id="receiptUpload" style="display: none;">
                <input type="file" name="payment_receipt" id="payment_receipt" accept=".jpg,.jpeg,.png,.pdf">
                <div class="receipt-upload-content">
                  <i class="fas fa-receipt"></i>
                  <div class="upload-text">Upload Payment Receipt</div>
                  <div class="upload-note">Upload a clear photo or scan of your payment receipt/screenshot</div>
                  <div class="upload-note">Accepted formats: JPG, PNG, PDF (Max 5MB)</div>
                </div>
              </div>
            </div>

            <!-- Payment Summary -->
            <div class="payment-summary" id="paymentSummary" style="display: none;">
              <h4><i class="fas fa-file-invoice-dollar"></i> Payment Summary</h4>
              <div class="summary-item">
                <span class="summary-label">Payment Method:</span>
                <span class="summary-value" id="selectedPaymentMethod">-</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Training Fee:</span>
                <span class="summary-value" id="summaryTrainingFee">₱0.00</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Total Amount:</span>
                <span class="summary-value total" id="summaryTotalAmount">₱0.00</span>
              </div>
            </div>
          </div>
          
          <!-- Common Fields -->
          <div class="form-row">
            <div class="form-group">
              <label for="valid_id">Valid ID *</label>
              <div class="file-upload-container">
                <input type="file" id="valid_id" name="valid_id" required accept=".jpg,.jpeg,.png,.pdf">
                <div class="file-upload-info">
                  <i class="fas fa-id-card"></i>
                  <span>Upload a clear photo of your valid ID</span>
                  <small>Accepted formats: JPG, PNG, PDF (Max 5MB)</small>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <label for="requirements">Requirements & Documents</label>
              <div class="file-upload-container">
                <input type="file" id="requirements" name="requirements" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                <div class="file-upload-info">
                  <i class="fas fa-file-upload"></i>
                  <span>Upload requirements/supporting documents</span>
                  <small>Accepted formats: JPG, PNG, PDF, DOC, DOCX (Max 10MB)</small>
                </div>
              </div>
            </div>
          </div>
          
          <div class="form-notice">
            <i class="fas fa-info-circle"></i>
            <p>By registering, you agree to provide accurate information. Your documents will be securely stored and used only for training registration purposes.</p>
          </div>
          
          <button type="submit" class="btn-submit">
            <i class="fas fa-user-plus"></i> Register for Training
          </button>
        </form>
      </div>
    </div>
  </div>

  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
<script>
// Training calendar data
const calendarTrainings = <?= json_encode($calendarTrainings) ?>;
const trainingColors = <?= json_encode($trainingColors) ?>;

let currentSession = null;

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
  generateTrainingCalendar();
  
  // Initialize payment method listeners
  const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
  paymentMethods.forEach(method => {
    method.addEventListener('change', function() {
      handlePaymentMethodChange(this.value);
    });
  });

  // Initialize file upload handlers
  initializeFileUploadHandlers();

  // Initialize form submission handler
  initializeFormSubmission();
});

function generateTrainingCalendar() {
  const container = document.getElementById('trainingCalendarContainer');
  if (!container) return;
  
  const today = new Date();
  const currentMonth = today.getMonth();
  const currentYear = today.getFullYear();
  
  let calendarHTML = '';
  
  // Generate 3 months starting from current month
  for (let monthOffset = 0; monthOffset < 3; monthOffset++) {
    const month = (currentMonth + monthOffset) % 12;
    const year = currentYear + Math.floor((currentMonth + monthOffset) / 12);
    
    calendarHTML += generateMonthCalendar(year, month, today);
  }
  
  container.innerHTML = calendarHTML;
}

function generateMonthCalendar(year, month, today) {
  const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];
  
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const todayStr = formatDateToString(today);
  
  let html = `
    <div class="month-calendar">
      <div class="month-header">
        <h3>${monthNames[month]} ${year}</h3>
      </div>
      <div class="calendar-grid">
        <div class="day-header">Sun</div>
        <div class="day-header">Mon</div>
        <div class="day-header">Tue</div>
        <div class="day-header">Wed</div>
        <div class="day-header">Thu</div>
        <div class="day-header">Fri</div>
        <div class="day-header">Sat</div>
  `;
  
  // Empty cells for days before month starts
  for (let i = 0; i < firstDay; i++) {
    html += '<div class="day-cell empty"></div>';
  }
  
  // Days of the month
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month, day);
    const dateStr = formatDateToString(date);
    const dayTrainings = calendarTrainings.filter(training => training.session_date === dateStr);
    
    let dayClass = 'day-cell';
    if (dayTrainings.length > 0) {
      dayClass += ' has-events';
    }
    if (dateStr === todayStr) {
      dayClass += ' today';
    }
    if (date < today && dateStr !== todayStr) {
      dayClass += ' past';
    }
    
    html += `<div class="${dayClass}" data-date="${dateStr}" title="${dayTrainings.length > 0 ? dayTrainings.map(e => e.title).join(', ') : ''}">
      <span class="day-number">${day}</span>`;
    
    if (dayTrainings.length > 0) {
      html += '<div class="event-indicators">';
      dayTrainings.slice(0, 3).forEach(training => { // Limit to 3 indicators per day
        const serviceColor = getTrainingServiceColor(training.major_service);
        html += `<div class="event-indicator ${serviceColor}" title="${escapeHtml(training.title)} - ${escapeHtml(training.venue)}"></div>`;
      });
      if (dayTrainings.length > 3) {
        html += `<div class="event-indicator more" title="+${dayTrainings.length - 3} more trainings">+${dayTrainings.length - 3}</div>`;
      }
      html += '</div>';
    }
    
    html += '</div>';
  }
  
  html += '</div></div>';
  return html;
}

function getTrainingServiceColor(service) {
  // Map service names to color classes that match your CSS
  const colorMap = {
    'Health Service': 'health',
    'Safety Service': 'safety', 
    'Welfare Service': 'welfare',
    'Disaster Management Service': 'disaster',
    'Red Cross Youth': 'rcy'
  };
  return colorMap[service] || 'general';
}

// Helper function to format date to YYYY-MM-DD string without timezone issues
function formatDateToString(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Helper function to escape HTML to prevent XSS
function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

    function openRegisterModal(session) {
      currentSession = session;
      document.getElementById('sessionId').value = session.session_id;
      document.getElementById('modalTitle').textContent = 'Register for ' + session.title;
      
      const trainingInfo = document.getElementById('trainingInfo');
      const sessionDate = new Date(session.session_date + 'T00:00:00'); // Force local time interpretation
      
      trainingInfo.innerHTML = `
        <div class="event-details">
          <h3>${escapeHtml(session.title)}</h3>
          <p><i class="fas fa-calendar"></i> ${sessionDate.toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
          <p><i class="fas fa-clock"></i> ${formatTime(session.start_time)} - ${formatTime(session.end_time)}</p>
          <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(session.venue)}</p>
          <p><i class="fas fa-tag"></i> ${escapeHtml(session.major_service)}</p>
          ${session.fee > 0 ? `<p><i class="fas fa-money-bill"></i> Fee: ₱${parseFloat(session.fee).toFixed(2)}</p>` : '<p><i class="fas fa-gift"></i> Free Training</p>'}
          <p><i class="fas fa-users"></i> Available Slots: ${session.capacity > 0 ? (session.capacity - session.registered_count) : 'Unlimited'}</p>
        </div>
      `;
      
      // Auto-fill training type and date for both tabs
      document.getElementById('training_type_individual').value = session.title;
      document.getElementById('training_date_individual').value = session.session_date;
      document.getElementById('training_type_org').value = session.title;
      document.getElementById('training_date_org').value = session.session_date;
      
      // Handle payment section
      updatePaymentSection(session.fee);
      
      // Reset form to individual tab
      switchTab('individual');
      
      document.getElementById('registerModal').classList.add('active');
      document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }

    function updatePaymentSection(fee) {
      const paymentSection = document.getElementById('paymentSection');
      const trainingFeeAmount = document.getElementById('trainingFeeAmount');
      const totalAmountDisplay = document.getElementById('totalAmountDisplay');
      const hiddenPaymentAmount = document.getElementById('hiddenPaymentAmount');

      if (fee > 0) {
        paymentSection.style.display = 'block';
        const formattedFee = '₱' + parseFloat(fee).toFixed(2);
        trainingFeeAmount.textContent = formattedFee;
        totalAmountDisplay.textContent = formattedFee;
        hiddenPaymentAmount.value = fee;
        
        // Update summary
        document.getElementById('summaryTrainingFee').textContent = formattedFee;
        document.getElementById('summaryTotalAmount').textContent = formattedFee;
        
        // Make payment method required
        const paymentModeInputs = document.querySelectorAll('input[name="payment_method"]');
        paymentModeInputs.forEach(input => input.required = true);
      } else {
        paymentSection.style.display = 'none';
        hiddenPaymentAmount.value = '0';
        
        // Make payment method not required for free training
        const paymentModeInputs = document.querySelectorAll('input[name="payment_method"]');
        paymentModeInputs.forEach(input => input.required = false);
      }
    }
    
    function closeRegisterModal() {
      document.getElementById('registerModal').classList.remove('active');
      document.body.style.overflow = ''; // Restore scrolling
      
      // Reset current session
      currentSession = null;
      
      // Reset form
      const form = document.getElementById('registerForm');
      if (form) {
        form.reset();
        
        // Reset to individual tab
        switchTab('individual');
        
        // Reset file upload containers
        resetFileUploads();
        
        // Reset payment forms
        resetPaymentForms();
        
        // Reset submit button
        const submitBtn = form.querySelector('.btn-submit');
        if (submitBtn) {
          submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Register for Training';
          submitBtn.disabled = false;
        }
      }
    }

    function resetPaymentForms() {
      // Hide all payment forms
      document.querySelectorAll('.payment-form').forEach(form => {
        form.style.display = 'none';
        form.classList.remove('active');
      });
      
      // Hide receipt upload and payment summary
      const receiptUpload = document.getElementById('receiptUpload');
      const paymentSummary = document.getElementById('paymentSummary');
      if (receiptUpload) receiptUpload.style.display = 'none';
      if (paymentSummary) paymentSummary.style.display = 'none';
      
      // Reset payment method selection
      document.querySelectorAll('input[name="payment_method"]').forEach(input => {
        input.checked = false;
      });
      
      // Clear payment form fields
      document.querySelectorAll('.payment-form input').forEach(input => {
        input.value = '';
        input.removeAttribute('required');
      });
    }
    
    function switchTab(tabName) {
      // Update tab buttons
      document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
      
      // Update tab content
      document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
      document.getElementById(`${tabName}-tab`).classList.add('active');
      
      // Update the active registration type input
      if (tabName === 'individual') {
        document.getElementById('registration_type_individual').disabled = false;
        document.getElementById('registration_type_organization').disabled = true;
        
        // Set required fields for individual
        setRequiredFields(true, false);
        
        // Clear organization fields
        document.getElementById('organization_name').value = '';
        document.getElementById('pax_count').value = '';
        
        // Re-populate training type and date if we have current session
        if (currentSession) {
          document.getElementById('training_type_individual').value = currentSession.title;
          document.getElementById('training_date_individual').value = currentSession.session_date;
        }
      } else {
        document.getElementById('registration_type_individual').disabled = true;
        document.getElementById('registration_type_organization').disabled = false;
        
        // Set required fields for organization
        setRequiredFields(false, true);
        
        // Clear individual fields
        document.getElementById('full_name').value = '';
        document.getElementById('location').value = '';
        document.getElementById('age').value = '';
        document.getElementById('rcy_status').value = '';
        
        // Re-populate training type and date if we have current session
        if (currentSession) {
          document.getElementById('training_type_org').value = currentSession.title;
          document.getElementById('training_date_org').value = currentSession.session_date;
        }
      }
    }
    
    function setRequiredFields(individual, organization) {
      // Individual fields
      document.getElementById('full_name').required = individual;
      document.getElementById('location').required = individual;
      document.getElementById('age').required = individual;
      document.getElementById('rcy_status').required = individual;
      document.getElementById('training_type_individual').required = individual;
      document.getElementById('training_date_individual').required = individual;
      
      // Organization fields
      document.getElementById('organization_name').required = organization;
      document.getElementById('training_type_org').required = organization;
      document.getElementById('training_date_org').required = organization;
      document.getElementById('pax_count').required = organization;
    }

    function handlePaymentMethodChange(selectedMethod) {
      // Hide all payment forms
      document.querySelectorAll('.payment-form').forEach(form => {
        form.classList.remove('active');
        form.style.display = 'none';
      });
      
      // Show selected payment form
      const selectedForm = document.getElementById(selectedMethod + '_form');
      if (selectedForm) {
        selectedForm.style.display = 'block';
        selectedForm.classList.add('active');
      }
      
      // Show/hide receipt upload based on payment method
      const receiptUpload = document.getElementById('receiptUpload');
      const paymentSummary = document.getElementById('paymentSummary');
      
      if (selectedMethod === 'cash') {
        if (receiptUpload) receiptUpload.style.display = 'none';
        const receiptInput = document.getElementById('payment_receipt');
        if (receiptInput) receiptInput.required = false;
      } else {
        if (receiptUpload) receiptUpload.style.display = 'block';
        const receiptInput = document.getElementById('payment_receipt');
        if (receiptInput) receiptInput.required = true;
      }
      
      // Show payment summary
      if (paymentSummary) paymentSummary.style.display = 'block';
      const selectedMethodSpan = document.getElementById('selectedPaymentMethod');
      if (selectedMethodSpan) selectedMethodSpan.textContent = getPaymentMethodName(selectedMethod);
      
      // Set required fields for selected payment method
      setPaymentRequiredFields(selectedMethod);
    }

    function getPaymentMethodName(method) {
      const names = {
        'bank_transfer': 'Bank Transfer',
        'gcash': 'GCash',
        'paymaya': 'PayMaya',
        'cash': 'Cash Payment'
      };
      return names[method] || method;
    }

    function setPaymentRequiredFields(method) {
      // Clear all payment required fields first
      document.querySelectorAll('.payment-form input').forEach(input => {
        input.removeAttribute('required');
      });
      
      // Set required fields based on selected method
      switch(method) {
        case 'bank_transfer':
          const bankRef = document.getElementById('bank_reference');
          if (bankRef) bankRef.setAttribute('required', 'required');
          break;
        case 'gcash':
          const gcashRef = document.getElementById('gcash_reference');
          const gcashSender = document.getElementById('gcash_sender');
          if (gcashRef) gcashRef.setAttribute('required', 'required');
          if (gcashSender) gcashSender.setAttribute('required', 'required');
          break;
        case 'paymaya':
          const paymayaRef = document.getElementById('paymaya_reference');
          const paymayaSender = document.getElementById('paymaya_sender');
          if (paymayaRef) paymayaRef.setAttribute('required', 'required');
          if (paymayaSender) paymayaSender.setAttribute('required', 'required');
          break;
        case 'cash':
          const cashName = document.getElementById('cash_name');
          if (cashName) cashName.setAttribute('required', 'required');
          break;
      }
    }
    
    function filterService(service) {
      const urlParams = new URLSearchParams(window.location.search);
      if (service === 'all') {
        urlParams.delete('service');
      } else {
        urlParams.set('service', service);
      }
      
      // Preserve search parameter if it exists
      const currentSearch = urlParams.get('search');
      if (currentSearch) {
        urlParams.set('search', currentSearch);
      }
      
      window.location.search = urlParams.toString();
    }
    
    function formatTime(timeString) {
      if (!timeString) return '';
      const time = new Date('1970-01-01T' + timeString + 'Z');
      return time.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      });
    }

    // Helper function to format date to YYYY-MM-DD string without timezone issues
    function formatDateToString(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    }
    
    // Helper function to escape HTML to prevent XSS
    function escapeHtml(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
    
    // File upload handling
    function handleFileUpload(inputElement) {
      const container = inputElement.closest('.file-upload-container') || inputElement.closest('.receipt-upload');
      const info = container.querySelector('.file-upload-info span') || container.querySelector('.upload-text');
      
      if (inputElement.files && inputElement.files[0]) {
        const file = inputElement.files[0];
        let maxSize;
        
        if (inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt') {
          maxSize = 5 * 1024 * 1024; // 5MB
        } else {
          maxSize = 10 * 1024 * 1024; // 10MB
        }
        
        // Check file size
        if (file.size > maxSize) {
          alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
          inputElement.value = '';
          resetFileUpload(container, inputElement.name);
          return;
        }
        
        // Check file type
        const allowedTypes = inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt' ? 
          ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'] :
          ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        
        if (!allowedTypes.includes(file.type)) {
          alert('Invalid file type. Please upload a supported file format.');
          inputElement.value = '';
          resetFileUpload(container, inputElement.name);
          return;
        }
        
        container.classList.add('has-file');
        info.textContent = `Selected: ${file.name}`;
      } else {
        resetFileUpload(container, inputElement.name);
      }
    }

    function resetFileUpload(container, inputName) {
      container.classList.remove('has-file');
      const info = container.querySelector('.file-upload-info span') || container.querySelector('.upload-text');
      
      if (inputName === 'valid_id') {
        info.textContent = 'Upload a clear photo of your valid ID';
      } else if (inputName === 'payment_receipt') {
        info.textContent = 'Upload Payment Receipt';
      } else {
        info.textContent = 'Upload requirements/supporting documents';
      }
    }

    function resetFileUploads() {
      document.querySelectorAll('.file-upload-container, .receipt-upload').forEach(container => {
        container.classList.remove('has-file');
      });
      
      // Reset individual upload messages
      const validIdContainer = document.querySelector('#valid_id').closest('.file-upload-container');
      const reqContainer = document.querySelector('#requirements').closest('.file-upload-container');
      const receiptContainer = document.querySelector('#payment_receipt').closest('.receipt-upload');
      
      if (validIdContainer) {
        const validIdInfo = validIdContainer.querySelector('.file-upload-info span');
        if (validIdInfo) validIdInfo.textContent = 'Upload a clear photo of your valid ID';
      }
      
      if (reqContainer) {
        const reqInfo = reqContainer.querySelector('.file-upload-info span');
        if (reqInfo) reqInfo.textContent = 'Upload requirements/supporting documents';
      }
      
      if (receiptContainer) {
        const receiptInfo = receiptContainer.querySelector('.upload-text');
        if (receiptInfo) receiptInfo.textContent = 'Upload Payment Receipt';
      }
    }

    function initializeFileUploadHandlers() {
      // Add event listeners for file inputs
      const validIdInput = document.getElementById('valid_id');
      const requirementsInput = document.getElementById('requirements');
      const receiptInput = document.getElementById('payment_receipt');
      
      if (validIdInput) {
        validIdInput.addEventListener('change', function() {
          handleFileUpload(this);
        });
      }

      if (requirementsInput) {
        requirementsInput.addEventListener('change', function() {
          handleFileUpload(this);
        });
      }

      if (receiptInput) {
        receiptInput.addEventListener('change', function() {
          handleFileUpload(this);
        });
      }
    }

    function initializeFormSubmission() {
      // Form validation and submission
      const registerForm = document.getElementById('registerForm');
      if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
          if (!validateForm(this)) {
            e.preventDefault();
            return;
          }

          // Show loading state
          const submitBtn = this.querySelector('.btn-submit');
          if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Registration...';
            submitBtn.disabled = true;
          }
        });
      }
    }

    function validateForm(form) {
      const activeTab = document.querySelector('.tab-content.active');
      const isIndividual = activeTab && activeTab.id === 'individual-tab';
      
      if (isIndividual) {
        const fullName = form.querySelector('#full_name');
        const location = form.querySelector('#location');
        const age = form.querySelector('#age');
        const rcyStatus = form.querySelector('#rcy_status');
        
        if (!fullName || !fullName.value.trim()) {
          alert('Please enter your full name.');
          if (fullName) fullName.focus();
          return false;
        }
        
        if (!location || !location.value.trim()) {
          alert('Please enter your location.');
          if (location) location.focus();
          return false;
        }
        
        if (!age || !age.value || age.value < 1 || age.value > 120) {
          alert('Please enter a valid age (1-120).');
          if (age) age.focus();
          return false;
        }
        
        if (!rcyStatus || !rcyStatus.value) {
          alert('Please select your RCY status.');
          if (rcyStatus) rcyStatus.focus();
          return false;
        }
      } else {
        // Organization validation
        const orgName = form.querySelector('#organization_name');
        const paxCount = form.querySelector('#pax_count');
        
        if (!orgName || !orgName.value.trim()) {
          alert('Please enter the organization/company name.');
          if (orgName) orgName.focus();
          return false;
        }
        
        if (!paxCount || !paxCount.value || paxCount.value < 1) {
          alert('Please enter the number of participants.');
          if (paxCount) paxCount.focus();
          return false;
        }
      }
      
      // Check payment method for paid sessions
      if (currentSession && parseFloat(currentSession.fee) > 0) {
        const paymentMode = form.querySelector('input[name="payment_method"]:checked');
        if (!paymentMode) {
          alert('Please select a payment method.');
          return false;
        }
        
        // Check receipt upload for non-cash payments
        if (paymentMode.value !== 'cash') {
          const receiptInput = form.querySelector('#payment_receipt');
          if (!receiptInput || !receiptInput.files || !receiptInput.files[0]) {
            alert('Please upload your payment receipt.');
            if (receiptInput) receiptInput.focus();
            return false;
          }
        }
      }
      
      // Check valid ID upload
      const validId = form.querySelector('#valid_id');
      if (!validId || !validId.files || !validId.files[0]) {
        alert('Please upload a valid ID.');
        if (validId) validId.focus();
        return false;
      }
      
      return true;
    }

    // Close modal when clicking outside
    document.addEventListener('click', function(e) {
      const modal = document.getElementById('registerModal');
      if (modal && e.target === modal) {
        closeRegisterModal();
      }
    });

    // Keyboard navigation for modals
    document.addEventListener('keydown', function(e) {
      const modal = document.getElementById('registerModal');
      if (modal && modal.classList.contains('active') && e.key === 'Escape') {
        closeRegisterModal();
      }
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
      const alerts = document.querySelectorAll('.alert');
      alerts.forEach(alert => {
        setTimeout(() => {
          if (alert.parentNode) {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => {
              if (alert.parentNode) {
                alert.remove();
              }
            }, 300);
          }
        }, 5000);
      });
    }, 100);

    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
      window.history.replaceState(null, null, window.location.href);
    }
</script>
</body>
</html>