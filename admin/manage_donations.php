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
    <!-- Apply saved sidebar state BEFORE CSS -->
  <?php $collapsed = isset($_COOKIE['sidebarCollapsed']) && $_COOKIE['sidebarCollapsed'] === 'true'; ?>
  <script>
    // Option 1: Set sidebar width early to prevent flicker
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
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
   <link rel="stylesheet" href="../assets/donations.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
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

      <div class="donation-sections">
        
        <section class="create-donation card">
          <h2><i class="fas fa-plus-circle"></i> Record New Donation</h2>
          <form method="POST" class="donation-form">
            <input type="hidden" name="create_donation" value="1">
            
            <div class="form-row">
              <div class="form-group">
                <label for="donation_type">Donation Type</label>
                <select id="donation-type" name="donation_type" required>
                  <option value="monetary">Monetary Donation</option>
                  <option value="in_kind">In-Kind Donation</option>
                </select>
              </div>
              
              <div class="form-group">
                <label for="donor_id">Donor</label>
                <select id="donor_id" name="donor_id" required>
                  <?php foreach ($donors as $donor): ?>
                    <option value="<?= $donor['donor_id'] ?>"><?= htmlspecialchars($donor['name']) ?> (<?= htmlspecialchars($donor['email']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="amount">Amount/Value</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0" required>
              </div>
              
              <div class="form-group">
                <label for="donation_date">Date</label>
                <input type="date" id="donation_date" name="donation_date" required>
              </div>
            </div>
            
            <div id="monetary-fields">
              <div class="form-group">
                <label for="payment_method">Payment Method</label>
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
                <label for="item_description">Item Description</label>
                <textarea id="item_description" name="item_description"></textarea>
              </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i> Record Donation
            </button>
          </form>
        </section>

   
        <section class="existing-donations">
          <div class="section-header">
            <h2><i class="fas fa-list"></i> All Donations</h2>
            <div class="search-box">
              <input type="text" placeholder="Search donations...">
              <button type="submit"><i class="fas fa-search"></i></button>
            </div>
          </div>
          
          <div class="stats-cards">
            <div class="stat-card">
              <div class="stat-icon blue">
                <i class="fas fa-hand-holding-usd"></i>
              </div>
              <div class="stat-content">
                <h3>Total Donations</h3>
                <p><?= $total_donations ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon green">
                <i class="fas fa-money-bill-wave"></i>
              </div>
              <div class="stat-content">
                <h3>Monetary</h3>
                <p><?= $monetary_count ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon purple">
                <i class="fas fa-box-open"></i>
              </div>
              <div class="stat-content">
                <h3>In-Kind</h3>
                <p><?= $inkind_count ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon orange">
                <i class="fas fa-check-circle"></i>
              </div>
              <div class="stat-content">
                <h3>Completed</h3>
                <p><?= $completed_count ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon yellow">
                <i class="fas fa-clock"></i>
              </div>
              <div class="stat-content">
                <h3>Pending</h3>
                <p><?= $pending_count ?></p>
              </div>
            </div>
          </div>
          
          <?php if (empty($allDonations)): ?>
            <div class="empty-state">
              <i class="fas fa-hand-holding-heart"></i>
              <h3>No Donations Found</h3>
              <p>There are no donations to display.</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Donor</th>
                    <th>Details</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Recorded By</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allDonations as $d): 
                    $status = !empty($d['status']) ? $d['status'] : 'pending';
                    $statusClass = 'status-' . $status;
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($d['donation_id']) ?></td>
                      <td>
                        <span class="badge badge-<?= $d['donation_type'] === 'monetary' ? 'primary' : 'info' ?>">
                          <?= ucfirst(str_replace('_', ' ', $d['donation_type'])) ?>
                        </span>
                      </td>
                      <td><?= htmlspecialchars($d['donor_name']) ?></td>
                      <td>
                        <?php if ($d['donation_type'] === 'monetary'): ?>
                          <select name="payment_method" form="update-form-<?= $d['donation_id'] ?>">
                            <option value="cash" <?= $d['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="check" <?= $d['payment_method'] === 'check' ? 'selected' : '' ?>>Check</option>
                            <option value="credit_card" <?= $d['payment_method'] === 'credit_card' ? 'selected' : '' ?>>Credit Card</option>
                            <option value="bank_transfer" <?= $d['payment_method'] === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="online" <?= $d['payment_method'] === 'online' ? 'selected' : '' ?>>Online Payment</option>
                          </select>
                        <?php else: ?>
                          <textarea name="item_description" form="update-form-<?= $d['donation_id'] ?>"><?= htmlspecialchars($d['item_description']) ?></textarea>
                        <?php endif; ?>
                      </td>
                      <td>
                        <input type="number" name="amount" value="<?= htmlspecialchars($d['amount']) ?>" 
                               step="0.01" min="0" form="update-form-<?= $d['donation_id'] ?>" required>
                      </td>
                      <td>
                        <input type="date" name="donation_date" value="<?= htmlspecialchars($d['donation_date']) ?>" 
                               form="update-form-<?= $d['donation_id'] ?>" required>
                      </td>
                      <td>
                        <select name="status" form="update-form-<?= $d['donation_id'] ?>" class="status-select">
                          <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                          <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                          <?php if ($d['donation_type'] === 'monetary'): ?>
                          <option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                          <?php endif; ?>
                        </select>
                      </td>
                      <td><?= htmlspecialchars($d['recorded_by']) ?></td>
                      <td class="actions">
                        <form method="POST" id="update-form-<?= $d['donation_id'] ?>" class="inline-form">
                          <input type="hidden" name="update_donation" value="1">
                          <input type="hidden" name="donation_id" value="<?= $d['donation_id'] ?>">
                          <input type="hidden" name="donation_type" value="<?= $d['donation_type'] ?>">
                          <button type="submit" class="btn btn-sm btn-update">
                            <i class="fas fa-save"></i> Update
                          </button>
                        </form>
                        
                        <form method="POST" class="inline-form" 
                              onsubmit="return confirm('Are you sure you want to delete this donation?')">
                          <input type="hidden" name="delete_donation" value="1">
                          <input type="hidden" name="donation_id" value="<?= $d['donation_id'] ?>">
                          <input type="hidden" name="donation_type" value="<?= $d['donation_type'] ?>">
                          <button type="submit" class="btn btn-sm btn-delete">
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
        </section>
      </div>
    </div>
  </div>

  <script>
    
    document.getElementById('donation-type').addEventListener('change', function() {
      const type = this.value;
      document.getElementById('monetary-fields').style.display = type === 'monetary' ? 'block' : 'none';
      document.getElementById('inkind-fields').style.display = type === 'in_kind' ? 'block' : 'none';
    });
  </script>
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>