<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = get_user_role();
$user_email = $_SESSION['email'] ?? '';

// Get active tab from query parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'inventory';

// Get or create admin branch
function getOrCreateAdminBranch($pdo, $user_id) {
    // Check if admin_branches table exists, if not create it
    $stmt = $pdo->query("SHOW TABLES LIKE 'admin_branches'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `admin_branches` (
              `branch_id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `branch_name` varchar(100) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`branch_id`),
              UNIQUE KEY `user_id` (`user_id`),
              FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
    
    $stmt = $pdo->prepare("SELECT branch_name FROM admin_branches WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    
    if (!$result) {
        // Create branch name based on admin role or email
        $stmt = $pdo->prepare("SELECT email, admin_role FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $branch_name = $user['admin_role'] ? ucfirst($user['admin_role']) . ' Branch' : explode('@', $user['email'])[0] . '_branch';
        
        $stmt = $pdo->prepare("INSERT INTO admin_branches (user_id, branch_name, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $branch_name]);
        
        return $branch_name;
    }
    
    return $result['branch_name'];
}

$admin_branch = getOrCreateAdminBranch($pdo, $user_id);

// Create vehicles table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicles` (
      `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
      `vehicle_type` enum('ambulance','van','truck','car','motorcycle') NOT NULL,
      `plate_number` varchar(20) NOT NULL,
      `model` varchar(100) NOT NULL,
      `year` int(4) NOT NULL,
      `status` enum('operational','maintenance','out_of_service') NOT NULL DEFAULT 'operational',
      `fuel_type` enum('gasoline','diesel','hybrid','electric') NOT NULL,
      `current_mileage` int(11) DEFAULT 0,
      `admin_id` int(11) NOT NULL,
      `branch_name` varchar(100) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`vehicle_id`),
      UNIQUE KEY `plate_number_admin` (`plate_number`, `admin_id`),
      KEY `admin_id` (`admin_id`),
      KEY `status` (`status`),
      FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Create vehicle_maintenance table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicle_maintenance` (
      `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
      `vehicle_id` int(11) NOT NULL,
      `maintenance_type` enum('routine','oil_change','tire_rotation','brake_service','engine_repair','transmission','electrical','bodywork','inspection','other') NOT NULL,
      `description` text DEFAULT NULL,
      `cost` decimal(10,2) DEFAULT 0.00,
      `maintenance_date` date NOT NULL,
      `next_maintenance_date` date DEFAULT NULL,
      `service_provider` varchar(100) DEFAULT NULL,
      `mileage_at_service` int(11) DEFAULT NULL,
      `admin_id` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`maintenance_id`),
      KEY `vehicle_id` (`vehicle_id`),
      KEY `admin_id` (`admin_id`),
      FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`vehicle_id`) ON DELETE CASCADE,
      FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Handle Category Management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $category_name = trim($_POST['category_name']);
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    
    if ($category_name) {
        try {
            if ($category_id) {
                $stmt = $pdo->prepare("UPDATE categories SET category_name = ? WHERE category_id = ?");
                $stmt->execute([$category_name, $category_id]);
                $successMessage = "Category updated successfully!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)");
                $stmt->execute([$category_name]);
                $successMessage = "Category added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving category: " . $e->getMessage();
        }
    }
}

// Handle Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $itemCount = $stmt->fetchColumn();
        
        if ($itemCount > 0) {
            $errorMessage = "Cannot delete category with existing items.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $successMessage = "Category deleted successfully!";
        }
    } catch (PDOException $e) {
        $errorMessage = "Error deleting category: " . $e->getMessage();
    }
}

// Handle Add/Edit Inventory Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $quantity = (int)$_POST['quantity'];
    $bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
    $location = trim($_POST['location']);
    $expiry_date = $_POST['expiry_date'];
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    
    if ($item_name && $category_id) {
        try {
            if ($item_id) {
                $stmt = $pdo->prepare("
                    UPDATE inventory_items 
                    SET item_name = ?, category_id = ?, quantity = ?, bank_id = ?, 
                        location = ?, expiry_date = ?
                    WHERE item_id = ?
                ");
                $stmt->execute([$item_name, $category_id, $quantity, $bank_id, 
                               $location, $expiry_date, $item_id]);
                $successMessage = "Item updated successfully!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_items 
                    (item_name, category_id, quantity, bank_id, location, expiry_date, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$item_name, $category_id, $quantity, $bank_id, 
                               $location, $expiry_date]);
                $successMessage = "Item added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving item: " . $e->getMessage();
        }
    }
}

// Handle Delete Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE item_id = ?");
        $stmt->execute([$item_id]);
        $successMessage = "Item deleted successfully!";
    } catch (PDOException $e) {
        $errorMessage = "Error deleting item: " . $e->getMessage();
    }
}

// Handle Blood Inventory Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_blood'])) {
    $blood_type = $_POST['blood_type'];
    $action_type = $_POST['action_type'];
    $units = (int)$_POST['units_count'];
    $bank_id = (int)$_POST['bank_id'];
    $location = trim($_POST['location'] ?? 'Main Storage');
    $notes = trim($_POST['blood_note'] ?? '');
    
    if ($blood_type && $action_type && $units > 0 && $bank_id) {
        try {
            $stmt = $pdo->prepare("SELECT units_available, inventory_id FROM blood_inventory WHERE blood_type = ? AND bank_id = ?");
            $stmt->execute([$blood_type, $bank_id]);
            $result = $stmt->fetch();
            
            if ($result) {
                $current_units = $result['units_available'];
                $inventory_id = $result['inventory_id'];
                $new_units = ($action_type === 'add') ? $current_units + $units : $current_units - $units;
                
                if ($action_type === 'remove' && $new_units < 0) {
                    $errorMessage = "Not enough units available.";
                } else {
                    $stmt = $pdo->prepare("UPDATE blood_inventory SET units_available = ?, location = ? WHERE inventory_id = ?");
                    $stmt->execute([$new_units, $location, $inventory_id]);
                    $successMessage = "Blood inventory updated successfully!";
                }
            } else {
                if ($action_type === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO blood_inventory (bank_id, blood_type, units_available, location) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$bank_id, $blood_type, $units, $location]);
                    $successMessage = "Blood inventory added successfully!";
                } else {
                    $errorMessage = "Cannot remove units from non-existent blood type.";
                }
            }
        } catch (PDOException $e) {
            $errorMessage = "Error updating blood inventory: " . $e->getMessage();
        }
    }
}

// Handle Vehicle Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vehicle'])) {
    $vehicle_type = trim($_POST['vehicle_type']);
    $plate_number = trim($_POST['plate_number']);
    $model = trim($_POST['model']);
    $year = (int)$_POST['year'];
    $status = $_POST['status'];
    $fuel_type = $_POST['fuel_type'];
    $current_mileage = (int)$_POST['current_mileage'];
    $vehicle_id = isset($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : 0;
    
    if ($vehicle_type && $plate_number) {
        try {
            if ($vehicle_id) {
                $stmt = $pdo->prepare("
                    UPDATE vehicles 
                    SET vehicle_type = ?, plate_number = ?, model = ?, year = ?, 
                        status = ?, fuel_type = ?, current_mileage = ?
                    WHERE vehicle_id = ? AND admin_id = ?
                ");
                $stmt->execute([$vehicle_type, $plate_number, $model, $year, 
                               $status, $fuel_type, $current_mileage, $vehicle_id, $user_id]);
                $successMessage = "Vehicle updated successfully!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles 
                    (vehicle_type, plate_number, model, year, status, fuel_type, 
                     current_mileage, admin_id, branch_name, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$vehicle_type, $plate_number, $model, $year, 
                               $status, $fuel_type, $current_mileage, $user_id, $admin_branch]);
                $successMessage = "Vehicle added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving vehicle: " . $e->getMessage();
        }
    }
}

// Handle Delete Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vehicle'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ? AND admin_id = ?");
        $stmt->execute([$vehicle_id, $user_id]);
        $successMessage = "Vehicle deleted successfully!";
    } catch (PDOException $e) {
        $errorMessage = "Error deleting vehicle: " . $e->getMessage();
    }
}

// Handle Add Maintenance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $maintenance_type = trim($_POST['maintenance_type']);
    $description = trim($_POST['description']);
    $cost = (float)$_POST['cost'];
    $maintenance_date = $_POST['maintenance_date'];
    $next_maintenance = $_POST['next_maintenance'] ?: null;
    $service_provider = trim($_POST['service_provider']);
    $mileage_at_service = (int)$_POST['mileage_at_service'];
    
    if ($vehicle_id && $maintenance_type && $maintenance_date) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vehicle_maintenance 
                (vehicle_id, maintenance_type, description, cost, maintenance_date, 
                 next_maintenance_date, service_provider, mileage_at_service, admin_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$vehicle_id, $maintenance_type, $description, $cost, 
                           $maintenance_date, $next_maintenance, $service_provider, 
                           $mileage_at_service, $user_id]);
            
            if ($mileage_at_service > 0) {
                $stmt = $pdo->prepare("
                    UPDATE vehicles SET current_mileage = ? 
                    WHERE vehicle_id = ? AND admin_id = ? AND current_mileage < ?
                ");
                $stmt->execute([$mileage_at_service, $vehicle_id, $user_id, $mileage_at_service]);
            }
            
            $successMessage = "Maintenance record added successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error adding maintenance: " . $e->getMessage();
        }
    }
}

// Get data based on active tab
$categories = [];
$items = [];
$blood_banks = [];
$blood_inventory = [];
$vehicles = [];
$maintenance_records = [];

// Get categories
try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Get blood banks
try {
    $blood_banks = $pdo->query("SELECT * FROM blood_banks ORDER BY branch_name")->fetchAll();
} catch (PDOException $e) {
    $blood_banks = [];
}

// Search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$bank_filter = isset($_GET['bank']) ? (int)$_GET['bank'] : 0;
$selected_bank_id = $bank_filter ?: (!empty($blood_banks) ? $blood_banks[0]['bank_id'] : 0);

// Get inventory items
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

    // Get stats
    $total_items = $pdo->query("SELECT COUNT(*) FROM inventory_items")->fetchColumn();
    $expiring_soon = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $expired_items = $pdo->query("SELECT COUNT(*) FROM inventory_items WHERE expiry_date < CURDATE()")->fetchColumn();
    $total_blood_units = $pdo->query("SELECT SUM(units_available) FROM blood_inventory")->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $items = [];
    $total_items = 0;
    $expiring_soon = 0;
    $expired_items = 0;
    $total_blood_units = 0;
}

// Get blood inventory for selected bank
if ($selected_bank_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT blood_type, units_available, location 
            FROM blood_inventory 
            WHERE bank_id = ?
            ORDER BY blood_type
        ");
        $stmt->execute([$selected_bank_id]);
        $blood_results = $stmt->fetchAll();
        
        $blood_inventory = [];
        foreach ($blood_results as $row) {
            $blood_inventory[$row['blood_type']] = $row['units_available'];
        }
        
        $all_blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        foreach ($all_blood_types as $type) {
            if (!isset($blood_inventory[$type])) {
                $blood_inventory[$type] = 0;
            }
        }
    } catch (PDOException $e) {
        $blood_inventory = array_fill_keys(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 0);
    }
} else {
    $blood_inventory = array_fill_keys(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 0);
}

// Get vehicles
try {
    $query = "SELECT * FROM vehicles WHERE admin_id = ?";
    $params = [$user_id];
    
    if ($search && $activeTab === 'vehicles') {
        $query .= " AND (plate_number LIKE ? OR model LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $query .= " ORDER BY vehicle_type, plate_number";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
    
    // Get vehicle stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
            SUM(CASE WHEN status = 'out_of_service' THEN 1 ELSE 0 END) as out_of_service
        FROM vehicles WHERE admin_id = ?
    ");
    $stmt->execute([$user_id]);
    $vehicle_stats = $stmt->fetch();
} catch (PDOException $e) {
    $vehicles = [];
    $vehicle_stats = ['total' => 0, 'operational' => 0, 'maintenance' => 0, 'out_of_service' => 0];
}

// Get selected vehicle's maintenance history
$selected_vehicle_id = isset($_GET['vehicle']) ? (int)$_GET['vehicle'] : 0;
if ($selected_vehicle_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM vehicle_maintenance 
            WHERE vehicle_id = ? AND admin_id = ?
            ORDER BY maintenance_date DESC
        ");
        $stmt->execute([$selected_vehicle_id, $user_id]);
        $maintenance_records = $stmt->fetchAll();
    } catch (PDOException $e) {
        $maintenance_records = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory & Fleet Management - <?= htmlspecialchars($admin_branch) ?> - PRC Admin</title>
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
  
  <div class="main-container">
    <div class="page-header">
      <div class="header-content">
        <h1><i class="fas fa-warehouse"></i> Inventory & Fleet Management</h1>
        <p>Comprehensive management system for <?= htmlspecialchars($admin_branch) ?></p>
      </div>
      <div class="branch-indicator">
        <i class="fas fa-building"></i>
        <span><?= htmlspecialchars($admin_branch) ?></span>
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

    <!-- Tab Navigation -->
    <div class="tab-navigation">
      <a href="?tab=inventory" class="tab-link <?= $activeTab === 'inventory' ? 'active' : '' ?>">
        <i class="fas fa-boxes"></i>
        <span>Medical Inventory</span>
      </a>
      <a href="?tab=blood" class="tab-link <?= $activeTab === 'blood' ? 'active' : '' ?>">
        <i class="fas fa-tint"></i>
        <span>Blood Bank</span>
      </a>
      <a href="?tab=vehicles" class="tab-link <?= $activeTab === 'vehicles' ? 'active' : '' ?>">
        <i class="fas fa-truck"></i>
        <span>Fleet Management</span>
      </a>
    </div>

    <!-- Medical Inventory Tab -->
    <div class="tab-content <?= $activeTab === 'inventory' ? 'active' : '' ?>" id="inventory-tab">
      <!-- Stats Overview -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-box"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $total_items ?></div>
            <div class="stat-label">Total Items</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $expiring_soon ?></div>
            <div class="stat-label">Expiring Soon</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
            <i class="fas fa-skull-crossbones"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $expired_items ?></div>
            <div class="stat-label">Expired</div>
          </div>
        </div>
      </div>

      <!-- Action Bar -->
      <div class="action-bar">
        <div class="search-filters">
          <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="inventory">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" name="search" placeholder="Search items..." value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <select name="category" class="filter-select" onchange="this.form.submit()">
              <option value="">All Categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            
            <select name="bank" class="filter-select" onchange="this.form.submit()">
              <option value="">All Locations</option>
              <?php foreach ($blood_banks as $bank): ?>
                <option value="<?= $bank['bank_id'] ?>" <?= $bank_filter == $bank['bank_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($bank['branch_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        
        <div class="action-buttons">
          <button class="btn-primary" onclick="openAddItemModal()">
            <i class="fas fa-plus"></i> Add Item
          </button>
          <button class="btn-secondary" onclick="toggleCategoryManager()">
            <i class="fas fa-tags"></i> Categories
          </button>
        </div>
      </div>

      <!-- Category Manager (Hidden by default) -->
      <div class="category-manager" id="categoryManager" style="display: none;">
        <div class="manager-header">
          <h3><i class="fas fa-tags"></i> Manage Categories</h3>
          <button class="close-btn" onclick="toggleCategoryManager()">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <form method="POST" class="category-form">
          <input type="hidden" name="save_category" value="1">
          <input type="hidden" name="category_id" id="categoryId">
          <div class="form-inline">
            <input type="text" name="category_name" id="categoryName" placeholder="Category name" required>
            <button type="submit" class="btn-primary">
              <i class="fas fa-save"></i> Save
            </button>
            <button type="button" class="btn-secondary" onclick="resetCategoryForm()">
              <i class="fas fa-times"></i> Cancel
            </button>
          </div>
        </form>
        
        <div class="categories-list">
          <?php foreach ($categories as $category): ?>
            <?php
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
            $stmt->execute([$category['category_id']]);
            $itemCount = $stmt->fetchColumn();
            ?>
            <div class="category-item">
              <span class="category-name"><?= htmlspecialchars($category['category_name']) ?></span>
              <span class="item-count">(<?= $itemCount ?> items)</span>
              <div class="category-actions">
                <button onclick="editCategory(<?= $category['category_id'] ?>, '<?= htmlspecialchars($category['category_name'], ENT_QUOTES) ?>')" class="btn-sm btn-edit">
                  <i class="fas fa-edit"></i>
                </button>
                <?php if ($itemCount == 0): ?>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this category?')">
                    <input type="hidden" name="delete_category" value="1">
                    <input type="hidden" name="category_id" value="<?= $category['category_id'] ?>">
                    <button type="submit" class="btn-sm btn-delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Inventory Table -->
      <div class="table-container">
        <?php if (empty($items)): ?>
          <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h3>No Inventory Items Found</h3>
            <p>Add your first inventory item to get started</p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Item Name</th>
                <th>Category</th>
                <th>Quantity</th>
                <th>Location</th>
                <th>Branch</th>
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
                  <td><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></td>
                  <td><?= $item['quantity'] ?></td>
                  <td><?= htmlspecialchars($item['location']) ?></td>
                  <td><?= htmlspecialchars($item['branch_name'] ?? 'Central') ?></td>
                  <td><?= date('M d, Y', $expiryDate) ?></td>
                  <td>
                    <span class="status-badge <?= $statusClass ?>">
                      <?= $statusText ?>
                    </span>
                  </td>
                  <td class="actions">
                    <button onclick='openEditItemModal(<?= json_encode($item) ?>)' class="btn-sm btn-edit">
                      <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this item?')">
                      <input type="hidden" name="delete_item" value="1">
                      <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                      <button type="submit" class="btn-sm btn-delete">
                        <i class="fas fa-trash"></i>
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

    <!-- Blood Bank Tab -->
    <div class="tab-content <?= $activeTab === 'blood' ? 'active' : '' ?>" id="blood-tab">
      <!-- Blood Bank Stats -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #dc143c 0%, #b91c1c 100%);">
            <i class="fas fa-tint"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $total_blood_units ?></div>
            <div class="stat-label">Total Blood Units</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
            <i class="fas fa-hospital"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= count($blood_banks) ?></div>
            <div class="stat-label">Blood Banks</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
            <i class="fas fa-vial"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number">8</div>
            <div class="stat-label">Blood Types</div>
          </div>
        </div>
      </div>

      <!-- Blood Bank Selector -->
      <div class="bank-selector">
        <h3><i class="fas fa-hospital"></i> Select Blood Bank</h3>
        <div class="bank-grid">
          <?php foreach ($blood_banks as $bank): ?>
            <a href="?tab=blood&bank=<?= $bank['bank_id'] ?>" 
               class="bank-card <?= $bank['bank_id'] == $selected_bank_id ? 'active' : '' ?>">
              <div class="bank-name"><?= htmlspecialchars($bank['branch_name']) ?></div>
              <div class="bank-address"><?= htmlspecialchars($bank['address']) ?></div>
              <?php
              $stmt = $pdo->prepare("SELECT SUM(units_available) FROM blood_inventory WHERE bank_id = ?");
              $stmt->execute([$bank['bank_id']]);
              $bank_units = $stmt->fetchColumn() ?: 0;
              ?>
              <div class="bank-units"><?= $bank_units ?> units</div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($selected_bank_id): ?>
        <!-- Blood Inventory Grid -->
        <div class="blood-inventory-section">
          <div class="section-header">
            <h3><i class="fas fa-tint"></i> Blood Inventory</h3>
            <button class="btn-primary" onclick="openBloodModal()">
              <i class="fas fa-plus"></i> Update Blood Stock
            </button>
          </div>
          
          <div class="blood-grid">
            <?php foreach ($blood_inventory as $type => $units): 
              $status = 'normal';
              if ($units < 10) $status = 'low';
              elseif ($units > 50) $status = 'high';
            ?>
              <div class="blood-card <?= $status ?>">
                <div class="blood-type"><?= $type ?></div>
                <div class="blood-units"><?= $units ?></div>
                <div class="blood-label">units</div>
                <div class="blood-status">
                  <?= $status === 'low' ? 'Low Stock' : ($status === 'high' ? 'High Stock' : 'Normal') ?>
                </div>
                <button onclick="openBloodModal('<?= $type ?>')" class="btn-sm btn-primary">
                  <i class="fas fa-edit"></i> Update
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-hospital"></i>
          <h3>No Blood Bank Selected</h3>
          <p>Please select a blood bank from above to manage blood inventory</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Vehicles Tab -->
    <div class="tab-content <?= $activeTab === 'vehicles' ? 'active' : '' ?>" id="vehicles-tab">
      <!-- Vehicle Stats -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $vehicle_stats['total'] ?></div>
            <div class="stat-label">Total Vehicles</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
            <i class="fas fa-check-circle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $vehicle_stats['operational'] ?></div>
            <div class="stat-label">Operational</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
            <i class="fas fa-tools"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $vehicle_stats['maintenance'] ?></div>
            <div class="stat-label">In Maintenance</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $vehicle_stats['out_of_service'] ?></div>
            <div class="stat-label">Out of Service</div>
          </div>
        </div>
      </div>

      <!-- Vehicle Action Bar -->
      <div class="action-bar">
        <div class="search-filters">
          <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="vehicles">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" name="search" placeholder="Search vehicles..." value="<?= htmlspecialchars($search) ?>">
            </div>
          </form>
        </div>
        
        <div class="action-buttons">
          <button class="btn-primary" onclick="openVehicleModal()">
            <i class="fas fa-plus"></i> Add Vehicle
          </button>
          <button class="btn-secondary" onclick="openMaintenanceModal()">
            <i class="fas fa-wrench"></i> Add Maintenance
          </button>
        </div>
      </div>

      <!-- Vehicles Grid -->
      <div class="vehicles-grid">
        <?php if (empty($vehicles)): ?>
          <div class="empty-state">
            <i class="fas fa-truck"></i>
            <h3>No Vehicles Found</h3>
            <p>Add your first vehicle to get started</p>
          </div>
        <?php else: ?>
          <?php foreach ($vehicles as $vehicle): ?>
            <div class="vehicle-card <?= $vehicle['status'] ?>">
              <div class="vehicle-header">
                <div class="vehicle-type">
                  <i class="fas fa-<?= $vehicle['vehicle_type'] === 'ambulance' ? 'ambulance' : 'truck' ?>"></i>
                  <?= ucfirst($vehicle['vehicle_type']) ?>
                </div>
                <div class="vehicle-status">
                  <?php if ($vehicle['status'] === 'operational'): ?>
                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                  <?php elseif ($vehicle['status'] === 'maintenance'): ?>
                    <i class="fas fa-tools" style="color: #ffc107;"></i>
                  <?php else: ?>
                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                  <?php endif; ?>
                </div>
              </div>
              
              <div class="vehicle-body">
                <h4><?= htmlspecialchars($vehicle['plate_number']) ?></h4>
                <p class="vehicle-model"><?= htmlspecialchars($vehicle['model']) ?> (<?= $vehicle['year'] ?>)</p>
                
                <div class="vehicle-info">
                  <div class="info-item">
                    <i class="fas fa-gas-pump"></i>
                    <span><?= ucfirst($vehicle['fuel_type']) ?></span>
                  </div>
                  <div class="info-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span><?= number_format($vehicle['current_mileage']) ?> km</span>
                  </div>
                </div>
                
                <div class="vehicle-actions">
                  <button onclick='openEditVehicleModal(<?= json_encode($vehicle) ?>)' class="btn-sm btn-edit">
                    <i class="fas fa-edit"></i>
                  </button>
                  <a href="?tab=vehicles&vehicle=<?= $vehicle['vehicle_id'] ?>" class="btn-sm btn-info">
                    <i class="fas fa-history"></i>
                  </a>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this vehicle?')">
                    <input type="hidden" name="delete_vehicle" value="1">
                    <input type="hidden" name="vehicle_id" value="<?= $vehicle['vehicle_id'] ?>">
                    <button type="submit" class="btn-sm btn-delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Maintenance History -->
      <?php if ($selected_vehicle_id && !empty($maintenance_records)): ?>
        <div class="maintenance-section">
          <h3><i class="fas fa-history"></i> Maintenance History</h3>
          <table class="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th>Provider</th>
                <th>Mileage</th>
                <th>Cost</th>
                <th>Next Due</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($maintenance_records as $record): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($record['maintenance_date'])) ?></td>
                  <td><?= ucfirst(str_replace('_', ' ', $record['maintenance_type'])) ?></td>
                  <td><?= htmlspecialchars($record['description']) ?></td>
                  <td><?= htmlspecialchars($record['service_provider']) ?></td>
                  <td><?= number_format($record['mileage_at_service']) ?> km</td>
                  <td>₱<?= number_format($record['cost'], 2) ?></td>
                  <td>
                    <?= $record['next_maintenance_date'] ? 
                        date('M d, Y', strtotime($record['next_maintenance_date'])) : 
                        '-' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modals -->
  
  <!-- Add/Edit Item Modal -->
  <div class="modal" id="itemModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="itemModalTitle">Add New Item</h2>
        <button class="close-btn" onclick="closeModal('itemModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST">
        <input type="hidden" name="save_item" value="1">
        <input type="hidden" name="item_id" id="itemId">
        
        <div class="form-group">
          <label>Item Name *</label>
          <input type="text" name="item_name" id="itemName" required>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Category *</label>
            <select name="category_id" id="itemCategory" required>
              <option value="">Select Category</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label>Quantity *</label>
            <input type="number" name="quantity" id="itemQuantity" min="0" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Blood Bank Location</label>
            <select name="bank_id" id="itemBank">
              <option value="">Central Storage</option>
              <?php foreach ($blood_banks as $bank): ?>
                <option value="<?= $bank['bank_id'] ?>"><?= htmlspecialchars($bank['branch_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label>Storage Location *</label>
            <input type="text" name="location" id="itemLocation" placeholder="e.g., Room A, Shelf 1" required>
          </div>
        </div>
        
        <div class="form-group">
          <label>Expiry Date *</label>
          <input type="date" name="expiry_date" id="itemExpiry" required>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Save Item
          </button>
          <button type="button" class="btn-secondary" onclick="closeModal('itemModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Blood Inventory Modal -->
  <div class="modal" id="bloodModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Update Blood Inventory</h2>
        <button class="close-btn" onclick="closeModal('bloodModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST">
        <input type="hidden" name="update_blood" value="1">
        <input type="hidden" name="blood_type" id="bloodType">
        <input type="hidden" name="bank_id" value="<?= $selected_bank_id ?>">
        
        <div class="blood-type-selector">
          <label>Select Blood Type</label>
          <div class="blood-type-grid">
            <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $type): ?>
              <div class="blood-type-option" data-type="<?= $type ?>" onclick="selectBloodType('<?= $type ?>')">
                <?= $type ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Action Type *</label>
            <select name="action_type" required>
              <option value="">Select Action</option>
              <option value="add">Add Units</option>
              <option value="remove">Remove Units</option>
            </select>
          </div>
          
          <div class="form-group">
            <label>Number of Units *</label>
            <input type="number" name="units_count" min="1" required>
          </div>
        </div>
        
        <div class="form-group">
          <label>Storage Location</label>
          <input type="text" name="location" placeholder="e.g., Main Storage, Refrigerator A" value="Main Storage">
        </div>
        
        <div class="form-group">
          <label>Notes</label>
          <input type="text" name="blood_note" placeholder="Optional notes">
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Update Blood Stock
          </button>
          <button type="button" class="btn-secondary" onclick="closeModal('bloodModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Vehicle Modal -->
  <div class="modal" id="vehicleModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="vehicleModalTitle">Add New Vehicle</h2>
        <button class="close-btn" onclick="closeModal('vehicleModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST">
        <input type="hidden" name="save_vehicle" value="1">
        <input type="hidden" name="vehicle_id" id="vehicleId">
        
        <div class="form-row">
          <div class="form-group">
            <label>Vehicle Type *</label>
            <select name="vehicle_type" id="vehicleType" required>
              <option value="ambulance">Ambulance</option>
              <option value="van">Van</option>
              <option value="truck">Truck</option>
              <option value="car">Car</option>
              <option value="motorcycle">Motorcycle</option>
            </select>
          </div>
          
          <div class="form-group">
            <label>Plate Number *</label>
            <input type="text" name="plate_number" id="plateNumber" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Model *</label>
            <input type="text" name="model" id="vehicleModel" required>
          </div>
          
          <div class="form-group">
            <label>Year *</label>
            <input type="number" name="year" id="vehicleYear" min="1900" max="<?= date('Y') + 1 ?>" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Status *</label>
            <select name="status" id="vehicleStatus" required>
              <option value="operational">Operational</option>
              <option value="maintenance">In Maintenance</option>
              <option value="out_of_service">Out of Service</option>
            </select>
          </div>
          
          <div class="form-group">
            <label>Fuel Type *</label>
            <select name="fuel_type" id="fuelType" required>
              <option value="gasoline">Gasoline</option>
              <option value="diesel">Diesel</option>
              <option value="hybrid">Hybrid</option>
              <option value="electric">Electric</option>
            </select>
          </div>
        </div>
        
        <div class="form-group">
          <label>Current Mileage (km)</label>
          <input type="number" name="current_mileage" id="currentMileage" min="0">
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Save Vehicle
          </button>
          <button type="button" class="btn-secondary" onclick="closeModal('vehicleModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Maintenance Modal -->
  <div class="modal" id="maintenanceModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Add Maintenance Record</h2>
        <button class="close-btn" onclick="closeModal('maintenanceModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST">
        <input type="hidden" name="add_maintenance" value="1">
        
        <div class="form-group">
          <label>Vehicle *</label>
          <select name="vehicle_id" required>
            <option value="">Select Vehicle</option>
            <?php foreach ($vehicles as $vehicle): ?>
              <option value="<?= $vehicle['vehicle_id'] ?>">
                <?= htmlspecialchars($vehicle['vehicle_type']) ?> - 
                <?= htmlspecialchars($vehicle['plate_number']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Maintenance Type *</label>
            <select name="maintenance_type" required>
              <option value="routine">Routine Service</option>
              <option value="oil_change">Oil Change</option>
              <option value="tire_rotation">Tire Rotation</option>
              <option value="brake_service">Brake Service</option>
              <option value="engine_repair">Engine Repair</option>
              <option value="transmission">Transmission Service</option>
              <option value="electrical">Electrical Repair</option>
              <option value="bodywork">Bodywork</option>
              <option value="inspection">Inspection</option>
              <option value="other">Other</option>
            </select>
          </div>
          
          <div class="form-group">
            <label>Maintenance Date *</label>
            <input type="date" name="maintenance_date" required max="<?= date('Y-m-d') ?>">
          </div>
        </div>
        
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="3"></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Service Provider</label>
            <input type="text" name="service_provider">
          </div>
          
          <div class="form-group">
            <label>Cost (₱)</label>
            <input type="number" name="cost" min="0" step="0.01">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Mileage at Service</label>
            <input type="number" name="mileage_at_service" min="0">
          </div>
          
          <div class="form-group">
            <label>Next Maintenance Date</label>
            <input type="date" name="next_maintenance" min="<?= date('Y-m-d') ?>">
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Save Record
          </button>
          <button type="button" class="btn-secondary" onclick="closeModal('maintenanceModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script>// Unified Inventory & Fleet Management JavaScript

// Toggle Category Manager
function toggleCategoryManager() {
    const manager = document.getElementById('categoryManager');
    if (manager) {
        manager.style.display = manager.style.display === 'none' ? 'block' : 'none';
    }
}

// Category Management
function editCategory(id, name) {
    document.getElementById('categoryId').value = id;
    document.getElementById('categoryName').value = name;
    
    // Show category manager if hidden
    const manager = document.getElementById('categoryManager');
    if (manager && manager.style.display === 'none') {
        manager.style.display = 'block';
    }
    
    // Focus on input
    document.getElementById('categoryName').focus();
}

function resetCategoryForm() {
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryName').value = '';
}

// Item Management
function openAddItemModal() {
    document.getElementById('itemModalTitle').textContent = 'Add New Item';
    document.getElementById('itemId').value = '';
    document.getElementById('itemName').value = '';
    document.getElementById('itemCategory').value = '';
    document.getElementById('itemQuantity').value = '';
    document.getElementById('itemBank').value = '';
    document.getElementById('itemLocation').value = '';
    document.getElementById('itemExpiry').value = '';
    
    // Set default expiry date to 6 months from now
    const futureDate = new Date();
    futureDate.setMonth(futureDate.getMonth() + 6);
    document.getElementById('itemExpiry').value = futureDate.toISOString().split('T')[0];
    
    openModal('itemModal');
}

function openEditItemModal(item) {
    document.getElementById('itemModalTitle').textContent = 'Edit Item';
    document.getElementById('itemId').value = item.item_id;
    document.getElementById('itemName').value = item.item_name;
    document.getElementById('itemCategory').value = item.category_id || '';
    document.getElementById('itemQuantity').value = item.quantity;
    document.getElementById('itemBank').value = item.bank_id || '';
    document.getElementById('itemLocation').value = item.location || '';
    document.getElementById('itemExpiry').value = item.expiry_date;
    
    openModal('itemModal');
}

// Blood Inventory Management
let selectedBloodType = null;

function openBloodModal(bloodType = null) {
    selectedBloodType = bloodType;
    
    // Clear previous selection
    document.querySelectorAll('.blood-type-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    if (bloodType) {
        document.getElementById('bloodType').value = bloodType;
        // Select the blood type option
        document.querySelector(`.blood-type-option[data-type="${bloodType}"]`)?.classList.add('selected');
    } else {
        document.getElementById('bloodType').value = '';
    }
    
    openModal('bloodModal');
}

function selectBloodType(type) {
    selectedBloodType = type;
    document.getElementById('bloodType').value = type;
    
    // Update visual selection
    document.querySelectorAll('.blood-type-option').forEach(option => {
        option.classList.remove('selected');
    });
    document.querySelector(`.blood-type-option[data-type="${type}"]`)?.classList.add('selected');
}

// Vehicle Management
function openVehicleModal() {
    document.getElementById('vehicleModalTitle').textContent = 'Add New Vehicle';
    document.getElementById('vehicleId').value = '';
    document.getElementById('vehicleType').value = 'ambulance';
    document.getElementById('plateNumber').value = '';
    document.getElementById('vehicleModel').value = '';
    document.getElementById('vehicleYear').value = '';
    document.getElementById('vehicleStatus').value = 'operational';
    document.getElementById('fuelType').value = 'gasoline';
    document.getElementById('currentMileage').value = '';
    
    openModal('vehicleModal');
}

function openEditVehicleModal(vehicle) {
    document.getElementById('vehicleModalTitle').textContent = 'Edit Vehicle';
    document.getElementById('vehicleId').value = vehicle.vehicle_id;
    document.getElementById('vehicleType').value = vehicle.vehicle_type;
    document.getElementById('plateNumber').value = vehicle.plate_number;
    document.getElementById('vehicleModel').value = vehicle.model;
    document.getElementById('vehicleYear').value = vehicle.year;
    document.getElementById('vehicleStatus').value = vehicle.status;
    document.getElementById('fuelType').value = vehicle.fuel_type;
    document.getElementById('currentMileage').value = vehicle.current_mileage;
    
    openModal('vehicleModal');
}

function openMaintenanceModal() {
    openModal('maintenanceModal');
}

// Modal Management
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
    }
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    // Modal click outside to close
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                setTimeout(() => {
                    this.style.display = 'none';
                }, 300);
            }
        });
    });

    // Blood type option click handlers
    document.querySelectorAll('.blood-type-option').forEach(option => {
        option.addEventListener('click', function() {
            const type = this.getAttribute('data-type');
            selectBloodType(type);
        });
    });

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                alert.remove();
            }, 300);
        }, 5000);
    });

    // Tab persistence based on URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'inventory';
    
    // Ensure the correct tab is shown
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.querySelectorAll('.tab-link').forEach(link => {
        link.classList.remove('active');
    });
    
    const activeContent = document.getElementById(activeTab + '-tab');
    if (activeContent) {
        activeContent.classList.add('active');
    }
    
    const activeLink = document.querySelector(`.tab-link[href*="tab=${activeTab}"]`);
    if (activeLink) {
        activeLink.classList.add('active');
    }

    // Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#dc3545';
                } else {
                    field.style.borderColor = '#e0e0e0';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Please fill all required fields');
            }
        });
    });

    // Clear form validation styles on input
    document.querySelectorAll('input, select, textarea').forEach(field => {
        field.addEventListener('input', function() {
            if (this.value.trim()) {
                this.style.borderColor = '#e0e0e0';
            }
        });
    });

    // Initialize date inputs with min/max values
    const today = new Date().toISOString().split('T')[0];
    
    // Set min date for expiry dates (today)
    document.querySelectorAll('input[type="date"][name="expiry_date"]').forEach(input => {
        input.min = today;
    });
    
    // Set max date for maintenance dates (today)
    document.querySelectorAll('input[type="date"][name="maintenance_date"]').forEach(input => {
        input.max = today;
    });
    
    // Set min date for next maintenance dates (tomorrow)
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.querySelectorAll('input[type="date"][name="next_maintenance"]').forEach(input => {
        input.min = tomorrow.toISOString().split('T')[0];
    });

    // Search form enhancement
    const searchBoxes = document.querySelectorAll('.search-box input');
    searchBoxes.forEach(input => {
        // Add clear button functionality
        input.addEventListener('keyup', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.form.submit();
            }
        });
    });

    // Enhance table row interactions
    const tableRows = document.querySelectorAll('.data-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    // Print functionality
    window.printInventory = function() {
        window.print();
    };

    // Export functionality placeholder
    window.exportInventory = function(format = 'csv') {
        // This would typically make an AJAX request to export data
        alert('Export functionality would be implemented here for format: ' + format);
    };
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Escape key to close modals
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        });
    }
    
    // Ctrl/Cmd + I to add inventory item
    if ((e.ctrlKey || e.metaKey) && e.key === 'i') {
        e.preventDefault();
        const activeTab = document.querySelector('.tab-content.active');
        if (activeTab && activeTab.id === 'inventory-tab') {
            openAddItemModal();
        }
    }
    
    // Ctrl/Cmd + V to add vehicle
    if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
        e.preventDefault();
        const activeTab = document.querySelector('.tab-content.active');
        if (activeTab && activeTab.id === 'vehicles-tab') {
            openVehicleModal();
        }
    }
});

// Utility function to format numbers
function formatNumber(num) {
    return new Intl.NumberFormat('en-US').format(num);
}

// Utility function to format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2
    }).format(amount);
}

// Enhanced table sorting (can be implemented if needed)
function sortTable(columnIndex, tableId) {
    const table = document.getElementById(tableId);
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Determine sort direction
    const isAscending = table.getAttribute('data-sort-order') === 'asc';
    table.setAttribute('data-sort-order', isAscending ? 'desc' : 'asc');
    
    // Sort rows
    rows.sort((a, b) => {
        const aValue = a.children[columnIndex].textContent.trim();
        const bValue = b.children[columnIndex].textContent.trim();
        
        // Try to parse as number first
        const aNum = parseFloat(aValue.replace(/[^0-9.-]/g, ''));
        const bNum = parseFloat(bValue.replace(/[^0-9.-]/g, ''));
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return isAscending ? aNum - bNum : bNum - aNum;
        }
        
        // Otherwise sort as string
        return isAscending ? 
            aValue.localeCompare(bValue) : 
            bValue.localeCompare(aValue);
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

// Touch device enhancements
if ('ontouchstart' in window) {
    document.body.classList.add('touch-device');
    
    // Add touch-friendly classes
    document.querySelectorAll('button, .btn-primary, .btn-secondary').forEach(button => {
        button.addEventListener('touchstart', function() {
            this.classList.add('touch-active');
        });
        
        button.addEventListener('touchend', function() {
            setTimeout(() => {
                this.classList.remove('touch-active');
            }, 150);
        });
    });
}</script>
</body>
</html>