<?php

require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $donationId = (int)$_POST['donation_id'];
    $donationType = $_POST['donation_type'];
    $newStatus = $_POST['status'];

    if ($donationType === 'in_kind') {
        $stmt = $pdo->prepare("UPDATE in_kind_donations SET status = ? WHERE donation_id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE donations SET status = ? WHERE donation_id = ?");
    }
    $stmt->execute([$newStatus, $donationId]);
    $successMessage = "Donation status updated successfully.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_donation'])) {
    $donationType = $_POST['donation_type'];
    $donorId = (int)$_POST['donor_id'];
    $amount = (float)$_POST['amount'];
    $donationDate = $_POST['donation_date'];
    $recordedBy = $_SESSION['user_id'];
    $status = 'completed'; 

    if ($donorId && $amount > 0 && $donationDate) {
        if ($donationType === 'monetary') {
            $paymentMethod = $_POST['payment_method'];
            $stmt = $pdo->prepare("INSERT INTO donations (donor_id, amount, donation_date, payment_method, recorded_by, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$donorId, $amount, $donationDate, $paymentMethod, $recordedBy, $status]);
        } else {
            $itemDescription = trim($_POST['item_description']);
            $status = 'pending'; 
            $stmt = $pdo->prepare("INSERT INTO in_kind_donations (donor_id, estimated_value, donation_date, item_description, recorded_by, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$donorId, $amount, $donationDate, $itemDescription, $recordedBy, $status]);
        }
        $successMessage = "Donation recorded successfully.";
    } else {
        $errorMessage = "Please fill all required fields.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_donation'])) {
    $donationId = (int)$_POST['donation_id'];
    $donationType = $_POST['donation_type'];
    $amount = (float)$_POST['amount'];
    $donationDate = $_POST['donation_date'];
    $status = $_POST['status'];

    if ($donationId && $amount > 0 && $donationDate) {
        if ($donationType === 'monetary') {
            $paymentMethod = $_POST['payment_method'];
            $stmt = $pdo->prepare("UPDATE donations SET amount = ?, donation_date = ?, payment_method = ?, status = ? WHERE donation_id = ?");
            $stmt->execute([$amount, $donationDate, $paymentMethod, $status, $donationId]);
        } else {
            $itemDescription = trim($_POST['item_description']);
            $stmt = $pdo->prepare("UPDATE in_kind_donations SET estimated_value = ?, donation_date = ?, item_description = ?, status = ? WHERE donation_id = ?");
            $stmt->execute([$amount, $donationDate, $itemDescription, $status, $donationId]);
        }
        $successMessage = "Donation updated successfully.";
    } else {
        $errorMessage = "Please fill all required fields.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_donation'])) {
    $donationId = (int)$_POST['donation_id'];
    $donationType = $_POST['donation_type'];

    if ($donationId) {
        if ($donationType === 'monetary') {
            $stmt = $pdo->prepare("DELETE FROM donations WHERE donation_id = ?");
        } else {
            $stmt = $pdo->prepare("DELETE FROM in_kind_donations WHERE donation_id = ?");
        }
        $stmt->execute([$donationId]);
        $successMessage = "Donation deleted successfully.";
    }
}


$monetaryDonations = $pdo->query("
    SELECT d.donation_id, r.name AS donor_name, r.email AS donor_email,
           d.amount, d.donation_date, d.payment_method,
           u.username AS recorded_by, 'monetary' AS donation_type,
           d.status
    FROM donations d
    JOIN donors r ON d.donor_id = r.donor_id
    JOIN users u ON d.recorded_by = u.user_id
    ORDER BY d.donation_date DESC
")->fetchAll();

$inkindDonations = $pdo->query("
    SELECT d.donation_id, r.name AS donor_name, r.email AS donor_email,
           d.estimated_value AS amount, d.donation_date, d.item_description,
           u.username AS recorded_by, 'in_kind' AS donation_type, d.status
    FROM in_kind_donations d
    JOIN donors r ON d.donor_id = r.donor_id
    JOIN users u ON d.recorded_by = u.user_id
    ORDER BY d.donation_date DESC
")->fetchAll();

$allDonations = array_merge($monetaryDonations, $inkindDonations);
usort($allDonations, function($a, $b) {
    return strtotime($b['donation_date']) - strtotime($a['donation_date']);
});

$donors = $pdo->query("SELECT donor_id, name, email FROM donors ORDER BY name")->fetchAll();

$monetary_count = $pdo->query("SELECT COUNT(*) FROM donations")->fetchColumn();
$inkind_count = $pdo->query("SELECT COUNT(*) FROM in_kind_donations")->fetchColumn();
$total_donations = $monetary_count + $inkind_count;

$completed_count = $pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'completed'")->fetchColumn() + 
                   $pdo->query("SELECT COUNT(*) FROM in_kind_donations WHERE status = 'completed'")->fetchColumn();
$pending_count = $pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'pending'")->fetchColumn() + 
                 $pdo->query("SELECT COUNT(*) FROM in_kind_donations WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Donations - PRC Portal</title>
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
    <div class="page-header">
      <h1><i class="fas fa-hand-holding-heart"></i> Donation Management</h1>
      <p>Record, update, and manage all donations</p>
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

    <!-- Action Bar -->
    <div class="action-bar">
      <div class="action-bar-left">
        <form method="GET" class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search donations...">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
        </form>
        
        <div class="status-filter">
          <button onclick="filterStatus('all')" class="active">All</button>
          <button onclick="filterStatus('completed')">Completed</button>
          <button onclick="filterStatus('pending')">Pending</button>
        </div>
      </div>
      
      <button class="btn-create" onclick="openCreateModal()">
        <i class="fas fa-plus-circle"></i> Record New Donation
      </button>
    </div>

    <!-- Statistics Overview -->
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
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-money-bill-wave"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $monetary_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Monetary</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-box-open"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $inkind_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">In-Kind</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
          <i class="fas fa-check-circle"></i>
        </div>
        <div>
          <div style="font-size: 1.5rem; font-weight: 700;"><?= $completed_count ?></div>
          <div style="color: var(--gray); font-size: 0.9rem;">Completed</div>
        </div>
      </div>
    </div>

    <!-- Donations Table -->
    <div class="donations-table-wrapper">
      <div class="table-header">
        <h2 class="table-title">All Donations</h2>
      </div>
      
      <?php if (empty($allDonations)): ?>
        <div class="empty-state">
          <i class="fas fa-hand-holding-heart"></i>
          <h3>No donations found</h3>
          <p>Click "Record New Donation" to get started</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Donation Details</th>
              <th>Donor</th>
              <th>Amount/Value</th>
              <th>Date</th>
              <th>Status</th>
              <th>Recorded By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allDonations as $d): 
              $status = !empty($d['status']) ? $d['status'] : 'pending';
              $statusClass = $status === 'completed' ? 'completed' : ($status === 'pending' ? 'pending' : 'other');
            ?>
              <tr>
                <td>
                  <div class="donation-title">
                    <span class="badge <?= $d['donation_type'] === 'monetary' ? 'badge-primary' : 'badge-info' ?>">
                      <?= ucfirst(str_replace('_', ' ', $d['donation_type'])) ?>
                    </span>
                    <?php if ($d['donation_type'] === 'in_kind'): ?>
                      <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                        <?= htmlspecialchars($d['item_description']) ?>
                      </div>
                    <?php else: ?>
                      <div style="font-size: 0.85rem; color: var(--gray); margin-top: 0.2rem;">
                        <?= htmlspecialchars($d['payment_method']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="donor-info">
                    <div><?= htmlspecialchars($d['donor_name']) ?></div>
                    <div style="font-size: 0.85rem; color: var(--gray);"><?= htmlspecialchars($d['donor_email']) ?></div>
                  </div>
                </td>
                <td><?= number_format($d['amount'], 2) ?></td>
                <td><?= date('M d, Y', strtotime($d['donation_date'])) ?></td>
                <td>
                  <span class="status-badge <?= $statusClass ?>">
                    <?= ucfirst($status) ?>
                  </span>
                </td>
                <td><?= htmlspecialchars($d['recorded_by']) ?></td>
                <td class="actions">
                  <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($d)) ?>)">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this donation?');">
                    <input type="hidden" name="delete_donation" value="1">
                    <input type="hidden" name="donation_id" value="<?= $d['donation_id'] ?>">
                    <input type="hidden" name="donation_type" value="<?= $d['donation_type'] ?>">
                    <button type="submit" class="btn-action btn-delete">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Create/Edit Modal -->
  <div class="modal" id="donationModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Record New Donation</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="donationForm">
        <input type="hidden" name="create_donation" value="1" id="formAction">
        <input type="hidden" name="donation_id" id="donationId">
        <input type="hidden" name="donation_type" id="donationTypeInput">
        
        <div class="form-group">
          <label for="donation_type">Donation Type *</label>
          <select id="donation_type" name="donation_type" required onchange="toggleDonationFields()">
            <option value="monetary">Monetary Donation</option>
            <option value="in_kind">In-Kind Donation</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="donor_id">Donor *</label>
          <select id="donor_id" name="donor_id" required>
            <?php foreach ($donors as $donor): ?>
              <option value="<?= $donor['donor_id'] ?>"><?= htmlspecialchars($donor['name']) ?> (<?= htmlspecialchars($donor['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="amount">Amount/Value *</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0" required>
          </div>
          
          <div class="form-group">
            <label for="donation_date">Date *</label>
            <input type="date" id="donation_date" name="donation_date" required>
          </div>
        </div>
        
        <div id="monetary-fields">
          <div class="form-group">
            <label for="payment_method">Payment Method *</label>
            <select id="payment_method" name="payment_method">
              <option value="cash">Cash</option>
              <option value="check">Check</option>
              <option value="credit_card">Credit Card</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="online">Online Payment</option>
            </select>
          </div>
        </div>
        
        <div id="inkind-fields" style="display: none;">
          <div class="form-group">
            <label for="item_description">Item Description *</label>
            <textarea id="item_description" name="item_description"></textarea>
          </div>
          
          <div class="form-group">
            <label for="status">Status *</label>
            <select id="status" name="status">
              <option value="pending">Pending</option>
              <option value="completed">Completed</option>
            </select>
          </div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Donation
        </button>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>
    function openCreateModal() {
      document.getElementById('modalTitle').textContent = 'Record New Donation';
      document.getElementById('formAction').name = 'create_donation';
      document.getElementById('donationForm').reset();
      document.getElementById('donationTypeInput').value = '';
      document.getElementById('donation_type').value = 'monetary';
      toggleDonationFields();
      document.getElementById('donationModal').classList.add('active');
    }
    
    function openEditModal(donation) {
      document.getElementById('modalTitle').textContent = 'Edit Donation';
      document.getElementById('formAction').name = 'update_donation';
      document.getElementById('donationId').value = donation.donation_id;
      document.getElementById('donationTypeInput').value = donation.donation_type;
      document.getElementById('donation_type').value = donation.donation_type;
      document.getElementById('donor_id').value = ''; // Need to set actual donor ID
      document.getElementById('amount').value = donation.amount;
      document.getElementById('donation_date').value = donation.donation_date;
      
      if (donation.donation_type === 'monetary') {
        document.getElementById('payment_method').value = donation.payment_method || 'cash';
      } else {
        document.getElementById('item_description').value = donation.item_description || '';
        document.getElementById('status').value = donation.status || 'pending';
      }
      
      toggleDonationFields();
      document.getElementById('donationModal').classList.add('active');
    }
    
    function closeModal() {
      document.getElementById('donationModal').classList.remove('active');
    }
    
    function filterStatus(status) {
      // Implement filtering logic here
      console.log('Filter by:', status);
    }
    
    function toggleDonationFields() {
      const type = document.getElementById('donation_type').value;
      document.getElementById('monetary-fields').style.display = type === 'monetary' ? 'block' : 'none';
      document.getElementById('inkind-fields').style.display = type === 'in_kind' ? 'block' : 'none';
    }
    
    // Close modal when clicking outside
    document.getElementById('donationModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
  </script>
</body>
</html>