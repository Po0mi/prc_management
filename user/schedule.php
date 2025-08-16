<?php
// /user/schedule.php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
if (current_user_role() !== 'user') {
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
                }
            }
        }

        // Handle requirements/documents upload
        if (isset($_FILES['requirements']) && $_FILES['requirements']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $fileType = $_FILES['requirements']['type'];
            
            if (in_array($fileType, $allowedTypes)) {
                $fileExtension = pathinfo($_FILES['requirements']['name'], PATHINFO_EXTENSION);
                $fileName = 'requirements_' . time() . '.' . $fileExtension;
                $documentsPath = $userFolder . '/' . $fileName;
                
                if (move_uploaded_file($_FILES['requirements']['tmp_name'], $documentsPath)) {
                    $documentsPath = 'uploads/training_user_' . $userId . '/' . $fileName;
                }
            }
        }

        if ($registrationType === 'individual') {
            $fullName = trim($_POST['full_name']);
            $location = trim($_POST['location']);
            $age = (int)$_POST['age'];
            $rcyStatus = trim($_POST['rcy_status']);
            $trainingType = trim($_POST['training_type']);
            $trainingDate = $_POST['training_date'];

            $stmt = $pdo->prepare("
                INSERT INTO session_registrations (
                    session_id, user_id, email, registration_type, full_name, location, age, 
                    rcy_status, training_type, training_date, valid_id_path, requirements_path, 
                    registration_date, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ");
            $stmt->execute([
                $sessionId, $userId, $userEmail, $registrationType, $fullName, $location, $age,
                $rcyStatus, $trainingType, $trainingDate, $validIdPath, $documentsPath
            ]);
        } else { // organization
            $organizationName = trim($_POST['organization_name']);
            $trainingType = trim($_POST['training_type']);
            $trainingDate = $_POST['training_date'];
            $paxCount = (int)$_POST['pax_count'];

            $stmt = $pdo->prepare("
                INSERT INTO session_registrations (
                    session_id, user_id, email, registration_type, organization_name, 
                    training_type, training_date, pax_count, valid_id_path, requirements_path, 
                    registration_date, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')
            ");
            $stmt->execute([
                $sessionId, $userId, $userEmail, $registrationType, $organizationName,
                $trainingType, $trainingDate, $paxCount, $validIdPath, $documentsPath
            ]);
        }
        
        $regMessage = "You have successfully registered for the training session. Your documents have been uploaded. Awaiting confirmation.";
    } else {
        $regMessage = "You are already registered for this training session.";
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

// Build query with filters
$whereConditions = [];
$params = [];

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

$sessionsByService = [];
foreach ($allSessions as $session) {
    $service = $session['major_service'];
    if (!isset($sessionsByService[$service])) {
        $sessionsByService[$service] = [];
    }
    $sessionsByService[$service][] = $session;
}

// Get statistics
$upcoming = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date >= CURDATE()")->fetchColumn();
$past = $pdo->query("SELECT COUNT(*) FROM training_sessions WHERE session_date < CURDATE()")->fetchColumn();
$total_sessions = $upcoming + $past;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM session_registrations WHERE user_id = ?");
$stmt->execute([$userId]);
$registered = $stmt->fetchColumn();

// Get user's registrations
$userRegistrations = $pdo->prepare("
    SELECT sr.*, ts.title, ts.session_date, ts.major_service
    FROM session_registrations sr
    JOIN training_sessions ts ON sr.session_id = ts.session_id
    WHERE sr.user_id = ?
    ORDER BY ts.session_date ASC
");
$userRegistrations->execute([$userId]);
$myRegistrations = $userRegistrations->fetchAll();

// Get statistics for my registrations
$pending_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'pending'; }));
$approved_registrations = count(array_filter($myRegistrations, function($reg) { return $reg['status'] === 'approved'; }));
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
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="header-content">
    <?php include 'header.php'; ?>
    
    <div class="admin-content">
      <div class="sessions-container">
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
        <?php foreach ($majorServices as $service): ?>
          <?php if (!empty($sessionsByService[$service])): ?>
            <div class="service-section">
              <div class="section-header">
                <h2><i class="fas fa-first-aid"></i> <?= htmlspecialchars($service) ?></h2>
              </div>
              
              <div class="sessions-table-container">
                <table class="sessions-table">
                  <thead>
                    <tr>
                      <th>Training Details</th>
                      <th>Date & Time</th>
                      <th>Venue</th>
                      <th>Capacity</th>
                      <th>Fee</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($sessionsByService[$service] as $s): 
                      $sessionDate = strtotime($s['session_date']);
                      $today = strtotime('today');
                      $isFull = $s['capacity'] > 0 && $s['registered_count'] >= $s['capacity'];
                      $isRegistered = $s['is_registered'];
                    ?>
                      <tr>
                        <td>
                          <div class="training-title"><?= htmlspecialchars($s['title']) ?></div>
                          <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                            <?= htmlspecialchars($s['description'] ?? 'No description available') ?>
                          </div>
                        </td>
                        <td>
                          <div class="training-date">
                            <span><?= date('M d, Y', $sessionDate) ?></span><br>
                            <small><?= date('g:i A', strtotime($s['start_time'])) ?> - <?= date('g:i A', strtotime($s['end_time'])) ?></small>
                          </div>
                        </td>
                        <td><?= htmlspecialchars($s['venue']) ?></td>
                        <td>
                          <div class="capacity-badge <?= $isFull ? 'full' : '' ?>">
                            <i class="fas fa-users"></i>
                            <?= $s['registered_count'] ?> / <?= $s['capacity'] ?: '∞' ?>
                            <?php if ($isFull): ?>
                              <span style="font-size: 0.7rem; background: var(--prc-red); color: white; padding: 0.2rem 0.4rem; border-radius: 4px;">FULL</span>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td>
                          <div style="font-weight: 600;">
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
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (empty($allSessions)): ?>
          <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>No training sessions found</h3>
            <p><?= $search ? 'Try adjusting your search criteria' : 'There are currently no training sessions available.' ?></p>
          </div>
        <?php endif; ?>

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
                    <th>Date</th>
                    <th>Type</th>
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
                    <td><?= date('M d, Y', strtotime($r['session_date'])) ?></td>
                    <td>
                      <span class="type-badge <?= $r['registration_type'] ?>">
                        <?= ucfirst($r['registration_type']) ?>
                      </span>
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
          <h2 class="modal-title" id="modalTitle">Register for Training</h2>
          <button class="close-modal" onclick="closeRegisterModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <form method="POST" id="registerForm" enctype="multipart/form-data">
          <input type="hidden" name="register_training" value="1">
          <input type="hidden" name="session_id" id="sessionId">
          
          <div class="training-info" id="trainingInfo">
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
              <input type="hidden" name="registration_type" value="individual">
              
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
                  <select id="training_type_individual" name="training_type" required>
                    <option value="">Select training type</option>
                    <option value="Basic First Aid">Basic First Aid</option>
                    <option value="CPR">CPR</option>
                    <option value="Disaster Preparedness">Disaster Preparedness</option>
                    <option value="Water Safety">Water Safety</option>
                    <option value="Community Health">Community Health</option>
                    <option value="Leadership Training">Leadership Training</option>
                    <option value="Other">Other</option>
                  </select>
                </div>
                
                <div class="form-group">
                  <label for="training_date_individual">Preferred Training Date *</label>
                  <input type="date" id="training_date_individual" name="training_date" required min="<?= date('Y-m-d') ?>">
                </div>
              </div>
            </div>
            
            <!-- Organization Tab -->
            <div class="tab-content" id="organization-tab">
              <input type="hidden" name="registration_type" value="organization">
              
              <div class="form-group">
                <label for="organization_name">Organization/Company Name *</label>
                <input type="text" id="organization_name" name="organization_name" placeholder="Enter organization/company name">
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label for="training_type_org">Training Type *</label>
                  <select id="training_type_org" name="training_type">
                    <option value="">Select training type</option>
                    <option value="Corporate First Aid">Corporate First Aid</option>
                    <option value="Workplace Safety">Workplace Safety</option>
                    <option value="Emergency Response">Emergency Response</option>
                    <option value="Team Building">Team Building</option>
                    <option value="Leadership Development">Leadership Development</option>
                    <option value="Custom Training">Custom Training</option>
                  </select>
                </div>
                
                <div class="form-group">
                  <label for="training_date_org">Preferred Training Date *</label>
                  <input type="date" id="training_date_org" name="training_date" min="<?= date('Y-m-d') ?>">
                </div>
              </div>
              
              <div class="form-group">
                <label for="pax_count">Number of Participants *</label>
                <input type="number" id="pax_count" name="pax_count" min="1" placeholder="Enter number of participants">
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
  <script src="js/darkmode.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
  <script>
    function openRegisterModal(session) {
      document.getElementById('sessionId').value = session.session_id;
      document.getElementById('modalTitle').textContent = 'Register for ' + session.title;
      
      const trainingInfo = document.getElementById('trainingInfo');
      trainingInfo.innerHTML = `
        <div class="training-details">
          <h3>${session.title}</h3>
          <p><i class="fas fa-calendar"></i> ${new Date(session.session_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</p>
          <p><i class="fas fa-clock"></i> ${formatTime(session.start_time)} - ${formatTime(session.end_time)}</p>
          <p><i class="fas fa-map-marker-alt"></i> ${session.venue}</p>
          <p><i class="fas fa-tag"></i> ${session.major_service}</p>
          ${session.fee > 0 ? `<p><i class="fas fa-money-bill"></i> Fee: ₱${parseFloat(session.fee).toFixed(2)}</p>` : '<p><i class="fas fa-gift"></i> Free Training</p>'}
        </div>
      `;
      
      document.getElementById('registerModal').classList.add('active');
    }
    
    function closeRegisterModal() {
      document.getElementById('registerModal').classList.remove('active');
    }
    
    function switchTab(tabName) {
      // Update tab buttons
      document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
      
      // Update tab content
      document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
      document.getElementById(`${tabName}-tab`).classList.add('active');
      
      // Update required fields based on tab
      if (tabName === 'individual') {
        document.querySelector('input[name="registration_type"]').value = 'individual';
        document.getElementById('full_name').required = true;
        document.getElementById('location').required = true;
        document.getElementById('age').required = true;
        document.getElementById('rcy_status').required = true;
        document.getElementById('training_type_individual').required = true;
        document.getElementById('training_date_individual').required = true;
        
        document.getElementById('organization_name').required = false;
        document.getElementById('training_type_org').required = false;
        document.getElementById('training_date_org').required = false;
        document.getElementById('pax_count').required = false;
      } else {
        document.querySelector('input[name="registration_type"]').value = 'organization';
        document.getElementById('organization_name').required = true;
        document.getElementById('training_type_org').required = true;
        document.getElementById('training_date_org').required = true;
        document.getElementById('pax_count').required = true;
        
        document.getElementById('full_name').required = false;
        document.getElementById('location').required = false;
        document.getElementById('age').required = false;
        document.getElementById('rcy_status').required = false;
        document.getElementById('training_type_individual').required = false;
        document.getElementById('training_date_individual').required = false;
      }
    }
    
    function filterService(service) {
      const urlParams = new URLSearchParams(window.location.search);
      if (service === 'all') {
        urlParams.delete('service');
      } else {
        urlParams.set('service', service);
      }
      window.location.search = urlParams.toString();
    }
    
    function formatTime(timeString) {
      const time = new Date('1970-01-01T' + timeString + 'Z');
      return time.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      });
    }
    
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
          info.textContent = 'Upload requirements/supporting documents';
        }
      }
    }

    // Add event listeners for file inputs
    document.getElementById('valid_id').addEventListener('change', function() {
      handleFileUpload(this);
    });

    document.getElementById('requirements').addEventListener('change', function() {
      handleFileUpload(this);
    });

    // Form validation
    document.getElementById('registerForm').addEventListener('submit', function(e) {
      const validId = document.getElementById('valid_id');
      const registrationType = document.querySelector('input[name="registration_type"]').value;

      if (!validId.files || !validId.files[0]) {
        e.preventDefault();
        alert('Please upload a valid ID.');
        return;
      }

      if (registrationType === 'individual') {
        const age = document.getElementById('age');
        if (age.value < 1 || age.value > 120) {
          e.preventDefault();
          alert('Please enter a valid age (1-120).');
          return;
        }
      } else if (registrationType === 'organization') {
        const paxCount = document.getElementById('pax_count');
        if (paxCount.value < 1) {
          e.preventDefault();
          alert('Please enter a valid number of participants (minimum 1).');
          return;
        }
      }

      // Show loading state
      const submitBtn = this.querySelector('.btn-submit');
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
      submitBtn.disabled = true;
    });

    // Close modal when clicking outside
    document.getElementById('registerModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeRegisterModal();
      }
    });

    // Initialize individual tab as default
    switchTab('individual');
  </script>
</body>
</html>