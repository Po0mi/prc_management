<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage   = '';
$successMessage = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank'])) {
    $branch_name = trim($_POST['branch_name']);
    $address     = trim($_POST['address']);
    $latitude    = floatval($_POST['latitude']);
    $longitude   = floatval($_POST['longitude']);

    if ($branch_name && $address) {
        $stmt = $pdo->prepare("
          INSERT INTO blood_banks (branch_name, address, latitude, longitude)
          VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$branch_name, $address, $latitude, $longitude]);
        $successMessage = "Blood bank added successfully!";
    } else {
        $errorMessage = "Branch name and address are required.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bank'])) {
    $bank_id = (int)$_POST['bank_id'];
    if ($bank_id) {
       
        $stmtInv = $pdo->prepare("DELETE FROM blood_inventory WHERE bank_id = ?");
        $stmtInv->execute([$bank_id]);

        $stmt = $pdo->prepare("DELETE FROM blood_banks WHERE bank_id = ?");
        $stmt->execute([$bank_id]);
        $successMessage = "Blood bank deleted successfully!";
    }
}


$banksStmt = $pdo->query("
  SELECT bb.bank_id, bb.branch_name, bb.address, bb.latitude, bb.longitude,
         bi.blood_type, bi.units_available
  FROM blood_banks bb
  LEFT JOIN blood_inventory bi ON bb.bank_id = bi.bank_id
  ORDER BY bb.branch_name
");
$rows = $banksStmt->fetchAll();

$banks = [];
foreach ($rows as $r) {
    $id = $r['bank_id'];
    if (!isset($banks[$id])) {
        $banks[$id] = [
            'branch_name' => $r['branch_name'],
            'address'     => $r['address'],
            'latitude'    => $r['latitude'],
            'longitude'   => $r['longitude'],
            'inventory'   => []
        ];
    }
    if ($r['blood_type']) {
        $banks[$id]['inventory'][] = [
            'blood_type'     => $r['blood_type'],
            'units_available'=> $r['units_available']
        ];
    }
}


$total_banks = count($banks);
$total_inventory = $pdo->query("SELECT SUM(units_available) FROM blood_inventory")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Blood Banks - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/blood-banks.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="blood-banks-container">
      <div class="page-header">
        <h1>Blood Bank Management</h1>
        <p>Manage blood bank branches and their inventory</p>
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

      <div class="bank-sections">
        
        <section class="add-bank card">
          <h2><i class="fas fa-hospital"></i> Add New Blood Bank</h2>
          <form method="POST" class="bank-form">
            <input type="hidden" name="add_bank" value="1">
            
            <div class="form-row">
              <div class="form-group">
                <label>Branch Name</label>
                <input type="text" name="branch_name" required>
              </div>
              
              <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" required>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label>Latitude</label>
                <input type="text" name="latitude" required>
              </div>
              
              <div class="form-group">
                <label>Longitude</label>
                <input type="text" name="longitude" required>
              </div>
            </div>
            
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-plus"></i> Add Blood Bank
            </button>
          </form>
        </section>

        <!-- Existing Banks Section -->
        <section class="existing-banks">
          <div class="section-header">
            <h2><i class="fas fa-map-marked-alt"></i> Blood Bank Branches</h2>
            <div class="search-box">
              <input type="text" placeholder="Search blood banks...">
              <button type="submit"><i class="fas fa-search"></i></button>
            </div>
          </div>
          
          <?php if (empty($banks)): ?>
            <div class="empty-state">
              <i class="fas fa-hospital-alt"></i>
              <h3>No Blood Banks Found</h3>
              <p>There are no blood bank branches to display.</p>
            </div>
          <?php else: ?>
            <div class="stats-cards">
              <div class="stat-card">
                <div class="stat-icon red">
                  <i class="fas fa-hospital"></i>
                </div>
                <div class="stat-content">
                  <h3>Total Branches</h3>
                  <p><?= $total_banks ?></p>
                </div>
              </div>
              
              <div class="stat-card">
                <div class="stat-icon orange">
                  <i class="fas fa-tint"></i>
                </div>
                <div class="stat-content">
                  <h3>Total Blood Units</h3>
                  <p><?= $total_inventory ?: '0' ?></p>
                </div>
              </div>
            </div>
            
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Branch Name</th>
                    <th>Address</th>
                    <th>Location</th>
                    <th>Inventory</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($banks as $bank_id => $bank): ?>
                  <tr>
                    <td><?= $bank_id ?></td>
                    <td><?= htmlspecialchars($bank['branch_name']) ?></td>
                    <td><?= htmlspecialchars($bank['address']) ?></td>
                    <td>
                      <?= htmlspecialchars($bank['latitude']) ?>, <?= htmlspecialchars($bank['longitude']) ?>
                    </td>
                    <td>
                      <?php if (!empty($bank['inventory'])): ?>
                        <div class="inventory-tags">
                          <?php foreach ($bank['inventory'] as $inv): ?>
                            <span class="inventory-tag <?= $inv['units_available'] < 5 ? 'low-stock' : '' ?>">
                              <?= htmlspecialchars($inv['blood_type']) ?>: <?= (int)$inv['units_available'] ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <span class="no-inventory">No inventory</span>
                      <?php endif; ?>
                    </td>
                    <td class="actions">
                      <a href="edit_bank.php?id=<?= $bank_id ?>" class="btn btn-sm btn-edit">
                        <i class="fas fa-edit"></i> Edit
                      </a>
                      
                      <a href="manage_inventory.php?bank=<?= $bank_id ?>" class="btn btn-sm btn-inventory">
                        <i class="fas fa-warehouse"></i> Inventory
                      </a>
                      
                      <form method="POST" class="inline-form" 
                            onsubmit="return confirm('Are you sure you want to delete this blood bank?')">
                        <input type="hidden" name="delete_bank" value="1">
                        <input type="hidden" name="bank_id" value="<?= $bank_id ?>">
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
</body>
</html>