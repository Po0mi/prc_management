<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Initialize statistics variables to prevent warnings
$total_donations = 0;
$completed_count = 0;
$blood_count = 0;
$pending_count = 0;

// Enhanced deletion handler with better security
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_donation'])) {
    $donationId = filter_var($_POST['donation_id'], FILTER_VALIDATE_INT);
    $donationType = filter_var($_POST['donation_type'], FILTER_SANITIZE_STRING);
    
    if (!$donationId || !in_array($donationType, ['monetary', 'blood', 'in_kind'])) {
        $errorMessage = "Invalid donation parameters.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Delete related approval logs first
            $logStmt = $pdo->prepare("DELETE FROM donation_approval_log WHERE donation_id = ? AND donation_type = ?");
            $logStmt->execute([$donationId, $donationType]);
            
            // Delete the main donation record
            $table_map = [
                'monetary' => 'donations',
                'blood' => 'blood_donations', 
                'in_kind' => 'in_kind_donations'
            ];
            
            $stmt = $pdo->prepare("DELETE FROM {$table_map[$donationType]} WHERE donation_id = ?");
            $result = $stmt->execute([$donationId]);
            
            if ($result && $stmt->rowCount() > 0) {
                $pdo->commit();
                $successMessage = "Donation deleted successfully.";
            } else {
                $pdo->rollback();
                $errorMessage = "Donation not found or already deleted.";
            }
        } catch (Exception $e) {
            $pdo->rollback();
            $errorMessage = "Error deleting donation: " . $e->getMessage();
        }
    }
}

// Enhanced update handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_donation'])) {
    $donationId = filter_var($_POST['donation_id'], FILTER_VALIDATE_INT);
    $donationType = filter_var($_POST['donation_type'], FILTER_SANITIZE_STRING);
    $donorId = filter_var($_POST['donor_id'], FILTER_VALIDATE_INT);
    $donationDate = $_POST['donation_date'];
    $status = $_POST['status'];

    if (!$donationId || !$donorId || !$donationDate || !$status) {
        $errorMessage = "All required fields must be filled.";
    } else {
        try {
            $updated = false;
            
            if ($donationType === 'monetary') {
                $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
                $paymentMethod = filter_var($_POST['payment_method'], FILTER_SANITIZE_STRING);
                
                if ($amount && $paymentMethod) {
                    $stmt = $pdo->prepare("
                        UPDATE donations 
                        SET donor_id = ?, amount = ?, donation_date = ?, payment_method = ?, status = ?, 
                            updated_at = NOW(), updated_by = ?
                        WHERE donation_id = ?
                    ");
                    $updated = $stmt->execute([$donorId, $amount, $donationDate, $paymentMethod, $status, $_SESSION['user_id'], $donationId]);
                }
                
            } elseif ($donationType === 'blood') {
                $bloodType = filter_var($_POST['blood_type'], FILTER_SANITIZE_STRING);
                $emergencyContact = filter_var($_POST['emergency_contact'], FILTER_SANITIZE_STRING);
                $donationLocation = filter_var($_POST['donation_location'], FILTER_SANITIZE_STRING);
                
                if ($bloodType && $emergencyContact) {
                    $stmt = $pdo->prepare("
                        UPDATE blood_donations 
                        SET donor_id = ?, blood_type = ?, donation_date = ?, donation_location = ?, 
                            emergency_contact = ?, status = ?, updated_at = NOW(), updated_by = ?
                        WHERE donation_id = ?
                    ");
                    $updated = $stmt->execute([$donorId, $bloodType, $donationDate, $donationLocation, $emergencyContact, $status, $_SESSION['user_id'], $donationId]);
                }
                
            } elseif ($donationType === 'in_kind') {
                $estimatedValue = filter_var($_POST['estimated_value'], FILTER_VALIDATE_FLOAT) ?? 0;
                $itemDescription = filter_var($_POST['item_description'], FILTER_SANITIZE_STRING);
                $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT) ?? 1;
                
                if ($itemDescription) {
                    $stmt = $pdo->prepare("
                        UPDATE in_kind_donations 
                        SET donor_id = ?, estimated_value = ?, donation_date = ?, item_description = ?, 
                            quantity = ?, status = ?, updated_at = NOW(), updated_by = ?
                        WHERE donation_id = ?
                    ");
                    $updated = $stmt->execute([$donorId, $estimatedValue, $donationDate, $itemDescription, $quantity, $status, $_SESSION['user_id'], $donationId]);
                }
            }
            
            if ($updated) {
                $successMessage = "Donation updated successfully.";
            } else {
                $errorMessage = "Failed to update donation - invalid data provided.";
            }
            
        } catch (Exception $e) {
            $errorMessage = "Error updating donation: " . $e->getMessage();
        }
    }
}

// Enhanced approval handler with proper validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_approve_donation'])) {
    $donationId = filter_var($_POST['donation_id'], FILTER_VALIDATE_INT);
    $donationType = filter_var($_POST['donation_type'], FILTER_SANITIZE_STRING);
    $newStatus = filter_var($_POST['new_status'], FILTER_SANITIZE_STRING);
    $notes = filter_var($_POST['notes'] ?? '', FILTER_SANITIZE_STRING);
    
    // Validate status values
    $validStatuses = [
        'monetary' => ['pending', 'approved', 'rejected'],
        'blood' => ['scheduled', 'confirmed', 'completed', 'cancelled', 'pending'],
        'in_kind' => ['pending', 'approved', 'rejected']
    ];
    
    if (!$donationId || !in_array($donationType, ['monetary', 'blood', 'in_kind']) || 
        !in_array($newStatus, $validStatuses[$donationType] ?? [])) {
        $errorMessage = "Invalid approval parameters.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Verify donation exists
            $table_map = [
                'monetary' => 'donations',
                'blood' => 'blood_donations',
                'in_kind' => 'in_kind_donations'
            ];
            
            $checkStmt = $pdo->prepare("SELECT donation_id FROM {$table_map[$donationType]} WHERE donation_id = ?");
            $checkStmt->execute([$donationId]);
            
            if (!$checkStmt->fetch()) {
                throw new Exception("Donation not found.");
            }
            
            // Update the donation status
            $stmt = $pdo->prepare("
                UPDATE {$table_map[$donationType]} 
                SET status = ?, approval_notes = ?, approved_by = ?, approved_date = NOW(),
                    updated_at = NOW(), updated_by = ?
                WHERE donation_id = ?
            ");
            
            $result = $stmt->execute([$newStatus, $notes, $_SESSION['user_id'], $_SESSION['user_id'], $donationId]);
            
            if ($result && $stmt->rowCount() > 0) {
                // Log the approval action
                $logStmt = $pdo->prepare("
                    INSERT INTO donation_approval_log (donation_id, donation_type, action, notes, admin_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $logStmt->execute([$donationId, $donationType, $newStatus, $notes, $_SESSION['user_id']]);
                
                // Send notification email if approved
                if (in_array($newStatus, ['approved', 'completed', 'confirmed'])) {
                    sendDonationApprovalEmail($donationId, $donationType, $newStatus);
                }
                
                $pdo->commit();
                $successMessage = "Donation status updated to " . ucfirst($newStatus) . " successfully.";
            } else {
                throw new Exception("No changes were made to the donation.");
            }
        } catch (Exception $e) {
            $pdo->rollback();
            $errorMessage = "Error updating donation: " . $e->getMessage();
        }
    }
}

// Enhanced bulk approval with better validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_approve'])) {
    $selectedDonations = $_POST['selected_donations'] ?? [];
    $bulkAction = filter_var($_POST['bulk_action'] ?? '', FILTER_SANITIZE_STRING);
    $bulkNotes = filter_var($_POST['bulk_notes'] ?? '', FILTER_SANITIZE_STRING);
    
    if (empty($selectedDonations)) {
        $errorMessage = "No donations selected.";
    } elseif (empty($bulkAction)) {
        $errorMessage = "No action selected.";
    } else {
        $successCount = 0;
        $failureCount = 0;
        $errors = [];
        
        $validStatuses = [
            'monetary' => ['pending', 'approved', 'rejected'],
            'blood' => ['scheduled', 'confirmed', 'completed', 'cancelled', 'pending'],
            'in_kind' => ['pending', 'approved', 'rejected']
        ];
        
        try {
            $pdo->beginTransaction();
            
            foreach ($selectedDonations as $donationData) {
                $parts = explode('_', $donationData);
                if (count($parts) !== 2) {
                    $failureCount++;
                    continue;
                }
                
                $donationId = filter_var($parts[0], FILTER_VALIDATE_INT);
                $donationType = filter_var($parts[1], FILTER_SANITIZE_STRING);
                
                if (!$donationId || !in_array($donationType, ['monetary', 'blood', 'in_kind']) ||
                    !in_array($bulkAction, $validStatuses[$donationType] ?? [])) {
                    $failureCount++;
                    continue;
                }
                
                try {
                    $table_map = [
                        'monetary' => 'donations',
                        'blood' => 'blood_donations',
                        'in_kind' => 'in_kind_donations'
                    ];
                    
                    $stmt = $pdo->prepare("
                        UPDATE {$table_map[$donationType]} 
                        SET status = ?, approval_notes = ?, approved_by = ?, approved_date = NOW(),
                            updated_at = NOW(), updated_by = ?
                        WHERE donation_id = ?
                    ");
                    
                    if ($stmt->execute([$bulkAction, "Bulk: " . $bulkNotes, $_SESSION['user_id'], $_SESSION['user_id'], $donationId]) && 
                        $stmt->rowCount() > 0) {
                        
                        $successCount++;
                        
                        // Log the bulk action
                        $logStmt = $pdo->prepare("
                            INSERT INTO donation_approval_log (donation_id, donation_type, action, notes, admin_id, created_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $logStmt->execute([$donationId, $donationType, $bulkAction, "Bulk: " . $bulkNotes, $_SESSION['user_id']]);
                        
                        // Send notification if approved
                        if (in_array($bulkAction, ['approved', 'completed', 'confirmed'])) {
                            sendDonationApprovalEmail($donationId, $donationType, $bulkAction);
                        }
                    } else {
                        $failureCount++;
                    }
                } catch (Exception $e) {
                    $failureCount++;
                    $errors[] = "Failed to update donation #$donationId: " . $e->getMessage();
                }
            }
            
            if ($failureCount === 0) {
                $pdo->commit();
                $successMessage = "Successfully updated {$successCount} donation(s) to {$bulkAction}.";
            } else {
                $pdo->rollback();
                $errorMessage = "Bulk operation partially failed. {$successCount} succeeded, {$failureCount} failed.";
            }
        } catch (Exception $e) {
            $pdo->rollback();
            $errorMessage = "Bulk operation failed: " . $e->getMessage();
        }
    }
}

// Enhanced create donation handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_donation'])) {
    $donationType = filter_var($_POST['donation_type'], FILTER_SANITIZE_STRING);
    $donorId = filter_var($_POST['donor_id'], FILTER_VALIDATE_INT);
    $donationDate = $_POST['donation_date'];
    $recordedBy = $_SESSION['user_id'];
    $status = filter_var($_POST['status'] ?? 'pending', FILTER_SANITIZE_STRING);

    if (!$donorId || !$donationDate || !in_array($donationType, ['monetary', 'blood', 'in_kind'])) {
        $errorMessage = "Please fill all required fields with valid data.";
    } else {
        try {
            $created = false;
            
            if ($donationType === 'monetary') {
                $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
                $paymentMethod = filter_var($_POST['payment_method'], FILTER_SANITIZE_STRING);
                
                if ($amount > 0 && $paymentMethod) {
                    $stmt = $pdo->prepare("
                        INSERT INTO donations (donor_id, amount, donation_date, payment_method, recorded_by, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $created = $stmt->execute([$donorId, $amount, $donationDate, $paymentMethod, $recordedBy, $status]);
                }
                
            } elseif ($donationType === 'blood') {
                $bloodType = filter_var($_POST['blood_type'], FILTER_SANITIZE_STRING);
                $donationLocation = filter_var($_POST['donation_location'] ?? '', FILTER_SANITIZE_STRING);
                $emergencyContact = filter_var($_POST['emergency_contact'], FILTER_SANITIZE_STRING);
                
                if ($bloodType && $emergencyContact) {
                    $stmt = $pdo->prepare("
                        INSERT INTO blood_donations (donor_id, blood_type, donation_date, donation_location, emergency_contact, recorded_by, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $created = $stmt->execute([$donorId, $bloodType, $donationDate, $donationLocation, $emergencyContact, $recordedBy, $status]);
                }
                
            } elseif ($donationType === 'in_kind') {
                $estimatedValue = filter_var($_POST['estimated_value'], FILTER_VALIDATE_FLOAT) ?? 0;
                $itemDescription = filter_var($_POST['item_description'], FILTER_SANITIZE_STRING);
                $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT) ?? 1;
                
                if ($itemDescription && $quantity > 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO in_kind_donations (donor_id, estimated_value, donation_date, item_description, quantity, recorded_by, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $created = $stmt->execute([$donorId, $estimatedValue, $donationDate, $itemDescription, $quantity, $recordedBy, $status]);
                }
            }
            
            if ($created) {
                $successMessage = ucfirst($donationType) . " donation recorded successfully.";
            } else {
                $errorMessage = "Failed to create donation - invalid data provided.";
            }
        } catch (Exception $e) {
            $errorMessage = "Error creating donation: " . $e->getMessage();
        }
    }
}

// Function to send approval email
function sendDonationApprovalEmail($donationId, $donationType, $status) {
    global $pdo;
    
    try {
        $table_map = [
            'monetary' => 'donations',
            'blood' => 'blood_donations',
            'in_kind' => 'in_kind_donations'
        ];
        
        $stmt = $pdo->prepare("
            SELECT d.*, donor.name, donor.email 
            FROM {$table_map[$donationType]} d
            JOIN donors donor ON d.donor_id = donor.donor_id
            WHERE d.donation_id = ?
        ");
        $stmt->execute([$donationId]);
        $donation = $stmt->fetch();
        
        if ($donation) {
            // Here you would integrate with your email system
            // For now, we'll log it
            error_log("Sending approval email to {$donation['email']} for donation #{$donationId} - Status: {$status}");
        }
    } catch (Exception $e) {
        error_log("Error sending approval email: " . $e->getMessage());
    }
}

// Enhanced queries with better error handling
try {
    // Get monetary donations
    $monetaryDonations = $pdo->query("
        SELECT d.donation_id, r.name AS donor_name, r.email AS donor_email, r.phone AS donor_phone,
               d.amount, d.donation_date, d.payment_method, d.payment_receipt,
               u.username AS recorded_by, 'monetary' AS donation_type,
               d.status, r.donor_id, d.approval_notes, d.approved_by, d.approved_date,
               approver.username AS approved_by_name, d.message,
               d.created_at, d.updated_at
        FROM donations d
        JOIN donors r ON d.donor_id = r.donor_id
        JOIN users u ON d.recorded_by = u.user_id
        LEFT JOIN users approver ON d.approved_by = approver.user_id
        ORDER BY d.created_at DESC, d.donation_date DESC
    ")->fetchAll() ?: [];

    // Get blood donations
    $bloodDonations = $pdo->query("
        SELECT d.donation_id, r.name AS donor_name, r.email AS donor_email, r.phone AS donor_phone,
               0 AS amount, d.donation_date, d.blood_type, d.donation_location, d.emergency_contact,
               u.username AS recorded_by, 'blood' AS donation_type, d.medical_history,
               d.status, r.donor_id, d.approval_notes, d.approved_by, d.approved_date,
               approver.username AS approved_by_name, d.last_donation_date,
               d.created_at, d.updated_at
        FROM blood_donations d
        JOIN donors r ON d.donor_id = r.donor_id
        JOIN users u ON d.recorded_by = u.user_id
        LEFT JOIN users approver ON d.approved_by = approver.user_id
        ORDER BY d.created_at DESC, d.donation_date DESC
    ")->fetchAll() ?: [];

    // Get in-kind donations
    $inkindDonations = $pdo->query("
        SELECT d.donation_id, r.name AS donor_name, r.email AS donor_email, r.phone AS donor_phone,
               d.estimated_value AS amount, d.donation_date, d.item_description, d.quantity,
               u.username AS recorded_by, 'in_kind' AS donation_type, d.status, r.donor_id,
               d.approval_notes, d.approved_by, d.approved_date, d.purpose,
               approver.username AS approved_by_name,
               d.created_at, d.updated_at
        FROM in_kind_donations d
        JOIN donors r ON d.donor_id = r.donor_id
        JOIN users u ON d.recorded_by = u.user_id
        LEFT JOIN users approver ON d.approved_by = approver.user_id
        ORDER BY d.created_at DESC, d.donation_date DESC
    ")->fetchAll() ?: [];

    // Combine and sort all donations
    $allDonations = array_merge($monetaryDonations, $bloodDonations, $inkindDonations);
    usort($allDonations, function($a, $b) {
        $timeA = strtotime($a['created_at'] ?? $a['donation_date']);
        $timeB = strtotime($b['created_at'] ?? $b['donation_date']);
        return $timeB - $timeA; // Most recent first
    });

    // Get donors for dropdown
    $donors = $pdo->query("SELECT donor_id, name, email FROM donors ORDER BY name")->fetchAll();

    // Enhanced statistics - assign to the variables that will be used in HTML
    $stats = [
        'monetary_count' => $pdo->query("SELECT COUNT(*) FROM donations")->fetchColumn() ?: 0,
        'blood_count' => $pdo->query("SELECT COUNT(*) FROM blood_donations")->fetchColumn() ?: 0,
        'inkind_count' => $pdo->query("SELECT COUNT(*) FROM in_kind_donations")->fetchColumn() ?: 0,
    ];

    // Assign to the variables that are used in the HTML
    $total_donations = $stats['monetary_count'] + $stats['blood_count'] + $stats['inkind_count'];
    $blood_count = $stats['blood_count'];
    
    $completed_count = 
        ($pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'approved'")->fetchColumn() ?: 0) + 
        ($pdo->query("SELECT COUNT(*) FROM blood_donations WHERE status = 'completed'")->fetchColumn() ?: 0) +
        ($pdo->query("SELECT COUNT(*) FROM in_kind_donations WHERE status = 'approved'")->fetchColumn() ?: 0);
        
    $pending_count = 
        ($pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'pending'")->fetchColumn() ?: 0) + 
        ($pdo->query("SELECT COUNT(*) FROM blood_donations WHERE status IN ('scheduled', 'pending')")->fetchColumn() ?: 0) +
        ($pdo->query("SELECT COUNT(*) FROM in_kind_donations WHERE status = 'pending'")->fetchColumn() ?: 0);
        
    $rejected_count = 
        ($pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'rejected'")->fetchColumn() ?: 0) + 
        ($pdo->query("SELECT COUNT(*) FROM blood_donations WHERE status = 'cancelled'")->fetchColumn() ?: 0) +
        ($pdo->query("SELECT COUNT(*) FROM in_kind_donations WHERE status = 'rejected'")->fetchColumn() ?: 0);

} catch (Exception $e) {
    error_log("Database error in donations.php: " . $e->getMessage());
    $allDonations = [];
    $donors = [];
    // Ensure variables have default values even on error
    $total_donations = 0;
    $completed_count = 0;
    $blood_count = 0;
    $pending_count = 0;
    $rejected_count = 0;
    if (empty($errorMessage)) {
        $errorMessage = "Error loading donation data. Please refresh the page.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Donation Management - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/donations.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <div class="donations-container">
     <?php include 'header.php'; ?>
    <div class="page-header">
      <div class="header-content">
        <h1><i class="fas fa-hand-holding-heart"></i> Donation Management</h1>
        <p>Review, approve, and manage all donations including blood donations with enhanced approval workflow</p>
      </div>
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

<div class="action-bar">
  <div class="action-bar-left">
    <form method="GET" class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" name="search" placeholder="Search donations..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    </form>
    
    <!-- Enhanced Filter Controls -->
    <div class="filter-controls">
      <div class="status-filter">
        <button type="button" onclick="filterStatus('all')" class="filter-btn active" data-filter="all">All</button>
        <button type="button" onclick="filterStatus('pending')" class="filter-btn" data-filter="pending">Pending</button>
        <button type="button" onclick="filterStatus('approved')" class="filter-btn" data-filter="approved">Approved</button>
        <button type="button" onclick="filterStatus('rejected')" class="filter-btn" data-filter="rejected">Rejected</button>
        <button type="button" onclick="filterType('blood')" class="filter-btn" data-filter="blood">Blood</button>
      </div>
      
      <!-- New Date Filter Section -->
      <div class="date-filter">
        <select id="dateFilter" onchange="applyDateFilter()" class="date-filter-select">
          <option value="all">All Dates</option>
          <option value="today">Today</option>
          <option value="yesterday">Yesterday</option>
          <option value="this_week">This Week</option>
          <option value="last_week">Last Week</option>
          <option value="this_month">This Month</option>
          <option value="last_month">Last Month</option>
          <option value="this_year">This Year</option>
          <option value="custom">Custom Range</option>
        </select>
        
        <!-- Custom Date Range Inputs (Initially Hidden) -->
        <div id="customDateRange" class="custom-date-range" style="display: none;">
          <input type="date" id="startDate" onchange="applyCustomDateFilter()" placeholder="Start Date">
          <span>to</span>
          <input type="date" id="endDate" onchange="applyCustomDateFilter()" placeholder="End Date">
        </div>
      </div>
      
      <!-- Month/Year Quick Filter -->
      <div class="month-filter">
        <select id="monthFilter" onchange="applyMonthFilter()" class="month-filter-select">
          <option value="">Filter by Month</option>
          <?php
          // Generate month options for current and previous years
          $currentYear = date('Y');
          $currentMonth = date('n');
          
          for ($year = $currentYear; $year >= $currentYear - 2; $year--) {
            $startMonth = ($year == $currentYear) ? $currentMonth : 12;
            for ($month = $startMonth; $month >= 1; $month--) {
              $monthName = date('F Y', mktime(0, 0, 0, $month, 1, $year));
              $monthValue = sprintf('%04d-%02d', $year, $month);
              echo "<option value=\"$monthValue\">$monthName</option>";
            }
          }
          ?>
        </select>
      </div>
    </div>
  </div>
  
  <div class="action-buttons">
    <button class="btn-secondary" onclick="openBulkModal()" style="margin-right: 0.5rem; display: none;">
      <i class="fas fa-tasks"></i> Bulk Actions
    </button>
    <button class="btn-secondary" onclick="clearAllFilters()" style="margin-right: 0.5rem;">
      <i class="fas fa-filter-circle-xmark"></i> Clear Filters
    </button>
    <button class="btn-create" onclick="openCreateModal()">
      <i class="fas fa-plus-circle"></i> Record New Donation
    </button>
  </div>
</div>


    <!-- Enhanced Statistics Overview -->
    <div class="stats-overview">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-hand-holding-heart"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $total_donations ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Total Donations</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">
          <i class="fas fa-tint"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $blood_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Blood Donations</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ffc107 0%, #ff8f00 100%);">
          <i class="fas fa-clock"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $pending_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Pending Approval</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-check-circle"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $completed_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Completed</div>
        </div>
      </div>
    </div>

    <!-- Enhanced Donations Table -->
    <div class="donations-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">All Donations</h2>
        <div class="table-controls">
          <label class="checkbox-container">
            <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
            <span class="checkmark"></span>
            Select All
          </label>
        </div>
      </div>
      
      <?php if (empty($allDonations)): ?>
        <div class="empty-state">
          <i class="fas fa-hand-holding-heart"></i>
          <h3>No donations found</h3>
          <p>Click "Record New Donation" to get started</p>
        </div>
      <?php else: ?>
        <form id="bulkActionForm">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width: 40px;">
                  <input type="checkbox" id="headerSelect" onchange="toggleSelectAll()">
                </th>
                <th>Donation Details</th>
                <th>Donor</th>
                <th>Type & Value</th>
                <th>Date</th>
                <th>Status</th>
                <th>Quick Actions</th>
                <th>Approval Info</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allDonations as $d): 
                $status = !empty($d['status']) ? $d['status'] : 'pending';
                $statusClass = in_array($status, ['approved', 'completed']) ? 'approved' : 
                              (in_array($status, ['pending', 'scheduled']) ? 'pending' : 
                              (in_array($status, ['rejected', 'cancelled']) ? 'rejected' : 'other'));
              ?>
                <tr class="donation-row" data-status="<?= $status ?>" data-type="<?= $d['donation_type'] ?>">
                  <td>
                    <input type="checkbox" class="donation-checkbox" 
                           name="selected_donations[]" 
                           value="<?= $d['donation_id'] ?>_<?= $d['donation_type'] ?>"
                           onchange="updateBulkActions()">
                  </td>
                  <td>
                    <div class="donation-title">
                      <span class="badge <?= $d['donation_type'] === 'monetary' ? 'badge-primary' : ($d['donation_type'] === 'blood' ? 'badge-danger' : 'badge-info') ?>">
                        <?php
                          switch($d['donation_type']) {
                            case 'monetary': echo '<i class="fas fa-money-bill-wave"></i> Monetary'; break;
                            case 'blood': echo '<i class="fas fa-tint"></i> Blood'; break;
                            case 'in_kind': echo '<i class="fas fa-box-open"></i> In-Kind'; break;
                          }
                        ?>
                      </span>
                      <span class="donation-id">#<?= $d['donation_id'] ?></span>
                      
                      <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                        <?php if ($d['donation_type'] === 'monetary'): ?>
                          Payment: <?= htmlspecialchars($d['payment_method']) ?>
                          <?php if (!empty($d['payment_receipt'])): ?>
                            <a href="../<?= htmlspecialchars($d['payment_receipt']) ?>" target="_blank" class="receipt-link">
                              <i class="fas fa-receipt"></i> Receipt
                            </a>
                          <?php endif; ?>
                        <?php elseif ($d['donation_type'] === 'blood'): ?>
                          Blood Type: <strong><?= htmlspecialchars($d['blood_type']) ?></strong>
                          <?php if (!empty($d['donation_location'])): ?>
                            | Location: <?= htmlspecialchars($d['donation_location']) ?>
                          <?php endif; ?>
                        <?php else: ?>
                          <?= htmlspecialchars($d['item_description']) ?>
                          <?php if (isset($d['quantity']) && $d['quantity'] > 1): ?>
                            (Qty: <?= $d['quantity'] ?>)
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="donor-info">
                      <div class="donor-name"><?= htmlspecialchars($d['donor_name']) ?></div>
                      <div class="donor-contact"><?= htmlspecialchars($d['donor_email']) ?></div>
                      <?php if (!empty($d['donor_phone'])): ?>
                        <div class="donor-contact" style="font-size: 0.8rem;">
                          <i class="fas fa-phone"></i> <?= htmlspecialchars($d['donor_phone']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($d['donation_type'] === 'blood'): ?>
                      <div class="blood-type-display">
                        <span class="blood-badge"><?= htmlspecialchars($d['blood_type']) ?></span>
                        <div style="font-size: 0.8rem; color: var(--gray);">Life-saving donation</div>
                      </div>
                    <?php else: ?>
                      <div class="amount-display">
                        <span class="amount-value">₱<?= number_format($d['amount'], 2) ?></span>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="date-display">
                      <span class="date-value"><?= date('M d, Y', strtotime($d['donation_date'])) ?></span>
                      <div style="font-size: 0.8rem; color: var(--gray);">
                        by <?= htmlspecialchars($d['recorded_by']) ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="status-badge <?= $statusClass ?>">
                      <?= ucfirst($status) ?>
                    </span>
                  </td>
                  <td>
                    <div class="quick-actions">
                      <?php if (in_array($status, ['pending', 'scheduled'])): ?>
                        <?php if ($d['donation_type'] === 'blood'): ?>
                          <button type="button" class="btn-quick btn-approve" 
                                  onclick="openApprovalModal('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', 'confirmed', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')">
                            <i class="fas fa-check"></i> Confirm
                          </button>
                          <button type="button" class="btn-quick btn-reject" 
                                  onclick="openApprovalModal('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', 'cancelled', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')">
                            <i class="fas fa-times"></i> Cancel
                          </button>
                        <?php else: ?>
                          <button type="button" class="btn-quick btn-approve" 
                                  onclick="openApprovalModal('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', 'approved', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')">
                            <i class="fas fa-check"></i> Approve
                          </button>
                          <button type="button" class="btn-quick btn-reject" 
                                  onclick="openApprovalModal('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', 'rejected', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')">
                            <i class="fas fa-times"></i> Reject
                          </button>
                        <?php endif; ?>
                      <?php elseif ($status === 'confirmed' && $d['donation_type'] === 'blood'): ?>
                        <button type="button" class="btn-quick btn-complete" 
                                onclick="openApprovalModal('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', 'completed', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')">
                          <i class="fas fa-check-double"></i> Complete
                        </button>
                      <?php elseif (in_array($status, ['approved', 'completed'])): ?>
                        <button type="button" class="btn-quick btn-pending" 
                                onclick="openApprovalModal('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', 'pending', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')">
                          <i class="fas fa-undo"></i> Revert
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="approval-info">
                      <?php if ($d['approved_by'] && $d['approved_date']): ?>
                        <div style="font-size: 0.8rem; color: var(--gray);">
                          <i class="fas fa-user-check"></i> <?= htmlspecialchars($d['approved_by_name']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--gray);">
                          <?= date('M d, Y g:i A', strtotime($d['approved_date'])) ?>
                        </div>
                        <?php if ($d['approval_notes']): ?>
                          <div class="approval-notes" title="<?= htmlspecialchars($d['approval_notes']) ?>">
                            <i class="fas fa-sticky-note"></i> Notes provided
                          </div>
                        <?php endif; ?>
                      <?php else: ?>
                        <span style="color: var(--gray); font-size: 0.8rem;">Not reviewed</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="actions">
                    <div class="action-button">
                     <button class="btn-action btn-view" 
        data-donation='<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>'
        title="View Details">
    <i class="fas fa-eye"></i>
</button>
<button class="btn-action btn-edit" 
        data-donation='<?= htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8') ?>'
        title="Edit Donation">
    <i class="fas fa-edit"></i>
</button>
                      <button type="button" class="btn-action btn-delete" 
                              onclick="confirmDeleteDonation('<?= $d['donation_id'] ?>', '<?= $d['donation_type'] ?>', '<?= htmlspecialchars(addslashes($d['donor_name'])) ?>')"
                              title="Delete Donation">
                        <i class="fas fa-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Enhanced Approval Modal -->
  <div class="modal" id="approvalModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="approvalModalTitle">Approve Donation</h2>
        <button class="close-modal" onclick="closeApprovalModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="approvalForm">
        <input type="hidden" name="quick_approve_donation" value="1">
        <input type="hidden" name="donation_id" id="approvalDonationId">
        <input type="hidden" name="donation_type" id="approvalDonationType">
        <input type="hidden" name="new_status" id="approvalNewStatus">
        
        <div class="approval-info-section">
          <div class="donor-summary" id="donorSummary">
            <!-- Donor info will be populated by JavaScript -->
          </div>
        </div>
        
        <div class="form-group">
          <label for="approvalNotes">Notes <span style="color: var(--gray);">(Optional)</span></label>
          <textarea id="approvalNotes" name="notes" rows="3" 
                    placeholder="Add notes for this donation..."></textarea>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeApprovalModal()">
            Cancel
          </button>
          <button type="submit" class="btn-submit" id="approvalSubmitBtn">
            <i class="fas fa-check"></i> Approve
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Enhanced Bulk Actions Modal -->
  <div class="modal" id="bulkModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title">Bulk Actions</h2>
        <button class="close-modal" onclick="closeBulkModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="bulkForm">
        <input type="hidden" name="bulk_approve" value="1">
        
        <div class="bulk-summary" id="bulkSummary">
          <!-- Selection summary will be populated by JavaScript -->
        </div>
        
        <div class="form-group">
          <label for="bulkAction">Action *</label>
          <select id="bulkAction" name="bulk_action" required>
            <option value="">Select action</option>
            <option value="approved">Approve</option>
            <option value="rejected">Reject</option>
            <option value="confirmed">Confirm (Blood Donations)</option>
            <option value="completed">Mark Complete</option>
            <option value="cancelled">Cancel</option>
            <option value="pending">Mark Pending</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="bulkNotes">Notes <span style="color: var(--gray);">(Optional)</span></label>
          <textarea id="bulkNotes" name="bulk_notes" rows="3" 
                    placeholder="Add notes for all selected donations..."></textarea>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeBulkModal()">
            Cancel
          </button>
          <button type="submit" class="btn-submit" id="bulkSubmitBtn" disabled>
            <i class="fas fa-tasks"></i> Apply to Selected
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Enhanced Create/Edit Modal -->
  <div class="modal" id="donationModal">
    <div class="modal-content modal-wide">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Record New Donation</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="donationForm">
        <input type="hidden" name="create_donation" value="1" id="formAction">
        <input type="hidden" name="donation_id" id="donationId">
        
        <div class="form-group">
          <label for="donation_type">Donation Type *</label>
          <select id="donation_type" name="donation_type" required onchange="toggleDonationFields()">
            <option value="monetary">Monetary Donation</option>
            <option value="blood">Blood Donation</option>
            <option value="in_kind">In-Kind Donation</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="donor_id">Donor *</label>
          <select id="donor_id" name="donor_id" required>
            <option value="">Select a donor</option>
            <?php foreach ($donors as $donor): ?>
              <option value="<?= $donor['donor_id'] ?>"><?= htmlspecialchars($donor['name']) ?> (<?= htmlspecialchars($donor['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="donation_date">Date *</label>
            <input type="date" id="donation_date" name="donation_date" required>
          </div>
          <div class="form-group" id="statusGroup">
            <label for="status">Status *</label>
            <select id="status" name="status">
              <option value="pending">Pending</option>
              <option value="approved">Pre-approved</option>
            </select>
          </div>
        </div>
        
        <!-- Monetary Donation Fields -->
        <div id="monetary-fields">
          <div class="form-row">
            <div class="form-group">
              <label for="amount">Amount (PHP) *</label>
              <input type="number" id="amount" name="amount" step="0.01" min="0" required>
            </div>
            <div class="form-group">
              <label for="payment_method">Payment Method *</label>
              <select id="payment_method" name="payment_method">
                <option value="cash">Cash</option>
                <option value="check">Check</option>
                <option value="credit_card">Credit Card</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="gcash">GCash</option>
                <option value="paymaya">PayMaya</option>
              </select>
            </div>
          </div>
        </div>
        
        <!-- Blood Donation Fields -->
        <div id="blood-fields" style="display: none;">
          <div class="form-row">
            <div class="form-group">
              <label for="blood_type">Blood Type *</label>
              <select id="blood_type" name="blood_type">
                <option value="">Select blood type</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
                <option value="Unknown">Unknown</option>
              </select>
            </div>
            <div class="form-group">
              <label for="emergency_contact">Emergency Contact *</label>
              <input type="tel" id="emergency_contact" name="emergency_contact" 
                     placeholder="Emergency contact number">
            </div>
          </div>
          
          <div class="form-group">
            <label for="donation_location">Donation Location</label>
            <select id="donation_location" name="donation_location">
              <option value="">Select location</option>
              <option value="PRC Main Office">PRC Main Office</option>
              <option value="Mobile Blood Drive">Mobile Blood Drive</option>
              <option value="Hospital Partner">Hospital Partner</option>
              <option value="Community Center">Community Center</option>
            </select>
          </div>
        </div>
        
        <!-- In-Kind Donation Fields -->
        <div id="inkind-fields" style="display: none;">
          <div class="form-group">
            <label for="item_description">Item Description *</label>
            <textarea id="item_description" name="item_description" rows="2"></textarea>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="quantity">Quantity</label>
              <input type="number" id="quantity" name="quantity" min="1" value="1">
            </div>
            <div class="form-group">
              <label for="estimated_value">Estimated Value (PHP)</label>
              <input type="number" id="estimated_value" name="estimated_value" step="0.01" min="0">
            </div>
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal()">
            Cancel
          </button>
          <button type="submit" class="btn-submit">
            <i class="fas fa-save"></i> Save Donation
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Donation Details Modal -->
  <div class="modal" id="detailsModal">
    <div class="modal-content modal-wide">
      <div class="modal-header">
        <h2 class="modal-title">Donation Details</h2>
        <button class="close-modal" onclick="closeDetailsModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div id="donationDetails">
        <!-- Details will be populated by JavaScript -->
      </div>
    </div>
  </div>
<script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
    <?php include 'chat_widget.php'; ?>
  <script>
 // Global variables for managing selections
let selectedDonations = new Set();

// Enhanced modal functions with event prevention
function openCreateModal(e) {
    if (e) e.preventDefault();
    document.getElementById('modalTitle').textContent = 'Record New Donation';
    document.getElementById('formAction').name = 'create_donation';
    document.getElementById('formAction').value = '1';
    document.getElementById('donationForm').reset();
    document.getElementById('donation_date').valueAsDate = new Date();
    toggleDonationFields();
    document.getElementById('donationModal').classList.add('active');
}

function openEditModal(donation, e) {
    if (e) e.preventDefault();
    document.getElementById('modalTitle').textContent = 'Edit Donation';
    document.getElementById('formAction').name = 'update_donation';
    document.getElementById('formAction').value = '1';
    document.getElementById('donationId').value = donation.donation_id;
    document.getElementById('donation_type').value = donation.donation_type;
    document.getElementById('donor_id').value = donation.donor_id || '';
    document.getElementById('donation_date').value = donation.donation_date;
    document.getElementById('status').value = donation.status || 'pending';
    
    if (donation.donation_type === 'monetary') {
        document.getElementById('amount').value = donation.amount;
        document.getElementById('payment_method').value = donation.payment_method || 'cash';
    } else if (donation.donation_type === 'blood') {
        document.getElementById('blood_type').value = donation.blood_type || '';
        document.getElementById('emergency_contact').value = donation.emergency_contact || '';
        document.getElementById('donation_location').value = donation.donation_location || '';
    } else {
        document.getElementById('item_description').value = donation.item_description || '';
        document.getElementById('quantity').value = donation.quantity || 1;
        document.getElementById('estimated_value').value = donation.amount || '';
    }
    
    toggleDonationFields();
    document.getElementById('donationModal').classList.add('active');
}

// Simplified view modal function - shows only receipt for monetary donations
function viewDonationDetails(donation, e) {
    if (e) e.preventDefault();
    if (!donation || typeof donation !== 'object') {
        console.error('Invalid donation data provided');
        alert('Error: Could not load donation details. Please try again.');
        return;
    }
    
    const detailsDiv = document.getElementById('donationDetails');
    if (!detailsDiv) {
        console.error('Details container not found');
        return;
    }
    
    try {
        const sanitize = (str) => {
            if (str === null || str === undefined) return '';
            const text = String(str);
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };
        
        const badgeClass = donation.donation_type === 'monetary' ? 'badge-primary' : 
                            (donation.donation_type === 'blood' ? 'badge-danger' : 'badge-info');
        
        const statusClass = ['approved', 'completed'].includes(donation.status) ? 'approved' : 
                            (['pending', 'scheduled'].includes(donation.status) ? 'pending' : 'rejected');
        
        let detailsHTML = `
            <div class="donation-detail-card">
                <div class="detail-header">
                    <h3>
                        <span class="badge ${badgeClass}">
                            ${sanitize(donation.donation_type.toUpperCase())}
                        </span>
                        Donation #${sanitize(donation.donation_id)}
                    </h3>
                    <span class="status-badge ${statusClass}">
                        ${sanitize(donation.status.toUpperCase())}
                    </span>
                </div>
                
                <div class="detail-content">
        `;
        
        // For monetary donations, show only receipt
        if (donation.donation_type === 'monetary' && donation.payment_receipt) {
            detailsHTML += `
                <div class="receipt-section">
                    <h4><i class="fas fa-receipt"></i> Payment Receipt</h4>
                    <div class="receipt-viewer">
                        <iframe src="../${sanitize(donation.payment_receipt)}" 
                                style="width: 100%; height: 400px; border: 1px solid #ddd; border-radius: 8px;"
                                title="Payment Receipt">
                        </iframe>
                        <div class="receipt-actions" style="margin-top: 1rem; text-align: center;">
                            <a href="../${sanitize(donation.payment_receipt)}" 
                               target="_blank" 
                               class="btn-primary" 
                               rel="noopener">
                                <i class="fas fa-external-link-alt"></i> Open in New Tab
                            </a>
                            <a href="../${sanitize(donation.payment_receipt)}" 
                               download 
                               class="btn-secondary" 
                               style="margin-left: 0.5rem;">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                    </div>
                </div>
            `;
        } else if (donation.donation_type === 'monetary') {
            detailsHTML += `
                <div class="no-receipt-section">
                    <i class="fas fa-file-invoice" style="font-size: 3rem; color: #ccc; margin-bottom: 1rem;"></i>
                    <h4>No Receipt Available</h4>
                    <p>No payment receipt was uploaded for this donation.</p>
                </div>
            `;
        } else {
            // For non-monetary donations, show basic info
            detailsHTML += `
                <div class="basic-info">
                    <p><i class="fas fa-info-circle"></i> This donation type does not have a receipt to display.</p>
                    <p><strong>Donor:</strong> ${sanitize(donation.donor_name)}</p>
                    <p><strong>Date:</strong> ${sanitize(donation.donation_date)}</p>
                </div>
            `;
        }
        
        detailsHTML += `
                </div>
            </div>
        `;
        
        detailsDiv.innerHTML = detailsHTML;
        
        const modal = document.getElementById('detailsModal');
        if (modal) {
            modal.classList.add('active');
        }
        
    } catch (error) {
        console.error('Error creating donation details:', error);
        detailsDiv.innerHTML = '<div class="error-message">Error loading donation details. Please try again.</div>';
    }
}

function closeModal(e) {
    if (e) e.preventDefault();
    document.getElementById('donationModal').classList.remove('active');
}

function closeDetailsModal(e) {
    if (e) e.preventDefault();
    document.getElementById('detailsModal').classList.remove('active');
}

function openApprovalModal(donationId, donationType, newStatus, donorName, e) {
    if (e) e.preventDefault();
    // Validate inputs
    if (!donationId || !donationType || !newStatus || !donorName) {
        console.error('Missing required parameters for approval modal');
        return;
    }
    
    // Set modal data
    const modalElements = {
        donationId: document.getElementById('approvalDonationId'),
        donationType: document.getElementById('approvalDonationType'),
        newStatus: document.getElementById('approvalNewStatus'),
        title: document.getElementById('approvalModalTitle'),
        submitBtn: document.getElementById('approvalSubmitBtn'),
        donorSummary: document.getElementById('donorSummary'),
        notesField: document.getElementById('approvalNotes')
    };
    
    // Check if all required elements exist
    const missingElements = Object.entries(modalElements).filter(([key, element]) => !element);
    if (missingElements.length > 0) {
        console.error('Missing modal elements:', missingElements.map(([key]) => key));
        alert('Error: Modal elements not found. Please refresh the page.');
        return;
    }
    
    modalElements.donationId.value = donationId;
    modalElements.donationType.value = donationType;
    modalElements.newStatus.value = newStatus;
    
    // Define action properties
    const actionConfig = {
        'approved': { text: 'Approve', color: '#28a745', icon: 'fa-check' },
        'rejected': { text: 'Reject', color: '#dc3545', icon: 'fa-times' },
        'pending': { text: 'Mark as Pending', color: '#ffc107', icon: 'fa-clock' },
        'confirmed': { text: 'Confirm', color: '#17a2b8', icon: 'fa-check-circle' },
        'completed': { text: 'Mark Complete', color: '#28a745', icon: 'fa-check-double' },
        'cancelled': { text: 'Cancel', color: '#6c757d', icon: 'fa-ban' }
    };
    
    const config = actionConfig[newStatus];
    if (!config) {
        console.error('Unknown status:', newStatus);
        return;
    }
    
    modalElements.title.textContent = `${config.text} Donation`;
    modalElements.submitBtn.innerHTML = `<i class="fas ${config.icon}"></i> ${config.text}`;
    modalElements.submitBtn.style.backgroundColor = config.color;
    
    // Create donor summary with safe HTML
    const donorInitial = donorName.charAt(0).toUpperCase();
    const safeId = String(donationId).replace(/[^0-9]/g, '');
    const safeType = donationType.replace(/[^a-z_]/gi, '').replace('_', ' ').toUpperCase();
    const safeName = donorName.replace(/[<>&"']/g, function(match) {
        const entities = {'<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#x27;'};
        return entities[match];
    });
    
    modalElements.donorSummary.innerHTML = `
            <div class="donor-card">
                <div class="donor-avatar">${donorInitial}</div>
                <div class="donor-details">
                    <strong>${safeName}</strong>
                    <div style="color: var(--gray); font-size: 0.9rem;">Donation ID: #${safeId}</div>
                    <div style="color: var(--gray); font-size: 0.9rem;">Type: ${safeType}</div>
                </div>
            </div>
        `;
    
    modalElements.notesField.value = '';
    
    const modal = document.getElementById('approvalModal');
    if (modal) {
        modal.classList.add('active');
        
        // Focus on notes field after modal opens
        setTimeout(() => {
            if (modalElements.notesField) {
                modalElements.notesField.focus();
            }
        }, 150);
    }
}

function closeApprovalModal(e) {
    if (e) e.preventDefault();
    document.getElementById('approvalModal').classList.remove('active');
}

function openBulkModal(e) {
    if (e) e.preventDefault();
    updateBulkSummary();
    document.getElementById('bulkModal').classList.add('active');
}

function closeBulkModal(e) {
    if (e) e.preventDefault();
    document.getElementById('bulkModal').classList.remove('active');
}

function toggleSelectAll() {
    const headerCheckbox = document.getElementById('headerSelect') || document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.donation-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = headerCheckbox.checked;
    });
    
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.donation-checkbox:checked');
    const bulkButton = document.querySelector('button[onclick="openBulkModal()"]');
    const bulkSubmitBtn = document.getElementById('bulkSubmitBtn');
    
    if (checkboxes.length > 0) {
        if (bulkButton) bulkButton.style.display = 'inline-flex';
        if (bulkSubmitBtn) bulkSubmitBtn.disabled = false;
    } else {
        if (bulkButton) bulkButton.style.display = 'none';
        if (bulkSubmitBtn) bulkSubmitBtn.disabled = true;
    }
    
    updateBulkSummary();
}

function updateBulkSummary() {
    const checkboxes = document.querySelectorAll('.donation-checkbox:checked');
    const summaryElement = document.getElementById('bulkSummary');
    
    if (!summaryElement) return;
    
    if (checkboxes.length === 0) {
        summaryElement.innerHTML = '<p style="color: var(--gray);">No donations selected</p>';
    } else {
        const typeCounts = {};
        const statusCounts = {};
        
        checkboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            if (row) {
                const type = row.dataset.type;
                const status = row.dataset.status;
                
                typeCounts[type] = (typeCounts[type] || 0) + 1;
                statusCounts[status] = (statusCounts[status] || 0) + 1;
            }
        });
        
        let summaryHTML = `<div class="bulk-selection-summary">
            <h4>Selected ${checkboxes.length} donation(s):</h4>
            <div class="type-breakdown">
                <strong>Types:</strong> `;
        
        Object.entries(typeCounts).forEach(([type, count]) => {
            const typeClass = type === 'monetary' ? 'badge-primary' : (type === 'blood' ? 'badge-danger' : 'badge-info');
            summaryHTML += `<span class="badge ${typeClass}">${count} ${type}</span> `;
        });
        
        summaryHTML += `</div><div class="status-breakdown"><strong>Status:</strong> `;
        
        Object.entries(statusCounts).forEach(([status, count]) => {
            const statusClass = ['approved', 'completed'].includes(status) ? 'approved' : 
                                (['pending', 'scheduled'].includes(status) ? 'pending' : 'rejected');
            summaryHTML += `<span class="status-badge ${statusClass}">${count} ${status}</span> `;
        });
        
        summaryHTML += '</div></div>';
        summaryElement.innerHTML = summaryHTML;
    }
}

// Enhanced status and type filtering to work with date filters
function filterStatus(status) {
    const buttons = document.querySelectorAll('.status-filter .filter-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    applyAllFilters();
}

function filterType(type) {
    const buttons = document.querySelectorAll('.status-filter .filter-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    applyAllFilters();
}

// Date filtering functions
function applyDateFilter() {
    const filter = document.getElementById('dateFilter').value;
    const customRange = document.getElementById('customDateRange');
    
    if (filter === 'custom') {
        customRange.style.display = 'flex';
        return;
    } else {
        customRange.style.display = 'none';
    }
    
    const today = new Date();
    let startDate, endDate;
    
    switch(filter) {
        case 'today':
            startDate = new Date(today);
            endDate = new Date(today);
            break;
        case 'yesterday':
            startDate = new Date(today.getTime() - 24 * 60 * 60 * 1000);
            endDate = new Date(today.getTime() - 24 * 60 * 60 * 1000);
            break;
        case 'this_week':
            const thisWeekStart = new Date(today.setDate(today.getDate() - today.getDay()));
            startDate = thisWeekStart;
            endDate = new Date();
            break;
        case 'last_week':
            const lastWeekEnd = new Date(today.setDate(today.getDate() - today.getDay() - 1));
            const lastWeekStart = new Date(lastWeekEnd.getTime() - 6 * 24 * 60 * 60 * 1000);
            startDate = lastWeekStart;
            endDate = lastWeekEnd;
            break;
        case 'this_month':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = new Date();
            break;
        case 'last_month':
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            startDate = lastMonth;
            endDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        case 'this_year':
            startDate = new Date(today.getFullYear(), 0, 1);
            endDate = new Date();
            break;
        default:
            filterDonationsByDate(null, null);
            return;
    }
    
    filterDonationsByDate(startDate, endDate);
}

function applyCustomDateFilter() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    if (startDate && endDate) {
        filterDonationsByDate(new Date(startDate), new Date(endDate));
    } else if (startDate) {
        filterDonationsByDate(new Date(startDate), null);
    } else if (endDate) {
        filterDonationsByDate(null, new Date(endDate));
    }
}

function applyMonthFilter() {
    const monthValue = document.getElementById('monthFilter').value;
    if (!monthValue) {
        filterDonationsByDate(null, null);
        return;
    }
    
    const [year, month] = monthValue.split('-');
    const startDate = new Date(parseInt(year), parseInt(month) - 1, 1);
    const endDate = new Date(parseInt(year), parseInt(month), 0);
    
    filterDonationsByDate(startDate, endDate);
}

function filterDonationsByDate(startDate, endDate) {
    const rows = document.querySelectorAll('.donation-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const donationDateCell = row.querySelector('.date-value');
        if (!donationDateCell) return;
        
        const donationDate = new Date(donationDateCell.textContent.trim());
        let shouldShow = true;
        
        if (startDate && donationDate < startDate) shouldShow = false;
        if (endDate && donationDate > endDate) shouldShow = false;
        
        if (shouldShow) {
            // Only show if it also passes other active filters
            const currentStatusFilter = document.querySelector('.status-filter .filter-btn.active')?.dataset.filter || 'all';
            if (currentStatusFilter !== 'all') {
                const rowStatus = row.dataset.status;
                const shouldShowStatus = currentStatusFilter === 'pending' ? 
                    ['pending', 'scheduled'].includes(rowStatus) :
                    currentStatusFilter === 'approved' ? 
                    ['approved', 'completed', 'confirmed'].includes(rowStatus) :
                    currentStatusFilter === 'rejected' ?
                    ['rejected', 'cancelled'].includes(rowStatus) :
                    currentStatusFilter === 'blood' ?
                    row.dataset.type === 'blood' :
                    rowStatus === currentStatusFilter;
                
                if (!shouldShowStatus) shouldShow = false;
            }
        }
        
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
    });
    
    updateFilterResults(visibleCount);
}

function clearAllFilters() {
    // Reset all filter controls
    document.getElementById('dateFilter').value = 'all';
    document.getElementById('monthFilter').value = '';
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    document.getElementById('customDateRange').style.display = 'none';
    
    // Reset status filter
    document.querySelectorAll('.status-filter .filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector('.status-filter .filter-btn[data-filter="all"]').classList.add('active');
    
    // Show all rows
    document.querySelectorAll('.donation-row').forEach(row => {
        row.style.display = '';
    });
    
    updateFilterResults(document.querySelectorAll('.donation-row').length);
}

function updateFilterResults(count) {
    const totalCount = document.querySelectorAll('.donation-row').length;
    
    // Update or create results indicator
    let resultsDiv = document.querySelector('.filter-results');
    if (!resultsDiv) {
        resultsDiv = document.createElement('div');
        resultsDiv.className = 'filter-results';
        document.querySelector('.action-bar').insertAdjacentElement('afterend', resultsDiv);
    }
    
    if (count === totalCount) {
        resultsDiv.style.display = 'none';
    } else {
        resultsDiv.style.display = 'block';
        resultsDiv.innerHTML = `<i class="fas fa-filter"></i> Showing ${count} of ${totalCount} donations`;
    }
}

function applyAllFilters() {
    const statusFilter = document.querySelector('.status-filter .filter-btn.active')?.dataset.filter || 'all';
    const rows = document.querySelectorAll('.donation-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        let shouldShow = true;
        
        // Apply status/type filter
        if (statusFilter !== 'all') {
            const rowStatus = row.dataset.status;
            const rowType = row.dataset.type;
            
            const shouldShowStatus = statusFilter === 'pending' ? 
                ['pending', 'scheduled'].includes(rowStatus) :
                statusFilter === 'approved' ? 
                ['approved', 'completed', 'confirmed'].includes(rowStatus) :
                statusFilter === 'rejected' ?
                ['rejected', 'cancelled'].includes(rowStatus) :
                statusFilter === 'blood' ?
                rowType === 'blood' :
                rowStatus === statusFilter;
            
            if (!shouldShowStatus) shouldShow = false;
        }
        
        // Apply date filter if active
        const dateFilterValue = document.getElementById('dateFilter').value;
        const monthFilterValue = document.getElementById('monthFilter').value;
        
        if (dateFilterValue !== 'all' || monthFilterValue) {
            // Re-check date constraints
            const donationDateCell = row.querySelector('.date-value');
            if (donationDateCell) {
                const donationDate = new Date(donationDateCell.textContent.trim());
                
                if (monthFilterValue) {
                    const [year, month] = monthFilterValue.split('-');
                    const monthStart = new Date(parseInt(year), parseInt(month) - 1, 1);
                    const monthEnd = new Date(parseInt(year), parseInt(month), 0);
                    
                    if (donationDate < monthStart || donationDate > monthEnd) {
                        shouldShow = false;
                    }
                } else if (dateFilterValue === 'custom') {
                    const startDate = document.getElementById('startDate').value;
                    const endDate = document.getElementById('endDate').value;
                    
                    if (startDate && donationDate < new Date(startDate)) shouldShow = false;
                    if (endDate && donationDate > new Date(endDate)) shouldShow = false;
                }
            }
        }
        
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
    });
    
    updateFilterResults(visibleCount);
}

function toggleDonationFields() {
    const type = document.getElementById('donation_type')?.value;
    const monetaryFields = document.getElementById('monetary-fields');
    const bloodFields = document.getElementById('blood-fields');
    const inkindFields = document.getElementById('inkind-fields');
    
    if (!type) return;
    
    // Hide all fields
    if (monetaryFields) monetaryFields.style.display = 'none';
    if (bloodFields) bloodFields.style.display = 'none';
    if (inkindFields) inkindFields.style.display = 'none';
    
    // Show relevant fields and update status options
    const statusSelect = document.getElementById('status');
    if (statusSelect) {
        statusSelect.innerHTML = '';
        
        if (type === 'monetary') {
            if (monetaryFields) monetaryFields.style.display = 'block';
            statusSelect.innerHTML = `
                <option value="pending">Pending</option>
                <option value="approved">Pre-approved</option>
            `;
        } else if (type === 'blood') {
            if (bloodFields) bloodFields.style.display = 'block';
            statusSelect.innerHTML = `
                <option value="scheduled">Scheduled</option>
                <option value="confirmed">Confirmed</option>
            `;
        } else {
            if (inkindFields) inkindFields.style.display = 'block';
            statusSelect.innerHTML = `
                <option value="pending">Pending</option>
                <option value="approved">Pre-approved</option>
            `;
        }
    }
}

function confirmDeleteDonation(donationId, donationType, donorName, e) {
    if (e) e.preventDefault();
    const safeName = String(donorName).replace(/[<>&"']/g, '');
    const safeId = String(donationId).replace(/[^0-9]/g, '');
    
    if (confirm(`Are you sure you want to delete the ${donationType} donation from ${safeName}? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_donation';
        deleteInput.value = '1';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'donation_id';
        idInput.value = safeId;
        
        const typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'donation_type';
        typeInput.value = donationType;
        
        form.appendChild(deleteInput);
        form.appendChild(idInput);
        form.appendChild(typeInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Enhanced error handling for modal operations
function handleModalError(operation, error) {
    console.error(`Modal ${operation} error:`, error);
    alert(`An error occurred while ${operation}. Please refresh the page and try again.`);
}

// Auto-hide alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);

// Improved modal closing - only close when clicking on the modal backdrop
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        if (e.target.id === 'donationModal') closeModal();
        if (e.target.id === 'approvalModal') closeApprovalModal();
        if (e.target.id === 'bulkModal') closeBulkModal();
        if (e.target.id === 'detailsModal') closeDetailsModal();
    }
});

// Add escape key handler for modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close any open modals
        const modals = document.querySelectorAll('.modal.active');
        modals.forEach(modal => {
            modal.classList.remove('active');
        });
    }
});

// Initialize everything when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize date field
    const donationDateField = document.getElementById('donation_date');
    if (donationDateField) {
        donationDateField.valueAsDate = new Date();
    }
    
    // Initialize donation fields
    toggleDonationFields();
    updateBulkActions();
    
    // Add event listeners for checkboxes
    const checkboxes = document.querySelectorAll('.donation-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActions);
    });
    
    // Handle bulk form submission
    const bulkForm = document.getElementById('bulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const selectedCheckboxes = document.querySelectorAll('.donation-checkbox:checked');
            
            if (selectedCheckboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one donation.');
                return;
            }
            
            selectedCheckboxes.forEach(checkbox => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'selected_donations[]';
                hiddenInput.value = checkbox.value;
                this.appendChild(hiddenInput);
            });
        });
    }
    
    // Update action button event listeners to prevent default behavior
    document.querySelectorAll('.btn-view').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const donationData = JSON.parse(this.getAttribute('data-donation') || '{}');
            viewDonationDetails(donationData, e);
        });
    });
    
    document.querySelectorAll('.btn-edit').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const donationData = JSON.parse(this.getAttribute('data-donation') || '{}');
            openEditModal(donationData, e);
        });
    });
    
    // Initialize filter results
    updateFilterResults(document.querySelectorAll('.donation-row').length);
    
    // Set up search functionality
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.donation-row');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const donorName = row.querySelector('.donor-name')?.textContent.toLowerCase() || '';
                const donorEmail = row.querySelector('.donor-contact')?.textContent.toLowerCase() || '';
                const donationType = row.dataset.type || '';
                const donationId = row.querySelector('.donation-id')?.textContent.toLowerCase() || '';
                const status = row.dataset.status || '';
                
                const shouldShow = donorName.includes(searchTerm) || 
                                  donorEmail.includes(searchTerm) || 
                                  donationType.includes(searchTerm) ||
                                  donationId.includes(searchTerm) ||
                                  status.includes(searchTerm);
                
                row.style.display = shouldShow ? '' : 'none';
                if (shouldShow) visibleCount++;
            });
            
            updateFilterResults(visibleCount);
        });
    }
    
    // Set up filter change handlers
    const dateFilterSelect = document.getElementById('dateFilter');
    if (dateFilterSelect) {
        dateFilterSelect.addEventListener('change', applyDateFilter);
    }
    
    const monthFilterSelect = document.getElementById('monthFilter');
    if (monthFilterSelect) {
        monthFilterSelect.addEventListener('change', applyMonthFilter);
    }
    
    const startDateInput = document.getElementById('startDate');
    if (startDateInput) {
        startDateInput.addEventListener('change', applyCustomDateFilter);
    }
    
    const endDateInput = document.getElementById('endDate');
    if (endDateInput) {
        endDateInput.addEventListener('change', applyCustomDateFilter);
    }
    
    // Set up status filter buttons
    document.querySelectorAll('.status-filter .filter-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const buttons = document.querySelectorAll('.status-filter .filter-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            applyAllFilters();
        });
    });
    
    // Initialize form validation
    const donationForm = document.getElementById('donationForm');
    if (donationForm) {
        donationForm.addEventListener('submit', function(e) {
            const donationType = document.getElementById('donation_type').value;
            const donorId = document.getElementById('donor_id').value;
            const donationDate = document.getElementById('donation_date').value;
            
            if (!donationType || !donorId || !donationDate) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return;
            }
            
            // Type-specific validation
            if (donationType === 'monetary') {
                const amount = document.getElementById('amount').value;
                const paymentMethod = document.getElementById('payment_method').value;
                
                if (!amount || amount <= 0 || !paymentMethod) {
                    e.preventDefault();
                    alert('Please enter a valid amount and payment method.');
                    return;
                }
            } else if (donationType === 'blood') {
                const bloodType = document.getElementById('blood_type').value;
                const emergencyContact = document.getElementById('emergency_contact').value;
                
                if (!bloodType || !emergencyContact) {
                    e.preventDefault();
                    alert('Please select blood type and enter emergency contact.');
                    return;
                }
            } else if (donationType === 'in_kind') {
                const itemDescription = document.getElementById('item_description').value;
                const quantity = document.getElementById('quantity').value;
                
                if (!itemDescription || !quantity || quantity <= 0) {
                    e.preventDefault();
                    alert('Please enter item description and valid quantity.');
                    return;
                }
            }
        });
    }
    
    // Initialize approval form validation
    const approvalForm = document.getElementById('approvalForm');
    if (approvalForm) {
        approvalForm.addEventListener('submit', function(e) {
            const donationId = document.getElementById('approvalDonationId').value;
            const donationType = document.getElementById('approvalDonationType').value;
            const newStatus = document.getElementById('approvalNewStatus').value;
            
            if (!donationId || !donationType || !newStatus) {
                e.preventDefault();
                alert('Missing approval information. Please try again.');
                return;
            }
            
            // Confirm action
            const statusText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            if (!confirm(`Are you sure you want to ${statusText.toLowerCase()} this donation?`)) {
                e.preventDefault();
                return;
            }
        });
    }
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K to focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
        
        // Ctrl/Cmd + N to open new donation modal
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            openCreateModal();
        }
    });
    
    // Add loading states for forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitButton.disabled = true;
                
                // Re-enable after 5 seconds in case of network issues
                setTimeout(() => {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                }, 5000);
            }
        });
    });
    
    // Add tooltips for action buttons
    document.querySelectorAll('[title]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            const title = this.getAttribute('title');
            if (title) {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = title;
                tooltip.style.cssText = `
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 5px 10px;
                    border-radius: 4px;
                    font-size: 12px;
                    z-index: 1000;
                    pointer-events: none;
                    white-space: nowrap;
                `;
                
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.left = rect.left + 'px';
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
                
                this._tooltip = tooltip;
            }
        });
        
        element.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                document.body.removeChild(this._tooltip);
                this._tooltip = null;
            }
        });
    });
    
    // Add print functionality
    window.printDonations = function() {
        const printWindow = window.open('', '_blank');
        const visibleRows = document.querySelectorAll('.donation-row:not([style*="display: none"])');
        
        let printContent = `
            <html>
                <head>
                    <title>Donation Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .badge { padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                        .badge-primary { background: #007bff; color: white; }
                        .badge-danger { background: #dc3545; color: white; }
                        .badge-info { background: #17a2b8; color: white; }
                        .status-badge { padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                        .approved { background: #28a745; color: white; }
                        .pending { background: #ffc107; color: black; }
                        .rejected { background: #dc3545; color: white; }
                        @media print { .no-print { display: none; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Donation Management Report</h1>
                        <p>Generated on ${new Date().toLocaleDateString()}</p>
                        <p>Total Records: ${visibleRows.length}</p>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Type</th>
                                <th>Donor</th>
                                <th>Amount/Details</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        visibleRows.forEach(row => {
            const id = row.querySelector('.donation-id')?.textContent || '';
            const type = row.dataset.type || '';
            const donor = row.querySelector('.donor-name')?.textContent || '';
            const amount = row.querySelector('.amount-value')?.textContent || 
                         row.querySelector('.blood-badge')?.textContent || 'N/A';
            const date = row.querySelector('.date-value')?.textContent || '';
            const status = row.dataset.status || '';
            
            printContent += `
                <tr>
                    <td>${id}</td>
                    <td>${type}</td>
                    <td>${donor}</td>
                    <td>${amount}</td>
                    <td>${date}</td>
                    <td>${status}</td>
                </tr>
            `;
        });
        
        printContent += `
                        </tbody>
                    </table>
                </body>
            </html>
        `;
        
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.print();
    };
    
    // Add export to CSV functionality
    window.exportDonationsCSV = function() {
        const visibleRows = document.querySelectorAll('.donation-row:not([style*="display: none"])');
        let csvContent = 'ID,Type,Donor,Email,Amount/Details,Date,Status\n';
        
        visibleRows.forEach(row => {
            const id = row.querySelector('.donation-id')?.textContent.replace('#', '') || '';
            const type = row.dataset.type || '';
            const donor = row.querySelector('.donor-name')?.textContent || '';
            const email = row.querySelector('.donor-contact')?.textContent || '';
            const amount = row.querySelector('.amount-value')?.textContent || 
                          row.querySelector('.blood-badge')?.textContent || 'N/A';
            const date = row.querySelector('.date-value')?.textContent || '';
            const status = row.dataset.status || '';
            
            csvContent += `"${id}","${type}","${donor}","${email}","${amount}","${date}","${status}"\n`;
        });
        
        const blob = new Blob([csvContent], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `donations_export_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    };
});
  </script>
</body>
</html>