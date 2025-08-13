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
            $pdo->beginTransaction();
            
            // Insert blood bank
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
            
            $bank_id = $pdo->lastInsertId();
            
            // Add initial blood inventory if provided
            if (!empty($_POST['blood_types']) && !empty($_POST['quantities'])) {
                $blood_types = $_POST['blood_types'];
                $quantities = $_POST['quantities'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO blood_inventory (bank_id, blood_type, units_available)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($blood_types as $index => $blood_type) {
                    if (!empty($blood_type) && isset($quantities[$index]) && $quantities[$index] > 0) {
                        $stmt->execute([$bank_id, $blood_type, intval($quantities[$index])]);
                    }
                }
            }
            
            $pdo->commit();
            $successMessage = "Blood bank added successfully with inventory!";
        } catch (PDOException $e) {
            $pdo->rollBack();
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
            $pdo->beginTransaction();
            
            // Update blood bank
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
            
            // Update inventory if provided
            if (!empty($_POST['blood_types']) && !empty($_POST['quantities'])) {
                // Delete existing inventory
                $stmt = $pdo->prepare("DELETE FROM blood_inventory WHERE bank_id = ?");
                $stmt->execute([$bank_id]);
                
                // Add new inventory
                $blood_types = $_POST['blood_types'];
                $quantities = $_POST['quantities'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO blood_inventory (bank_id, blood_type, units_available)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($blood_types as $index => $blood_type) {
                    if (!empty($blood_type) && isset($quantities[$index]) && $quantities[$index] > 0) {
                        $stmt->execute([$bank_id, $blood_type, intval($quantities[$index])]);
                    }
                }
            }
            
            $pdo->commit();
            $successMessage = "Blood bank updated successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
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
            
            // Delete inventory first
            $stmtInv = $pdo->prepare("DELETE FROM blood_inventory WHERE bank_id = ?");
            $stmtInv->execute([$bank_id]);

            // Then delete the bank
            $stmt = $pdo->prepare("DELETE FROM blood_banks WHERE bank_id = ?");
            $stmt->execute([$bank_id]);
            
            $pdo->commit();
            $successMessage = "Blood bank deleted successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = "Error deleting blood bank: " . $e->getMessage();
        }
    }
}

// Get branch suggestions for auto-fill
if (isset($_GET['get_branches'])) {
    $query = trim($_GET['q']);
    if ($query) {
        // Common Philippine Red Cross branch names
        $suggestions = [
            'Manila Chapter',
            'Quezon City Chapter',
            'Makati Chapter',
            'Cebu Chapter',
            'Davao Chapter',
            'Baguio Chapter',
            'Iloilo Chapter',
            'Cagayan de Oro Chapter',
            'Zamboanga Chapter',
            'Bacolod Chapter',
            'Dumaguete Chapter',
            'Tacloban Chapter',
            'General Santos Chapter',
            'Butuan Chapter',
            'Iligan Chapter',
            'Puerto Princesa Chapter',
            'Antipolo Chapter',
            'Las Piñas Chapter',
            'Muntinlupa Chapter',
            'Parañaque Chapter',
            'Pasay Chapter',
            'Pasig Chapter',
            'Taguig Chapter',
            'Valenzuela Chapter',
            'Caloocan Chapter',
            'Malabon Chapter',
            'Marikina Chapter',
            'Navotas Chapter',
            'San Juan Chapter'
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

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search) {
    $stmt = $pdo->prepare("
        SELECT bb.*, 
               bi.blood_type, bi.units_available,
               (SELECT COUNT(*) FROM blood_inventory WHERE bank_id = bb.bank_id) AS inventory_count
        FROM blood_banks bb
        LEFT JOIN blood_inventory bi ON bb.bank_id = bi.bank_id
        WHERE bb.branch_name LIKE :search OR bb.address LIKE :search
        ORDER BY bb.branch_name
    ");
    $stmt->execute([':search' => "%$search%"]);
} else {
    $stmt = $pdo->query("
        SELECT bb.*, 
               bi.blood_type, bi.units_available,
               (SELECT COUNT(*) FROM blood_inventory WHERE bank_id = bb.bank_id) AS inventory_count
        FROM blood_banks bb
        LEFT JOIN blood_inventory bi ON bb.bank_id = bi.bank_id
        ORDER BY bb.branch_name
    ");
}
$rows = $stmt->fetchAll();

// Group by bank
$banks = [];
foreach ($rows as $r) {
    $id = $r['bank_id'];
    if (!isset($banks[$id])) {
        $banks[$id] = [
            'branch_name' => $r['branch_name'],
            'address' => $r['address'],
            'latitude' => $r['latitude'],
            'longitude' => $r['longitude'],
            'contact_number' => $r['contact_number'],
            'operating_hours' => $r['operating_hours'],
            'inventory_count' => $r['inventory_count'],
            'inventory' => []
        ];
    }
    if ($r['blood_type']) {
        $banks[$id]['inventory'][] = [
            'blood_type' => $r['blood_type'],
            'units_available' => $r['units_available']
        ];
    }
}

// Get stats
$total_banks = $pdo->query("SELECT COUNT(*) FROM blood_banks")->fetchColumn();
$total_inventory = $pdo->query("SELECT SUM(units_available) FROM blood_inventory")->fetchColumn();
$low_stock = $pdo->query("
    SELECT COUNT(DISTINCT bank_id) 
    FROM blood_inventory 
    WHERE units_available < 5
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Blood Banks - PRC Portal</title>
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
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/blood-banks.css?v=<?php echo time(); ?>">
  <style>
    /* Enhanced autocomplete styles */
    .autocomplete-container {
      position: relative;
    }
    
    .autocomplete-suggestions {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: white;
      border: 1px solid #ddd;
      border-top: none;
      border-radius: 0 0 8px 8px;
      max-height: 200px;
      overflow-y: auto;
      z-index: 1000;
      display: none;
    }
    
    .autocomplete-suggestion {
      padding: 0.8rem;
      cursor: pointer;
      border-bottom: 1px solid #f0f0f0;
      transition: background-color 0.2s;
    }
    
    .autocomplete-suggestion:hover,
    .autocomplete-suggestion.selected {
      background-color: #f8f9fa;
    }
    
    .autocomplete-suggestion:last-child {
      border-bottom: none;
    }
    
    /* Blood type and quantity rows */
    .inventory-row {
      display: grid;
      grid-template-columns: 1fr 1fr auto;
      gap: 1rem;
      align-items: end;
      margin-bottom: 1rem;
      padding: 1rem;
      background: #f8f9fa;
      border-radius: 8px;
    }
    
    .add-inventory-btn, .remove-inventory-btn {
      padding: 0.5rem 1rem;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .add-inventory-btn {
      background: #28a745;
      color: white;
      margin-bottom: 1rem;
    }
    
    .add-inventory-btn:hover {
      background: #218838;
    }
    
    .remove-inventory-btn {
      background: #dc3545;
      color: white;
      height: 40px;
      width: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .remove-inventory-btn:hover {
      background: #c82333;
    }
    
    .inventory-section {
      margin-top: 1.5rem;
      padding-top: 1.5rem;
      border-top: 2px solid #e9ecef;
    }
    
    .inventory-section h3 {
      margin-bottom: 1rem;
      color: var(--dark);
      font-size: 1.1rem;
    }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="blood-banks-container">
      <div class="page-header">
        <h1><i class="fas fa-hospital"></i> Blood Bank Management</h1>
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

      <div class="action-bar">
        <form method="GET" class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search blood banks..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
          <?php if ($search): ?>
            <a href="blood_banks.php" class="clear-search">
              <i class="fas fa-times"></i>
            </a>
          <?php endif; ?>
        </form>
        
        <button class="btn-create" onclick="openAddBankModal()">
          <i class="fas fa-plus-circle"></i> Add New Blood Bank
        </button>
      </div>

      <div class="stats-overview">
        <div class="stat-card">
          <div class="stat-icon red">
            <i class="fas fa-hospital"></i>
          </div>
          <div>
            <div class="stat-number"><?= $total_banks ?></div>
            <div class="stat-label">Total Branches</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon blue">
            <i class="fas fa-tint"></i>
          </div>
          <div>
            <div class="stat-number"><?= $total_inventory ?: '0' ?></div>
            <div class="stat-label">Total Blood Units</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon orange">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div>
            <div class="stat-number"><?= $low_stock ?: '0' ?></div>
            <div class="stat-label">Low Stock Banks</div>
          </div>
        </div>
      </div>

      <div class="bank-sections">
        <!-- Map Section -->
        <section class="card map-section">
          <div class="card-header">
            <h2><i class="fas fa-map-marked-alt"></i> Blood Bank Locations</h2>
          </div>
          <div class="card-body">
            <div id="banksMap" style="height: 400px; border-radius: 8px;"></div>
          </div>
        </section>

        <!-- Blood Banks Table Section -->
        <section class="card banks-table-section">
          <div class="card-header">
            <h2><i class="fas fa-list"></i> Blood Bank Branches</h2>
          </div>
          <div class="card-body">
            <?php if (empty($banks)): ?>
              <div class="empty-state">
                <i class="fas fa-hospital-alt"></i>
                <h3>No Blood Banks Found</h3>
                <p><?= $search ? 'Try a different search term' : 'Add your first blood bank branch' ?></p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Branch Name</th>
                      <th>Address</th>
                      <th>Contact</th>
                      <th>Hours</th>
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
                      <td><?= htmlspecialchars($bank['contact_number'] ?? 'N/A') ?></td>
                      <td><?= htmlspecialchars($bank['operating_hours'] ?? 'N/A') ?></td>
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
                        <button class="btn-action btn-edit" onclick="openEditBankModal(<?= htmlspecialchars(json_encode([
                          'bank_id' => $bank_id,
                          'branch_name' => $bank['branch_name'],
                          'address' => $bank['address'],
                          'latitude' => $bank['latitude'],
                          'longitude' => $bank['longitude'],
                          'contact_number' => $bank['contact_number'],
                          'operating_hours' => $bank['operating_hours'],
                          'inventory' => $bank['inventory']
                        ])) ?>)">
                          <i class="fas fa-edit"></i>
                        </button>
                        
                        <a href="manage_inventory.php?bank=<?= $bank_id ?>" class="btn-action btn-inventory">
                          <i class="fas fa-warehouse"></i>
                        </a>
                        
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this blood bank?')">
                          <input type="hidden" name="delete_bank" value="1">
                          <input type="hidden" name="bank_id" value="<?= $bank_id ?>">
                          <button type="submit" class="btn-action btn-delete">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
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
        <h2 class="modal-title" id="modalTitle">Add New Blood Bank</h2>
        <button class="close-modal" onclick="closeModal()">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="bankForm">
        <input type="hidden" name="add_bank" value="1" id="formAction">
        <input type="hidden" name="bank_id" id="bankId">
        
        <div class="form-group autocomplete-container">
          <label for="branch_name">Branch Name *</label>
          <input type="text" id="branch_name" name="branch_name" required autocomplete="off">
          <div class="autocomplete-suggestions" id="branchSuggestions"></div>
        </div>
        
        <div class="form-group">
          <label for="address">Address *</label>
          <textarea id="address" name="address" rows="2" required></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="contact_number">Contact Number</label>
            <input type="text" id="contact_number" name="contact_number">
          </div>
          
          <div class="form-group">
            <label for="operating_hours">Operating Hours</label>
            <input type="text" id="operating_hours" name="operating_hours" placeholder="e.g. 9AM-5PM">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="latitude">Latitude *</label>
            <input type="number" step="any" id="latitude" name="latitude" required>
          </div>
          
          <div class="form-group">
            <label for="longitude">Longitude *</label>
            <input type="number" step="any" id="longitude" name="longitude" required>
          </div>
        </div>
        
        <div class="map-container">
          <div id="locationMap" style="height: 250px; border-radius: 8px;"></div>
          <small class="map-note">Click on the map to set location coordinates</small>
        </div>
        
        <div class="inventory-section">
          <h3><i class="fas fa-tint"></i> Initial Blood Inventory (Optional)</h3>
          <button type="button" class="add-inventory-btn" onclick="addInventoryRow()">
            <i class="fas fa-plus"></i> Add Blood Type
          </button>
          <div id="inventoryContainer"></div>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Blood Bank
        </button>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
  <script>
    // Blood types array
    const bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    
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
    
    // Inventory management
    function addInventoryRow(bloodType = '', quantity = '') {
      const container = document.getElementById('inventoryContainer');
      const rowIndex = container.children.length;
      
      const row = document.createElement('div');
      row.className = 'inventory-row';
      row.innerHTML = `
        <div class="form-group">
          <label>Blood Type</label>
          <select name="blood_types[]" required>
            <option value="">Select Blood Type</option>
            ${bloodTypes.map(type => 
              `<option value="${type}" ${type === bloodType ? 'selected' : ''}>${type}</option>`
            ).join('')}
          </select>
        </div>
        <div class="form-group">
          <label>Quantity (Units)</label>
          <input type="number" name="quantities[]" min="0" value="${quantity}" required>
        </div>
        <button type="button" class="remove-inventory-btn" onclick="removeInventoryRow(this)">
          <i class="fas fa-times"></i>
        </button>
      `;
      
      container.appendChild(row);
    }
    
    function removeInventoryRow(button) {
      button.closest('.inventory-row').remove();
    }
    
    // Initialize main map
    let banksMap = L.map('banksMap').setView([14.5995, 120.9842], 13); // Default to Manila
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(banksMap);
    
    // Add markers for existing banks
    <?php foreach ($banks as $bank_id => $bank): ?>
      <?php if ($bank['latitude'] && $bank['longitude']): ?>
        L.marker([<?= $bank['latitude'] ?>, <?= $bank['longitude'] ?>])
          .addTo(banksMap)
          .bindPopup(`<b><?= addslashes($bank['branch_name']) ?></b><br><?= addslashes($bank['address']) ?>`);
      <?php endif; ?>
    <?php endforeach; ?>
    
    // Modal functions
    function openAddBankModal() {
      document.getElementById('modalTitle').textContent = 'Add New Blood Bank';
      document.getElementById('formAction').name = 'add_bank';
      document.getElementById('bankForm').reset();
      document.getElementById('bankId').value = '';
      document.getElementById('inventoryContainer').innerHTML = '';
      document.getElementById('bankModal').classList.add('active');
      
      // Initialize location map
      initLocationMap(14.5995, 120.9842);
    }
    
    function openEditBankModal(bank) {
      document.getElementById('modalTitle').textContent = 'Edit Blood Bank';
      document.getElementById('formAction').name = 'update_bank';
      document.getElementById('bankId').value = bank.bank_id;
      document.getElementById('branch_name').value = bank.branch_name;
      document.getElementById('address').value = bank.address;
      document.getElementById('contact_number').value = bank.contact_number || '';
      document.getElementById('operating_hours').value = bank.operating_hours || '';
      document.getElementById('latitude').value = bank.latitude;
      document.getElementById('longitude').value = bank.longitude;
      
      // Clear and populate inventory
      document.getElementById('inventoryContainer').innerHTML = '';
      if (bank.inventory && bank.inventory.length > 0) {
        bank.inventory.forEach(inv => {
          addInventoryRow(inv.blood_type, inv.units_available);
        });
      }
      
      document.getElementById('bankModal').classList.add('active');
      
      // Initialize location map with bank coordinates
      initLocationMap(bank.latitude, bank.longitude);
    }
    
    function closeModal() {
      document.getElementById('bankModal').classList.remove('active');
    }
    
    // Location map for modal
    let locationMap, locationMarker;
    
    function initLocationMap(lat, lng) {
      if (locationMap) {
        locationMap.remove();
      }
      
      locationMap = L.map('locationMap').setView([lat, lng], 15);
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(locationMap);
      
      // Add marker
      locationMarker = L.marker([lat, lng], {draggable: true}).addTo(locationMap);
      
      // Update coordinates when marker is dragged
      locationMarker.on('dragend', function(e) {
        const coords = e.target.getLatLng();
        document.getElementById('latitude').value = coords.lat.toFixed(6);
        document.getElementById('longitude').value = coords.lng.toFixed(6);
      });
      
      // Update coordinates when map is clicked
      locationMap.on('click', function(e) {
        const coords = e.latlng;
        document.getElementById('latitude').value = coords.lat.toFixed(6);
        document.getElementById('longitude').value = coords.lng.toFixed(6);
        locationMarker.setLatLng(coords);
      });
    }
    
    // Close modal when clicking outside
    document.getElementById('bankModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeModal();
      }
    });
    
    // Auto-fill coordinates from address using Nominatim API
    document.getElementById('address').addEventListener('blur', function() {
      const address = this.value;
      if (!address || document.getElementById('latitude').value) {
        return; // Don't override existing coordinates
      }
      
      fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}&limit=1`)
        .then(response => response.json())
        .then(data => {
          if (data.length > 0) {
            document.getElementById('latitude').value = data[0].lat;
            document.getElementById('longitude').value = data[0].lon;
            
            // Update map view if it exists
            if (locationMap) {
              const lat = parseFloat(data[0].lat);
              const lng = parseFloat(data[0].lon);
              locationMap.setView([lat, lng], 15);
              locationMarker.setLatLng([lat, lng]);
            }
          }
        })
        .catch(error => console.error('Geocoding error:', error));
    });
    
    // Keyboard navigation for autocomplete
    document.getElementById('branch_name').addEventListener('keydown', function(e) {
      const suggestions = document.getElementById('branchSuggestions');
      const items = suggestions.querySelectorAll('.autocomplete-suggestion');
      let selected = suggestions.querySelector('.autocomplete-suggestion.selected');
      
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (selected) {
          selected.classList.remove('selected');
          const next = selected.nextElementSibling;
          if (next) {
            next.classList.add('selected');
          } else {
            items[0]?.classList.add('selected');
          }
        } else {
          items[0]?.classList.add('selected');
        }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (selected) {
          selected.classList.remove('selected');
          const prev = selected.previousElementSibling;
          if (prev) {
            prev.classList.add('selected');
          } else {
            items[items.length - 1]?.classList.add('selected');
          }
        } else {
          items[items.length - 1]?.classList.add('selected');
        }
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (selected) {
          this.value = selected.textContent;
          suggestions.style.display = 'none';
        }
      } else if (e.key === 'Escape') {
        suggestions.style.display = 'none';
      }
    });
    
    // Form validation
    document.getElementById('bankForm').addEventListener('submit', function(e) {
      const bloodTypes = document.querySelectorAll('select[name="blood_types[]"]');
      const quantities = document.querySelectorAll('input[name="quantities[]"]');
      
      // Check for duplicate blood types
      const selectedTypes = [];
      let hasDuplicates = false;
      
      bloodTypes.forEach((select, index) => {
        const value = select.value;
        if (value) {
          if (selectedTypes.includes(value)) {
            hasDuplicates = true;
            select.style.borderColor = '#dc3545';
          } else {
            selectedTypes.push(value);
            select.style.borderColor = '#e0e0e0';
          }
        }
      });
      
      if (hasDuplicates) {
        e.preventDefault();
        alert('Please remove duplicate blood types.');
        return false;
      }
      
      // Validate quantities
      let hasInvalidQuantity = false;
      quantities.forEach(input => {
        const value = parseInt(input.value);
        if (input.value && (isNaN(value) || value < 0)) {
          hasInvalidQuantity = true;
          input.style.borderColor = '#dc3545';
        } else {
          input.style.borderColor = '#e0e0e0';
        }
      });
      
      if (hasInvalidQuantity) {
        e.preventDefault();
        alert('Please enter valid quantities (must be 0 or positive numbers).');
        return false;
      }
    });
    
    // Auto-resize modal content
    function resizeModal() {
      const modal = document.getElementById('bankModal');
      const content = modal.querySelector('.modal-content');
      const maxHeight = window.innerHeight * 0.9;
      
      if (content.offsetHeight > maxHeight) {
        content.style.maxHeight = maxHeight + 'px';
        content.style.overflowY = 'auto';
      } else {
        content.style.maxHeight = 'none';
        content.style.overflowY = 'visible';
      }
    }
    
    // Call resize on window resize
    window.addEventListener('resize', resizeModal);
    
    // Initialize with one inventory row for new banks
    document.addEventListener('DOMContentLoaded', function() {
      // Set default location to Philippines center
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function(position) {
          const lat = position.coords.latitude;
          const lng = position.coords.longitude;
          banksMap.setView([lat, lng], 13);
        });
      }
    });
  </script>
</body>
</html>