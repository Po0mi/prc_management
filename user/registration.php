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
        <div class="events-container">
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
                
                <button class="btn-view-calendar" onclick="toggleCalendar()">
                    <i class="fas fa-calendar"></i> View Calendar
                </button>
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

            <!-- Calendar Modal -->
            <div class="calendar-modal" id="calendarModal">
                <div class="calendar-content">
                    <div class="calendar-header">
                        <h2><i class="fas fa-calendar-alt"></i> Events Calendar</h2>
                        <button class="close-calendar" onclick="toggleCalendar()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="calendar-container" id="calendarContainer">
                        <!-- Calendar will be generated by JavaScript -->
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
                                        <div style="font-weight: 600;">
                                            <?= $e['fee'] > 0 ? '₱' . number_format($e['fee'], 2) : 'Free' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($isRegistered): ?>
                                            <span class="status-badge <?= $e['user_status'] ?>">
                                                <?= ucfirst($e['user_status']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge available">Available</span>
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
    </div>

    <!-- Registration Modal -->
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
                
                <div class="event-info" id="eventInfo">
                    <!-- Event details will be populated by JavaScript -->
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="Enter your full name">
                    </div>
                    
                    <div class="form-group">
                        <label for="age">Age *</label>
                        <input type="number" id="age" name="age" required min="1" max="120" placeholder="Enter your age">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>
                
                <div class="form-group">
                    <label for="payment_mode">Mode of Payment *</label>
                    <select id="payment_mode" name="payment_mode" required>
                        <option value="">Select payment method</option>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="gcash">GCash</option>
                        <option value="paymaya">PayMaya</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="check">Check</option>
                    </select>
                </div>
                
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
        
        function openRegisterModal(event) {
            document.getElementById('eventId').value = event.event_id;
            document.getElementById('modalTitle').textContent = 'Register for ' + event.title;
            
            const eventInfo = document.getElementById('eventInfo');
            eventInfo.innerHTML = `
                <div class="event-details">
                    <h3>${event.title}</h3>
                    <p><i class="fas fa-calendar"></i> ${new Date(event.event_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
                    <p><i class="fas fa-map-marker-alt"></i> ${event.location}</p>
                    <p><i class="fas fa-info-circle"></i> ${event.description || 'No description available'}</p>
                    ${event.fee > 0 ? `<p><i class="fas fa-money-bill"></i> Fee: ₱${parseFloat(event.fee).toFixed(2)}</p>` : '<p><i class="fas fa-gift"></i> Free Event</p>'}
                </div>
            `;
            
            document.getElementById('registerModal').classList.add('active');
        }
        
        function closeRegisterModal() {
            document.getElementById('registerModal').classList.remove('active');
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
        
        function toggleCalendar() {
            const modal = document.getElementById('calendarModal');
            if (modal.classList.contains('active')) {
                modal.classList.remove('active');
            } else {
                generateCalendar();
                modal.classList.add('active');
            }
        }
        
        function generateCalendar() {
            const container = document.getElementById('calendarContainer');
            const today = new Date();
            const currentMonth = today.getMonth();
            const currentYear = today.getFullYear();
            
            let calendarHTML = '';
            
            // Generate 3 months starting from current month
            for (let monthOffset = 0; monthOffset < 3; monthOffset++) {
                const month = (currentMonth + monthOffset) % 12;
                const year = currentYear + Math.floor((currentMonth + monthOffset) / 12);
                
                calendarHTML += generateMonthCalendar(year, month);
            }
            
            container.innerHTML = calendarHTML;
        }
        
        function generateMonthCalendar(year, month) {
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            
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
                const dateStr = date.toISOString().split('T')[0];
                const dayEvents = calendarEvents.filter(event => event.event_date === dateStr);
                
                let dayClass = 'day-cell';
                if (dayEvents.length > 0) {
                    dayClass += ' has-events';
                }
                
                html += `<div class="${dayClass}" data-date="${dateStr}">
                    <span class="day-number">${day}</span>`;
                
                if (dayEvents.length > 0) {
                    html += '<div class="event-indicators">';
                    dayEvents.forEach(event => {
                        html += `<div class="event-indicator" title="${event.title} - ${event.location}"></div>`;
                    });
                    html += '</div>';
                }
                
                html += '</div>';
            }
            
            html += '</div></div>';
            return html;
        }
        
        // Close modals when clicking outside
        document.getElementById('registerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRegisterModal();
            }
        });
        
        document.getElementById('calendarModal').addEventListener('click', function(e) {
            if (e.target === this) {
                toggleCalendar();
            }
        });

        // File upload handling
        function handleFileUpload(inputElement) {
            const container = inputElement.closest('.file-upload-container');
            const info = container.querySelector('.file-upload-info span');
            
            if (inputElement.files && inputElement.files[0]) {
                const file = inputElement.files[0];
                const maxSize = inputElement.name === 'valid_id' ? 5 * 1024 * 1024 : 10 * 1024 * 1024; // 5MB for ID, 10MB for documents
                
                if (file.size > maxSize) {
                    alert(`File size too large. Maximum allowed: ${maxSize / (1024 * 1024)}MB`);
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

        // Add event listeners for file inputs
        document.getElementById('valid_id').addEventListener('change', function() {
            handleFileUpload(this);
        });

        document.getElementById('documents').addEventListener('change', function() {
            handleFileUpload(this);
        });

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const validId = document.getElementById('valid_id');
            const age = document.getElementById('age');
            const paymentMode = document.getElementById('payment_mode');

            if (!validId.files || !validId.files[0]) {
                e.preventDefault();
                alert('Please upload a valid ID.');
                return;
            }

            if (age.value < 1 || age.value > 120) {
                e.preventDefault();
                alert('Please enter a valid age (1-120).');
                return;
            }

            if (!paymentMode.value) {
                e.preventDefault();
                alert('Please select a payment method.');
                return;
            }

            // Show loading state
            const submitBtn = this.querySelector('.btn-submit');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>