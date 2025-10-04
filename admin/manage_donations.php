<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $donationId = filter_var($_POST['donation_id'], FILTER_VALIDATE_INT);
    $donationType = filter_var($_POST['donation_type'], FILTER_SANITIZE_STRING);
    $newStatus = filter_var($_POST['new_status'], FILTER_SANITIZE_STRING);
    $notes = filter_var($_POST['notes'] ?? '', FILTER_SANITIZE_STRING);
    
    $validStatuses = ['pending', 'approved', 'declined'];
    
    if (!$donationId || !in_array($donationType, ['monetary', 'inkind']) || !in_array($newStatus, $validStatuses)) {
        $errorMessage = "Invalid request parameters.";
    } else {
        try {
            $table = $donationType === 'monetary' ? 'donations' : 'in_kind_donations';
            
            $stmt = $pdo->prepare("
                UPDATE $table 
                SET status = ?, approval_notes = ?, approved_by = ?, approved_date = NOW()
                WHERE donation_id = ?
            ");
            
            if ($stmt->execute([$newStatus, $notes, $_SESSION['user_id'], $donationId])) {
                $successMessage = "Donation status updated to " . ucfirst($newStatus);
            } else {
                $errorMessage = "Failed to update donation status.";
            }
        } catch (Exception $e) {
            $errorMessage = "Error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$dateFilter = $_GET['date_range'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'newest';
$searchTerm = $_GET['search'] ?? '';

// Build query
$whereConditions = [];
$params = [];

if ($statusFilter !== 'all') {
    $whereConditions[] = "status = ?";
    $params[] = $statusFilter;
}

// Date filtering
if ($dateFilter !== 'all') {
    $today = date('Y-m-d');
    switch($dateFilter) {
        case 'today':
            $whereConditions[] = "DATE(donation_date) = ?";
            $params[] = $today;
            break;
        case 'week':
            $whereConditions[] = "donation_date >= DATE_SUB(?, INTERVAL 7 DAY)";
            $params[] = $today;
            break;
        case 'month':
            $whereConditions[] = "donation_date >= DATE_SUB(?, INTERVAL 30 DAY)";
            $params[] = $today;
            break;
        case 'year':
            $whereConditions[] = "YEAR(donation_date) = YEAR(?)";
            $params[] = $today;
            break;
    }
}

if ($searchTerm) {
    $whereConditions[] = "(donor.name LIKE ? OR donor.email LIKE ? OR donor.phone LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Sorting
$orderBy = match($sortBy) {
    'oldest' => 'ORDER BY d.created_at ASC',
    'amount_high' => 'ORDER BY d.amount DESC',
    'amount_low' => 'ORDER BY d.amount ASC',
    'name' => 'ORDER BY donor.name ASC',
    default => 'ORDER BY d.created_at DESC',
};

try {
    // Get monetary donations
    if ($typeFilter === 'all' || $typeFilter === 'monetary') {
        $monetaryQuery = "
            SELECT 
                d.donation_id,
                d.amount,
                d.donation_date,
                d.payment_method,
                d.payment_receipt,
                d.message,
                d.status,
                d.approval_notes,
                d.approved_date,
                d.created_at,
                donor.donor_id,
                donor.name AS donor_name,
                donor.email AS donor_email,
                donor.phone AS donor_phone,
                approver.username AS approved_by_name,
                'monetary' AS donation_type
            FROM donations d
            JOIN donors donor ON d.donor_id = donor.donor_id
            LEFT JOIN users approver ON d.approved_by = approver.user_id
            $whereClause
            $orderBy
        ";
        
        $stmt = $pdo->prepare($monetaryQuery);
        $stmt->execute($params);
        $monetaryDonations = $stmt->fetchAll();
    } else {
        $monetaryDonations = [];
    }
    
    // Get in-kind donations
    if ($typeFilter === 'all' || $typeFilter === 'inkind') {
        $inkindQuery = "
            SELECT 
                d.donation_id,
                d.item_description,
                d.quantity,
                d.estimated_value AS amount,
                d.donation_date,
                d.purpose,
                d.status,
                d.approval_notes,
                d.approved_date,
                d.created_at,
                donor.donor_id,
                donor.name AS donor_name,
                donor.email AS donor_email,
                donor.phone AS donor_phone,
                approver.username AS approved_by_name,
                'inkind' AS donation_type
            FROM in_kind_donations d
            JOIN donors donor ON d.donor_id = donor.donor_id
            LEFT JOIN users approver ON d.approved_by = approver.user_id
            $whereClause
            $orderBy
        ";
        
        $stmt = $pdo->prepare($inkindQuery);
        $stmt->execute($params);
        $inkindDonations = $stmt->fetchAll();
    } else {
        $inkindDonations = [];
    }
    
    // Combine and sort
    $allDonations = array_merge($monetaryDonations, $inkindDonations);
    
    // Re-sort combined results
    if ($sortBy === 'newest') {
        usort($allDonations, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    } elseif ($sortBy === 'oldest') {
        usort($allDonations, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));
    } elseif ($sortBy === 'amount_high') {
        usort($allDonations, fn($a, $b) => $b['amount'] <=> $a['amount']);
    } elseif ($sortBy === 'amount_low') {
        usort($allDonations, fn($a, $b) => $a['amount'] <=> $b['amount']);
    } elseif ($sortBy === 'name') {
        usort($allDonations, fn($a, $b) => strcasecmp($a['donor_name'], $b['donor_name']));
    }
    
    // Statistics
    $stats = [
        'total' => count($allDonations),
        'pending' => count(array_filter($allDonations, fn($d) => $d['status'] === 'pending')),
        'approved' => count(array_filter($allDonations, fn($d) => $d['status'] === 'approved')),
        'declined' => count(array_filter($allDonations, fn($d) => $d['status'] === 'declined')),
        'total_amount' => array_sum(array_column($allDonations, 'amount')),
    ];
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $allDonations = [];
    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'declined' => 0, 'total_amount' => 0];
    $errorMessage = "Error loading donations.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Donation Management - PRC Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/donations.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>

  <div class="donations-container">

    
    <div class="page-header">
      <div class="header-content">
        <h1><i class="fas fa-hand-holding-heart"></i> Donation Management</h1>
        <p>Review and manage donations submitted by users</p>
      </div>
    </div>

    <?php if ($errorMessage): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($errorMessage) ?></span>
      </div>
    <?php endif; ?>
    
    <?php if ($successMessage): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($successMessage) ?></span>
      </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
      <div class="stat-card stat-total">
        <div class="stat-icon">
          <i class="fas fa-hand-holding-heart"></i>
        </div>
        <div class="stat-content">
          <div class="stat-value"><?= $stats['total'] ?></div>
          <div class="stat-label">Total Donations</div>
        </div>
      </div>
      
      <div class="stat-card stat-pending">
        <div class="stat-icon">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
          <div class="stat-value"><?= $stats['pending'] ?></div>
          <div class="stat-label">Pending Review</div>
        </div>
      </div>
      
      <div class="stat-card stat-approved">
        <div class="stat-icon">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
          <div class="stat-value"><?= $stats['approved'] ?></div>
          <div class="stat-label">Approved</div>
        </div>
      </div>
      
      <div class="stat-card stat-declined">
        <div class="stat-icon">
          <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-content">
          <div class="stat-value"><?= $stats['declined'] ?></div>
          <div class="stat-label">Declined</div>
        </div>
      </div>
      
      <div class="stat-card stat-amount">
        <div class="stat-icon">
          <i class="fas fa-peso-sign"></i>
        </div>
        <div class="stat-content">
          <div class="stat-value">₱<?= number_format($stats['total_amount'], 2) ?></div>
          <div class="stat-label">Total Amount</div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
      <div class="filter-left">
        <form method="GET" class="search-form">
          <div class="search-input-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search by donor name, email, or phone..." 
                   value="<?= htmlspecialchars($searchTerm) ?>">
          </div>
          <button type="submit" class="btn-search">
            <i class="fas fa-search"></i>
            Search
          </button>
        </form>
      </div>
      
      <div class="filter-right">
        <select name="status" onchange="applyFilters()" id="statusFilter" class="filter-select">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="declined" <?= $statusFilter === 'declined' ? 'selected' : '' ?>>Declined</option>
        </select>
        
        <select name="type" onchange="applyFilters()" id="typeFilter" class="filter-select">
          <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
          <option value="monetary" <?= $typeFilter === 'monetary' ? 'selected' : '' ?>>Monetary</option>
          <option value="inkind" <?= $typeFilter === 'inkind' ? 'selected' : '' ?>>In-Kind</option>
        </select>
        
        <select name="date_range" onchange="applyFilters()" id="dateFilter" class="filter-select">
          <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
          <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
          <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This Month</option>
          <option value="year" <?= $dateFilter === 'year' ? 'selected' : '' ?>>This Year</option>
        </select>
        
        <select name="sort" onchange="applyFilters()" id="sortFilter" class="filter-select">
          <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest" <?= $sortBy === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="amount_high" <?= $sortBy === 'amount_high' ? 'selected' : '' ?>>Amount: High to Low</option>
          <option value="amount_low" <?= $sortBy === 'amount_low' ? 'selected' : '' ?>>Amount: Low to High</option>
          <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Donor Name (A-Z)</option>
        </select>
        
        <button onclick="clearFilters()" class="btn-clear" title="Clear Filters">
          <i class="fas fa-redo"></i>
        </button>
      </div>
    </div>

    <!-- Donations Table -->
    <div class="table-container">
      <div class="table-header">
        <h2 class="table-title">All Donations (<?= count($allDonations) ?>)</h2>
        <div class="table-actions">
          <button onclick="exportToCSV()" class="btn-export">
            <i class="fas fa-file-csv"></i>
            Export CSV
          </button>
        </div>
      </div>
      
      <?php if (empty($allDonations)): ?>
        <div class="empty-state">
          <i class="fas fa-inbox"></i>
          <h3>No donations found</h3>
          <p>Donations submitted by users will appear here</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="donations-table">
            <thead>
              <tr>
                <th>Donor</th>
                <th>Type & Details</th>
                <th>Amount</th>
                <th>Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allDonations as $d): 
                $statusClass = match($d['status']) {
                  'approved' => 'status-approved',
                  'pending' => 'status-pending',
                  'declined' => 'status-declined',
                  default => 'status-pending'
                };
              ?>
                <tr class="donation-row">
                  <td>
                    <div class="donor-cell">
                      <div class="donor-avatar">
                        <?= strtoupper(substr($d['donor_name'], 0, 1)) ?>
                      </div>
                      <div class="donor-info">
                        <div class="donor-name"><?= htmlspecialchars($d['donor_name']) ?></div>
                        <div class="donor-email"><?= htmlspecialchars($d['donor_email']) ?></div>
                        <div class="donor-phone">
                          <i class="fas fa-phone"></i>
                          <?= htmlspecialchars($d['donor_phone']) ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="type-cell">
                      <span class="type-badge type-<?= $d['donation_type'] ?>">
                        <i class="fas fa-<?= $d['donation_type'] === 'monetary' ? 'money-bill-wave' : 'box-open' ?>"></i>
                        <?= ucfirst($d['donation_type']) ?>
                      </span>
                      <div class="type-details">
                        <?php if ($d['donation_type'] === 'monetary'): ?>
                          <?= ucfirst(str_replace('_', ' ', $d['payment_method'])) ?>
                        <?php else: ?>
                          <?= htmlspecialchars($d['item_description']) ?>
                          <span class="quantity">(Qty: <?= $d['quantity'] ?>)</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="amount-cell">
                      ₱<?= number_format($d['amount'], 2) ?>
                    </div>
                  </td>
                  <td>
                    <div class="date-cell">
                      <div class="date-main"><?= date('M d, Y', strtotime($d['donation_date'])) ?></div>
                      <div class="date-sub">
                        <i class="far fa-clock"></i>
                        <?= date('M d, Y g:i A', strtotime($d['created_at'])) ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="status-cell">
                      <span class="status-badge <?= $statusClass ?>">
                        <i class="fas fa-<?= $d['status'] === 'approved' ? 'check-circle' : ($d['status'] === 'pending' ? 'clock' : 'times-circle') ?>"></i>
                        <?= ucfirst($d['status']) ?>
                      </span>
                      <?php if ($d['approved_date']): ?>
                        <div class="status-info">
                          by <?= htmlspecialchars($d['approved_by_name']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div class="action-buttons">
                      <button class="btn-action btn-view" 
                              onclick='viewDonation(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                              title="View Details">
                        <i class="fas fa-eye"></i>
                      </button>
                      
                      <?php if ($d['status'] === 'pending'): ?>
                        <button class="btn-action btn-approve" 
                                onclick='updateStatus(<?= $d["donation_id"] ?>, "<?= $d["donation_type"] ?>", "approved")' 
                                title="Approve">
                          <i class="fas fa-check"></i>
                        </button>
                        <button class="btn-action btn-decline" 
                                onclick='updateStatus(<?= $d["donation_id"] ?>, "<?= $d["donation_type"] ?>", "declined")' 
                                title="Decline">
                          <i class="fas fa-times"></i>
                        </button>
                      <?php else: ?>
                        <button class="btn-action btn-revert" 
                                onclick='updateStatus(<?= $d["donation_id"] ?>, "<?= $d["donation_type"] ?>", "pending")' 
                                title="Mark as Pending">
                          <i class="fas fa-undo"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- View Details Modal -->
  <div class="modal" id="viewModal">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title">
            <i class="fas fa-file-invoice"></i>
            Donation Details
          </h2>
          <button class="modal-close" onclick="closeViewModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="modal-body" id="donationDetails"></div>
      </div>
    </div>
  </div>

  <!-- Status Update Modal -->
  <div class="modal" id="statusModal">
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <div class="modal-header">
          <h2 class="modal-title" id="statusModalTitle">Update Status</h2>
          <button class="modal-close" onclick="closeStatusModal()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <form method="POST">
          <div class="modal-body">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="donation_id" id="statusDonationId">
            <input type="hidden" name="donation_type" id="statusDonationType">
            <input type="hidden" name="new_status" id="statusNewStatus">
            
            <div class="form-group">
              <label for="notes" class="form-label">
                <i class="fas fa-sticky-note"></i>
                Notes (Optional)
              </label>
              <textarea id="notes" name="notes" rows="4" class="form-control" 
                        placeholder="Add any notes about this decision..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">
              Cancel
            </button>
            <button type="submit" class="btn btn-primary" id="statusSubmitBtn">
              Confirm
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
    <?php include 'floating_notification_widget.php'; ?>
  
  <script>
  function viewDonation(donation) {
    const details = document.getElementById('donationDetails');
    
    let html = `
      <div class="detail-grid">
        <div class="detail-section">
          <h4 class="detail-title">
            <i class="fas fa-user"></i>
            Donor Information
          </h4>
          <div class="detail-item">
            <span class="detail-label">Name:</span>
            <span class="detail-value">${donation.donor_name}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Email:</span>
            <span class="detail-value">${donation.donor_email}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Phone:</span>
            <span class="detail-value">${donation.donor_phone}</span>
          </div>
        </div>
        
        <div class="detail-section">
          <h4 class="detail-title">
            <i class="fas fa-info-circle"></i>
            Donation Details
          </h4>
          <div class="detail-item">
            <span class="detail-label">Type:</span>
            <span class="detail-value">${donation.donation_type === 'monetary' ? 'Monetary' : 'In-Kind'}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Amount:</span>
            <span class="detail-value amount-highlight">₱${parseFloat(donation.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Date:</span>
            <span class="detail-value">${new Date(donation.donation_date).toLocaleDateString()}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Submitted:</span>
            <span class="detail-value">${new Date(donation.created_at).toLocaleString()}</span>
          </div>
    `;
    
    if (donation.donation_type === 'monetary') {
      html += `
          <div class="detail-item">
            <span class="detail-label">Payment Method:</span>
            <span class="detail-value">${donation.payment_method.replace('_', ' ').toUpperCase()}</span>
          </div>
      `;
      if (donation.message) {
        html += `
          <div class="detail-item">
            <span class="detail-label">Message:</span>
            <span class="detail-value">${donation.message}</span>
          </div>
        `;
      }
    } else {
      html += `
          <div class="detail-item">
            <span class="detail-label">Item:</span>
            <span class="detail-value">${donation.item_description}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">Quantity:</span>
            <span class="detail-value">${donation.quantity}</span>
          </div>
      `;
      if (donation.purpose) {
        html += `
          <div class="detail-item">
            <span class="detail-label">Purpose:</span>
            <span class="detail-value">${donation.purpose}</span>
          </div>
        `;
      }
    }
    
    html += '</div>';
    
    // Receipt section
    if (donation.donation_type === 'monetary' && donation.payment_receipt) {
      html += `
        <div class="detail-section detail-section-full">
          <h4 class="detail-title">
            <i class="fas fa-receipt"></i>
            Payment Receipt
          </h4>
          <div class="receipt-viewer">
            <iframe src="../${donation.payment_receipt}" class="receipt-frame"></iframe>
            <div class="receipt-actions">
              <a href="../${donation.payment_receipt}" target="_blank" class="btn btn-secondary">
                <i class="fas fa-external-link-alt"></i>
                Open in New Tab
              </a>
              <a href="../${donation.payment_receipt}" download class="btn btn-secondary">
                <i class="fas fa-download"></i>
                Download
              </a>
            </div>
          </div>
        </div>
      `;
    }
    
    // Admin notes section
    if (donation.approval_notes) {
      html += `
        <div class="detail-section detail-section-full">
          <h4 class="detail-title">
            <i class="fas fa-sticky-note"></i>
            Admin Notes
          </h4>
          <div class="admin-notes">
            ${donation.approval_notes}
          </div>
          ${donation.approved_by_name ? `
            <div class="notes-meta">
              <i class="fas fa-user"></i>
              ${donation.approved_by_name} 
              <i class="far fa-clock"></i>
              ${new Date(donation.approved_date).toLocaleString()}
            </div>
          ` : ''}
        </div>
      `;
    }
    
    html += '</div>';
    
    details.innerHTML = html;
    document.getElementById('viewModal').classList.add('active');
  }
  
  function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
  }
  
  function updateStatus(donationId, donationType, newStatus) {
    document.getElementById('statusDonationId').value = donationId;
    document.getElementById('statusDonationType').value = donationType;
    document.getElementById('statusNewStatus').value = newStatus;
    
    const statusText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
    document.getElementById('statusModalTitle').innerHTML = `<i class="fas fa-${newStatus === 'approved' ? 'check' : (newStatus === 'pending' ? 'clock' : 'times')}-circle"></i> ${statusText} Donation`;
    document.getElementById('statusSubmitBtn').innerHTML = `<i class="fas fa-${newStatus === 'approved' ? 'check' : (newStatus === 'pending' ? 'undo' : 'times')}"></i> Confirm`;
    
    document.getElementById('statusModal').classList.add('active');
  }
  
  function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('active');
  }
  
  function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const type = document.getElementById('typeFilter').value;
    const dateRange = document.getElementById('dateFilter').value;
    const sort = document.getElementById('sortFilter').value;
    const search = document.querySelector('input[name="search"]').value;
    
    const params = new URLSearchParams();
    if (status !== 'all') params.append('status', status);
    if (type !== 'all') params.append('type', type);
    if (dateRange !== 'all') params.append('date_range', dateRange);
    if (sort !== 'newest') params.append('sort', sort);
    if (search) params.append('search', search);
    
    window.location.href = '?' + params.toString();
  }
  
  function clearFilters() {
    window.location.href = window.location.pathname;
  }
  
  function exportToCSV() {
    const rows = [['Donor Name', 'Email', 'Phone', 'Type', 'Amount', 'Date', 'Status']];
    
    document.querySelectorAll('.donation-row').forEach(row => {
      const cells = row.querySelectorAll('td');
      const donorName = cells[0].querySelector('.donor-name').textContent.trim();
      const donorEmail = cells[0].querySelector('.donor-email').textContent.trim();
      const donorPhone = cells[0].querySelector('.donor-phone').textContent.trim();
      const type = cells[1].querySelector('.type-badge').textContent.trim();
      const amount = cells[2].textContent.trim();
      const date = cells[3].querySelector('.date-main').textContent.trim();
      const status = cells[4].querySelector('.status-badge').textContent.trim();
      
      rows.push([donorName, donorEmail, donorPhone, type, amount, date, status]);
    });
    
    const csv = rows.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `donations_export_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  }
  
  // Close modals on backdrop click
  document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
      if (e.target === this) {
        this.classList.remove('active');
      }
    });
  });
  
  // Escape key to close modals
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal.active').forEach(modal => {
        modal.classList.remove('active');
      });
    }
  });
  
  // Auto-hide alerts
  setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
      alert.style.transition = 'opacity 0.3s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 300);
    });
  }, 5000);
  
  // Search form submission
  document.querySelector('.search-form').addEventListener('submit', function(e) {
    e.preventDefault();
    applyFilters();
  });
  </script>
</body>
</html>