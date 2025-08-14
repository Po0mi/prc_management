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
    $bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
    $location = trim($_POST['location']) ?: 'Central Storage';

    if ($item_name && $quantity >= 0 && $expiry_date && $category_id) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory_items (item_name, quantity, expiry_date, category_id, bank_id, location)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$item_name, $quantity, $expiry_date, $category_id, $bank_id, $location]);
            $successMessage = "Item added successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error adding item: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill all required fields correctly (quantity must be ≥ 0).";
    }
}

// Update Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_id = (int)$_POST['item_id'];
    $item_name = trim($_POST['item_name']);
    $quantity = (int)$_POST['quantity'];
    $expiry_date = $_POST['expiry_date'];
    $category_id = (int)$_POST['category_id'];
    $bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
    $location = trim($_POST['location']) ?: 'Central Storage';

    if ($item_id && $item_name && $quantity >= 0 && $expiry_date && $category_id) {
        try {
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET item_name = ?, quantity = ?, expiry_date = ?, category_id = ?, bank_id = ?, location = ?
                WHERE item_id = ?
            ");
            $stmt->execute([$item_name, $quantity, $expiry_date, $category_id, $bank_id, $location, $item_id]);
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

// Update Blood Inventory - Now connected to blood banks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_blood'])) {
    $blood_type = $_POST['blood_type'];
    $action_type = $_POST['action_type'];
    $units = (int)$_POST['units_count'];
    $notes = trim($_POST['blood_note'] ?? '');
    $location = trim($_POST['location'] ?? 'Main Storage');
    $bank_id = (int)$_POST['bank_id']; // Now required
    
    if ($blood_type && $action_type && $units > 0 && $bank_id) {
        try {
            // Check if blood type exists in inventory for this bank
            $stmt = $pdo->prepare("SELECT units_available, inventory_id FROM blood_inventory WHERE blood_type = ? AND bank_id = ?");
            $stmt->execute([$blood_type, $bank_id]);
            $result = $stmt->fetch();
            
            if ($result) {
                // Blood type exists, update the units
                $current_units = $result['units_available'];
                $inventory_id = $result['inventory_id'];
                $new_units = ($action_type === 'add') 
                    ? $current_units + $units 
                    : $current_units - $units;
                
                // Check if we have enough units to remove
                if ($action_type === 'remove' && $new_units < 0) {
                    $errorMessage = "Not enough units available for removal.";
                } else {
                    $stmt = $pdo->prepare("UPDATE blood_inventory SET units_available = ?, location = ? WHERE inventory_id = ?");
                    $stmt->execute([$new_units, $location, $inventory_id]);
                    
                    // Log the transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO blood_inventory_log (inventory_id, bank_id, blood_type, action_type, units, notes)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$inventory_id, $bank_id, $blood_type, $action_type, $units, $notes]);
                    
                    $successMessage = "Blood inventory updated successfully!";
                }
            } else {
                // Blood type doesn't exist, create new record (only if adding units)
                if ($action_type === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO blood_inventory (bank_id, blood_type, units_available, location) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$bank_id, $blood_type, $units, $location]);
                    
                    $inventory_id = $pdo->lastInsertId();
                    
                    // Log the transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO blood_inventory_log (inventory_id, bank_id, blood_type, action_type, units, notes)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$inventory_id, $bank_id, $blood_type, $action_type, $units, $notes]);
                    
                    $successMessage = "Blood inventory added successfully!";
                } else {
                    $errorMessage = "Cannot remove units from a blood type that doesn't exist.";
                }
            }
        } catch (PDOException $e) {
            $errorMessage = "Error updating blood inventory: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill all required fields correctly including blood bank selection.";
    }
}

// Get all categories
try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();
} catch (PDOException $e) {
    $categories = [];
    if (empty($errorMessage)) {
        $errorMessage = "Categories table not found. Please create the required tables.";
    }
}

// Get all blood banks
try {
    $blood_banks = $pdo->query("SELECT * FROM blood_banks ORDER BY branch_name")->fetchAll();
} catch (PDOException $e) {
    $blood_banks = [];
}

// Get selected bank or default to first bank
$selected_bank_id = isset($_GET['bank']) ? (int)$_GET['bank'] : (!empty($blood_banks) ? $blood_banks[0]['bank_id'] : 0);

// Get blood inventory for selected bank
try {
    if ($selected_bank_id) {
        $stmt = $pdo->prepare("
            SELECT blood_type, units_available, location 
            FROM blood_inventory 
            WHERE bank_id = ?
            ORDER BY blood_type
        ");
        $stmt->execute([$selected_bank_id]);
        $blood_results = $stmt->fetchAll();
        
        // Convert to associative array
        $blood_inventory = [];
        foreach ($blood_results as $row) {
            $blood_inventory[$row['blood_type']] = $row['units_available'];
        }
        
        // Ensure all blood types are present
        $all_blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        foreach ($all_blood_types as $type) {
            if (!isset($blood_inventory[$type])) {
                $blood_inventory[$type] = 0;
            }
        }
    } else {
        $blood_inventory = array_fill_keys(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 0);
    }
} catch (PDOException $e) {
    $blood_inventory = array_fill_keys(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 0);
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
$bank_filter = isset($_GET['bank_filter']) ? (int)$_GET['bank_filter'] : 0;

try {
    $query = "
        SELECT i.*, c.category_name, b.branch_name 
        FROM inventory_items i
        LEFT JOIN categories c ON i.category_id = c.category_id
        LEFT JOIN blood_banks b ON i.bank_id = b.bank_id
        WHERE 1=1
    ";
    $params = [];
    
    if ($search) {
        $query .= " AND i.item_name LIKE ?";
        $params[] = "%$search%";
    }
    
    if ($category_filter) {
        $query .= " AND i.category_id = ?";
        $params[] = $category_filter;
    }
    
    if ($bank_filter) {
        $query .= " AND i.bank_id = ?";
        $params[] = $bank_filter;
    }
    
    $query .= " ORDER BY i.expiry_date ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Get inventory stats
    $total_items = $pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn();
    $expiring_soon = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $expired_items = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE expiry_date < CURDATE()")->fetchColumn();
    
    // Get total blood units across all banks
    $total_blood_units = $pdo->query("SELECT SUM(units_available) FROM blood_inventory")->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $items = [];
    $total_items = 0;
    $expiring_soon = 0;
    $expired_items = 0;
    $total_blood_units = 0;
    if (empty($errorMessage)) {
        $errorMessage = "Database tables not found. Please create the required tables first.";
    }
}

// Get selected bank name for display
$selected_bank_name = 'Central Inventory';
if ($selected_bank_id) {
    foreach ($blood_banks as $bank) {
        if ($bank['bank_id'] == $selected_bank_id) {
            $selected_bank_name = $bank['branch_name'];
            break;
        }
    }
}
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
        <p>Manage blood inventory and medical supplies across all blood bank locations</p>
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

      <!-- Bank Selector -->
      <div class="bank-selector">
        <div class="bank-selector-header">
          <h3><i class="fas fa-hospital"></i> Select Blood Bank Location</h3>
          <a href="manage_blood_banks.php" class="btn-manage-banks">
            <i class="fas fa-map-marked-alt"></i> Manage Locations
          </a>
        </div>
        <div class="bank-options">
          <?php foreach ($blood_banks as $bank): ?>
            <a href="?bank=<?= $bank['bank_id'] ?>" 
               class="bank-option <?= $bank['bank_id'] == $selected_bank_id ? 'active' : '' ?>">
              <div class="bank-info">
                <div class="bank-name"><?= htmlspecialchars($bank['branch_name']) ?></div>
                <div class="bank-address"><?= htmlspecialchars($bank['address']) ?></div>
              </div>
              <?php
              // Get blood units count for this bank
              try {
                $stmt = $pdo->prepare("SELECT SUM(units_available) FROM blood_inventory WHERE bank_id = ?");
                $stmt->execute([$bank['bank_id']]);
                $bank_blood_units = $stmt->fetchColumn() ?: 0;
              } catch (PDOException $e) {
                $bank_blood_units = 0;
              }
              ?>
              <div class="bank-stats">
                <span class="blood-units"><?= $bank_blood_units ?> units</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Action Bar -->
      <div class="action-bar">
        <form method="GET" class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" name="search" placeholder="Search inventory..." value="<?= htmlspecialchars($search) ?>">
          <input type="hidden" name="bank" value="<?= $selected_bank_id ?>">
          <button type="submit"><i class="fas fa-arrow-right"></i></button>
          <?php if ($search): ?>
            <a href="manage_inventory.php?bank=<?= $selected_bank_id ?>" class="clear-search">
              <i class="fas fa-times"></i>
            </a>
          <?php endif; ?>
        </form>
        
        <div class="action-buttons">
          <button class="btn-create" onclick="openAddItemModal()">
            <i class="fas fa-plus-circle"></i> Add Medical Item
          </button>
          <button class="btn-create-blood" onclick="openBloodInventoryModal()">
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
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #dc143c 0%, #b91c1c 100%);">
            <i class="fas fa-tint"></i>
          </div>
          <div>
            <div class="stat-number"><?= $total_blood_units ?></div>
            <div class="stat-label">Total Blood Units</div>
          </div>
        </div>
      </div>

      <!-- Blood Inventory Section -->
      <section class="card blood-inventory-section">
        <div class="card-header">
          <h2><i class="fas fa-tint"></i> Blood Inventory - <?= htmlspecialchars($selected_bank_name) ?></h2>
          <p style="margin: 0; color: #666; font-size: 0.9rem;">
            <?= $selected_bank_id ? 'Manage blood inventory for this specific location' : 'Select a blood bank location to manage inventory' ?>
          </p>
        </div>
        <div class="card-body">
          <?php if ($selected_bank_id): ?>
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
            
            <!-- Blood Inventory Activity Log -->
            <div class="activity-log" style="margin-top: 2rem;">
              <h3><i class="fas fa-history"></i> Recent Blood Activity - <?= htmlspecialchars($selected_bank_name) ?></h3>
              <div class="log-container">
                <?php
                try {
                  $stmt = $pdo->prepare("
                    SELECT bil.*, bi.location, bb.branch_name
                    FROM blood_inventory_log bil
                    LEFT JOIN blood_inventory bi ON bil.inventory_id = bi.inventory_id
                    LEFT JOIN blood_banks bb ON bil.bank_id = bb.bank_id
                    WHERE bil.bank_id = ?
                    ORDER BY bil.log_date DESC 
                    LIMIT 5
                  ");
                  $stmt->execute([$selected_bank_id]);
                  $recent_logs = $stmt->fetchAll();
                  
                  if ($recent_logs): ?>
                    <?php foreach ($recent_logs as $log): ?>
                      <div class="log-entry">
                        <div class="log-icon <?= $log['action_type'] === 'add' ? 'add' : 'remove' ?>">
                          <i class="fas fa-<?= $log['action_type'] === 'add' ? 'plus' : 'minus' ?>"></i>
                        </div>
                        <div class="log-details">
                          <div class="log-main">
                            <strong><?= htmlspecialchars($log['blood_type']) ?></strong> - 
                            <?= $log['action_type'] === 'add' ? 'Added' : 'Removed' ?> 
                            <span class="units"><?= $log['units'] ?> units</span>
                          </div>
                          <div class="log-meta">
                            <?= date('M d, Y H:i', strtotime($log['log_date'])) ?>
                            <?php if ($log['notes']): ?>
                              - <?= htmlspecialchars($log['notes']) ?>
                            <?php endif; ?>
                            <?php if ($log['location']): ?>
                              - Location: <?= htmlspecialchars($log['location']) ?>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p class="no-logs">No recent activity found for this location</p>
                  <?php endif; ?>
                <?php } catch (PDOException $e) {
                  echo '<p class="no-logs">Activity log unavailable</p>';
                } ?>
              </div>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <i class="fas fa-hospital"></i>
              <h3>No Blood Bank Selected</h3>
              <p>Please select a blood bank location above to manage its blood inventory</p>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Inventory Content -->
      <div class="inventory-content">
        <!-- Categories Section -->

<!-- Enhanced Categories Section - Replace the existing categories section with this -->
<section class="card categories-section">
  <div class="card-header">
    <h2><i class="fas fa-tags"></i> Categories Management</h2>
  </div>
  <div class="card-body">
    
    <!-- Add Category Form -->
    <form method="POST" class="category-form">
      <input type="hidden" name="add_category" value="1">
      <div class="form-group">
        <label for="category_name">Add New Category</label>
        <div class="input-group">
          <input 
            type="text" 
            id="category_name" 
            name="category_name" 
            placeholder="Enter category name (e.g., Medical Supplies, Blood Products...)" 
            required
          >
          <button type="submit" class="btn-submit">
            <i class="fas fa-plus"></i> Add Category
          </button>
        </div>
      </div>
    </form>

    <!-- Category Statistics -->
    <div class="category-stats">
      <div class="stat-item">
        <div class="stat-number"><?= count($categories) ?></div>
        <div class="stat-label">Total Categories</div>
      </div>
      <div class="stat-item">
        <div class="stat-number"><?= $total_items ?></div>
        <div class="stat-label">Total Items</div>
      </div>
      <div class="stat-item">
        <div class="stat-number">
          <?php
          $active_categories = 0;
          foreach ($categories as $category) {
            try {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
              $stmt->execute([$category['category_id']]);
              if ($stmt->fetchColumn() > 0) $active_categories++;
            } catch (PDOException $e) {}
          }
          echo $active_categories;
          ?>
        </div>
        <div class="stat-label">Active Categories</div>
      </div>
    </div>
    
    <!-- Categories List -->
    <div class="categories-list">
      <h4><i class="fas fa-list"></i> Existing Categories</h4>
      
      <?php if (empty($categories)): ?>
        <div class="categories-empty">
          <i class="fas fa-folder-open"></i>
          <h4>No Categories Yet</h4>
          <p>Add your first category above to get started organizing your inventory.</p>
        </div>
      <?php else: ?>
        <div class="categories-items">
          <?php foreach ($categories as $category): 
            try {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
              $stmt->execute([$category['category_id']]);
              $itemCount = $stmt->fetchColumn();
            } catch (PDOException $e) {
              $itemCount = 0;
            }
            
            // Generate a simple icon based on category name
            $categoryLetter = strtoupper(substr($category['category_name'], 0, 1));
          ?>
            <div class="category-item">
              <div class="category-item-info">
                <div class="category-icon">
                  <?= $categoryLetter ?>
                </div>
                <div class="category-details">
                  <div class="category-name"><?= htmlspecialchars($category['category_name']) ?></div>
                  <div class="category-count">
                    <i class="fas fa-box"></i>
                    <?= $itemCount ?> <?= $itemCount == 1 ? 'item' : 'items' ?>
                  </div>
                </div>
              </div>
              
              <div class="category-actions">
                <button 
                  class="btn-view-category" 
                  onclick="filterByCategory(<?= $category['category_id'] ?>)"
                  title="View items in this category"
                >
                  <i class="fas fa-eye"></i> View Items
                </button>
                
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this category? This cannot be undone.')">
                  <input type="hidden" name="delete_category" value="1">
                  <input type="hidden" name="category_id" value="<?= $category['category_id'] ?>">
                  <button 
                    type="submit" 
                    class="btn-delete-category"
                    title="Delete category"
                    <?= $itemCount > 0 ? 'disabled title="Cannot delete category with items"' : '' ?>
                  >
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    
  </div>
</section>
        <!-- Inventory Table Section -->
        <section class="card inventory-table-section">
          <div class="card-header">
            <div class="table-header">
              <h2><i class="fas fa-boxes"></i> Medical Supplies Inventory</h2>
              <div class="table-filters">
                <select onchange="filterByBank(this.value)" class="filter-select">
                  <option value="0">All Locations</option>
                  <?php foreach ($blood_banks as $bank): ?>
                    <option value="<?= $bank['bank_id'] ?>" <?= $bank_filter == $bank['bank_id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($bank['branch_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                
                <select onchange="filterByCategory(this.value)" class="filter-select">
                  <option value="0">All Categories</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= $category['category_id'] ?>" <?= $category_filter == $category['category_id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($category['category_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            
            <?php if ($category_filter || $bank_filter): ?>
              <div style="margin-top: 10px; font-size: 0.9rem;">
                <?php if ($category_filter): 
                  $filtered_category = '';
                  foreach ($categories as $cat) {
                    if ($cat['category_id'] == $category_filter) {
                      $filtered_category = $cat['category_name'];
                      break;
                    }
                  }
                ?>
                  <span class="filter-tag">
                    Category: <?= htmlspecialchars($filtered_category) ?>
                    <a href="?bank=<?= $selected_bank_id ?>">
                      <i class="fas fa-times"></i>
                    </a>
                  </span>
                <?php endif; ?>
                
                <?php if ($bank_filter): 
                  $filtered_bank = '';
                  foreach ($blood_banks as $bank) {
                    if ($bank['bank_id'] == $bank_filter) {
                      $filtered_bank = $bank['branch_name'];
                      break;
                    }
                  }
                ?>
                  <span class="filter-tag">
                    Location: <?= htmlspecialchars($filtered_bank) ?>
                    <a href="?bank=<?= $selected_bank_id ?>">
                      <i class="fas fa-times"></i>
                    </a>
                  </span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (empty($items)): ?>
              <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No Inventory Items Found</h3>
                <p><?= $search || $category_filter || $bank_filter ? 'Try different search criteria' : 'Add your first inventory item' ?></p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Category</th>
                      <th>Location</th>
                      <th>Blood Bank</th>
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
                        <td><?= htmlspecialchars($item['category_name'] ?? 'No Category') ?></td>
                        <td><?= htmlspecialchars($item['location'] ?? 'Central Storage') ?></td>
                        <td><?= htmlspecialchars($item['branch_name'] ?? 'Central') ?></td>
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
                          <form method="POST" onsubmit="return confirm('Are you sure you want to delete this item?')" style="display: inline;">
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
        
        <div class="form-row">
          <div class="form-group">
            <label for="bank_id">Blood Bank Location</label>
            <select id="bank_id" name="bank_id">
              <option value="">Central Storage</option>
              <?php foreach ($blood_banks as $bank): ?>
                <option value="<?= $bank['bank_id'] ?>" <?= $bank['bank_id'] == $selected_bank_id ? 'selected' : '' ?>>
                  <?= htmlspecialchars($bank['branch_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label for="location">Storage Location</label>
            <input type="text" id="location" name="location" placeholder="e.g., Storage Room A, Refrigerator" value="Central Storage">
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
        <input type="hidden" name="bank_id" id="bloodBankId" value="<?= $selected_bank_id ?>">
        
        <div class="form-group">
          <label>Blood Bank Location</label>
          <select onchange="changeBloodBank(this.value)" class="form-control">
            <?php foreach ($blood_banks as $bank): ?>
              <option value="<?= $bank['bank_id'] ?>" <?= $bank['bank_id'] == $selected_bank_id ? 'selected' : '' ?>>
                <?= htmlspecialchars($bank['branch_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
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
          <label for="blood_location">Storage Location</label>
          <input type="text" id="blood_location" name="location" placeholder="e.g., Main Storage, Refrigerator A, etc." value="Main Storage">
        </div>
        
        <div class="form-group">
          <label for="blood_note">Note (Optional)</label>
          <input type="text" id="blood_note" name="blood_note" placeholder="Donation source, usage purpose, or other notes">
        </div>
        
        <button type="submit" class="btn-submit">
          <i class="fas fa-save"></i> Update Blood Inventory
        </button>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>
// Enhanced category management functions
function filterByCategory(categoryId) {
  const currentParams = new URLSearchParams(window.location.search);
  if (categoryId && categoryId !== '0') {
    currentParams.set('category', categoryId);
  } else {
    currentParams.delete('category');
  }
  
  // Smooth scroll to inventory table after filtering
  window.location.href = `${window.location.pathname}?${currentParams.toString()}`;
  
  // Add visual feedback
  setTimeout(() => {
    const inventorySection = document.querySelector('.inventory-table-section');
    if (inventorySection) {
      inventorySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      inventorySection.style.animation = 'highlightSection 2s ease';
    }
  }, 100);
}

// Add highlight animation for filtered results
const style = document.createElement('style');
style.textContent = `
  @keyframes highlightSection {
    0% { box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
    50% { box-shadow: 0 8px 25px rgba(160, 0, 0, 0.2); }
    100% { box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
  }
`;
document.head.appendChild(style);

// Auto-focus on category input after page load
document.addEventListener('DOMContentLoaded', function() {
  const categoryInput = document.getElementById('category_name');
  if (categoryInput && !window.location.search.includes('category=')) {
    // Only focus if not filtering results
    setTimeout(() => categoryInput.focus(), 500);
  }
});

// Form validation and enhancement
document.querySelector('.category-form').addEventListener('submit', function(e) {
  const input = document.getElementById('category_name');
  const value = input.value.trim();
  
  if (value.length < 2) {
    e.preventDefault();
    alert('Category name must be at least 2 characters long.');
    input.focus();
    return false;
  }
  
  if (value.length > 50) {
    e.preventDefault();
    alert('Category name must be less than 50 characters long.');
    input.focus();
    return false;
  }
  
  // Visual feedback during submission
  const button = this.querySelector('.btn-submit');
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
  button.disabled = true;
});

// Real-time character count for category input
document.getElementById('category_name').addEventListener('input', function() {
  const maxLength = 50;
  const currentLength = this.value.length;
  const remaining = maxLength - currentLength;
  
  // Remove existing counter
  const existingCounter = document.querySelector('.char-counter');
  if (existingCounter) existingCounter.remove();
  
  // Add character counter
  if (currentLength > 0) {
    const counter = document.createElement('div');
    counter.className = 'char-counter';
    counter.style.cssText = `
      font-size: 0.8rem; 
      color: ${remaining < 10 ? '#dc3545' : '#6c757d'}; 
      margin-top: 0.3rem;
      text-align: right;
    `;
    counter.textContent = `${remaining} characters remaining`;
    this.parentNode.appendChild(counter);
  }
});
</script>
  <script>
    // Blood inventory data from PHP
    const bloodInventory = <?= json_encode($blood_inventory) ?>;
    const bloodBanks = <?= json_encode($blood_banks) ?>;
    const selectedBankId = <?= $selected_bank_id ?>;
  
    function openAddItemModal() {
      document.getElementById('modalTitle').textContent = 'Add New Item';
      document.getElementById('formAction').name = 'add_item';
      document.getElementById('itemForm').reset();
      document.getElementById('itemId').value = '';
      
      // Pre-select the current bank
      if (selectedBankId) {
        document.getElementById('bank_id').value = selectedBankId;
      }
      
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
      document.getElementById('bank_id').value = item.bank_id || '';
      document.getElementById('location').value = item.location || 'Central Storage';
      document.getElementById('itemModal').classList.add('active');
    }
    
    function openBloodInventoryModal(bloodType = null) {
      if (!selectedBankId) {
        alert('Please select a blood bank location first.');
        return;
      }
      
      const modal = document.getElementById('bloodModal');
      modal.classList.add('active');
      
      document.getElementById('bloodBankId').value = selectedBankId;
      
      if (bloodType) {
        // Pre-select the blood type
        selectBloodType(document.querySelector(`.blood-type-option[data-type="${bloodType}"]`));
      } else {
        // Clear any previous selection
        document.querySelectorAll('.blood-type-option').forEach(opt => {
          opt.classList.remove('selected');
        });
        document.getElementById('bloodType').value = '';
        document.getElementById('current_units').value = '';
        document.getElementById('bloodModalTitle').textContent = 'Manage Blood Inventory';
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
      updateBloodTypeDisplay();
    }
    
    function changeBloodBank(bankId) {
      document.getElementById('bloodBankId').value = bankId;
      // Redirect to update the blood inventory data
      window.location.href = `?bank=${bankId}`;
    }
    
    function closeModal(modalId) {
      document.getElementById(modalId).classList.remove('active');
    }
    
    function filterByCategory(categoryId) {
      const currentParams = new URLSearchParams(window.location.search);
      if (categoryId && categoryId !== '0') {
        currentParams.set('category', categoryId);
      } else {
        currentParams.delete('category');
      }
      window.location.href = `${window.location.pathname}?${currentParams.toString()}`;
    }
    
    function filterByBank(bankId) {
      const currentParams = new URLSearchParams(window.location.search);
      if (bankId && bankId !== '0') {
        currentParams.set('bank_filter', bankId);
      } else {
        currentParams.delete('bank_filter');
      }
      window.location.href = `${window.location.pathname}?${currentParams.toString()}`;
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
    
    // Form validation for blood inventory
    document.getElementById('bloodForm').addEventListener('submit', function(e) {
      const bloodType = document.getElementById('bloodType').value;
      const actionType = document.getElementById('action_type').value;
      const unitsCount = parseInt(document.getElementById('units_count').value);
      const currentUnits = parseInt(document.getElementById('current_units').value) || 0;
      const bankId = document.getElementById('bloodBankId').value;
      
      if (!bankId) {
        e.preventDefault();
        alert('Please select a blood bank location.');
        return false;
      }
      
      if (!bloodType) {
        e.preventDefault();
        alert('Please select a blood type.');
        return false;
      }
      
      if (!actionType) {
        e.preventDefault();
        alert('Please select an action type.');
        return false;
      }
      
      if (!unitsCount || unitsCount <= 0) {
        e.preventDefault();
        alert('Please enter a valid number of units.');
        return false;
      }
      
      // Check if removing more units than available
      if (actionType === 'remove' && unitsCount > currentUnits) {
        e.preventDefault();
        alert(`Cannot remove ${unitsCount} units. Only ${currentUnits} units available for ${bloodType}.`);
        return false;
      }
    });
    
    // Enhanced visual feedback for blood inventory management
    function updateBloodTypeDisplay() {
      const selectedType = document.getElementById('bloodType').value;
      if (selectedType) {
        document.getElementById('current_units').value = currentUnits;
        
        // Update visual feedback based on stock level
        const inputField = document.getElementById('current_units');
        inputField.className = '';
        if (currentUnits < 10) {
          inputField.style.borderColor = '#dc3545';
          inputField.style.backgroundColor = '#fff5f5';
        } else if (currentUnits > 50) {
          inputField.style.borderColor = '#28a745';
          inputField.style.backgroundColor = '#f8fff8';
        } else {
          inputField.style.borderColor = '#ffc107';
          inputField.style.backgroundColor = '#fffef5';
        }
      }
    }
    
    // Real-time validation for units input
    document.getElementById('units_count').addEventListener('input', function() {
      const actionType = document.getElementById('action_type').value;
      const unitsCount = parseInt(this.value);
      const currentUnits = parseInt(document.getElementById('current_units').value) || 0;
      
      if (actionType === 'remove' && unitsCount > currentUnits) {
        this.style.borderColor = '#dc3545';
        this.style.backgroundColor = '#fff5f5';
      } else {
        this.style.borderColor = '#e0e0e0';
        this.style.backgroundColor = 'white';
      }
    });
    
    // Update validation when action type changes
    document.getElementById('action_type').addEventListener('change', function() {
      const unitsInput = document.getElementById('units_count');
      unitsInput.dispatchEvent(new Event('input'));
    });
  </script>

  <style>
    /* Bank Selector Styles */
    .bank-selector {
      background: white;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .bank-selector-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid #f0f0f0;
    }
    
    .bank-selector-header h3 {
      margin: 0;
      color: var(--dark);
      font-size: 1.2rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .btn-manage-banks {
      background: linear-gradient(135deg, var(--blue) 0%, #0056b3 100%);
      color: white;
      padding: 0.6rem 1.2rem;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(0, 86, 179, 0.3);
    }
    
    .btn-manage-banks:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 86, 179, 0.4);
    }
    
    .bank-options {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 1rem;
    }
    
    .bank-option {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem;
      border: 2px solid #e0e0e0;
      border-radius: 10px;
      text-decoration: none;
      color: var(--dark);
      transition: all 0.3s ease;
      background: #f8f9fa;
    }
    
    .bank-option:hover {
      border-color: var(--prc-red);
      background: #fff5f5;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .bank-option.active {
      border-color: var(--prc-red);
      background: linear-gradient(135deg, #fff5f5 0%, #ffe6e6 100%);
      box-shadow: 0 4px 12px rgba(160, 0, 0, 0.2);
    }
    
    .bank-info {
      flex: 1;
    }
    
    .bank-name {
      font-weight: 600;
      font-size: 1rem;
      margin-bottom: 0.3rem;
      color: var(--dark);
    }
    
    .bank-address {
      font-size: 0.85rem;
      color: var(--gray);
      line-height: 1.3;
    }
    
    .bank-stats {
      text-align: right;
    }
    
    .blood-units {
      background: var(--prc-red);
      color: white;
      padding: 0.4rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
    }
    
    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 1rem;
    }
    
    /* Table Filters */
    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }
    
    .table-filters {
      display: flex;
      gap: 0.5rem;
    }
    
    .filter-select {
      padding: 0.5rem;
      border: 2px solid #e0e0e0;
      border-radius: 6px;
      font-size: 0.9rem;
      min-width: 150px;
    }
    
    .filter-select:focus {
      border-color: var(--prc-red);
      outline: none;
    }
    
    .filter-tag {
      background: #f0f0f0;
      padding: 0.3rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      margin-right: 0.5rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .filter-tag a {
      color: var(--prc-red);
      text-decoration: none;
    }
    
    /* Enhanced blood inventory styles */
    .activity-log {
      border-top: 1px solid #e9ecef;
      padding-top: 1.5rem;
    }
    
    .activity-log h3 {
      color: var(--dark);
      margin-bottom: 1rem;
      font-size: 1.1rem;
    }
    
    .log-container {
      max-height: 300px;
      overflow-y: auto;
    }
    
    .log-entry {
      display: flex;
      align-items: center;
      padding: 0.75rem;
      margin-bottom: 0.5rem;
      background: #f8f9fa;
      border-radius: 8px;
      border-left: 4px solid transparent;
    }
    
    .log-entry:hover {
      background: #e9ecef;
    }
    
    .log-icon {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 1rem;
      font-size: 0.8rem;
    }
    
    .log-icon.add {
      background: #d4edda;
      color: #155724;
    }
    
    .log-entry:has(.log-icon.add) {
      border-left-color: #28a745;
    }
    
    .log-icon.remove {
      background: #f8d7da;
      color: #721c24;
    }
    
    .log-entry:has(.log-icon.remove) {
      border-left-color: #dc3545;
    }
    
    .log-details {
      flex: 1;
    }
    
    .log-main {
      font-weight: 500;
      margin-bottom: 0.25rem;
    }
    
    .log-meta {
      font-size: 0.85rem;
      color: #6c757d;
    }
    
    .units {
      color: var(--prc-red);
      font-weight: 600;
    }
    
    .no-logs {
      text-align: center;
      color: #6c757d;
      font-style: italic;
      padding: 2rem;
    }
    
    /* Blood type selection enhancements */
    .blood-type-option {
      cursor: pointer;
      padding: 0.75rem 1rem;
      margin: 0.25rem;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      text-align: center;
      font-weight: 600;
      transition: all 0.3s ease;
      background: white;
    }
    
    .blood-type-option:hover {
      border-color: var(--prc-red);
      background: #fff5f5;
    }
    
    .blood-type-option.selected {
      border-color: var(--prc-red);
      background: var(--prc-red);
      color: white;
    }
    
    .blood-type-options {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 0.5rem;
      margin-bottom: 1.5rem;
    }
    
    /* Form validation styles */
    .form-group input.invalid {
      border-color: #dc3545 !important;
      background-color: #fff5f5 !important;
    }
    
    .form-group input.valid {
      border-color: #28a745 !important;
      background-color: #f8fff8 !important;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      .bank-options {
        grid-template-columns: 1fr;
      }
      
      .action-buttons {
        width: 100%;
      }
      
      .action-buttons .btn-create,
      .action-buttons .btn-create-blood {
        flex: 1;
        justify-content: center;
      }
      
      .blood-type-options {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .stats-overview {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .table-header {
        flex-direction: column;
        align-items: stretch;
      }
      
      .table-filters {
        width: 100%;
      }
      
      .filter-select {
        flex: 1;
        min-width: auto;
      }
    }
    
    @media (max-width: 576px) {
      .bank-selector-header {
        flex-direction: column;
        align-items: stretch;
        gap: 1rem;
      }
      
      .btn-manage-banks {
        justify-content: center;
      }
      
      .blood-type-options {
        grid-template-columns: 1fr;
      }
      
      .stats-overview {
        grid-template-columns: 1fr;
      }
      
      .table-filters {
        flex-direction: column;
      }
    }
  </style>
</body>
</html>