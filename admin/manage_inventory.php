<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);
    
    if ($category_name) {
        try {
            $stmt = $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $stmt->execute([$category_name]);
            $successMessage = "Category added successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error adding category: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please enter a category name.";
    }
}

// Add Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item_name = trim($_POST['item_name']);
    $quantity = (int)$_POST['quantity'];
    $expiry_date = $_POST['expiry_date'];
    $category_id = (int)$_POST['category_id'];

    if ($item_name && $quantity >= 0 && $expiry_date && $category_id) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory_items (item_name, quantity, expiry_date, category_id)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$item_name, $quantity, $expiry_date, $category_id]);
            $successMessage = "Item added successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error adding item: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill all fields correctly (quantity must be ≥ 0).";
    }
}

// Update Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_id = (int)$_POST['item_id'];
    $item_name = trim($_POST['item_name']);
    $quantity = (int)$_POST['quantity'];
    $expiry_date = $_POST['expiry_date'];
    $category_id = (int)$_POST['category_id'];

    if ($item_id && $item_name && $quantity >= 0 && $expiry_date && $category_id) {
        try {
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET item_name = ?, quantity = ?, expiry_date = ?, category_id = ?
                WHERE item_id = ?
            ");
            $stmt->execute([$item_name, $quantity, $expiry_date, $category_id, $item_id]);
            $successMessage = "Item updated successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error updating item: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Invalid data provided for update.";
    }
}

// Delete Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    if ($item_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE item_id = ?");
            $stmt->execute([$item_id]);
            $successMessage = "Item deleted successfully.";
        } catch (PDOException $e) {
            $errorMessage = "Error deleting item: " . $e->getMessage();
        }
    }
}

// Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];
    if ($category_id) {
        try {
            // Check if category is in use
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $errorMessage = "Cannot delete category - it's being used by inventory items.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
                $stmt->execute([$category_id]);
                $successMessage = "Category deleted successfully.";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error deleting category: " . $e->getMessage();
        }
    }
}

// Update Blood Inventory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_blood'])) {
    $blood_type = $_POST['blood_type'];
    $action_type = $_POST['action_type'];
    $units = (int)$_POST['units_count'];
    $notes = trim($_POST['blood_note'] ?? '');
    
    if ($blood_type && $action_type && $units > 0) {
        try {
            // First, check if blood type exists in the table
            $stmt = $pdo->prepare("SELECT units_available FROM blood_inventory WHERE blood_type = ?");
            $stmt->execute([$blood_type]);
            $current_units = $stmt->fetchColumn();
            
            if ($current_units !== false) {
                // Blood type exists, update the units
                $new_units = ($action_type === 'add') 
                    ? $current_units + $units 
                    : $current_units - $units;
                
                // Check if we have enough units to remove
                if ($action_type === 'remove' && $new_units < 0) {
                    $errorMessage = "Not enough units available for removal.";
                } else {
                    $stmt = $pdo->prepare("UPDATE blood_inventory SET units_available = ? WHERE blood_type = ?");
                    $stmt->execute([$new_units, $blood_type]);
                    
                    // Log the transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO blood_inventory_log (blood_type, action_type, units, notes)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$blood_type, $action_type, $units, $notes]);
                    
                    $successMessage = "Blood inventory updated successfully!";
                }
            } else {
                // Blood type doesn't exist, insert new record (only if adding units)
                if ($action_type === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO blood_inventory (blood_type, units_available) VALUES (?, ?)");
                    $stmt->execute([$blood_type, $units]);
                    
                    // Log the transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO blood_inventory_log (blood_type, action_type, units, notes)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$blood_type, $action_type, $units, $notes]);
                    
                    $successMessage = "Blood inventory added successfully!";
                } else {
                    $errorMessage = "Cannot remove units from a blood type that doesn't exist.";
                }
            }
        } catch (PDOException $e) {
            $errorMessage = "Error updating blood inventory: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill all required fields correctly.";
    }
}

// Get all categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();

// Get blood inventory
try {
    $stmt = $pdo->query("
        SELECT blood_type, units_available 
        FROM blood_inventory 
        ORDER BY blood_type
    ");
    $blood_inventory = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Ensure all blood types are present
    $all_blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    foreach ($all_blood_types as $type) {
        if (!isset($blood_inventory[$type])) {
            $blood_inventory[$type] = 0;
        }
    }
} catch (PDOException $e) {
    // If table doesn't exist, create a mock blood inventory
    $blood_inventory = [
        'A+' => 42,
        'A-' => 15,
        'B+' => 36,
        'B-' => 8,
        'AB+' => 12,
        'AB-' => 5,
        'O+' => 58,
        'O-' => 18
    ];
}

// Determine blood status for each type
$blood_status = [];
foreach ($blood_inventory as $type => $units) {
    if ($units < 10) {
        $blood_status[$type] = ['status' => 'low', 'text' => 'Low Stock'];
    } elseif ($units > 50) {
        $blood_status[$type] = ['status' => 'high', 'text' => 'High Stock'];
    } else {
        $blood_status[$type] = ['status' => 'normal', 'text' => 'Normal'];
    }
}

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

if ($search || $category_filter) {
    $query = "
        SELECT i.*, c.category_name 
        FROM inventory_items i
        LEFT JOIN categories c ON i.category_id = c.category_id
        WHERE 1=1
    ";
    $params = [];
    
    if ($search) {
        // Use LIKE search if FULLTEXT is not available
        $query .= " AND i.item_name LIKE ?";
        $params[] = "%$search%";
    }
    
    if ($category_filter) {
        $query .= " AND i.category_id = ?";
        $params[] = $category_filter;
    }
    
    $query .= " ORDER BY i.expiry_date ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
} else {
    $stmt = $pdo->query("
        SELECT i.*, c.category_name 
        FROM inventory_items i
        LEFT JOIN categories c ON i.category_id = c.category_id
        ORDER BY i.expiry_date ASC
    ");
}
$items = $stmt->fetchAll();

// Get inventory stats
$total_items = $pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn();
$expiring_soon = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$expired_items = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE expiry_date < CURDATE()")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Inventory - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/manage_inventory.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="inventory-container">
      <div class="page-header">
        <h1><i class="fas fa-boxes"></i> Inventory Management</h1>
        <p>Track and manage your inventory items</p>
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
        <form method="GET" class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search inventory..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
          <?php if ($search): ?>
            <a href="manage_inventory.php" class="clear-search">
              <i class="fas fa-times"></i>
            </a>
          <?php endif; ?>
        </form>
        
        <div>
          <button class="btn-create" onclick="openAddItemModal()">
            <i class="fas fa-plus-circle"></i> Add New Item
          </button>
          <button class="btn-create-blood" style="margin-left: 10px; background: linear-gradient(135deg, var(--prc-red) 0%, var(--prc-red-dark) 100%);" onclick="openBloodInventoryModal()">
            <i class="fas fa-tint"></i> Manage Blood Inventory
          </button>
        </div>
      </div>

      <!-- Statistics Overview -->
      <div class="stats-overview">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-box"></i>
          </div>
          <div>
            <div class="stat-number"><?= $total_items ?></div>
            <div class="stat-label">Total Items</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div>
            <div class="stat-number"><?= $expiring_soon ?></div>
            <div class="stat-label">Expiring Soon</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);">
            <i class="fas fa-skull-crossbones"></i>
          </div>
          <div>
            <div class="stat-number"><?= $expired_items ?></div>
            <div class="stat-label">Expired</div>
          </div>
        </div>
      </div>

      <!-- Blood Inventory Section -->
      <section class="card blood-inventory-section">
        <div class="card-header">
          <h2><i class="fas fa-tint"></i> Blood Inventory</h2>
        </div>
        <div class="card-body">
          <div class="blood-inventory-grid">
            <?php foreach ($blood_inventory as $type => $units): ?>
              <div class="blood-type-card">
                <div class="blood-type"><?= $type ?></div>
                <div class="blood-quantity"><?= $units ?></div>
                <div class="blood-unit">units</div>
                <div class="blood-status <?= $blood_status[$type]['status'] ?>">
                  <?= $blood_status[$type]['text'] ?>
                </div>
                <button class="blood-manage-btn" onclick="openBloodInventoryModal('<?= $type ?>')">
                  <i class="fas fa-edit"></i> Manage
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- Inventory Content -->
      <div class="inventory-content">
        <!-- Categories Section - Redesigned as cards -->
        <section class="card categories-section">
          <div class="card-header">
            <h2><i class="fas fa-tags"></i> Categories</h2>
          </div>
          <div class="card-body">
            <form method="POST" class="category-form">
              <input type="hidden" name="add_category" value="1">
              <div class="form-group">
                <label for="category_name">Add New Category</label>
                <div class="input-group">
                  <input type="text" id="category_name" name="category_name" placeholder="Enter category name" required>
                  <button type="submit" class="btn-submit small">
                    <i class="fas fa-plus"></i> Add Category
                  </button>
                </div>
              </div>
            </form>
            
            <div class="categories-grid">
              <?php if (empty($categories)): ?>
                <p class="empty-message">No categories found. Add your first category above.</p>
              <?php else: ?>
                <?php foreach ($categories as $category): 
                  // Get item count for this category
                  $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
                  $stmt->execute([$category['category_id']]);
                  $itemCount = $stmt->fetchColumn();
                ?>
                  <div class="category-card">
                    <div class="category-card-header">
                      <h3><?= htmlspecialchars($category['category_name']) ?></h3>
                      <form method="POST" onsubmit="return confirm('Delete this category?')">
                        <input type="hidden" name="delete_category" value="1">
                        <input type="hidden" name="category_id" value="<?= $category['category_id'] ?>">
                        <button type="submit" class="btn-delete small">
                          <i class="fas fa-trash-alt"></i>
                        </button>
                      </form>
                    </div>
                    <div class="category-card-body">
                      <div class="category-item-count">
                        <i class="fas fa-box"></i>
                        <span><?= $itemCount ?> items in this category</span>
                      </div>
                    </div>
                    <div class="category-card-footer">
                      <button class="view-category-btn" onclick="filterByCategory(<?= $category['category_id'] ?>)">
                        <i class="fas fa-eye"></i> View Items
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- Inventory Table Section -->
        <section class="card inventory-table-section">
          <div class="card-header">
            <h2><i class="fas fa-boxes"></i> Inventory Items</h2>
            <?php if ($category_filter): 
              $filtered_category = '';
              foreach ($categories as $cat) {
                if ($cat['category_id'] == $category_filter) {
                  $filtered_category = $cat['category_name'];
                  break;
                }
              }
            ?>
              <div style="margin-top: 10px; font-size: 0.9rem;">
                <span style="background: #f0f0f0; padding: 5px 10px; border-radius: 4px;">
                  Filtered by: <?= htmlspecialchars($filtered_category) ?>
                  <a href="manage_inventory.php" style="margin-left: 8px; color: var(--prc-red);">
                    <i class="fas fa-times"></i> Clear
                  </a>
                </span>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (empty($items)): ?>
              <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No Inventory Items Found</h3>
                <p><?= $search || $category_filter ? 'Try different search criteria' : 'Add your first inventory item' ?></p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Category</th>
                      <th>Quantity</th>
                      <th>Expiry Date</th>
                      <th>Status</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $item): 
                      $expiryDate = strtotime($item['expiry_date']);
                      $today = strtotime('today');
                      $soon = strtotime('+30 days');
                      
                      if ($expiryDate < $today) {
                        $statusClass = 'expired';
                        $statusText = 'Expired';
                      } elseif ($expiryDate < $soon) {
                        $statusClass = 'warning';
                        $statusText = 'Expiring Soon';
                      } else {
                        $statusClass = 'good';
                        $statusText = 'Good';
                      }
                    ?>
                      <tr>
                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                        <td><?= htmlspecialchars($item['category_name']) ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td><?= date('M d, Y', $expiryDate) ?></td>
                        <td>
                          <span class="status-badge <?= $statusClass ?>">
                            <?= $statusText ?>
                          </span>
                        </td>
                        <td class="actions">
                          <button class="btn-action btn-edit" onclick="openEditItemModal(<?= htmlspecialchars(json_encode($item)) ?>)">
                            <i class="fas fa-edit"></i>
                          </button>
                          <form method="POST" onsubmit="return confirm('Are you sure you want to delete this item?')">
                            <input type="hidden" name="delete_item" value="1">
                            <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
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

  <!-- Add/Edit Item Modal -->
  <div class="modal" id="itemModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="modalTitle">Add New Item</h2>
        <button class="close-modal" onclick="closeModal('itemModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="itemForm">
        <input type="hidden" name="add_item" value="1" id="formAction">
        <input type="hidden" name="item_id" id="itemId">
        
        <div class="form-group">
          <label for="item_name">Item Name *</label>
          <input type="text" id="item_name" name="item_name" required>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="category_id">Category *</label>
            <select id="category_id" name="category_id" required>
              <option value="">Select a category</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= $category['category_id'] ?>">
                  <?= htmlspecialchars($category['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label for="quantity">Quantity *</label>
            <input type="number" id="quantity" name="quantity" min="0" required>
          </div>
        </div>
        
        <div class="form-group">
          <label for="expiry_date">Expiry Date *</label>
          <input type="date" id="expiry_date" name="expiry_date" required>
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Save Item
        </button>
      </form>
    </div>
  </div>

  <!-- Blood Inventory Modal -->
  <div class="modal" id="bloodModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="bloodModalTitle">Manage Blood Inventory</h2>
        <button class="close-modal" onclick="closeModal('bloodModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="bloodForm">
        <input type="hidden" name="update_blood" value="1">
        <input type="hidden" name="blood_type" id="bloodType">
        
        <div class="blood-type-select">
          <label>Select Blood Type</label>
          <div class="blood-type-options">
            <div class="blood-type-option" data-type="A+" onclick="selectBloodType(this)">A+</div>
            <div class="blood-type-option" data-type="A-" onclick="selectBloodType(this)">A-</div>
            <div class="blood-type-option" data-type="B+" onclick="selectBloodType(this)">B+</div>
            <div class="blood-type-option" data-type="B-" onclick="selectBloodType(this)">B-</div>
            <div class="blood-type-option" data-type="AB+" onclick="selectBloodType(this)">AB+</div>
            <div class="blood-type-option" data-type="AB-" onclick="selectBloodType(this)">AB-</div>
            <div class="blood-type-option" data-type="O+" onclick="selectBloodType(this)">O+</div>
            <div class="blood-type-option" data-type="O-" onclick="selectBloodType(this)">O-</div>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label for="current_units">Current Units</label>
            <input type="number" id="current_units" name="current_units" readonly>
          </div>
          
          <div class="form-group">
            <label for="action_type">Action Type</label>
            <select id="action_type" name="action_type" required>
              <option value="">Select action</option>
              <option value="add">Add Units</option>
              <option value="remove">Remove Units</option>
            </select>
          </div>
        </div>
        
        <div class="form-group">
          <label for="units_count">Number of Units *</label>
          <input type="number" id="units_count" name="units_count" min="1" required>
        </div>
        
        <div class="form-group">
          <label for="blood_note">Note (Optional)</label>
          <input type="text" id="blood_note" name="blood_note" placeholder="Donation source or usage purpose">
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Update Blood Inventory
        </button>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>
    // Blood inventory data from PHP
    const bloodInventory = <?= json_encode($blood_inventory) ?>;
  
    function openAddItemModal() {
      document.getElementById('modalTitle').textContent = 'Add New Item';
      document.getElementById('formAction').name = 'add_item';
      document.getElementById('itemForm').reset();
      document.getElementById('itemId').value = '';
      document.getElementById('itemModal').classList.add('active');
      
      // Set default expiry date to today + 30 days
      const today = new Date();
      const futureDate = new Date(today);
      futureDate.setDate(futureDate.getDate() + 30);
      
      const formattedDate = futureDate.toISOString().split('T')[0];
      document.getElementById('expiry_date').value = formattedDate;
    }
    
    function openEditItemModal(item) {
      document.getElementById('modalTitle').textContent = 'Edit Item';
      document.getElementById('formAction').name = 'update_item';
      document.getElementById('itemId').value = item.item_id;
      document.getElementById('item_name').value = item.item_name;
      document.getElementById('category_id').value = item.category_id;
      document.getElementById('quantity').value = item.quantity;
      document.getElementById('expiry_date').value = item.expiry_date;
      document.getElementById('itemModal').classList.add('active');
    }
    
    function openBloodInventoryModal(bloodType = null) {
      const modal = document.getElementById('bloodModal');
      modal.classList.add('active');
      
      if (bloodType) {
        // Pre-select the blood type
        selectBloodType(document.querySelector(`.blood-type-option[data-type="${bloodType}"]`));
      }
    }
    
    function selectBloodType(element) {
      // Clear all selected
      document.querySelectorAll('.blood-type-option').forEach(opt => {
        opt.classList.remove('selected');
      });
      
      // Select this one
      element.classList.add('selected');
      
      const bloodType = element.getAttribute('data-type');
      document.getElementById('bloodType').value = bloodType;
      document.getElementById('bloodModalTitle').textContent = `Manage ${bloodType} Blood Inventory`;
      
      // Set current units
      document.getElementById('current_units').value = bloodInventory[bloodType] || 0;
    }
    
    function closeModal(modalId) {
      document.getElementById(modalId).classList.remove('active');
    }
    
    function filterByCategory(categoryId) {
      // Redirect to the same page but with category filter
      window.location.href = `manage_inventory.php?category=${categoryId}`;
    }
    
    // Close modal when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', function(e) {
        if (e.target === this) {
          this.classList.remove('active');
        }
      });
    });

    // Initialize date picker with min date as today
    document.addEventListener('DOMContentLoaded', function() {
      const today = new Date().toISOString().split('T')[0];
      document.getElementById('expiry_date').min = today;
    });
  </script>
</body>
</html>