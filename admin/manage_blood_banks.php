<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Add Blood Bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank'])) {
    $branch_name = trim($_POST['branch_name']);
    $address = trim($_POST['address']);
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $contact_number = trim($_POST['contact_number']);
    $operating_hours = trim($_POST['operating_hours']);

    if ($branch_name && $address) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO blood_banks 
                (branch_name, address, latitude, longitude, contact_number, operating_hours)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $branch_name, 
                $address, 
                $latitude, 
                $longitude,
                $contact_number,
                $operating_hours
            ]);
            
            $successMessage = "Blood bank location added successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error adding blood bank: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Branch name and address are required.";
    }
}

// Update Blood Bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_bank'])) {
    $bank_id = (int)$_POST['bank_id'];
    $branch_name = trim($_POST['branch_name']);
    $address = trim($_POST['address']);
    $latitude = floatval($_POST['latitude']);
    $longitude = floatval($_POST['longitude']);
    $contact_number = trim($_POST['contact_number']);
    $operating_hours = trim($_POST['operating_hours']);

    if ($bank_id && $branch_name && $address) {
        try {
            $stmt = $pdo->prepare("
                UPDATE blood_banks SET
                branch_name = ?,
                address = ?,
                latitude = ?,
                longitude = ?,
                contact_number = ?,
                operating_hours = ?
                WHERE bank_id = ?
            ");
            $stmt->execute([
                $branch_name, 
                $address, 
                $latitude, 
                $longitude,
                $contact_number,
                $operating_hours,
                $bank_id
            ]);
            
            $successMessage = "Blood bank location updated successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error updating blood bank: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Invalid data provided for update.";
    }
}

// Delete Blood Bank
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bank'])) {
    $bank_id = (int)$_POST['bank_id'];
    if ($bank_id) {
        try {
            $pdo->beginTransaction();
            
            // Delete related records first
            $stmt = $pdo->prepare("DELETE FROM blood_inventory_log WHERE bank_id = ?");
            $stmt->execute([$bank_id]);
            
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE bank_id = ?");
            $stmt->execute([$bank_id]);
            
            $stmt = $pdo->prepare("DELETE FROM blood_inventory WHERE bank_id = ?");
            $stmt->execute([$bank_id]);

            $stmt = $pdo->prepare("DELETE FROM blood_banks WHERE bank_id = ?");
            $stmt->execute([$bank_id]);
            
            $pdo->commit();
            $successMessage = "Blood bank location deleted successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = "Error deleting blood bank: " . $e->getMessage();
        }
    }
}

// Get branch suggestions for autocomplete
if (isset($_GET['get_branches'])) {
    $query = trim($_GET['q']);
    if ($query) {
        $suggestions = [
            'Manila Chapter', 'Quezon City Chapter', 'Makati Chapter', 'Cebu Chapter',
            'Davao Chapter', 'Baguio Chapter', 'Iloilo Chapter', 'Cagayan de Oro Chapter',
            'Zamboanga Chapter', 'Bacolod Chapter', 'Dumaguete Chapter', 'Tacloban Chapter',
            'General Santos Chapter', 'Butuan Chapter', 'Iligan Chapter', 'Puerto Princesa Chapter'
        ];
        
        $filtered = array_filter($suggestions, function($suggestion) use ($query) {
            return stripos($suggestion, $query) !== false;
        });
        
        header('Content-Type: application/json');
        echo json_encode(array_values($filtered));
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// Get detailed bank information
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    if ($search) {
        $stmt = $pdo->prepare("
            SELECT bb.*,
                   COUNT(DISTINCT bi.blood_type) as blood_types_count,
                   SUM(bi.units_available) as total_blood_units,
                   COUNT(DISTINCT ii.item_id) as medical_items_count,
                   MIN(bi.units_available) as min_blood_units
            FROM blood_banks bb
            LEFT JOIN blood_inventory bi ON bb.bank_id = bi.bank_id
            LEFT JOIN inventory_items ii ON bb.bank_id = ii.bank_id
            WHERE bb.branch_name LIKE :search OR bb.address LIKE :search
            GROUP BY bb.bank_id
            ORDER BY bb.branch_name
        ");
        $stmt->execute([':search' => "%$search%"]);
    } else {
        $stmt = $pdo->query("
            SELECT bb.*,
                   COUNT(DISTINCT bi.blood_type) as blood_types_count,
                   SUM(bi.units_available) as total_blood_units,
                   COUNT(DISTINCT ii.item_id) as medical_items_count,
                   MIN(bi.units_available) as min_blood_units
            FROM blood_banks bb
            LEFT JOIN blood_inventory bi ON bb.bank_id = bi.bank_id
            LEFT JOIN inventory_items ii ON bb.bank_id = ii.bank_id
            GROUP BY bb.bank_id
            ORDER BY bb.branch_name
        ");
    }
    $banks = $stmt->fetchAll();

    // Get detailed inventory for each bank
    foreach ($banks as &$bank) {
        // Get blood inventory details
        $stmt = $pdo->prepare("
            SELECT blood_type, units_available
            FROM blood_inventory 
            WHERE bank_id = ? 
            ORDER BY blood_type
        ");
        $stmt->execute([$bank['bank_id']]);
        $bank['blood_inventory'] = $stmt->fetchAll();

        // Get recent medical supplies
        $stmt = $pdo->prepare("
            SELECT ii.item_name, c.category_name
            FROM inventory_items ii
            LEFT JOIN categories c ON ii.category_id = c.category_id
            WHERE ii.bank_id = ?
            ORDER BY ii.expiry_date ASC
            LIMIT 3
        ");
        $stmt->execute([$bank['bank_id']]);
        $bank['medical_supplies'] = $stmt->fetchAll();

        // Determine stock status
        if ($bank['min_blood_units'] !== null) {
            if ($bank['min_blood_units'] < 5) {
                $bank['stock_status'] = 'critical';
                $bank['stock_text'] = 'Critical Stock';
            } elseif ($bank['min_blood_units'] < 10) {
                $bank['stock_status'] = 'low';
                $bank['stock_text'] = 'Low Stock';
            } else {
                $bank['stock_status'] = 'normal';
                $bank['stock_text'] = 'Normal Stock';
            }
        } else {
            $bank['stock_status'] = 'empty';
            $bank['stock_text'] = 'No Stock';
        }
    }

    // Get overall stats
    $total_banks = count($banks);
    $total_inventory = $pdo->query("SELECT SUM(units_available) FROM blood_inventory")->fetchColumn() ?: 0;
    $critical_stock_banks = count(array_filter($banks, function($bank) {
        return $bank['stock_status'] === 'critical' || $bank['stock_status'] === 'low';
    }));
    $total_medical_items = $pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn() ?: 0;

} catch (PDOException $e) {
    $banks = [];
    $total_banks = 0;
    $total_inventory = 0;
    $critical_stock_banks = 0;
    $total_medical_items = 0;
    $errorMessage = "Error loading data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blood Bank Locations - PRC Portal</title>
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/blood-banks.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="blood-banks-container">
      <!-- Page Header -->
      <div class="page-header">
        <h1><i class="fas fa-map-marked-alt"></i> Blood Bank Locations</h1>
        <p>Manage blood bank branches and monitor inventory levels across all locations</p>
      </div>
      
      <!-- Alert Messages -->
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
        <div class="action-buttons">
          <button class="btn-primary" onclick="openAddBankModal()">
            <i class="fas fa-plus-circle"></i> Add New Location
          </button>
          <a href="manage_inventory.php" class="btn-secondary">
            <i class="fas fa-boxes"></i> Manage Inventory
          </a>
        </div>
        
        <form method="GET" class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search locations..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
        </form>
      </div>

      <!-- Statistics Overview -->
      <div class="stats-overview">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);">
            <i class="fas fa-hospital"></i>
          </div>
          <div class="stat-content">
            <div class="stat-number"><?= $total_banks ?></div>
            <div class="stat-label">Total Locations</div>
            <div class="stat-trend">Active branches</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #dc143c 0%, #b91c1c 100%);">
            <i class="fas fa-tint"></i>
          </div>
          <div class="stat-content">
            <div class="stat-number"><?= number_format($total_inventory) ?></div>
            <div class="stat-label">Blood Units</div>
            <div class="stat-trend">Total inventory</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-content">
            <div class="stat-number"><?= $critical_stock_banks ?></div>
            <div class="stat-label">Critical/Low Stock</div>
            <div class="stat-trend">Need attention</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-boxes"></i>
          </div>
          <div class="stat-content">
            <div class="stat-number"><?= number_format($total_medical_items) ?></div>
            <div class="stat-label">Medical Items</div>
            <div class="stat-trend">All locations</div>
          </div>
        </div>
      </div>

      <div class="main-content">
        <!-- Interactive Map Section -->
        <section class="card map-section">
          <div class="card-header">
            <h2><i class="fas fa-globe-asia"></i> Interactive Map</h2>
            <div class="map-controls">
              <button class="map-control-btn" onclick="centerMapPhilippines()">
                <i class="fas fa-search-location"></i> Center Map
              </button>
              <button class="map-control-btn" onclick="showAllMarkers()">
                <i class="fas fa-eye"></i> Show All
              </button>
            </div>
          </div>
          <div class="card-body">
            <div id="banksMap"></div>
            <div class="map-legend">
              <div class="legend-item">
                <div class="legend-marker normal"></div>
                <span>Normal Stock</span>
              </div>
              <div class="legend-item">
                <div class="legend-marker low"></div>
                <span>Low Stock</span>
              </div>
              <div class="legend-item">
                <div class="legend-marker critical"></div>
                <span>Critical Stock</span>
              </div>
            </div>
          </div>
        </section>

        <!-- Blood Bank Locations Section -->
        <section class="card">
          <div class="card-header">
            <h2><i class="fas fa-hospital-alt"></i> Blood Bank Locations</h2>
            <div class="view-toggle">
              <button class="toggle-btn active" onclick="switchView('cards')" data-view="cards">
                <i class="fas fa-th-large"></i> Cards
              </button>
              <button class="toggle-btn" onclick="switchView('table')" data-view="table">
                <i class="fas fa-table"></i> Table
              </button>
            </div>
          </div>
          <div class="card-body">
            <?php if (empty($banks)): ?>
              <div class="empty-state">
                <i class="fas fa-hospital-symbol"></i>
                <h3>No Blood Bank Locations Found</h3>
                <p><?= $search ? 'Try a different search term' : 'Add your first blood bank location to get started' ?></p>
                <button class="btn-create" onclick="openAddBankModal()">
                  <i class="fas fa-plus-circle"></i> Add First Location
                </button>
              </div>
            <?php else: ?>
              <!-- Cards View -->
              <div class="cards-view active" id="cardsView">
                <div class="bank-cards-grid">
                  <?php foreach ($banks as $bank): ?>
                    <div class="bank-card">
                      <div class="bank-card-header">
                        <div class="bank-info">
                          <h3><?= htmlspecialchars($bank['branch_name']) ?></h3>
                          <p class="bank-address">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($bank['address']) ?>
                          </p>
                        </div>
                        <div class="stock-status <?= $bank['stock_status'] ?>">
                          <?= $bank['stock_text'] ?>
                        </div>
                      </div>

                      <div class="bank-card-stats">
                        <div class="stat-item">
                          <div class="stat-value"><?= $bank['total_blood_units'] ?: '0' ?></div>
                          <div class="stat-label">Blood Units</div>
                        </div>
                        <div class="stat-item">
                          <div class="stat-value"><?= $bank['blood_types_count'] ?: '0' ?></div>
                          <div class="stat-label">Blood Types</div>
                        </div>
                        <div class="stat-item">
                          <div class="stat-value"><?= $bank['medical_items_count'] ?: '0' ?></div>
                          <div class="stat-label">Medical Items</div>
                        </div>
                      </div>

                      <div class="bank-card-details">
                        <div class="detail-row">
                          <span class="detail-label">
                            <i class="fas fa-tint"></i> Blood Types
                          </span>
                          <span class="detail-value">
                            <?php if (!empty($bank['blood_inventory'])): ?>
                              <div class="blood-types-mini">
                                <?php foreach (array_slice($bank['blood_inventory'], 0, 4) as $blood): ?>
                                  <span class="blood-type-mini <?= $blood['units_available'] < 5 ? 'low' : '' ?>">
                                    <?= $blood['blood_type'] ?>: <?= $blood['units_available'] ?>
                                  </span>
                                <?php endforeach; ?>
                                <?php if (count($bank['blood_inventory']) > 4): ?>
                                  <span class="more-types">+<?= count($bank['blood_inventory']) - 4 ?> more</span>
                                <?php endif; ?>
                              </div>
                            <?php else: ?>
                              <span style="color: #6c757d; font-style: italic;">No inventory</span>
                            <?php endif; ?>
                          </span>
                        </div>

                        <div class="detail-row">
                          <span class="detail-label">
                            <i class="fas fa-phone"></i> Contact
                          </span>
                          <span class="detail-value">
                            <?= htmlspecialchars($bank['contact_number'] ?: 'Not specified') ?>
                          </span>
                        </div>

                        <div class="detail-row">
                          <span class="detail-label">
                            <i class="fas fa-clock"></i> Hours
                          </span>
                          <span class="detail-value">
                            <?= htmlspecialchars($bank['operating_hours'] ?: 'Not specified') ?>
                          </span>
                        </div>
                      </div>

                      <div class="bank-card-actions">
                        <button class="action-btn view-btn" onclick="focusMapLocation(<?= $bank['latitude'] ?>, <?= $bank['longitude'] ?>, '<?= addslashes($bank['branch_name']) ?>')">
                          <i class="fas fa-map-marked-alt"></i> Map
                        </button>
                        <a href="manage_inventory.php?bank=<?= $bank['bank_id'] ?>" class="action-btn inventory-btn">
                          <i class="fas fa-warehouse"></i> Inventory
                        </a>
                        <button class="action-btn edit-btn" onclick="openEditBankModal(<?= htmlspecialchars(json_encode($bank)) ?>)">
                          <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="action-btn delete-btn" onclick="confirmDelete(<?= $bank['bank_id'] ?>, '<?= addslashes($bank['branch_name']) ?>')">
                          <i class="fas fa-trash"></i> Delete
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Table View -->
              <div class="table-view" id="tableView">
                <div class="table-responsive">
                  <table class="data-table">
                    <thead>
                      <tr>
                        <th>Location</th>
                        <th>Contact Info</th>
                        <th>Blood Inventory</th>
                        <th>Stock Status</th>
                        <th>Medical Items</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($banks as $bank): ?>
                      <tr>
                        <td>
                          <div class="location-info">
                            <strong><?= htmlspecialchars($bank['branch_name']) ?></strong>
                            <div class="address"><?= htmlspecialchars($bank['address']) ?></div>
                            <div class="coordinates">
                              <i class="fas fa-map-marker-alt"></i>
                              <?= number_format($bank['latitude'], 4) ?>, <?= number_format($bank['longitude'], 4) ?>
                            </div>
                          </div>
                        </td>
                        <td>
                          <div class="contact-info">
                            <div><i class="fas fa-phone"></i> <?= htmlspecialchars($bank['contact_number'] ?: 'N/A') ?></div>
                            <div><i class="fas fa-clock"></i> <?= htmlspecialchars($bank['operating_hours'] ?: 'N/A') ?></div>
                          </div>
                        </td>
                        <td>
                          <div class="inventory-summary">
                            <div class="summary-stat">
                              <strong><?= $bank['total_blood_units'] ?: '0' ?></strong> units
                            </div>
                            <div class="summary-stat">
                              <strong><?= $bank['blood_types_count'] ?: '0' ?></strong> types
                            </div>
                            <?php if (!empty($bank['blood_inventory'])): ?>
                              <div class="blood-types-mini">
                                <?php foreach (array_slice($bank['blood_inventory'], 0, 4) as $blood): ?>
                                  <span class="blood-type-mini <?= $blood['units_available'] < 5 ? 'low' : '' ?>">
                                    <?= $blood['blood_type'] ?>: <?= $blood['units_available'] ?>
                                  </span>
                                <?php endforeach; ?>
                                <?php if (count($bank['blood_inventory']) > 4): ?>
                                  <span class="more-types">+<?= count($bank['blood_inventory']) - 4 ?> more</span>
                                <?php endif; ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td>
                          <span class="status-badge <?= $bank['stock_status'] ?>">
                            <?= $bank['stock_text'] ?>
                          </span>
                        </td>
                        <td>
                          <div class="medical-items-summary">
                            <strong><?= $bank['medical_items_count'] ?: '0' ?></strong> items
                            <?php if (!empty($bank['medical_supplies'])): ?>
                              <div class="items-preview">
                                <?php foreach (array_slice($bank['medical_supplies'], 0, 2) as $item): ?>
                                  <div class="item-preview"><?= htmlspecialchars($item['item_name']) ?></div>
                                <?php endforeach; ?>
                                <?php if (count($bank['medical_supplies']) > 2): ?>
                                  <div class="more-items">+<?= count($bank['medical_supplies']) - 2 ?> more</div>
                                <?php endif; ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="actions">
                          <button class="btn-action btn-map" onclick="focusMapLocation(<?= $bank['latitude'] ?>, <?= $bank['longitude'] ?>, '<?= addslashes($bank['branch_name']) ?>')" title="View on Map">
                            <i class="fas fa-map-marked-alt"></i>
                          </button>
                          <a href="manage_inventory.php?bank=<?= $bank['bank_id'] ?>" class="btn-action btn-inventory" title="Manage Inventory">
                            <i class="fas fa-warehouse"></i>
                          </a>
                          <button class="btn-action btn-edit" onclick="openEditBankModal(<?= htmlspecialchars(json_encode($bank)) ?>)" title="Edit Location">
                            <i class="fas fa-edit"></i>
                          </button>
                          <button class="btn-action btn-delete" onclick="confirmDelete(<?= $bank['bank_id'] ?>, '<?= addslashes($bank['branch_name']) ?>')" title="Delete Location">
                            <i class="fas fa-trash"></i>
                          </button>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>

  <!-- Add/Edit Blood Bank Modal -->
  <div class="modal" id="bankModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Add New Blood Bank Location</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div class="modal-body">
        <form method="POST" id="bankForm">
          <input type="hidden" name="add_bank" value="1" id="formAction">
          <input type="hidden" name="bank_id" id="bankId">
          
          <div class="form-group autocomplete-container">
            <label for="branch_name">Branch Name *</label>
            <input type="text" id="branch_name" name="branch_name" required autocomplete="off" placeholder="e.g., Manila Chapter">
            <div class="autocomplete-suggestions" id="branchSuggestions"></div>
          </div>
          
          <div class="form-group">
            <label for="address">Complete Address *</label>
            <textarea id="address" name="address" rows="3" required placeholder="Enter the full address including street, city, and postal code"></textarea>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="contact_number">Contact Number</label>
              <input type="text" id="contact_number" name="contact_number" placeholder="e.g., +63-2-8527-0000">
            </div>
            
            <div class="form-group">
              <label for="operating_hours">Operating Hours</label>
              <input type="text" id="operating_hours" name="operating_hours" placeholder="e.g., 8:00 AM - 5:00 PM">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="latitude">Latitude *</label>
              <input type="number" step="any" id="latitude" name="latitude" required placeholder="e.g., 14.5995">
            </div>
            
            <div class="form-group">
              <label for="longitude">Longitude *</label>
              <input type="number" step="any" id="longitude" name="longitude" required placeholder="e.g., 120.9842">
            </div>
          </div>
          
          <div class="map-container">
            <label>Click on the map to set location coordinates</label>
            <div id="locationMap"></div>
            <div class="map-note">
              <i class="fas fa-info-circle"></i>
              Click and drag the marker to adjust the location, or click anywhere on the map to place it.
            </div>
          </div>
          
          <div class="form-actions">
            <button type="button" class="btn-cancel" onclick="closeModal()">
              <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" class="btn-submit">
              <i class="fas fa-save"></i> Save Location
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete Confirmation Modal -->
  <div class="modal" id="deleteModal">
    <div class="modal-content" style="max-width: 400px;">
      <div class="modal-header">
        <h2 class="modal-title">Confirm Delete</h2>
        <button class="close-modal" onclick="closeDeleteModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <div class="modal-body" style="text-align: center;">
        <div style="font-size: 3rem; color: #dc3545; margin-bottom: 1rem;">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h3>Delete Blood Bank Location</h3>
        <p>Are you sure you want to delete <strong id="deleteBankName"></strong>?</p>
        <p style="color: #6c757d; font-size: 0.9rem;">This will also delete all associated inventory and cannot be undone.</p>
        
        <form method="POST" style="margin-top: 1.5rem;">
          <input type="hidden" name="delete_bank" value="1">
          <input type="hidden" name="bank_id" id="deleteBankId">
          <div style="display: flex; gap: 1rem; justify-content: center;">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">
              <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" class="btn-submit" style="background: #dc3545;">
              <i class="fas fa-trash"></i> Delete
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
  <script>
    // Initialize variables
    let banksMap, locationMap, locationMarker;
    let bankMarkers = [];
    const banksData = <?= json_encode($banks) ?>;
    
    // Initialize main map
    function initMainMap() {
      banksMap = L.map('banksMap').setView([12.8797, 121.7740], 6);
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(banksMap);
      
      // Add markers for all banks
      banksData.forEach(bank => {
        if (bank.latitude && bank.longitude) {
          const marker = createBankMarker(bank);
          bankMarkers.push(marker);
          marker.addTo(banksMap);
        }
      });
    }
    
    // Create marker with color based on stock status
    function createBankMarker(bank) {
      let markerColor = '#28a745'; // Normal - green
      if (bank.stock_status === 'critical') markerColor = '#dc3545'; // Critical - red
      else if (bank.stock_status === 'low') markerColor = '#ffc107'; // Low - yellow
      else if (bank.stock_status === 'empty') markerColor = '#6c757d'; // Empty - gray
      
      const customIcon = L.divIcon({
        html: `<div style="background-color: ${markerColor}; width: 16px; height: 16px; border-radius: 50%; border: 2px solid white; box-shadow: 0 1px 3px rgba(0,0,0,0.3);"></div>`,
        className: 'custom-marker',
        iconSize: [16, 16],
        iconAnchor: [8, 8]
      });
      
      const popupContent = `
        <div style="min-width: 200px;">
          <h4 style="margin: 0 0 0.5rem 0; color: #343a40;">${bank.branch_name}</h4>
          <p style="margin: 0 0 0.75rem 0; color: #6c757d; font-size: 0.9rem;"><i class="fas fa-map-marker-alt"></i> ${bank.address}</p>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
            <div style="text-align: center; background: #f8f9fa; padding: 0.5rem; border-radius: 6px;">
              <strong style="display: block; font-size: 1.2rem; color: #a00000;">${bank.total_blood_units || 0}</strong>
              <small style="color: #6c757d;">Blood Units</small>
            </div>
            <div style="text-align: center; background: #f8f9fa; padding: 0.5rem; border-radius: 6px;">
              <strong style="display: block; font-size: 1.2rem; color: #a00000;">${bank.medical_items_count || 0}</strong>
              <small style="color: #6c757d;">Medical Items</small>
            </div>
          </div>
          <div style="text-align: center; padding: 0.4rem 0.8rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; background: ${getStatusColor(bank.stock_status)};">
            ${bank.stock_text}
          </div>
        </div>
      `;
      
      return L.marker([bank.latitude, bank.longitude], { icon: customIcon })
        .bindPopup(popupContent, { maxWidth: 250 });
    }
    
    function getStatusColor(status) {
      switch(status) {
        case 'normal': return '#d4edda; color: #155724';
        case 'low': return '#fff3cd; color: #856404';
        case 'critical': return '#f8d7da; color: #721c24';
        default: return '#e2e3e5; color: #383d41';
      }
    }
    
    // Map control functions
    function centerMapPhilippines() {
      banksMap.setView([12.8797, 121.7740], 6);
    }
    
    function showAllMarkers() {
      if (bankMarkers.length > 0) {
        const group = new L.featureGroup(bankMarkers);
        banksMap.fitBounds(group.getBounds().pad(0.1));
      }
    }
    
    function focusMapLocation(lat, lng, name) {
      banksMap.setView([lat, lng], 15);
      
      bankMarkers.forEach(marker => {
        const markerLatLng = marker.getLatLng();
        if (Math.abs(markerLatLng.lat - lat) < 0.0001 && Math.abs(markerLatLng.lng - lng) < 0.0001) {
          marker.openPopup();
        }
      });
      
      document.getElementById('banksMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    // View toggle functionality
    function switchView(viewType) {
      const cardsView = document.getElementById('cardsView');
      const tableView = document.getElementById('tableView');
      const toggleBtns = document.querySelectorAll('.toggle-btn');
      
      toggleBtns.forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.view === viewType) {
          btn.classList.add('active');
        }
      });
      
      if (viewType === 'cards') {
        cardsView.classList.add('active');
        tableView.classList.remove('active');
      } else {
        cardsView.classList.remove('active');
        tableView.classList.add('active');
      }
      
      localStorage.setItem('bloodBanksViewPreference', viewType);
    }
    
    // Autocomplete functionality
    let autocompleteTimeout;
    
    document.getElementById('branch_name').addEventListener('input', function() {
      const query = this.value.trim();
      const suggestionsDiv = document.getElementById('branchSuggestions');
      
      clearTimeout(autocompleteTimeout);
      
      if (query.length < 2) {
        suggestionsDiv.style.display = 'none';
        return;
      }
      
      autocompleteTimeout = setTimeout(() => {
        fetch(`blood_banks.php?get_branches=1&q=${encodeURIComponent(query)}`)
          .then(response => response.json())
          .then(suggestions => {
            suggestionsDiv.innerHTML = '';
            
            if (suggestions.length > 0) {
              suggestions.forEach(suggestion => {
                const div = document.createElement('div');
                div.className = 'autocomplete-suggestion';
                div.textContent = suggestion;
                div.onclick = () => {
                  document.getElementById('branch_name').value = suggestion;
                  suggestionsDiv.style.display = 'none';
                };
                suggestionsDiv.appendChild(div);
              });
              suggestionsDiv.style.display = 'block';
            } else {
              suggestionsDiv.style.display = 'none';
            }
          })
          .catch(error => {
            console.error('Error fetching suggestions:', error);
            suggestionsDiv.style.display = 'none';
          });
      }, 300);
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.autocomplete-container')) {
        document.getElementById('branchSuggestions').style.display = 'none';
      }
    });
    
    // Modal functions
    function openAddBankModal() {
      document.getElementById('modalTitle').textContent = 'Add New Blood Bank Location';
      document.getElementById('formAction').name = 'add_bank';
      document.getElementById('bankForm').reset();
      document.getElementById('bankId').value = '';
      document.getElementById('bankModal').classList.add('active');
      
      setTimeout(() => initLocationMap(14.5995, 120.9842), 100);
    }
    
    function openEditBankModal(bank) {
      document.getElementById('modalTitle').textContent = 'Edit Blood Bank Location';
      document.getElementById('formAction').name = 'update_bank';
      document.getElementById('bankId').value = bank.bank_id;
      document.getElementById('branch_name').value = bank.branch_name;
      document.getElementById('address').value = bank.address;
      document.getElementById('contact_number').value = bank.contact_number || '';
      document.getElementById('operating_hours').value = bank.operating_hours || '';
      document.getElementById('latitude').value = bank.latitude;
      document.getElementById('longitude').value = bank.longitude;
      
      document.getElementById('bankModal').classList.add('active');
      
      setTimeout(() => initLocationMap(bank.latitude, bank.longitude), 100);
    }
    
    function closeModal() {
      document.getElementById('bankModal').classList.remove('active');
      if (locationMap) {
        locationMap.remove();
        locationMap = null;
      }
    }
    
    function confirmDelete(bankId, bankName) {
      document.getElementById('deleteBankId').value = bankId;
      document.getElementById('deleteBankName').textContent = bankName;
      document.getElementById('deleteModal').classList.add('active');
    }
    
    function closeDeleteModal() {
      document.getElementById('deleteModal').classList.remove('active');
    }
    
    // Location map for modal
    function initLocationMap(lat, lng) {
      if (locationMap) {
        locationMap.remove();
      }
      
      locationMap = L.map('locationMap').setView([lat, lng], 15);
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(locationMap);
      
      locationMarker = L.marker([lat, lng], {draggable: true}).addTo(locationMap);
      
      locationMarker.on('dragend', function(e) {
        const coords = e.target.getLatLng();
        document.getElementById('latitude').value = coords.lat.toFixed(6);
        document.getElementById('longitude').value = coords.lng.toFixed(6);
      });
      
      locationMap.on('click', function(e) {
        const coords = e.latlng;
        document.getElementById('latitude').value = coords.lat.toFixed(6);
        document.getElementById('longitude').value = coords.lng.toFixed(6);
        locationMarker.setLatLng(coords);
      });
    }
    
    // Auto-fill coordinates from address
    document.getElementById('address').addEventListener('blur', function() {
      const address = this.value;
      if (!address || document.getElementById('latitude').value) {
        return;
      }
      
      const searchQuery = address + ', Philippines';
      
      fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchQuery)}&limit=1&countrycodes=ph`)
        .then(response => response.json())
        .then(data => {
          if (data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lng = parseFloat(data[0].lon);
            
            document.getElementById('latitude').value = lat.toFixed(6);
            document.getElementById('longitude').value = lng.toFixed(6);
            
            if (locationMap && locationMarker) {
              locationMap.setView([lat, lng], 15);
              locationMarker.setLatLng([lat, lng]);
            }
          }
        })
        .catch(error => console.error('Geocoding error:', error));
    });
    
    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', function(e) {
        if (e.target === this) {
          if (this.id === 'deleteModal') {
            closeDeleteModal();
          } else {
            closeModal();
          }
        }
      });
    });
    
    // Initialize everything when page loads
    document.addEventListener('DOMContentLoaded', function() {
      initMainMap();
      
      const savedView = localStorage.getItem('bloodBanksViewPreference') || 'cards';
      switchView(savedView);
      
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
          const lat = position.coords.latitude;
          const lng = position.coords.longitude;
          
          if (lat >= 4.5 && lat <= 21.5 && lng >= 114.0 && lng <= 127.0) {
            banksMap.setView([lat, lng], 10);
          }
        }, function() {
          // Geolocation failed, keep default view
        });
      }
    });
  </script>
</body>
</html>