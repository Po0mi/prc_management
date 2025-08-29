<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();

$user_role = get_user_role();
if ($user_role) {
    // If user has an admin role, redirect to admin dashboard
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
    $age = (int)$_POST['age'];
    
    // Get event details to check if it's a paid event
    $eventQuery = $pdo->prepare("SELECT fee FROM events WHERE event_id = ?");
    $eventQuery->execute([$eventId]);
    $eventData = $eventQuery->fetch();
    $eventFee = $eventData ? floatval($eventData['fee']) : 0;
    
    // Handle payment mode with default for free events
    if ($eventFee <= 0) {
        $paymentMode = 'free'; // Force free payment mode for free events
    } else {
        $paymentMode = isset($_POST['payment_mode']) ? trim($_POST['payment_mode']) : '';
    }

    // Handle registration type and associated fields
    $registrationType = 'individual'; // Default to individual
    $organizationName = '';
    $contactPerson = '';
    $contactEmail = '';
    $paxCount = 1;
    $location = '';

    // Check if organization registration was selected
    if (isset($_POST['registration_type']) && $_POST['registration_type'] === 'organization') {
        $registrationType = 'organization';
        $organizationName = isset($_POST['organization_name']) ? trim($_POST['organization_name']) : '';
        $contactPerson = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
        $contactEmail = isset($_POST['contact_email']) ? trim($_POST['contact_email']) : '';
        $paxCount = isset($_POST['pax_count']) ? (int)$_POST['pax_count'] : 1;
    } else {
        // For individual registration, use the main form fields
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    }

    // Validate required fields based on registration type
    if ($registrationType === 'individual') {
        if (empty($fullName) || empty($email) || empty($age) || empty($location)) {
            $regMessage = "Please fill in all required fields for individual registration.";
        }
    } else {
        if (empty($organizationName) || empty($contactPerson) || empty($contactEmail) || empty($paxCount)) {
            $regMessage = "Please fill in all required fields for organization registration.";
        }
        
        // Validate contact email format
        if (empty($regMessage) && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $regMessage = "Please provide a valid contact email address.";
        }
    }

    // Validate main email format
    if (empty($regMessage) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $regMessage = "Please provide a valid email address.";
    }

    // Validate payment method for paid events
    if (empty($regMessage) && $eventFee > 0 && empty($paymentMode)) {
        $regMessage = "Please select a payment method for this paid event.";
    }

    // Only proceed if no validation errors
    if (empty($regMessage)) {
        $check = $pdo->prepare("SELECT * FROM registrations WHERE event_id = ? AND user_id = ?");
        $check->execute([$eventId, $userId]);

        if ($check->rowCount() === 0) {
            // Create user folder if it doesn't exist
            $userFolder = __DIR__ . "/../uploads/user_" . $userId;
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
                        $validIdPath = 'uploads/user_' . $userId . '/' . $fileName;
                    }
                } else {
                    $regMessage = "Invalid file type for Valid ID. Please upload JPG, PNG, or PDF files only.";
                }
            }

            // Handle additional documents upload
            if (empty($regMessage) && isset($_FILES['documents']) && $_FILES['documents']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $fileType = $_FILES['documents']['type'];
                
                if (in_array($fileType, $allowedTypes)) {
                    $fileExtension = pathinfo($_FILES['documents']['name'], PATHINFO_EXTENSION);
                    $fileName = 'documents_' . time() . '.' . $fileExtension;
                    $documentsPath = $userFolder . '/' . $fileName;
                    
                    if (move_uploaded_file($_FILES['documents']['tmp_name'], $documentsPath)) {
                        $documentsPath = 'uploads/user_' . $userId . '/' . $fileName;
                    }
                } else {
                    $regMessage = "Invalid file type for additional documents.";
                }
            }

            // Handle payment receipt upload for paid events (except cash)
            if (empty($regMessage) && $eventFee > 0 && $paymentMode !== 'cash' && $paymentMode !== 'free' && isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                $fileType = $_FILES['payment_receipt']['type'];
                
                if (in_array($fileType, $allowedTypes)) {
                    // Check file size (5MB limit)
                    $maxSize = 5 * 1024 * 1024;
                    if ($_FILES['payment_receipt']['size'] > $maxSize) {
                        $regMessage = "Payment receipt file size too large. Maximum allowed: 5MB";
                    } else {
                        $fileExtension = pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION);
                        $fileName = 'payment_receipt_' . time() . '.' . $fileExtension;
                        $paymentReceiptPath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $paymentReceiptPath)) {
                            $paymentReceiptPath = 'uploads/user_' . $userId . '/' . $fileName;
                        } else {
                            $regMessage = "Failed to upload payment receipt.";
                        }
                    }
                } else {
                    $regMessage = "Invalid file type for payment receipt. Please upload JPG, PNG, or PDF files only.";
                }
            }

            // Validate payment receipt for paid events (except cash and free)
            if (empty($regMessage) && $eventFee > 0 && $paymentMode !== 'cash' && $paymentMode !== 'free') {
                if (empty($paymentReceiptPath)) {
                    $regMessage = "Payment receipt is required for " . ucfirst(str_replace('_', ' ', $paymentMode)) . " payments.";
                }
            }

            // Only proceed if no file upload errors
            if (empty($regMessage)) {
                // Validate required valid ID upload
                if (empty($validIdPath)) {
                    $regMessage = "Valid ID upload is required.";
                } else {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO registrations (
                                event_id, user_id, registration_date, full_name, email, age, 
                                payment_mode, valid_id_path, documents_path, payment_receipt_path, status,
                                registration_type, organization_name, contact_person, 
                                contact_email, pax_count, location
                            )
                            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $eventId, $userId, $fullName, $email, $age, $paymentMode, 
                            $validIdPath, $documentsPath, $paymentReceiptPath, $registrationType, 
                            $organizationName, $contactPerson, $contactEmail, $paxCount, $location
                        ]);
                        
                        if ($eventFee <= 0) {
                            $regMessage = "You have successfully registered for this free event. Your documents have been uploaded. Awaiting confirmation.";
                        } else {
                            $regMessage = "You have successfully registered. Your documents and payment information have been uploaded. Awaiting confirmation.";
                        }
                        
                    } catch (PDOException $e) {
                        error_log("Registration error: " . $e->getMessage());
                        $regMessage = "An error occurred during registration. Please try again.";
                    }
                }
            }
        } else {
            $regMessage = "You are already registered for this event.";
        }
    }
} 

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build query with filters
$whereConditions = [];
$params = [];

$whereConditions[] = "event_date >= CURDATE()";

if ($search) {
    $whereConditions[] = "(title LIKE :search OR location LIKE :search OR description LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter === 'this_week') {
    $whereConditions[] = "event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($statusFilter === 'this_month') {
    $whereConditions[] = "event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Get events with registration counts and user registration status
$query = "
    SELECT e.*, 
           COUNT(r.registration_id) AS registrations_count,
           ur.registration_id AS user_registered,
           ur.status AS user_status
    FROM events e
    LEFT JOIN registrations r ON e.event_id = r.event_id
    LEFT JOIN registrations ur ON e.event_id = ur.event_id AND ur.user_id = ?
    $whereClause
    GROUP BY e.event_id
    ORDER BY e.event_date ASC
";

$stmt = $pdo->prepare($query);
$stmt->bindValue(1, $userId, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$events = $stmt->fetchAll();

// Get user's registrations
$userRegistrations = $pdo->prepare("
    SELECT r.registration_id, r.event_id, r.full_name, r.email, r.age, r.payment_mode, 
           r.valid_id_path, r.documents_path, r.status, r.registration_date, r.registration_type,
           r.organization_name, r.contact_person, r.contact_email, r.pax_count, r.location,
           e.title, e.event_date
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    WHERE r.user_id = ?
    ORDER BY e.event_date ASC
");
$userRegistrations->execute([$userId]);
$myRegistrations = $userRegistrations->fetchAll();

// Get statistics
$upcoming_events = $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn();
$my_registrations = count($myRegistrations);
$pending_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'pending'; }));
$approved_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'approved'; }));

// Get events for small calendar (next 3 months)
$calendarEvents = $pdo->query("
    SELECT event_id, title, event_date, location
    FROM events 
    WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
    ORDER BY event_date ASC
")->fetchAll();

// Get extended events for large calendar modal (6 months range with more details)
$extendedCalendarEvents = $pdo->query("
    SELECT event_id, title, event_date, location, description, fee, capacity,
           (SELECT COUNT(*) FROM registrations WHERE event_id = events.event_id) as registrations_count
    FROM events 
    WHERE event_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY event_date ASC
")->fetchAll();

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
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/registration.css?v=<?php echo time(); ?>">
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
                    <h1><i class="fas fa-calendar-alt"></i> Event Registration</h1>
                    <p>Register for upcoming PRC events and manage your registrations</p>
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
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Search events..." value="<?= htmlspecialchars($search) ?>">
                            <button type="submit"><i class="fas fa-arrow-right"></i></button>
                        </form>
                        
                        <div class="status-filter">
                            <button onclick="filterStatus('all')" class="<?= !$statusFilter ? 'active' : '' ?>">All</button>
                            <button onclick="filterStatus('this_week')" class="<?= $statusFilter === 'this_week' ? 'active' : '' ?>">This Week</button>
                            <button onclick="filterStatus('this_month')" class="<?= $statusFilter === 'this_month' ? 'active' : '' ?>">This Month</button>
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
                            <div style="font-size: 1.5rem; font-weight: 700;"><?= $upcoming_events ?></div>
                            <div style="color: var(--gray); font-size: 0.9rem;">Upcoming Events</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 700;"><?= $my_registrations ?></div>
                            <div style="color: var(--gray); font-size: 0.9rem;">My Registrations</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 700;"><?= $pending_registrations ?></div>
                            <div style="color: var(--gray); font-size: 0.9rem;">Pending</div>
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

                <!-- Available Events Table -->
                <div class="events-table-wrapper">
                    <div class="table-header">
                        <h2 class="table-title">Available Events</h2>
                    </div>
                    
                    <?php if (empty($events)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No events found</h3>
                            <p><?= $search ? 'Try adjusting your search criteria' : 'No upcoming events available for registration' ?></p>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Event Details</th>
                                    <th>Date</th>
                                    <th>Location</th>
                                    <th>Capacity</th>
                                    <th>Fee</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $e): 
                                    $eventDate = strtotime($e['event_date']);
                                    $isFull = $e['capacity'] > 0 && $e['registrations_count'] >= $e['capacity'];
                                    $isRegistered = $e['user_registered'] !== null;
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
                                            <div class="registrations-badge <?= $isFull ? 'full' : '' ?>">
                                                <i class="fas fa-users"></i>
                                                <?= $e['registrations_count'] ?> / <?= $e['capacity'] ?: '∞' ?>
                                                <?php if ($isFull): ?>
                                                    <span style="font-size: 0.7rem; background: var(--prc-red); color: white; padding: 0.2rem 0.4rem; border-radius: 4px;">FULL</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fee">
                                                <?= $e['fee'] > 0 ? '₱' . number_format($e['fee'], 2) : 'Free' ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($isRegistered): ?>
                                                <span class="status-badge <?= $e['user_status'] ?>">
                                                    <?= ucfirst($e['user_status']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge available"><i class="fas fa-clock"></i> Available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <?php if (!$isRegistered && !$isFull): ?>
                                                <button class="btn-action btn-register" onclick="openRegisterModal(<?= htmlspecialchars(json_encode($e)) ?>)">
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
                        <h2><i class="fas fa-list-check"></i> My Registrations</h2>
                    </div>
                    
                    <?php if (empty($myRegistrations)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h3>No registrations found</h3>
                            <p>You haven't registered for any events yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Event</th>
                                        <th>Date</th>
                                        <th>Full Name</th>
                                        <th>Age</th>
                                        <th>Email</th>
                                        <th>Payment Mode</th>
                                        <th>Documents</th>
                                        <th>Registered On</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($myRegistrations as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['title']) ?></td>
                                        <td><?= date('M d, Y', strtotime($r['event_date'])) ?></td>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= htmlspecialchars($r['age']) ?></td>
                                        <td><?= htmlspecialchars($r['email']) ?></td>
                                        <td><?= htmlspecialchars($r['payment_mode']) ?></td>
                                        <!-- Updated My Registrations Table HTML -->
<!-- Replace the existing documents column in your registrations table with this enhanced version -->

<td>
    <div class="document-links">
        <?php if ($r['valid_id_path']): ?>
            <a href="../<?= htmlspecialchars($r['valid_id_path']) ?>" target="_blank" class="doc-link">
                <i class="fas fa-id-card"></i> Valid ID
            </a>
        <?php endif; ?>
        
        <?php if ($r['documents_path']): ?>
            <a href="../<?= htmlspecialchars($r['documents_path']) ?>" target="_blank" class="doc-link">
                <i class="fas fa-file-alt"></i> Documents
            </a>
        <?php endif; ?>
        
        <?php if ($r['payment_receipt_path']): ?>
            <a href="../<?= htmlspecialchars($r['payment_receipt_path']) ?>" target="_blank" class="doc-link payment-receipt">
                <i class="fas fa-receipt"></i> Payment Receipt
            </a>
        <?php endif; ?>
        
        <?php if (empty($r['valid_id_path']) && empty($r['documents_path']) && empty($r['payment_receipt_path'])): ?>
            <span class="no-documents">No documents uploaded</span>
        <?php endif; ?>
    </div>
</td>
                                        <td><?= date('M d, Y', strtotime($r['registration_date'])) ?></td>
                                        <td>
                                            <span class="status-badge <?= $r['status'] ?>">
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

      <div class="calendar-sidebar">
    <div class="calendar-header">
        <h2><i class="fas fa-calendar-alt"></i> Events Calendar</h2>
        <button class="btn-view-calendar" onclick="openCalendarModal()">
            <i class="fas fa-expand"></i> View Full Calendar
        </button>
    </div>
    <div class="calendar-container" id="calendarContainer">
        <!-- Existing calendar content -->
    </div>
</div>
<!-- Large Calendar Modal -->
<div class="modal calendar-modal" id="calendarModal">
    <div class="modal-content calendar-modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-calendar-alt"></i> Events Calendar
            </h2>
            <button class="close-modal" onclick="closeCalendarModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="calendar-nav">
            <button class="nav-btn" onclick="changeMonth(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="current-month-year" id="currentMonthYear">
                <!-- Will be populated by JavaScript -->
            </div>
            <button class="nav-btn" onclick="changeMonth(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        
        <div class="large-calendar-container" id="largeCalendarContainer">
            <!-- Calendar will be generated by JavaScript -->
        </div>
        
        <div class="calendar-legend">
            <div class="legend-item">
                <div class="legend-dot has-events"></div>
                <span>Has Events</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot today"></div>
                <span>Today</span>
            </div>
            <div class="legend-item">
                <div class="legend-dot past"></div>
                <span>Past Date</span>
            </div>
        </div>
    </div>
</div>

<!-- Event Details Tooltip -->
<div class="event-tooltip" id="eventTooltip">
    <div class="tooltip-content">
        <!-- Event details will be populated here -->
    </div>
</div>
<!-- Updated Registration Modal HTML Structure -->
<div class="modal" id="registerModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title" id="modalTitle">Register for Event</h2>
      <button class="close-modal" onclick="closeRegisterModal()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    
    <form method="POST" id="registerForm" enctype="multipart/form-data">
      <input type="hidden" name="register_event" value="1">
      <input type="hidden" name="event_id" id="eventId">
      <input type="hidden" name="payment_amount" id="hiddenPaymentAmount" value="0">
      
      <!-- Event Information Display -->
      <div class="event-info" id="eventInfo">
        <!-- Event details will be populated by JavaScript -->
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
              <label for="email">Email Address *</label>
              <input type="email" id="email" name="email" required placeholder="Enter your email address">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="age">Age *</label>
              <input type="number" id="age" name="age" required min="1" max="120" placeholder="Enter your age">
            </div>
            
            <div class="form-group">
              <label for="location">Location *</label>
              <input type="text" id="location" name="location" required placeholder="Enter your location">
            </div>
          </div>
        </div>
        
        <!-- Organization Tab -->
        <div class="tab-content" id="organization-tab">
          <input type="hidden" name="registration_type" value="organization" id="registration_type_organization" disabled>
          
          <div class="form-group">
            <label for="organization_name">Organization/Company Name *</label>
            <input type="text" id="organization_name" name="organization_name" placeholder="Enter organization/company name">
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="contact_person">Contact Person *</label>
              <input type="text" id="contact_person" name="contact_person" placeholder="Enter contact person name">
            </div>
            
            <div class="form-group">
              <label for="contact_email">Contact Email *</label>
              <input type="email" id="contact_email" name="contact_email" placeholder="Enter contact email">
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
              <span class="fee-label">Event Registration Fee:</span>
              <span class="fee-amount" id="eventFeeAmount">₱0.00</span>
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
              <input type="radio" name="payment_mode" value="bank_transfer" id="bank_transfer">
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
              <input type="radio" name="payment_mode" value="gcash" id="gcash">
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
              <input type="radio" name="payment_mode" value="paymaya" id="paymaya">
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
              <input type="radio" name="payment_mode" value="credit_card" id="credit_card">
              <div class="payment-card">
                <div class="payment-icon card">
                  <i class="fas fa-credit-card"></i>
                </div>
                <div class="payment-details">
                  <div class="payment-name">Credit Card</div>
                  <div class="payment-description">Pay with Visa/Mastercard</div>
                </div>
                <div class="payment-status"></div>
              </div>
            </div>

            <div class="payment-option">
              <input type="radio" name="payment_mode" value="cash" id="cash">
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
              </div>
            </div>
            <div class="payment-instructions">
              <h6><i class="fas fa-info-circle"></i> Instructions</h6>
              <ol>
                <li>Transfer the exact amount to the bank account above</li>
                <li>Keep your bank receipt/confirmation</li>
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
            <div class="payment-instructions">
              <h6><i class="fas fa-info-circle"></i> Instructions</h6>
              <ol>
                <li>Open your GCash app and select "Send Money"</li>
                <li>Send the exact amount to the number above</li>
                <li>Take a screenshot of the successful transaction</li>
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
                  <span>+63 918 765 4321</span>
                </div>
                <div class="bank-field">
                  <label>Account Name</label>
                  <span>Philippine Red Cross Tacloban</span>
                </div>
              </div>
            </div>
            <div class="payment-instructions">
              <h6><i class="fas fa-info-circle"></i> Instructions</h6>
              <ol>
                <li>Open your PayMaya app and select "Send Money"</li>
                <li>Send the exact amount to the number above</li>
                <li>Take a screenshot of the successful transaction</li>
                <li>Upload the screenshot below</li>
              </ol>
            </div>
          </div>

          <!-- Credit Card Form -->
          <div class="payment-form" id="credit_card_form">
            <h5><i class="fas fa-credit-card"></i> Credit Card Payment Details</h5>
            <div class="payment-note">
              <i class="fas fa-info-circle"></i>
              <div class="payment-note-content">
                <strong>Important Note:</strong>
                <p>Credit card payments are processed through our secure payment gateway. You will be redirected to complete your payment after registration.</p>
              </div>
            </div>
            <div class="bank-details">
              <div class="bank-info">
                <div class="bank-field">
                  <label>Accepted Cards</label>
                  <span>Visa, Mastercard, JCB</span>
                </div>
                <div class="bank-field">
                  <label>Processing Fee</label>
                  <span>3.5% of total amount</span>
                </div>
              </div>
            </div>
            <div class="payment-instructions">
              <h6><i class="fas fa-info-circle"></i> Instructions</h6>
              <ol>
                <li>Complete the registration form</li>
                <li>You will be redirected to our secure payment gateway</li>
                <li>Enter your credit card details on the secure page</li>
                <li>Complete the payment to finalize your registration</li>
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
                <p>You have selected cash payment. Please visit our office during business hours to complete your payment.</p>
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
          </div>

          <!-- Payment Receipt Upload (for non-cash payments) -->
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
            <span class="summary-label">Event Fee:</span>
            <span class="summary-value" id="summaryEventFee">₱0.00</span>
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
          <label for="documents">Additional Documents</label>
          <div class="file-upload-container">
            <input type="file" id="documents" name="documents" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
            <div class="file-upload-info">
              <i class="fas fa-file-upload"></i>
              <span>Upload supporting documents (optional)</span>
              <small>Accepted formats: JPG, PNG, PDF, DOC, DOCX (Max 10MB)</small>
            </div>
          </div>
        </div>
      </div>
      
      <div class="form-notice">
        <i class="fas fa-info-circle"></i>
        <p>By registering, you agree to provide accurate information. Your documents will be securely stored and used only for event registration purposes.</p>
      </div>
      
      <button type="submit" class="btn-submit">
        <i class="fas fa-user-plus"></i> Register for Event
      </button>
    </form>
  </div>
</div>
  <script> window.calendarEventsData = <?= json_encode($extendedCalendarEvents) ?>;</script>
    <script src="/js/register.js"></script>
    <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="js/darkmode.js?v=<?php echo time(); ?>"></script>
    <script src="js/header.js?v=<?php echo time(); ?>"></script>
<script>
// Global variable to store current event data
let currentEventData = null;

// Global variables for calendar modal
let currentCalendarMonth = new Date().getMonth();
let currentCalendarYear = new Date().getFullYear();

// Function to open the calendar modal
function openCalendarModal() {
    const modal = document.getElementById('calendarModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        currentCalendarMonth = new Date().getMonth();
        currentCalendarYear = new Date().getFullYear();
        updateLargeCalendar();
    }
}

// Function to close the calendar modal
function closeCalendarModal() {
    const modal = document.getElementById('calendarModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Function to change month in the large calendar
function changeMonth(direction) {
    currentCalendarMonth += direction;
    
    if (currentCalendarMonth > 11) {
        currentCalendarMonth = 0;
        currentCalendarYear++;
    } else if (currentCalendarMonth < 0) {
        currentCalendarMonth = 11;
        currentCalendarYear--;
    }
    
    updateLargeCalendar();
}

// Function to update the large calendar display
function updateLargeCalendar() {
    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    
    const currentMonthYear = document.getElementById('currentMonthYear');
    if (currentMonthYear) {
        currentMonthYear.textContent = `${monthNames[currentCalendarMonth]} ${currentCalendarYear}`;
    }
    
    const calendarContainer = document.getElementById('largeCalendarContainer');
    if (calendarContainer) {
        calendarContainer.innerHTML = generateLargeCalendarGrid(currentCalendarYear, currentCalendarMonth);
    }
}

// Helper function to check if user is registered for any event on a specific date
function checkUserRegistrationForDate(dateStr) {
    if (typeof window.userRegistrations === 'undefined') return false;
    
    return window.userRegistrations.some(reg => reg.event_date === dateStr);
}

// Helper function to check if user is registered for a specific event
function checkUserRegistrationForEvent(eventId) {
    if (typeof window.userRegistrations === 'undefined') return false;
    
    return window.userRegistrations.some(reg => reg.event_id === parseInt(eventId));
}

// Fixed calendar generation with proper event indicators
function generateCalendar() {
    const container = document.getElementById('calendarContainer');
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

// Fixed month calendar generation with proper indicators
function generateMonthCalendar(year, month, today) {
    const monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    
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
        
        // Get events for this day - use the correct data source
        const dayEvents = (typeof window.calendarEventsData !== 'undefined' ? 
            window.calendarEventsData : (typeof window.calendarEvents !== 'undefined' ? window.calendarEvents : []))
            .filter(event => event.event_date === dateStr);
        
        let dayClass = 'day-cell';
        let hasRegisteredEvent = false;
        
        if (dayEvents.length > 0) {
            dayClass += ' has-events';
            
            // Check if user is registered for any event on this day
            hasRegisteredEvent = checkUserRegistrationForDate(dateStr);
            
            if (hasRegisteredEvent) {
                dayClass += ' has-registered-event';
            }
        }
        
        if (dateStr === todayStr) {
            dayClass += ' today';
        }
        
        // Build tooltip text with registration status
        let tooltipText = '';
        if (dayEvents.length > 0) {
            tooltipText = dayEvents.map(e => {
                const isRegistered = checkUserRegistrationForEvent(e.event_id);
                const status = isRegistered ? ' (Registered)' : '';
                return `${e.title}${status}`;
            }).join(', ');
        }
        
        html += `<div class="${dayClass}" data-date="${dateStr}" title="${escapeHtml(tooltipText)}">
            <span class="day-number">${day}</span>`;
        
        if (dayEvents.length > 0) {
            html += '<div class="event-indicators">';
            
            // Show up to 3 event indicators
            dayEvents.slice(0, 3).forEach(event => {
                const isRegistered = checkUserRegistrationForEvent(event.event_id);
                let eventClass = 'event-indicator';
                
                if (isRegistered) {
                    eventClass += ' registered';
                }
                
                html += `<div class="${eventClass}" 
                           title="${escapeHtml(event.title)} - ${escapeHtml(event.location)}${isRegistered ? ' (You are registered)' : ''}">
                         </div>`;
            });
            
            // Show count for additional events
            if (dayEvents.length > 3) {
                html += `<div class="event-indicator more" title="+${dayEvents.length - 3} more events">+${dayEvents.length - 3}</div>`;
            }
            html += '</div>';
        }
        
        html += '</div>';
    }
    
    html += '</div></div>';
    return html;
}

// Fixed large calendar generation for modal
function generateLargeCalendarGrid(year, month) {
    const today = new Date();
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const todayStr = formatDateToString(today);
    
    let html = `
        <div class="large-calendar-grid">
            <div class="calendar-weekdays">
                <div class="weekday">Sun</div>
                <div class="weekday">Mon</div>
                <div class="weekday">Tue</div>
                <div class="weekday">Wed</div>
                <div class="weekday">Thu</div>
                <div class="weekday">Fri</div>
                <div class="weekday">Sat</div>
            </div>
            <div class="calendar-days">
    `;
    
    // Empty cells for days before month starts
    for (let i = 0; i < firstDay; i++) {
        html += '<div class="calendar-day empty"></div>';
    }
    
    // Days of the month
    for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateStr = formatDateToString(date);
        
        // Get events for this day from the extended calendar events data
        const dayEvents = typeof window.calendarEventsData !== 'undefined' ? 
            window.calendarEventsData.filter(event => event.event_date === dateStr) : [];
        
        let dayClass = 'calendar-day';
        let hasRegisteredEvent = false;
        
        if (dayEvents.length > 0) {
            dayClass += ' has-events';
            hasRegisteredEvent = checkUserRegistrationForDate(dateStr);
            
            if (hasRegisteredEvent) {
                dayClass += ' has-registered-event';
            }
        }
        
        if (dateStr === todayStr) {
            dayClass += ' today';
        }
        if (date < today && dateStr !== todayStr) {
            dayClass += ' past';
        }
        
        html += `<div class="${dayClass}" data-date="${dateStr}" 
                    onmouseover="showEventTooltip(event, '${dateStr}')"
                    onmouseout="hideEventTooltip()">
            <div class="day-number">${day}</div>`;
        
        if (dayEvents.length > 0) {
            html += '<div class="event-dots">';
            
            // Show up to 3 event dots with registration status
            for (let i = 0; i < Math.min(dayEvents.length, 3); i++) {
                const event = dayEvents[i];
                const isRegistered = checkUserRegistrationForEvent(event.event_id);
                const registrationClass = isRegistered ? ' registered' : '';
                
                html += `<div class="event-dot${registrationClass}"></div>`;
            }
            
            if (dayEvents.length > 3) {
                html += `<div class="event-count">+${dayEvents.length - 3}</div>`;
            }
            html += '</div>';
            
            // Add registration status indicator for large calendar
            if (hasRegisteredEvent) {
                html += '<div class="registration-indicator"></div>';
            }
        }
        
        html += '</div>';
    }
    
    html += '</div></div>';
    return html;
}

// Enhanced event tooltip with registration status
function showEventTooltip(event, dateStr) {
    const tooltip = document.getElementById('eventTooltip');
    if (!tooltip || typeof window.calendarEventsData === 'undefined') return;
    
    const dayEvents = window.calendarEventsData.filter(e => e.event_date === dateStr);
    if (dayEvents.length === 0) return;
    
    let tooltipContent = '';
    dayEvents.forEach(eventData => {
        const isRegistered = checkUserRegistrationForEvent(eventData.event_id);
        const registrationStatus = isRegistered ? 
            '<div class="tooltip-event-status registered">✓ Registered</div>' : 
            '<div class="tooltip-event-status available">Available</div>';
        
        tooltipContent += `
            <div class="tooltip-event ${isRegistered ? 'registered' : ''}">
                <div class="tooltip-event-title">${escapeHtml(eventData.title)}</div>
                <div class="tooltip-event-location">📍 ${escapeHtml(eventData.location)}</div>
                <div class="tooltip-event-capacity">👥 ${eventData.registrations_count || 0}/${eventData.capacity || '∞'}</div>
                ${eventData.fee > 0 ? `<div class="tooltip-event-fee">💰 ₱${parseFloat(eventData.fee).toFixed(2)}</div>` : '<div class="tooltip-event-fee">🆓 Free</div>'}
                ${registrationStatus}
            </div>
        `;
    });
    
    tooltip.querySelector('.tooltip-content').innerHTML = tooltipContent;
    tooltip.style.display = 'block';
    
    // Position tooltip
    const rect = event.target.getBoundingClientRect();
    tooltip.style.left = (rect.left + window.scrollX) + 'px';
    tooltip.style.top = (rect.bottom + window.scrollY + 5) + 'px';
}

// Function to hide event tooltip
function hideEventTooltip() {
    const tooltip = document.getElementById('eventTooltip');
    if (tooltip) {
        tooltip.style.display = 'none';
    }
}

// Tab switching functionality
function switchTab(tabName) {
    // Remove active class from all tabs and content
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(btn => btn.classList.remove('active'));
    tabContents.forEach(content => content.classList.remove('active'));
    
    // Add active class to selected tab and content
    const activeButton = document.querySelector(`.tab-btn[onclick*="${tabName}"]`);
    const activeContent = document.getElementById(`${tabName}-tab`);
    
    if (activeButton && activeContent) {
        activeButton.classList.add('active');
        activeContent.classList.add('active');
    }
    
    // Enable/disable appropriate hidden inputs
    if (tabName === 'individual') {
        const regTypeIndividual = document.getElementById('registration_type_individual');
        const regTypeOrganization = document.getElementById('registration_type_organization');
        
        if (regTypeIndividual) regTypeIndividual.disabled = false;
        if (regTypeOrganization) regTypeOrganization.disabled = true;
        
        // Clear organization fields
        const orgFields = ['organization_name', 'contact_person', 'contact_email', 'pax_count'];
        orgFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.value = '';
        });
        
        // Set required status for individual fields
        const individualFields = [
            { id: 'full_name', required: true },
            { id: 'email', required: true },
            { id: 'age', required: true },
            { id: 'location', required: true }
        ];
        
        const organizationFields = [
            { id: 'organization_name', required: false },
            { id: 'contact_person', required: false },
            { id: 'contact_email', required: false },
            { id: 'pax_count', required: false }
        ];
        
        individualFields.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) element.required = field.required;
        });
        
        organizationFields.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) element.required = field.required;
        });
        
    } else if (tabName === 'organization') {
        const regTypeIndividual = document.getElementById('registration_type_individual');
        const regTypeOrganization = document.getElementById('registration_type_organization');
        
        if (regTypeIndividual) regTypeIndividual.disabled = true;
        if (regTypeOrganization) regTypeOrganization.disabled = false;
        
        // Clear individual fields
        const individualFields = ['full_name', 'email', 'age', 'location'];
        individualFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) field.value = '';
        });
        
        // Set required status for organization fields
        const organizationFields = [
            { id: 'organization_name', required: true },
            { id: 'contact_person', required: true },
            { id: 'contact_email', required: true },
            { id: 'pax_count', required: true }
        ];
        
        const individualFieldsReq = [
            { id: 'full_name', required: false },
            { id: 'email', required: false },
            { id: 'age', required: false },
            { id: 'location', required: false }
        ];
        
        organizationFields.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) element.required = field.required;
        });
        
        individualFieldsReq.forEach(field => {
            const element = document.getElementById(field.id);
            if (element) element.required = field.required;
        });
    }
    
    // Update payment section if event has fee
    updatePaymentSection();
}

// Payment section visibility and calculation
function updatePaymentSection() {
    if (!currentEventData) return;
    
    const paymentSection = document.getElementById('paymentSection');
    const eventFee = parseFloat(currentEventData.fee) || 0;
    
    if (eventFee > 0) {
        // Show payment section for paid events
        if (paymentSection) paymentSection.style.display = 'block';
        
        // Update fee amounts
        const eventFeeAmount = document.getElementById('eventFeeAmount');
        const totalAmountDisplay = document.getElementById('totalAmountDisplay');
        const hiddenPaymentAmount = document.getElementById('hiddenPaymentAmount');
        
        if (eventFeeAmount) eventFeeAmount.textContent = `₱${eventFee.toFixed(2)}`;
        if (totalAmountDisplay) totalAmountDisplay.textContent = `₱${eventFee.toFixed(2)}`;
        if (hiddenPaymentAmount) hiddenPaymentAmount.value = eventFee;
        
        // Make payment method required
        const paymentModeInputs = document.querySelectorAll('input[name="payment_mode"]');
        paymentModeInputs.forEach(input => input.required = true);
        
    } else {
        // Hide payment section for free events
        if (paymentSection) paymentSection.style.display = 'none';
        
        // Set default payment mode for free events
        const cashPayment = document.getElementById('cash');
        if (cashPayment) {
            cashPayment.checked = true;
        }
        
        // Make payment method not required for free events
        const paymentModeInputs = document.querySelectorAll('input[name="payment_mode"]');
        paymentModeInputs.forEach(input => input.required = false);
    }
}

// Payment method selection handling
function handlePaymentMethodChange() {
    const selectedPaymentMethod = document.querySelector('input[name="payment_mode"]:checked');
    if (!selectedPaymentMethod) return;
    
    const paymentValue = selectedPaymentMethod.value;
    
    // Hide all payment forms
    const paymentForms = document.querySelectorAll('.payment-form');
    paymentForms.forEach(form => {
        form.classList.remove('active');
        form.style.display = 'none';
    });
    
    // Show selected payment form
    const selectedForm = document.getElementById(`${paymentValue}_form`);
    if (selectedForm) {
        selectedForm.style.display = 'block';
        selectedForm.classList.add('active');
    }
    
    // Handle receipt upload visibility
    const receiptUpload = document.getElementById('receiptUpload');
    const receiptInput = document.getElementById('payment_receipt');
    
    if (receiptUpload) {
        // Show receipt upload for all payment methods except cash and free
        if (paymentValue === 'cash' || paymentValue === 'free') {
            receiptUpload.style.display = 'none';
            if (receiptInput) {
                receiptInput.required = false;
                receiptInput.value = ''; // Clear any selected file
            }
        } else {
            receiptUpload.style.display = 'block';
            if (receiptInput) {
                receiptInput.required = true;
            }
        }
    }
    
    // Update payment summary
    updatePaymentSummary(paymentValue);
}

// Update payment summary
function updatePaymentSummary(paymentMethod) {
    const paymentSummary = document.getElementById('paymentSummary');
    const selectedPaymentMethod = document.getElementById('selectedPaymentMethod');
    const summaryEventFee = document.getElementById('summaryEventFee');
    const summaryTotalAmount = document.getElementById('summaryTotalAmount');
    
    if (!currentEventData || !paymentSummary || !selectedPaymentMethod) return;
    
    const eventFee = parseFloat(currentEventData.fee) || 0;
    
    // Show payment summary for paid events
    if (eventFee > 0) {
        paymentSummary.style.display = 'block';
        
        // Update payment method name
        const paymentNames = {
            'bank_transfer': 'Bank Transfer',
            'gcash': 'GCash',
            'paymaya': 'PayMaya',
            'credit_card': 'Credit Card',
            'cash': 'Cash Payment',
            'free': 'Free'
        };
        
        selectedPaymentMethod.textContent = paymentNames[paymentMethod] || paymentMethod;
        
        // Update fee amounts
        if (summaryEventFee) summaryEventFee.textContent = `₱${eventFee.toFixed(2)}`;
        if (summaryTotalAmount) summaryTotalAmount.textContent = `₱${eventFee.toFixed(2)}`;
    } else {
        paymentSummary.style.display = 'none';
    }
}

// Enhanced file upload handling
function handleFileUpload(inputElement) {
    const container = inputElement.closest('.file-upload-container, .receipt-upload');
    if (!container) return;
    
    let info;
    
    if (container.classList.contains('receipt-upload')) {
        info = container.querySelector('.upload-text');
    } else {
        info = container.querySelector('.file-upload-info span');
    }
    
    if (inputElement.files && inputElement.files[0]) {
        const file = inputElement.files[0];
        
        // Determine max file size based on input type
        let maxSize;
        if (inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt') {
            maxSize = 5 * 1024 * 1024; // 5MB for ID and receipt
        } else {
            maxSize = 10 * 1024 * 1024; // 10MB for other documents
        }
        
        // Check file size
        if (file.size > maxSize) {
            alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
            inputElement.value = '';
            return;
        }
        
        // Check file type
        let allowedTypes;
        if (inputElement.name === 'valid_id' || inputElement.name === 'payment_receipt') {
            allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        } else {
            allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        }
        
        if (!allowedTypes.includes(file.type)) {
            alert('Invalid file type. Please upload a supported file format.');
            inputElement.value = '';
            return;
        }
        
        container.classList.add('has-file');
        if (info) {
            info.textContent = `Selected: ${file.name}`;
        }
    } else {
        container.classList.remove('has-file');
        if (info) {
            // Reset info text based on input type
            if (inputElement.name === 'valid_id') {
                info.textContent = 'Upload a clear photo of your valid ID';
            } else if (inputElement.name === 'payment_receipt') {
                info.textContent = 'Upload Payment Receipt';
            } else {
                info.textContent = 'Upload supporting documents (optional)';
            }
        }
    }
}

// Enhanced form validation with payment receipt checks
function validateForm(form) {
    const activeTab = document.querySelector('.tab-content.active');
    const isIndividual = activeTab && activeTab.id === 'individual-tab';
    
    if (isIndividual) {
        const fullName = form.querySelector('#full_name');
        const email = form.querySelector('#email');
        const age = form.querySelector('#age');
        const location = form.querySelector('#location');
        
        if (!fullName || !fullName.value.trim()) {
            alert('Please enter your full name.');
            if (fullName) fullName.focus();
            return false;
        }
        
        if (!email || !email.value.trim()) {
            alert('Please enter your email address.');
            if (email) email.focus();
            return false;
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email.value.trim())) {
            alert('Please enter a valid email address.');
            email.focus();
            return false;
        }
        
        if (!age || !age.value || age.value < 1 || age.value > 120) {
            alert('Please enter a valid age (1-120).');
            if (age) age.focus();
            return false;
        }
        
        if (!location || !location.value.trim()) {
            alert('Please enter your location.');
            if (location) location.focus();
            return false;
        }
    } else {
        // Organization validation
        const orgName = form.querySelector('#organization_name');
        const contactPerson = form.querySelector('#contact_person');
        const contactEmail = form.querySelector('#contact_email');
        const paxCount = form.querySelector('#pax_count');
        
        if (!orgName || !orgName.value.trim()) {
            alert('Please enter the organization/company name.');
            if (orgName) orgName.focus();
            return false;
        }
        
        if (!contactPerson || !contactPerson.value.trim()) {
            alert('Please enter the contact person name.');
            if (contactPerson) contactPerson.focus();
            return false;
        }
        
        if (!contactEmail || !contactEmail.value.trim()) {
            alert('Please enter the contact email.');
            if (contactEmail) contactEmail.focus();
            return false;
        }
        
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(contactEmail.value.trim())) {
            alert('Please enter a valid contact email address.');
            contactEmail.focus();
            return false;
        }
        
        if (!paxCount || !paxCount.value || paxCount.value < 1) {
            alert('Please enter the number of participants.');
            if (paxCount) paxCount.focus();
            return false;
        }
    }
    
    // Enhanced payment validation for paid events
    if (currentEventData && parseFloat(currentEventData.fee) > 0) {
        const paymentMode = form.querySelector('input[name="payment_mode"]:checked');
        if (!paymentMode) {
            alert('Please select a payment method.');
            return false;
        }
        
        // Check receipt upload for non-cash payments
        if (paymentMode.value !== 'cash' && paymentMode.value !== 'free') {
            const receiptInput = form.querySelector('#payment_receipt');
            if (!receiptInput || !receiptInput.files || !receiptInput.files[0]) {
                const paymentMethodName = getPaymentMethodName(paymentMode.value);
                alert(`Please upload your payment receipt for ${paymentMethodName} payment.`);
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

// Helper function to get payment method display name
function getPaymentMethodName(value) {
    const paymentNames = {
        'bank_transfer': 'Bank Transfer',
        'gcash': 'GCash',
        'paymaya': 'PayMaya',
        'credit_card': 'Credit Card',
        'cash': 'Cash Payment'
    };
    return paymentNames[value] || value;
}

// Initialize all payment-related event listeners
function initializePaymentListeners() {
    const paymentModeInputs = document.querySelectorAll('input[name="payment_mode"]');
    paymentModeInputs.forEach(input => {
        input.addEventListener('change', handlePaymentMethodChange);
    });
    
    // Payment receipt upload handling
    const paymentReceiptInput = document.getElementById('payment_receipt');
    if (paymentReceiptInput) {
        paymentReceiptInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }
}

// Enhanced openRegisterModal function
function openRegisterModal(event) {
    // Store current event data
    currentEventData = event;
    
    const eventIdInput = document.getElementById('eventId');
    const modalTitle = document.getElementById('modalTitle');
    
    if (eventIdInput) eventIdInput.value = event.event_id;
    if (modalTitle) modalTitle.textContent = 'Register for ' + event.title;
    
    const eventInfo = document.getElementById('eventInfo');
    if (eventInfo) {
        const eventDate = new Date(event.event_date + 'T00:00:00'); // Force local time interpretation
        
        eventInfo.innerHTML = `
            <div class="event-details">
                <h3>${escapeHtml(event.title)}</h3>
                <p><i class="fas fa-calendar"></i> ${eventDate.toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                <p><i class="fas fa-map-marker-alt"></i> ${escapeHtml(event.location)}</p>
                <p><i class="fas fa-info-circle"></i> ${escapeHtml(event.description || 'No description available')}</p>
                ${event.fee > 0 ? `<p><i class="fas fa-money-bill"></i> Fee: ₱${parseFloat(event.fee).toFixed(2)}</p>` : '<p><i class="fas fa-gift"></i> Free Event</p>'}
            </div>
        `;
    }
    
    // Reset form to individual tab
    switchTab('individual');
    
    // Update payment section based on event fee
    updatePaymentSection();
    
    // Reset payment method selection
    const paymentModeInputs = document.querySelectorAll('input[name="payment_mode"]');
    paymentModeInputs.forEach(input => input.checked = false);
    
    // Hide all payment forms initially
    const paymentForms = document.querySelectorAll('.payment-form');
    paymentForms.forEach(form => {
        form.style.display = 'none';
        form.classList.remove('active');
    });
    
    // Hide payment summary initially
    const paymentSummary = document.getElementById('paymentSummary');
    if (paymentSummary) paymentSummary.style.display = 'none';
    
    // Hide receipt upload initially
    const receiptUpload = document.getElementById('receiptUpload');
    if (receiptUpload) receiptUpload.style.display = 'none';
    
    const modal = document.getElementById('registerModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }
}

function closeRegisterModal() {
    const modal = document.getElementById('registerModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = ''; // Restore scrolling
    }
    
    // Reset current event data
    currentEventData = null;
    
    // Reset form
    const form = document.getElementById('registerForm');
    if (form) {
        form.reset();
        
        // Reset to individual tab
        switchTab('individual');
        
        // Reset file upload containers
        const fileContainers = form.querySelectorAll('.file-upload-container');
        fileContainers.forEach(container => {
            container.classList.remove('has-file');
            const info = container.querySelector('.file-upload-info span');
            const input = container.querySelector('input[type="file"]');
            if (input && input.name === 'valid_id' && info) {
                info.textContent = 'Upload a clear photo of your valid ID';
            } else if (info) {
                info.textContent = 'Upload supporting documents (optional)';
            }
        });
        
        // Reset receipt upload container
        const receiptContainer = form.querySelector('.receipt-upload');
        if (receiptContainer) {
            receiptContainer.classList.remove('has-file');
            const receiptInfo = receiptContainer.querySelector('.upload-text');
            if (receiptInfo) {
                receiptInfo.textContent = 'Upload Payment Receipt';
            }
        }
        
        // Hide payment section
        const paymentSection = document.getElementById('paymentSection');
        if (paymentSection) paymentSection.style.display = 'none';
        
        // Hide all payment forms
        const paymentForms = document.querySelectorAll('.payment-form');
        paymentForms.forEach(form => {
            form.style.display = 'none';
            form.classList.remove('active');
        });
        
        // Hide payment summary
        const paymentSummary = document.getElementById('paymentSummary');
        if (paymentSummary) paymentSummary.style.display = 'none';
        
        // Hide receipt upload
        const receiptUpload = document.getElementById('receiptUpload');
        if (receiptUpload) receiptUpload.style.display = 'none';
        
        // Reset submit button
        const submitBtn = form.querySelector('.btn-submit');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Register for Event';
            submitBtn.disabled = false;
        }
    }
}

function filterStatus(status) {
    const urlParams = new URLSearchParams(window.location.search);
    if (status === 'all') {
        urlParams.delete('status');
    } else {
        urlParams.set('status', status);
    }
    
    // Preserve search parameter if it exists
    const currentSearch = urlParams.get('search');
    if (currentSearch) {
        urlParams.set('search', currentSearch);
    }
    
    window.location.search = urlParams.toString();
}

// Function to show events for a specific day
function showDayEvents(date, events) {
    const eventDate = new Date(date + 'T00:00:00');
    const formattedDate = eventDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    let eventsHtml = `<h4>Events on ${formattedDate}</h4><ul>`;
    events.forEach(event => {
        eventsHtml += `<li><strong>${escapeHtml(event.title)}</strong> - ${escapeHtml(event.location)}</li>`;
    });
    eventsHtml += '</ul>';

    // You can implement a tooltip or small popup here
    // For now, we'll just use an alert as an example
    alert(`Events on ${formattedDate}:\n${events.map(e => `• ${e.title} - ${e.location}`).join('\n')}`);
}

// Utility function to debounce search input
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Add live search functionality
function initializeLiveSearch() {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        const debouncedSearch = debounce(function(value) {
            if (value.length >= 3 || value.length === 0) {
                // Perform search automatically after 3 characters or when cleared
                const form = searchInput.closest('form');
                if (form) {
                    form.submit();
                }
            }
        }, 500);

        searchInput.addEventListener('input', function() {
            debouncedSearch(this.value.trim());
        });
    }
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

// Initialize event listeners when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize payment listeners
    initializePaymentListeners();
    
    // Initialize calendar
    generateCalendar();
    
    // Initialize live search
    initializeLiveSearch();
    
    // Close modal when clicking outside
    const modal = document.getElementById('registerModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeRegisterModal();
            }
        });
    }

    // Close calendar modal when clicking outside
    const calendarModal = document.getElementById('calendarModal');
    if (calendarModal) {
        calendarModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeCalendarModal();
            }
        });
    }

    // File upload event listeners
    const validIdInput = document.getElementById('valid_id');
    if (validIdInput) {
        validIdInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    const documentsInput = document.getElementById('documents');
    if (documentsInput) {
        documentsInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    const receiptInput = document.getElementById('payment_receipt');
    if (receiptInput) {
        receiptInput.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }

    // Form submission handling
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
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
                submitBtn.disabled = true;
            }
        });
    }

    // Search form handling
    const searchForm = document.querySelector('.search-box');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            const searchInput = this.querySelector('input[name="search"]');
            if (searchInput && !searchInput.value.trim()) {
                e.preventDefault();
                // If search is empty, just remove the search parameter
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.delete('search');
                window.location.search = urlParams.toString();
            }
        });
    }

    // Keyboard navigation for modals
    document.addEventListener('keydown', function(e) {
        const registerModal = document.getElementById('registerModal');
        const calendarModal = document.getElementById('calendarModal');
        
        if (calendarModal && calendarModal.classList.contains('active')) {
            if (e.key === 'Escape') {
                closeCalendarModal();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                changeMonth(-1);
            } else if (e.key === 'ArrowRight') {
                e.preventDefault();
                changeMonth(1);
            }
        } else if (registerModal && registerModal.classList.contains('active')) {
            if (e.key === 'Escape') {
                closeRegisterModal();
            }
        }
    });

    // Auto-resize textareas if any
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });

    // Calendar day click handling for small calendar
    document.addEventListener('click', function(e) {
        const dayCell = e.target.closest('.day-cell.has-events');
        if (dayCell) {
            const date = dayCell.getAttribute('data-date');
            if (date) {
                // Get events for this date from available data sources
                let dayEvents = [];
                
                if (typeof window.calendarEventsData !== 'undefined') {
                    dayEvents = window.calendarEventsData.filter(event => event.event_date === date);
                } else if (typeof window.calendarEvents !== 'undefined') {
                    dayEvents = window.calendarEvents.filter(event => event.event_date === date);
                }
                
                if (dayEvents.length > 0) {
                    showDayEvents(date, dayEvents);
                }
            }
        }
    });

    // Enhanced calendar day hover for better UX
    document.addEventListener('mouseover', function(e) {
        const dayCell = e.target.closest('.day-cell.has-events');
        if (dayCell && !dayCell.classList.contains('tooltip-shown')) {
            const indicators = dayCell.querySelectorAll('.event-indicator');
            indicators.forEach((indicator, index) => {
                setTimeout(() => {
                    indicator.style.transform = 'scale(1.2)';
                }, index * 50);
            });
            dayCell.classList.add('tooltip-shown');
        }
    });

    document.addEventListener('mouseout', function(e) {
        const dayCell = e.target.closest('.day-cell.has-events');
        if (dayCell && dayCell.classList.contains('tooltip-shown')) {
            const indicators = dayCell.querySelectorAll('.event-indicator');
            indicators.forEach(indicator => {
                indicator.style.transform = 'scale(1)';
            });
            dayCell.classList.remove('tooltip-shown');
        }
    });

    // Console log for debugging calendar data
    console.log('Calendar Events Data:', typeof window.calendarEventsData !== 'undefined' ? window.calendarEventsData : 'Not available');
    console.log('User Registrations:', typeof window.userRegistrations !== 'undefined' ? window.userRegistrations : 'Not available');
    console.log('Calendar Events:', typeof window.calendarEvents !== 'undefined' ? window.calendarEvents : 'Not available');
});
    </script>
</body>
</html>