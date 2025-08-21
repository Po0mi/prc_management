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
    $paymentMode = trim($_POST['payment_mode']);

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
            }
        }

        // Handle additional documents upload
        if (isset($_FILES['documents']) && $_FILES['documents']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $fileType = $_FILES['documents']['type'];
            
            if (in_array($fileType, $allowedTypes)) {
                $fileExtension = pathinfo($_FILES['documents']['name'], PATHINFO_EXTENSION);
                $fileName = 'documents_' . time() . '.' . $fileExtension;
                $documentsPath = $userFolder . '/' . $fileName;
                
                if (move_uploaded_file($_FILES['documents']['tmp_name'], $documentsPath)) {
                    $documentsPath = 'uploads/user_' . $userId . '/' . $fileName;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO registrations (event_id, user_id, registration_date, full_name, email, age, payment_mode, valid_id_path, documents_path, status)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$eventId, $userId, $fullName, $email, $age, $paymentMode, $validIdPath, $documentsPath]);
        $regMessage = "You have successfully registered. Your documents have been uploaded. Awaiting confirmation.";
    } else {
        $regMessage = "You are already registered for this event.";
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
           r.valid_id_path, r.documents_path, r.status, r.registration_date, e.title, e.event_date
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

// Get events for calendar (next 3 months)
$calendarEvents = $pdo->query("
    SELECT event_id, title, event_date, location
    FROM events 
    WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
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

            <!-- Calendar Sidebar -->
            <div class="calendar-sidebar">
                <div class="calendar-header">
                    <h2><i class="fas fa-calendar-alt"></i> Events Calendar</h2>
                </div>
                <div class="calendar-container" id="calendarContainer">
                    <!-- Calendar will be generated by JavaScript -->
                </div>
            </div>
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
              <input type="radio" name="payment_mode" value="check" id="check">
              <div class="payment-card">
                <div class="payment-icon check">
                  <i class="fas fa-money-check"></i>
                </div>
                <div class="payment-details">
                  <div class="payment-name">Check Payment</div>
                  <div class="payment-description">Pay via company check</div>
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

          <!-- Check Payment Form -->
          <div class="payment-form" id="check_form">
            <h5><i class="fas fa-money-check"></i> Check Payment Details</h5>
            <div class="payment-note">
              <i class="fas fa-info-circle"></i>
              <div class="payment-note-content">
                <strong>Important Note:</strong>
                <p>Please make the check payable to "Philippine Red Cross - Tacloban Chapter" and deliver to our office.</p>
              </div>
            </div>
            <div class="bank-details">
              <div class="bank-info">
                <div class="bank-field">
                  <label>Payable To</label>
                  <span>Philippine Red Cross - Tacloban Chapter</span>
                </div>
                <div class="bank-field">
                  <label>Office Address</label>
                  <span>123 Remedios Street, Tacloban City</span>
                </div>
                <div class="bank-field">
                  <label>Business Hours</label>
                  <span>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</span>
                </div>
              </div>
            </div>
            <div class="payment-instructions">
              <h6><i class="fas fa-info-circle"></i> Instructions</h6>
              <ol>
                <li>Write a check for the exact amount</li>
                <li>Make it payable to "Philippine Red Cross - Tacloban Chapter"</li>
                <li>Write the event name in the memo line</li>
                <li>Deliver the check to our office during business hours</li>
                <li>Upload a photo of the check below for our records</li>
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
    <script src="/js/register.js"></script>
    <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <script src="js/darkmode.js?v=<?php echo time(); ?>"></script>
    <script src="js/header.js?v=<?php echo time(); ?>"></script>
    <script>
        // Calendar events data
        const calendarEvents = <?= json_encode($calendarEvents) ?>;
        
        // Global variable to store current event data
        let currentEventData = null;

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
                document.getElementById('registration_type_individual').disabled = false;
                document.getElementById('registration_type_organization').disabled = true;
                
                // Clear organization fields
                document.getElementById('organization_name').value = '';
                document.getElementById('contact_person').value = '';
                document.getElementById('contact_email').value = '';
                document.getElementById('pax_count').value = '';
                
                // Make individual fields required
                document.getElementById('full_name').required = true;
                document.getElementById('email').required = true;
                document.getElementById('age').required = true;
                document.getElementById('location').required = true;
                
                // Make organization fields not required
                document.getElementById('organization_name').required = false;
                document.getElementById('contact_person').required = false;
                document.getElementById('contact_email').required = false;
                document.getElementById('pax_count').required = false;
                
            } else if (tabName === 'organization') {
                document.getElementById('registration_type_individual').disabled = true;
                document.getElementById('registration_type_organization').disabled = false;
                
                // Clear individual fields
                document.getElementById('full_name').value = '';
                document.getElementById('email').value = '';
                document.getElementById('age').value = '';
                document.getElementById('location').value = '';
                
                // Make organization fields required
                document.getElementById('organization_name').required = true;
                document.getElementById('contact_person').required = true;
                document.getElementById('contact_email').required = true;
                document.getElementById('pax_count').required = true;
                
                // Make individual fields not required
                document.getElementById('full_name').required = false;
                document.getElementById('email').required = false;
                document.getElementById('age').required = false;
                document.getElementById('location').required = false;
            }
            
            // Update payment calculation if event has fee
            updatePaymentSection();
        }

        // Payment section visibility and calculation
        function updatePaymentSection() {
            if (!currentEventData) return;
            
            const paymentSection = document.getElementById('paymentSection');
            const eventFee = parseFloat(currentEventData.fee) || 0;
            
            if (eventFee > 0) {
                // Show payment section for paid events
                paymentSection.style.display = 'block';
                
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
                paymentSection.style.display = 'none';
                
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
            if (receiptUpload) {
                // Show receipt upload for all payment methods except cash
                if (paymentValue === 'cash') {
                    receiptUpload.style.display = 'none';
                    const receiptInput = document.getElementById('payment_receipt');
                    if (receiptInput) receiptInput.required = false;
                } else {
                    receiptUpload.style.display = 'block';
                    const receiptInput = document.getElementById('payment_receipt');
                    if (receiptInput) receiptInput.required = true;
                }
            }
            
            // Update payment summary
            updatePaymentSummary(paymentValue);
        }

        // Update payment summary
        function updatePaymentSummary(paymentMethod) {
            const paymentSummary = document.getElementById('paymentSummary');
            const selectedPaymentMethodSpan = document.getElementById('selectedPaymentMethod');
            const summaryEventFee = document.getElementById('summaryEventFee');
            const summaryTotalAmount = document.getElementById('summaryTotalAmount');
            
            if (!currentEventData) return;
            
            const eventFee = parseFloat(currentEventData.fee) || 0;
            
            if (eventFee > 0 && paymentMethod) {
                paymentSummary.style.display = 'block';
                
                // Payment method names
                const paymentNames = {
                    'bank_transfer': 'Bank Transfer',
                    'gcash': 'GCash',
                    'paymaya': 'PayMaya',
                    'credit_card': 'Credit Card',
                    'check': 'Check Payment',
                    'cash': 'Cash Payment'
                };
                
                if (selectedPaymentMethodSpan) {
                    selectedPaymentMethodSpan.textContent = paymentNames[paymentMethod] || paymentMethod;
                }
                if (summaryEventFee) {
                    summaryEventFee.textContent = `₱${eventFee.toFixed(2)}`;
                }
                if (summaryTotalAmount) {
                    summaryTotalAmount.textContent = `₱${eventFee.toFixed(2)}`;
                }
            } else {
                paymentSummary.style.display = 'none';
            }
        }

        // Initialize payment method event listeners
        function initializePaymentListeners() {
            const paymentModeInputs = document.querySelectorAll('input[name="payment_mode"]');
            paymentModeInputs.forEach(input => {
                input.addEventListener('change', handlePaymentMethodChange);
            });
        }
        
        // Enhanced openRegisterModal function
        function openRegisterModal(event) {
            // Store current event data
            currentEventData = event;
            
            document.getElementById('eventId').value = event.event_id;
            document.getElementById('modalTitle').textContent = 'Register for ' + event.title;
            
            const eventInfo = document.getElementById('eventInfo');
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
            
            document.getElementById('registerModal').classList.add('active');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }
        
        function closeRegisterModal() {
            document.getElementById('registerModal').classList.remove('active');
            document.body.style.overflow = ''; // Restore scrolling
            
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
                    if (input && input.name === 'valid_id') {
                        info.textContent = 'Upload a clear photo of your valid ID';
                    } else if (info) {
                        info.textContent = 'Upload supporting documents (optional)';
                    }
                });
                
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
                const dayEvents = typeof calendarEvents !== 'undefined' ? 
                    calendarEvents.filter(event => event.event_date === dateStr) : [];
                
                let dayClass = 'day-cell';
                if (dayEvents.length > 0) {
                    dayClass += ' has-events';
                }
                if (dateStr === todayStr) {
                    dayClass += ' today';
                }
                if (date < today && dateStr !== todayStr) {
                    dayClass += ' past';
                }
                
                html += `<div class="${dayClass}" data-date="${dateStr}" title="${dayEvents.length > 0 ? dayEvents.map(e => e.title).join(', ') : ''}">
                    <span class="day-number">${day}</span>`;
                
                if (dayEvents.length > 0) {
                    html += '<div class="event-indicators">';
                    dayEvents.slice(0, 3).forEach(event => { // Limit to 3 indicators per day
                        html += `<div class="event-indicator" title="${escapeHtml(event.title)} - ${escapeHtml(event.location)}"></div>`;
                    });
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
            const container = inputElement.closest('.file-upload-container');
            const info = container.querySelector('.file-upload-info span');
            
            if (inputElement.files && inputElement.files[0]) {
                const file = inputElement.files[0];
                const maxSize = inputElement.name === 'valid_id' ? 5 * 1024 * 1024 : 10 * 1024 * 1024; // 5MB for ID, 10MB for documents
                
                // Check file size
                if (file.size > maxSize) {
                    alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
                    inputElement.value = '';
                    return;
                }
                
                // Check file type
                const allowedTypes = inputElement.name === 'valid_id' ? 
                    ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'] :
                    ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                
                if (!allowedTypes.includes(file.type)) {
                    alert('Invalid file type. Please upload a supported file format.');
                    inputElement.value = '';
                    return;
                }
                
                container.classList.add('has-file');
                info.textContent = `Selected: ${file.name}`;
            } else {
                container.classList.remove('has-file');
                if (inputElement.name === 'valid_id') {
                    info.textContent = 'Upload a clear photo of your valid ID';
                } else {
                    info.textContent = 'Upload supporting documents (optional)';
                }
            }
        }
        
        // Enhanced form validation
        function validateForm(form) {
            const activeTab = document.querySelector('.tab-content.active');
            const isIndividual = activeTab && activeTab.id === 'individual-tab';
            
            if (isIndividual) {
                const fullName = form.querySelector('#full_name');
                const email = form.querySelector('#email');
                const age = form.querySelector('#age');
                const location = form.querySelector('#location');
                
                if (!fullName.value.trim()) {
                    alert('Please enter your full name.');
                    fullName.focus();
                    return false;
                }
                
                if (!email.value.trim()) {
                    alert('Please enter your email address.');
                    email.focus();
                    return false;
                }
                
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email.value.trim())) {
                    alert('Please enter a valid email address.');
                    email.focus();
                    return false;
                }
                
                if (!age.value || age.value < 1 || age.value > 120) {
                    alert('Please enter a valid age (1-120).');
                    age.focus();
                    return false;
                }
                
                if (!location.value.trim()) {
                    alert('Please enter your location.');
                    location.focus();
                    return false;
                }
            } else {
                // Organization validation
                const orgName = form.querySelector('#organization_name');
                const contactPerson = form.querySelector('#contact_person');
                const contactEmail = form.querySelector('#contact_email');
                const paxCount = form.querySelector('#pax_count');
                
                if (!orgName.value.trim()) {
                    alert('Please enter the organization/company name.');
                    orgName.focus();
                    return false;
                }
                
                if (!contactPerson.value.trim()) {
                    alert('Please enter the contact person name.');
                    contactPerson.focus();
                    return false;
                }
                
                if (!contactEmail.value.trim()) {
                    alert('Please enter the contact email.');
                    contactEmail.focus();
                    return false;
                }
                
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(contactEmail.value.trim())) {
                    alert('Please enter a valid contact email address.');
                    contactEmail.focus();
                    return false;
                }
                
                if (!paxCount.value || paxCount.value < 1) {
                    alert('Please enter the number of participants.');
                    paxCount.focus();
                    return false;
                }
            }
            
            // Check payment method for paid events
            if (currentEventData && parseFloat(currentEventData.fee) > 0) {
                const paymentMode = form.querySelector('input[name="payment_mode"]:checked');
                if (!paymentMode) {
                    alert('Please select a payment method.');
                    return false;
                }
                
                // Check receipt upload for non-cash payments
                if (paymentMode.value !== 'cash') {
                    const receiptInput = form.querySelector('#payment_receipt');
                    if (!receiptInput.files || !receiptInput.files[0]) {
                        alert('Please upload your payment receipt.');
                        receiptInput.focus();
                        return false;
                    }
                }
            }
            
            // Check valid ID upload
            const validId = form.querySelector('#valid_id');
            if (!validId.files || !validId.files[0]) {
                alert('Please upload a valid ID.');
                validId.focus();
                return false;
            }
            
            return true;
        }
        
        // Initialize event listeners when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize payment listeners
            initializePaymentListeners();
            
            // Initialize calendar
            generateCalendar();
            
            // Close modal when clicking outside
            const modal = document.getElementById('registerModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeRegisterModal();
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

            // Keyboard navigation for modal
            document.addEventListener('keydown', function(e) {
                const modal = document.getElementById('registerModal');
                if (modal && modal.classList.contains('active')) {
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

            // Calendar day click handling
            document.addEventListener('click', function(e) {
                if (e.target.closest('.day-cell.has-events')) {
                    const dayCell = e.target.closest('.day-cell');
                    const date = dayCell.getAttribute('data-date');
                    if (date && typeof calendarEvents !== 'undefined') {
                        const dayEvents = calendarEvents.filter(event => event.event_date === date);
                        if (dayEvents.length > 0) {
                            showDayEvents(date, dayEvents);
                        }
                    }
                }
            });
        });

        // Function to show events for a specific day (optional enhancement)
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

        // Add live search functionality (optional)
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
    </script>
</body>
</html>