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
$admin_role = $_SESSION['admin_role'] ?? 'super';
$user_email = $_SESSION['email'] ?? '';

// Get active tab from query parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'items';

// Service-specific categories
$serviceCategories = [
    'health' => ['Medical Supplies', 'Medications', 'First Aid Kits', 'Laboratory Equipment'],
    'safety' => ['Safety Equipment', 'PPE', 'Emergency Supplies', 'Training Materials'],
    'welfare' => ['Relief Goods', 'Food Supplies', 'Clothing', 'Educational Materials'],
    'disaster' => ['Emergency Equipment', 'Communication Devices', 'Rescue Tools', 'Shelter Supplies'],
    'youth' => ['Training Materials', 'Sports Equipment', 'Activity Supplies', 'Leadership Resources']
];

$vehicleCategories = [
    'health' => ['Ambulances', 'Medical Transport'],
    'safety' => ['Fire Trucks', 'Safety Patrol'],
    'welfare' => ['Relief Trucks', 'Mobile Kitchens'],
    'disaster' => ['Emergency Response', 'Rescue Vehicles'],
    'youth' => ['Youth Transport', 'Training Vehicles']
];

// Current service
$currentService = $admin_role === 'super' ? 'all' : $admin_role;

// Create enhanced tables with error handling
try {
    // Categories table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `categories` (
          `category_id` int(11) NOT NULL AUTO_INCREMENT,
          `category_name` varchar(100) NOT NULL,
          `category_type` enum('inventory','vehicle','general') DEFAULT 'general',
          `service_area` enum('health','safety','welfare','disaster','youth','super','shared') DEFAULT 'shared',
          `created_by` int(11) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`category_id`),
          INDEX `idx_category_type` (`category_type`),
          INDEX `idx_category_service` (`service_area`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Inventory categories table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory_categories` (
          `category_id` int(11) NOT NULL AUTO_INCREMENT,
          `category_name` varchar(100) NOT NULL,
          `service_area` enum('health','safety','welfare','disaster','youth','super','shared') DEFAULT 'shared',
          `created_by` int(11) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`category_id`),
          INDEX `idx_service_area` (`service_area`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Updated inventory items table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory_items` (
          `item_id` int(11) NOT NULL AUTO_INCREMENT,
          `item_code` varchar(50) UNIQUE NOT NULL,
          `item_name` varchar(255) NOT NULL,
          `description` text,
          `category_id` int(11) NOT NULL,
          `current_stock` int(11) NOT NULL DEFAULT 0,
          `minimum_stock` int(11) NOT NULL DEFAULT 0,
          `unit` varchar(50) NOT NULL DEFAULT 'pcs',
          `location` varchar(255),
          `service_area` enum('health','safety','welfare','disaster','youth','super') NOT NULL,
          `status` enum('active','inactive','discontinued') DEFAULT 'active',
          `created_by` int(11) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`item_id`),
          INDEX `idx_service_area` (`service_area`),
          INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Vehicles table
 $pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicles` (
      `vehicle_id` int(11) NOT NULL AUTO_INCREMENT,
      `vehicle_name` varchar(100) NOT NULL,
      `vehicle_code` varchar(50) UNIQUE NOT NULL,
      `category_id` int(11) NOT NULL,
      `vehicle_type` varchar(50) DEFAULT NULL,
      `plate_number` varchar(20) NOT NULL UNIQUE,
      `model` varchar(100) NOT NULL,
      `year` int(4) NOT NULL,
      `status` enum('operational','maintenance','out_of_service') DEFAULT 'operational',
      `fuel_type` enum('gasoline','diesel','hybrid','electric') NOT NULL,
      `current_mileage` int(11) DEFAULT 0,
      `last_maintenance_date` date DEFAULT NULL,
      `next_maintenance_date` date DEFAULT NULL,
      `maintenance_interval` int(11) DEFAULT 5000,
      `service_area` enum('health','safety','welfare','disaster','youth','super') NOT NULL,
      `branch_name` varchar(100) DEFAULT NULL,
      `location` varchar(255) DEFAULT NULL,
      `created_by` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`vehicle_id`),
      INDEX `idx_vehicles_status` (`status`),
      INDEX `idx_vehicles_service` (`service_area`),
      INDEX `idx_vehicles_type` (`vehicle_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

    // Vehicle maintenance table
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `vehicle_maintenance` (
      `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
      `vehicle_id` int(11) NOT NULL,
      `maintenance_type` enum('routine','repair','inspection','emergency') NOT NULL,
      `description` text NOT NULL,
      `cost` decimal(10,2) DEFAULT 0.00,
      `maintenance_date` date NOT NULL,
      `next_maintenance_date` date DEFAULT NULL,
      `service_provider` varchar(255) DEFAULT NULL,
      `mileage_at_service` int(11) DEFAULT NULL,
      `notes` text DEFAULT NULL,
      `created_by` int(11) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`maintenance_id`),
      INDEX `idx_maintenance_date` (`maintenance_date`),
      INDEX `idx_maintenance_type` (`maintenance_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
    // Inventory transactions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory_transactions` (
          `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
          `item_id` int(11) NOT NULL,
          `transaction_type` enum('in','out','adjustment','transfer') NOT NULL,
          `quantity` int(11) NOT NULL,
          `previous_stock` int(11) NOT NULL,
          `new_stock` int(11) NOT NULL,
          `reference_number` varchar(100),
          `notes` text,
          `transaction_date` date NOT NULL,
          `created_by` int(11) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`transaction_id`),
          INDEX `idx_item_id` (`item_id`),
          INDEX `idx_transaction_type` (`transaction_type`),
          INDEX `idx_transaction_date` (`transaction_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

} catch (PDOException $e) {
    $errorMessage = "Database setup error: " . $e->getMessage();
}

// Initialize default categories
$defaultInventoryCategories = $serviceCategories[$currentService] ?? ['General Supplies'];
foreach ($defaultInventoryCategories as $catName) {
    try {
        $stmt = $pdo->prepare("SELECT category_id FROM inventory_categories WHERE category_name = ? AND service_area = ?");
        $stmt->execute([$catName, $currentService === 'all' ? 'shared' : $currentService]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO inventory_categories (category_name, service_area, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$catName, $currentService === 'all' ? 'shared' : $currentService, $user_id]);
        }
    } catch (PDOException $e) {
        // Continue if category creation fails
    }
}

$defaultVehicleCategories = $vehicleCategories[$currentService] ?? ['General Transport'];
foreach ($defaultVehicleCategories as $catName) {
    try {
        $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = ? AND category_type = 'vehicle' AND service_area = ?");
        $stmt->execute([$catName, $currentService === 'all' ? 'shared' : $currentService]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO categories (category_name, category_type, service_area, created_by) VALUES (?, 'vehicle', ?, ?)");
            $stmt->execute([$catName, $currentService === 'all' ? 'shared' : $currentService, $user_id]);
        }
    } catch (PDOException $e) {
        // Continue if category creation fails
    }
}

// Handle Add/Edit Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vehicle'])) {
    $vehicle_name = trim($_POST['vehicle_name'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null; // NULL is allowed
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $plate_number = trim($_POST['plate_number'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = (int)($_POST['year'] ?? 0);
    $fuel_type = $_POST['fuel_type'] ?? '';
    $current_mileage = (int)($_POST['current_mileage'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $branch_name = trim($_POST['branch_name'] ?? '');
    $vehicle_id = isset($_POST['vehicle_id']) && !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : 0;
    
    // Category is optional based on your CREATE TABLE (DEFAULT NULL)
    if ($vehicle_name && $plate_number && $model && $year && $fuel_type) {
        try {
            $service_area = ($admin_role === 'super') ? 'super' : $admin_role;
            
            if ($vehicle_id > 0) {
                // Update existing vehicle - use admin_id as per your actual schema
                $stmt = $pdo->prepare("
                    UPDATE vehicles 
                    SET vehicle_name = ?, category_id = ?, vehicle_type = ?, plate_number = ?, 
                        model = ?, year = ?, fuel_type = ?, current_mileage = ?, 
                        location = ?, branch_name = ?
                    WHERE vehicle_id = ? AND (admin_id = ? OR ? = 'super')
                ");
                $stmt->execute([$vehicle_name, $category_id, $vehicle_type, $plate_number, 
                               $model, $year, $fuel_type, $current_mileage, $location, 
                               $branch_name, $vehicle_id, $user_id, $admin_role]);
                $successMessage = "Vehicle updated successfully!";
            } else {
                // Generate vehicle code
                $code_prefix = strtoupper(substr($service_area, 0, 3));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE service_area = ?");
                $stmt->execute([$service_area]);
                $count = $stmt->fetchColumn() + 1;
                $vehicle_code = $code_prefix . 'V' . str_pad($count, 3, '0', STR_PAD_LEFT);
                
                // Insert new vehicle - use admin_id as per your actual schema
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles 
                    (vehicle_code, vehicle_name, category_id, vehicle_type, plate_number, 
                     model, year, fuel_type, current_mileage, location, branch_name, 
                     service_area, admin_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$vehicle_code, $vehicle_name, $category_id, $vehicle_type, 
                               $plate_number, $model, $year, $fuel_type, $current_mileage, 
                               $location, $branch_name, $service_area, $user_id]);
                $successMessage = "Vehicle added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving vehicle: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill in all required fields (vehicle name, plate number, model, year, and fuel type).";
    }
}


// Handle Add/Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $item_name = trim($_POST['item_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $unit = trim($_POST['unit'] ?? 'pcs');
    $minimum_stock = (int)($_POST['minimum_stock'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $item_id = isset($_POST['item_id']) && !empty($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    
    if ($item_name && $category_id) {
        try {
            $service_area = ($admin_role === 'super') ? 'super' : $admin_role;
            
            if ($item_id > 0) {
                // Update existing item
                $stmt = $pdo->prepare("
                    UPDATE inventory_items 
                    SET item_name = ?, description = ?, category_id = ?, unit = ?, 
                        minimum_stock = ?, location = ?, service_area = ?
                    WHERE item_id = ? AND (created_by = ? OR ? = 'super')
                ");
                $stmt->execute([$item_name, $description, $category_id, $unit, 
                               $minimum_stock, $location, $service_area, $item_id, $user_id, $admin_role]);
                $successMessage = "Item updated successfully!";
            } else {
                // Generate item code
                $code_prefix = strtoupper(substr($service_area, 0, 3));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE service_area = ?");
                $stmt->execute([$service_area]);
                $count = $stmt->fetchColumn() + 1;
                $item_code = $code_prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                // Insert new item
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_items 
                    (item_code, item_name, description, category_id, unit, minimum_stock, 
                     location, service_area, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$item_code, $item_name, $description, $category_id, $unit, 
                               $minimum_stock, $location, $service_area, $user_id]);
                $successMessage = "Item added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving item: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill in all required fields.";
    }
}

// Handle Stock Transaction
// FIXED: Handle Stock Transaction - Better validation and error handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction'])) {
    $item_id = (int)($_POST['item_id'] ?? 0);
    $transaction_type = $_POST['transaction_type'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reference_number = trim($_POST['reference_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? '';
    
    // Enhanced validation with specific error messages
    if (!$item_id) {
        $errorMessage = "Please select an item.";
    } elseif (!$transaction_type) {
        $errorMessage = "Please select a transaction type.";
    } elseif ($quantity <= 0) {
        $errorMessage = "Please enter a valid quantity greater than 0.";
    } elseif (!$transaction_date) {
        $errorMessage = "Please select a transaction date.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get current stock and item details
            $stmt = $pdo->prepare("SELECT current_stock, item_name, item_code FROM inventory_items WHERE item_id = ?");
            $stmt->execute([$item_id]);
            $item_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item_data) {
                throw new Exception("Item not found.");
            }
            
            $current_stock = (int)$item_data['current_stock'];
            $item_name = $item_data['item_name'];
            $item_code = $item_data['item_code'];
            
            // Calculate new stock based on transaction type
            $new_stock = $current_stock;
            $actual_quantity = $quantity; // This will be the quantity recorded in transactions
            
            if ($transaction_type === 'in') {
                $new_stock = $current_stock + $quantity;
            } elseif ($transaction_type === 'out') {
                $new_stock = $current_stock - $quantity;
                if ($new_stock < 0) {
                    throw new Exception("Insufficient stock for {$item_code} - {$item_name}. Current stock: {$current_stock}, Requested: {$quantity}");
                }
            } elseif ($transaction_type === 'adjustment') {
                // For adjustment, quantity is the new total stock level
                $new_stock = $quantity;
                $actual_quantity = $new_stock - $current_stock; // Calculate the difference
            } else {
                throw new Exception("Invalid transaction type.");
            }
            
            // Update item stock
            $stmt = $pdo->prepare("UPDATE inventory_items SET current_stock = ? WHERE item_id = ?");
            if (!$stmt->execute([$new_stock, $item_id])) {
                throw new Exception("Failed to update item stock.");
            }
            
            // Record the transaction
            $stmt = $pdo->prepare("
                INSERT INTO inventory_transactions 
                (item_id, transaction_type, quantity, previous_stock, new_stock, 
                 reference_number, notes, transaction_date, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if (!$stmt->execute([$item_id, $transaction_type, $actual_quantity, $current_stock, 
                               $new_stock, $reference_number, $notes, $transaction_date, $user_id])) {
                throw new Exception("Failed to record transaction.");
            }
            
            $pdo->commit();
            
            // Success message with details
            $action = '';
            if ($transaction_type === 'in') {
                $action = "Added {$quantity} units";
            } elseif ($transaction_type === 'out') {
                $action = "Removed {$quantity} units";
            } elseif ($transaction_type === 'adjustment') {
                $action = "Adjusted stock to {$quantity} units";
            }
            
            $successMessage = "{$action} for {$item_code} - {$item_name}. New stock level: {$new_stock}";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMessage = "Transaction failed: " . $e->getMessage();
        }
    }
}

// Handle Vehicle Maintenance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_maintenance'])) {
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $maintenance_type = $_POST['maintenance_type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0.00;
    $maintenance_date = $_POST['maintenance_date'] ?? '';
    $next_maintenance_date = !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : null;
    $service_provider = trim($_POST['service_provider'] ?? '');
    $mileage_at_service = !empty($_POST['mileage_at_service']) ? (int)$_POST['mileage_at_service'] : null;
    
    if ($vehicle_id && $maintenance_type && $description && $maintenance_date) {
        try {
            $stmt = $pdo->prepare("
    INSERT INTO vehicle_maintenance 
    (vehicle_id, maintenance_type, description, cost, maintenance_date, 
     next_maintenance_date, service_provider, mileage_at_service, created_by) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
            $stmt->execute([$vehicle_id, $maintenance_type, $description, $cost, 
               $maintenance_date, $next_maintenance_date, $service_provider, 
               $mileage_at_service, $user_id]);
            
            // Update vehicle maintenance dates using correct field names
            $updateFields = ["last_maintenance_date = ?"];
            $updateParams = [$maintenance_date];
            
            if ($next_maintenance_date) {
                $updateFields[] = "next_maintenance_date = ?";
                $updateParams[] = $next_maintenance_date;
            }
            
            if ($mileage_at_service) {
                $updateFields[] = "current_mileage = ?";
                $updateParams[] = $mileage_at_service;
            }
            
            $updateParams[] = $vehicle_id;
            
            $stmt = $pdo->prepare("
                UPDATE vehicles 
                SET " . implode(", ", $updateFields) . "
                WHERE vehicle_id = ?
            ");
            $stmt->execute($updateParams);
            
            $successMessage = "Maintenance record added successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error saving maintenance record: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill in all required fields.";
    }
}
// Handle Add/Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $category_name = trim($_POST['category_name'] ?? '');
    $category_type = $_POST['category_type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $category_id = isset($_POST['category_id']) && !empty($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    
    if ($category_name && $category_type) {
        try {
            $service_area = ($admin_role === 'super') ? 'shared' : $admin_role;
            $table = ($category_type === 'inventory') ? 'inventory_categories' : 'categories';
            
            if ($category_id > 0) {
                // Update existing category
                if ($category_type === 'inventory') {
                    $stmt = $pdo->prepare("UPDATE inventory_categories SET category_name = ?, service_area = ? WHERE category_id = ?");
                    $stmt->execute([$category_name, $service_area, $category_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, category_type = ?, service_area = ? WHERE category_id = ?");
                    $stmt->execute([$category_name, $category_type, $service_area, $category_id]);
                }
                $successMessage = "Category updated successfully!";
            } else {
                // Insert new category
                if ($category_type === 'inventory') {
                    $stmt = $pdo->prepare("INSERT INTO inventory_categories (category_name, service_area, created_by) VALUES (?, ?, ?)");
                    $stmt->execute([$category_name, $service_area, $user_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO categories (category_name, category_type, service_area, created_by) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$category_name, $category_type, $service_area, $user_id]);
                }
                $successMessage = "Category added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving category: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill in all required fields.";
    }
}

// Handle Delete Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $category_type = $_POST['category_type'] ?? '';
    
    if ($category_id && $category_type) {
        try {
            $table = ($category_type === 'inventory') ? 'inventory_categories' : 'categories';
            $stmt = $pdo->prepare("DELETE FROM $table WHERE category_id = ?");
            $stmt->execute([$category_id]);
            $successMessage = "Category deleted successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error deleting category: " . $e->getMessage();
        }
    }
}

// Get data for display
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Get categories
try {
    if ($admin_role === 'super') {
        $categories = $pdo->query("SELECT * FROM inventory_categories ORDER BY service_area, category_name")->fetchAll();
        $vehicleCategoriesData = $pdo->query("SELECT * FROM categories WHERE category_type = 'vehicle' ORDER BY service_area, category_name")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM inventory_categories WHERE service_area = ? OR service_area = 'shared' ORDER BY category_name");
        $stmt->execute([$admin_role]);
        $categories = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE category_type = 'vehicle' AND (service_area = ? OR service_area = 'shared') ORDER BY category_name");
        $stmt->execute([$admin_role]);
        $vehicleCategoriesData = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $categories = [];
    $vehicleCategoriesData = [];
}

// Get items with search and filters
try {
    $query = "
        SELECT i.*, c.category_name
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON i.category_id = c.category_id
        WHERE 1=1
    ";
    $params = [];
    
    if ($admin_role !== 'super') {
        $query .= " AND (i.service_area = ? OR i.created_by = ?)";
        $params[] = $admin_role;
        $params[] = $user_id;
    }
    
    if ($search) {
        $query .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR i.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($category_filter) {
        $query .= " AND i.category_id = ?";
        $params[] = $category_filter;
    }
    
    if ($status_filter) {
        if ($status_filter === 'low_stock') {
            $query .= " AND i.current_stock <= i.minimum_stock";
        } elseif ($status_filter === 'out_of_stock') {
            $query .= " AND i.current_stock = 0";
        } else {
            $query .= " AND i.status = ?";
            $params[] = $status_filter;
        }
    }
    
    $query .= " ORDER BY i.item_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $items = [];
}

// Get vehicles
try {
    $query = "
        SELECT v.*, c.category_name
        FROM vehicles v
        LEFT JOIN categories c ON v.category_id = c.category_id
        WHERE 1=1
    ";
    $params = [];
    
    if ($admin_role !== 'super') {
        $query .= " AND (v.service_area = ? OR v.admin_id = ?)";
        $params[] = $admin_role;
        $params[] = $user_id;
    }
    
    if ($search) {
        $query .= " AND (v.vehicle_name LIKE ? OR v.vehicle_code LIKE ? OR v.plate_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $query .= " ORDER BY v.vehicle_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $vehicles = [];
}

// Get statistics
try {
    $statsWhere = $admin_role === 'super' ? "" : "WHERE (service_area = ? OR created_by = ?)";
    $statsParams = $admin_role === 'super' ? [] : [$admin_role, $user_id];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items $statsWhere");
    $stmt->execute($statsParams);
    $total_items = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items $statsWhere " . 
                         ($statsWhere ? "AND" : "WHERE") . " current_stock <= minimum_stock");
    $stmt->execute($statsParams);
    $low_stock_items = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items $statsWhere " . 
                         ($statsWhere ? "AND" : "WHERE") . " current_stock = 0");
    $stmt->execute($statsParams);
    $out_of_stock = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT SUM(current_stock) FROM inventory_items $statsWhere");
    $stmt->execute($statsParams);
    $total_stock = $stmt->fetchColumn() ?: 0;
    
    // Vehicle stats
    $vehicleStatsWhere = $admin_role === 'super' ? "" : "WHERE (service_area = ? OR created_by = ?)";
    $vehicleStatsParams = $admin_role === 'super' ? [] : [$admin_role, $user_id];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles $vehicleStatsWhere");
    $stmt->execute($vehicleStatsParams);
    $total_vehicles = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles $vehicleStatsWhere " . 
                         ($vehicleStatsWhere ? "AND" : "WHERE") . " status = 'maintenance'");
    $stmt->execute($vehicleStatsParams);
    $vehicles_in_maintenance = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles $vehicleStatsWhere " . 
                         ($vehicleStatsWhere ? "AND" : "WHERE") . " status = 'operational'");
    $stmt->execute($vehicleStatsParams);
    $operational_vehicles = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $total_items = $low_stock_items = $out_of_stock = $total_stock = 0;
    $total_vehicles = $vehicles_in_maintenance = $operational_vehicles = 0;
}

$serviceTitle = [
    'health' => 'Health Services',
    'safety' => 'Safety Services', 
    'welfare' => 'Welfare Services',
    'disaster' => 'Disaster Management',
    'youth' => 'Red Cross Youth',
    'super' => 'All Services'
];

$currentServiceTitle = $serviceTitle[$admin_role] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $currentServiceTitle ?> - Inventory & Vehicle Management - PRC Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/sidebar_admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/manage_inventory.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  <div class="main-container">
     <?php include 'header.php'; ?>
    <div class="page-header">
      <div class="header-content">
        <h1>
          <i class="fas fa-boxes"></i> 
          <?= $currentServiceTitle ?> - Inventory & Vehicle Management
        </h1>
        <p>Comprehensive inventory and vehicle tracking with maintenance records</p>
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

    <!-- Section Navigation -->
    <div class="section-navigation">
      <button class="section-toggle <?= $activeTab === 'items' ? 'active' : '' ?>" onclick="switchSection('items')">
        <i class="fas fa-boxes"></i>
        <span>Inventory Items</span>
      </button>
      <button class="section-toggle <?= $activeTab === 'categories' ? 'active' : '' ?>" onclick="switchSection('categories')">
  <i class="fas fa-tags"></i>
  <span>Categories</span>
</button>
      <button class="section-toggle <?= $activeTab === 'vehicles' ? 'active' : '' ?>" onclick="switchSection('vehicles')">
        <i class="fas fa-truck"></i>
        <span>Vehicles</span>
      </button>
      <button class="section-toggle <?= $activeTab === 'transactions' ? 'active' : '' ?>" onclick="switchSection('transactions')">
        <i class="fas fa-exchange-alt"></i>
        <span>Transactions</span>
      </button>
      <button class="section-toggle <?= $activeTab === 'maintenance' ? 'active' : '' ?>" onclick="switchSection('maintenance')">
        <i class="fas fa-tools"></i>
        <span>Maintenance</span>
      </button>
    </div>

    <!-- Items Section -->
    <div class="section-content <?= $activeTab === 'items' ? 'active' : '' ?>" id="items-section">
      <!-- Stats Overview -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-boxes"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $total_items ?></div>
            <div class="stat-label">Total Items</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
            <i class="fas fa-layer-group"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= number_format($total_stock) ?></div>
            <div class="stat-label">Total Stock</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $low_stock_items ?></div>
            <div class="stat-label">Low Stock</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
            <i class="fas fa-times-circle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $out_of_stock ?></div>
            <div class="stat-label">Out of Stock</div>
          </div>
        </div>
      </div>

      <!-- Action Bar -->
      <div class="action-bar">
        <div class="search-filters">
          <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="items">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" name="search" placeholder="Search items..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="category" onchange="this.form.submit()">
              <option value="">All Categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()">
              <option value="">All Status</option>
              <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="low_stock" <?= $status_filter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
              <option value="out_of_stock" <?= $status_filter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
              <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </form>
        </div>
        
        <div class="action-buttons">
          <button class="btn-primary" onclick="openAddItemModal()">
            <i class="fas fa-plus"></i> Add Item
          </button>
        </div>
      </div>

      <!-- Items Table -->
      <div class="items-section">
        <h3><i class="fas fa-list"></i> Inventory Items</h3>
        
        <?php if (empty($items)): ?>
          <div class="empty-state">
            <i class="fas fa-boxes"></i>
            <h3>No Items Found</h3>
            <p>Add your first inventory item to get started</p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Current Stock</th>
                <th>Min Stock</th>
                <th>Unit</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <?php
                  $stock_status = 'good';
                  $row_class = '';
                  if ($item['current_stock'] == 0) {
                    $stock_status = 'out_of_stock';
                    $row_class = 'out_of_stock';
                  } elseif ($item['current_stock'] <= $item['minimum_stock']) {
                    $stock_status = 'low_stock';
                    $row_class = 'low_stock';
                  }
                ?>
                <tr class="<?= $row_class ?>">
                  <td><strong><?= htmlspecialchars($item['item_code']) ?></strong></td>
                  <td>
                    <div>
                      <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                      <?php if ($item['description']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($item['description']) ?></small>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($item['category_name'] ?? 'N/A') ?></td>
                  <td>
                    <span class="stock-number <?= $stock_status ?>"><?= $item['current_stock'] ?></span>
                  </td>
                  <td><?= $item['minimum_stock'] ?></td>
                  <td><?= htmlspecialchars($item['unit']) ?></td>
                  <td><?= htmlspecialchars($item['location'] ?? 'N/A') ?></td>
                  <td>
                    <span class="status-badge <?= $item['status'] ?>">
                      <?= ucfirst($item['status']) ?>
                    </span>
                    <?php if ($stock_status !== 'good'): ?>
                      <br><span class="status-badge <?= $stock_status ?>">
                        <?= $stock_status === 'out_of_stock' ? 'Out of Stock' : 'Low Stock' ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="actions">
                      <button onclick='openEditItemModal(<?= json_encode($item) ?>)' class="btn-sm btn-edit" title="Edit">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button onclick='openTransactionModal(<?= $item['item_id'] ?>, "<?= htmlspecialchars($item['item_name']) ?>")' class="btn-sm btn-primary" title="Add Transaction">
                        <i class="fas fa-exchange-alt"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Vehicles Section -->
    <div class="section-content <?= $activeTab === 'vehicles' ? 'active' : '' ?>" id="vehicles-section">
      <!-- Vehicle Stats -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $total_vehicles ?></div>
            <div class="stat-label">Total Vehicles</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
            <i class="fas fa-check-circle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $operational_vehicles ?></div>
            <div class="stat-label">Operational</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
            <i class="fas fa-tools"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $vehicles_in_maintenance ?></div>
            <div class="stat-label">In Maintenance</div>
          </div>
        </div>
      </div>

      <!-- Action Bar -->
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
          <button class="btn-primary" onclick="openAddVehicleModal()">
            <i class="fas fa-plus"></i> Add Vehicle
          </button>
        </div>
      </div>

      <!-- Vehicles Table -->
      <div class="items-section">
        <h3><i class="fas fa-truck"></i> Fleet Vehicles</h3>
        
        <?php if (empty($vehicles)): ?>
          <div class="empty-state">
            <i class="fas fa-truck"></i>
            <h3>No Vehicles Found</h3>
            <p>Add your first vehicle to get started</p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Vehicle Name</th>
                <th>Category</th>
                <th>Plate Number</th>
                <th>Model</th>
                <th>Year</th>
                <th>Mileage</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vehicles as $vehicle): ?>
                <tr class="<?= $vehicle['status'] ?>">
                  <td><strong><?= htmlspecialchars($vehicle['vehicle_code']) ?></strong></td>
                  <td>
                    <div>
                      <strong><?= htmlspecialchars($vehicle['vehicle_name']) ?></strong>
                      <?php if ($vehicle['vehicle_type']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($vehicle['vehicle_type']) ?></small>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($vehicle['category_name'] ?? 'N/A') ?></td>
                  <td><strong><?= htmlspecialchars($vehicle['plate_number']) ?></strong></td>
                  <td><?= htmlspecialchars($vehicle['model']) ?></td>
                  <td><?= $vehicle['year'] ?></td>
                  <td><?= number_format($vehicle['current_mileage']) ?> km</td>
                  <td>
                    <span class="status-badge <?= $vehicle['status'] ?>">
                      <?= ucfirst(str_replace('_', ' ', $vehicle['status'])) ?>
                    </span>
                  </td>
                  <td>
                    <div class="actions">
                      <button onclick='openEditVehicleModal(<?= json_encode($vehicle) ?>)' class="btn-sm btn-edit" title="Edit">
                        <i class="fas fa-edit"></i>
                      </button>
                      <button onclick='openMaintenanceModal(<?= $vehicle['vehicle_id'] ?>, "<?= htmlspecialchars($vehicle['vehicle_name']) ?>")' class="btn-sm btn-primary" title="Add Maintenance">
                        <i class="fas fa-tools"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Transactions Section -->
    <div class="section-content <?= $activeTab === 'transactions' ? 'active' : '' ?>" id="transactions-section">
      <div class="action-bar">
        <h3><i class="fas fa-exchange-alt"></i> Quick Transaction</h3>
        <div class="action-buttons">
          <button class="btn-primary" onclick="openTransactionModal()">
            <i class="fas fa-plus"></i> New Transaction
          </button>
        </div>
      </div>

      <div class="items-section">
        <h3><i class="fas fa-history"></i> Recent Transactions</h3>
        
        <?php
        // Get recent transactions
        try {
          $query = "
            SELECT t.*, i.item_name, i.item_code, u.email as created_by_email
            FROM inventory_transactions t
            LEFT JOIN inventory_items i ON t.item_id = i.item_id
            LEFT JOIN users u ON t.created_by = u.user_id
            WHERE 1=1
          ";
          $params = [];
          
          if ($admin_role !== 'super') {
            $query .= " AND (i.service_area = ? OR i.created_by = ?)";
            $params[] = $admin_role;
            $params[] = $user_id;
          }
          
          $query .= " ORDER BY t.transaction_date DESC, t.created_at DESC LIMIT 100";
          
          $stmt = $pdo->prepare($query);
          $stmt->execute($params);
          $recent_transactions = $stmt->fetchAll();
        } catch (PDOException $e) {
          $recent_transactions = [];
        }
        ?>
        
        <?php if (empty($recent_transactions)): ?>
          <div class="empty-state">
            <i class="fas fa-exchange-alt"></i>
            <h3>No Transactions Found</h3>
            <p>Start recording stock movements to see transaction history</p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Item</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Reference</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_transactions as $transaction): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($transaction['transaction_date'])) ?></td>
                  <td>
                    <strong><?= htmlspecialchars($transaction['item_code']) ?></strong><br>
                    <small><?= htmlspecialchars($transaction['item_name']) ?></small>
                  </td>
                  <td>
                    <span class="transaction-type <?= $transaction['transaction_type'] ?>">
                      <?php
                        $types = [
                          'in' => ['Stock In', 'fas fa-arrow-up', '#28a745'],
                          'out' => ['Stock Out', 'fas fa-arrow-down', '#dc3545'],
                          'adjustment' => ['Adjustment', 'fas fa-edit', '#ffc107'],
                          'transfer' => ['Transfer', 'fas fa-exchange-alt', '#17a2b8']
                        ];
                        $type_info = $types[$transaction['transaction_type']] ?? ['Unknown', 'fas fa-question', '#6c757d'];
                      ?>
                      <i class="<?= $type_info[1] ?>" style="color: <?= $type_info[2] ?>"></i>
                      <?= $type_info[0] ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($transaction['transaction_type'] === 'out'): ?>
                      <span class="text-danger">-<?= $transaction['quantity'] ?></span>
                    <?php elseif ($transaction['transaction_type'] === 'in'): ?>
                      <span class="text-success">+<?= $transaction['quantity'] ?></span>
                    <?php else: ?>
                      <?= $transaction['quantity'] ?>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($transaction['reference_number'] ?? 'N/A') ?></td>
                  <td><?= htmlspecialchars(explode('@', $transaction['created_by_email'])[0]) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Maintenance Section -->
    <div class="section-content <?= $activeTab === 'maintenance' ? 'active' : '' ?>" id="maintenance-section">
      <div class="action-bar">
        <h3><i class="fas fa-tools"></i> Vehicle Maintenance</h3>
        <div class="action-buttons">
          <button class="btn-primary" onclick="openMaintenanceModal()">
            <i class="fas fa-plus"></i> Add Maintenance
          </button>
        </div>
      </div>

      <div class="items-section">
        <h3><i class="fas fa-history"></i> Maintenance Records</h3>
        
        <?php
        // Get maintenance records
        try {
          $query = "
            SELECT m.*, v.vehicle_name, v.vehicle_code, v.plate_number, u.email as created_by_email
            FROM vehicle_maintenance m
            LEFT JOIN vehicles v ON m.vehicle_id = v.vehicle_id
            LEFT JOIN users u ON m.created_by = u.user_id
            WHERE 1=1
          ";
          $params = [];
          
          if ($admin_role !== 'super') {
            $query .= " AND (v.service_area = ? OR v.created_by = ?)";
            $params[] = $admin_role;
            $params[] = $user_id;
          }
          
          $query .= " ORDER BY m.maintenance_date DESC LIMIT 100";
          
          $stmt = $pdo->prepare($query);
          $stmt->execute($params);
          $maintenance_records = $stmt->fetchAll();
        } catch (PDOException $e) {
          $maintenance_records = [];
        }
        ?>
        
        <?php if (empty($maintenance_records)): ?>
          <div class="empty-state">
            <i class="fas fa-tools"></i>
            <h3>No Maintenance Records Found</h3>
            <p>Start recording vehicle maintenance to track service history</p>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Vehicle</th>
                <th>Type</th>
                <th>Description</th>
                <th>Cost</th>
                <th>Service Provider</th>
                <th>By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($maintenance_records as $record): ?>
                <tr>
                  <td><?= date('M d, Y', strtotime($record['maintenance_date'])) ?></td>
                  <td>
                    <strong><?= htmlspecialchars($record['vehicle_code']) ?></strong><br>
                    <small><?= htmlspecialchars($record['vehicle_name']) ?></small><br>
                    <small class="text-muted"><?= htmlspecialchars($record['plate_number']) ?></small>
                  </td>
                  <td>
                    <span class="status-badge <?= $record['maintenance_type'] ?>">
                      <?= ucfirst($record['maintenance_type']) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($record['description']) ?></td>
                  <td><?= $record['cost'] > 0 ? '₱' . number_format($record['cost'], 2) : 'N/A' ?></td>
                  <td><?= htmlspecialchars($record['service_provider'] ?? 'Internal') ?></td>
                  <td><?= htmlspecialchars(explode('@', $record['created_by_email'])[0]) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <!-- Categories Section -->
<div class="section-content <?= $activeTab === 'categories' ? 'active' : '' ?>" id="categories-section">
  <div class="action-bar">
    <h3><i class="fas fa-tags"></i> Category Management</h3>
    <div class="action-buttons">
      <button class="btn-primary" onclick="openAddCategoryModal()">
        <i class="fas fa-plus"></i> Add Category
      </button>
    </div>
  </div>

  <div class="items-section">
    <h3><i class="fas fa-list"></i> All Categories</h3>
    
    <?php
    // Get all categories
    try {
      $inventory_cats = [];
      $vehicle_cats = [];
      
      if ($admin_role === 'super') {
        $stmt = $pdo->query("SELECT *, 'inventory' as type FROM inventory_categories ORDER BY service_area, category_name");
        $inventory_cats = $stmt->fetchAll();
        
        $stmt = $pdo->query("SELECT *, 'vehicle' as type FROM categories WHERE category_type = 'vehicle' ORDER BY service_area, category_name");
        $vehicle_cats = $stmt->fetchAll();
      } else {
        $stmt = $pdo->prepare("SELECT *, 'inventory' as type FROM inventory_categories WHERE service_area = ? OR service_area = 'shared' ORDER BY category_name");
        $stmt->execute([$admin_role]);
        $inventory_cats = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("SELECT *, 'vehicle' as type FROM categories WHERE category_type = 'vehicle' AND (service_area = ? OR service_area = 'shared') ORDER BY category_name");
        $stmt->execute([$admin_role]);
        $vehicle_cats = $stmt->fetchAll();
      }
      
      $all_categories = array_merge($inventory_cats, $vehicle_cats);
    } catch (PDOException $e) {
      $all_categories = [];
    }
    ?>
    
    <?php if (empty($all_categories)): ?>
      <div class="empty-state">
        <i class="fas fa-tags"></i>
        <h3>No Categories Found</h3>
        <p>Add your first category to get started</p>
      </div>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Service Area</th>
            <th>Items Count</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($all_categories as $cat): ?>
            <?php
            // Count items in this category
            try {
              if ($cat['type'] === 'inventory') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE category_id = ?");
              } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE category_id = ?");
              }
              $stmt->execute([$cat['category_id']]);
              $item_count = $stmt->fetchColumn();
            } catch (PDOException $e) {
              $item_count = 0;
            }
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($cat['category_name']) ?></strong></td>
              <td>
                <span class="status-badge <?= $cat['type'] ?>">
                  <?= ucfirst($cat['type']) ?>
                </span>
              </td>
              <td><?= ucfirst($cat['service_area']) ?></td>
              <td><?= $item_count ?> items</td>
              <td>
                <div class="actions">
                  <button onclick='openEditCategoryModal(<?= json_encode($cat) ?>)' class="btn-sm btn-edit" title="Edit">
                    <i class="fas fa-edit"></i>
                  </button>
                  <?php if ($item_count == 0): ?>
                    <button onclick='deleteCategory(<?= $cat['category_id'] ?>, "<?= $cat['type'] ?>", "<?= htmlspecialchars($cat['category_name']) ?>")' class="btn-sm btn-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
  </div>

  <!-- MODALS -->
  
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
        
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" id="itemDescription" rows="3"></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Category *</label>
            <select name="category_id" id="itemCategory" required>
              <option value="">Select Category</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>">
                  <?= htmlspecialchars($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label>Unit *</label>
            <input type="text" name="unit" id="itemUnit" value="pcs" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Minimum Stock *</label>
            <input type="number" name="minimum_stock" id="itemMinStock" min="0" value="10" required>
          </div>
          
          <div class="form-group">
            <label>Storage Location</label>
            <input type="text" name="location" id="itemLocation" placeholder="e.g., Room A, Shelf 1">
          </div>
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

  <!-- Add/Edit Vehicle Modal -->
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
        
        <div class="form-group">
          <label>Vehicle Name *</label>
          <input type="text" name="vehicle_name" id="vehicleName" required>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Category *</label>
            <select name="category_id" id="vehicleCategory" required>
              <option value="">Select Category</option>
              <?php foreach ($vehicleCategoriesData as $cat): ?>
                <option value="<?= $cat['category_id'] ?>">
                  <?= htmlspecialchars($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label>Vehicle Type</label>
            <input type="text" name="vehicle_type" id="vehicleType" placeholder="e.g., Ambulance, Truck">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Plate Number *</label>
            <input type="text" name="plate_number" id="vehiclePlate" required>
          </div>
          
          <div class="form-group">
            <label>Model *</label>
            <input type="text" name="model" id="vehicleModel" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Year *</label>
            <input type="number" name="year" id="vehicleYear" min="1900" max="2030" required>
          </div>
          
          <div class="form-group">
            <label>Fuel Type *</label>
            <select name="fuel_type" id="vehicleFuelType" required>
              <option value="">Select Fuel Type</option>
              <option value="gasoline">Gasoline</option>
              <option value="diesel">Diesel</option>
              <option value="hybrid">Hybrid</option>
              <option value="electric">Electric</option>
            </select>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Current Mileage</label>
            <input type="number" name="current_mileage" id="vehicleMileage" min="0" value="0">
          </div>
          
          <div class="form-group">
            <label>Branch</label>
            <input type="text" name="branch_name" id="vehicleBranch" placeholder="e.g., Main Branch">
          </div>
        </div>
        
        <div class="form-group">
          <label>Location</label>
          <input type="text" name="location" id="vehicleLocation" placeholder="e.g., Garage Bay 1">
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

  <!-- Transaction Modal -->
  <div class="modal" id="transactionModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Record Transaction</h2>
        <button class="close-btn" onclick="closeModal('transactionModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST">
        <input type="hidden" name="save_transaction" value="1">
        
        <div class="form-group">
          <label>Item *</label>
          <select name="item_id" id="transactionItem" required>
            <option value="">Select Item</option>
            <?php foreach ($items as $item): ?>
              <option value="<?= $item['item_id'] ?>" data-stock="<?= $item['current_stock'] ?>">
                <?= htmlspecialchars($item['item_code']) ?> - <?= htmlspecialchars($item['item_name']) ?>
                (Stock: <?= $item['current_stock'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Transaction Type *</label>
            <select name="transaction_type" id="transactionType" required>
              <option value="in">Stock In (+)</option>
              <option value="out">Stock Out (-)</option>
              <option value="adjustment">Stock Adjustment</option>
            </select>
          </div>
          
          <div class="form-group">
            <label>Quantity *</label>
            <input type="number" name="quantity" id="transactionQuantity" min="1" required>
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Reference Number</label>
            <input type="text" name="reference_number" id="transactionRef" placeholder="e.g., PO-001, DR-123">
          </div>
          
          <div class="form-group">
            <label>Transaction Date *</label>
            <input type="date" name="transaction_date" id="transactionDate" required>
          </div>
        </div>
        
        <div class="form-group">
          <label>Notes</label>
          <textarea name="notes" id="transactionNotes" rows="3" placeholder="Additional notes about this transaction..."></textarea>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Record Transaction
          </button>
          <button type="button" class="btn-secondary" onclick="closeModal('transactionModal')">
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
        <input type="hidden" name="save_maintenance" value="1">
        
        <div class="form-group">
          <label>Vehicle *</label>
          <select name="vehicle_id" id="maintenanceVehicle" required>
            <option value="">Select Vehicle</option>
            <?php foreach ($vehicles as $vehicle): ?>
              <option value="<?= $vehicle['vehicle_id'] ?>">
                <?= htmlspecialchars($vehicle['vehicle_code']) ?> - <?= htmlspecialchars($vehicle['vehicle_name']) ?>
                (<?= htmlspecialchars($vehicle['plate_number']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Maintenance Type *</label>
            <select name="maintenance_type" required>
              <option value="">Select Type</option>
              <option value="routine">Routine Maintenance</option>
              <option value="repair">Repair</option>
              <option value="inspection">Inspection</option>
              <option value="emergency">Emergency Repair</option>
            </select>
          </div>
          
          <div class="form-group">
            <label>Cost</label>
            <input type="number" name="cost" step="0.01" min="0" placeholder="0.00">
          </div>
        </div>
        
        <div class="form-group">
          <label>Description *</label>
          <textarea name="description" rows="3" required placeholder="Describe the maintenance work performed..."></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Maintenance Date *</label>
            <input type="date" name="maintenance_date" required>
          </div>
          
          <div class="form-group">
            <label>Next Maintenance Date</label>
            <input type="date" name="next_maintenance_date">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Service Provider</label>
            <input type="text" name="service_provider" placeholder="e.g., ABC Auto Shop">
          </div>
          
          <div class="form-group">
            <label>Mileage at Service</label>
            <input type="number" name="mileage_at_service" min="0">
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Save Maintenance
          </button>
          <button type="button" class="btn-secondary" onclick="closeModal('maintenanceModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>
<!-- Add/Edit Category Modal -->
<div class="modal" id="categoryModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="categoryModalTitle">Add New Category</h2>
      <button class="close-btn" onclick="closeModal('categoryModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    
    <form method="POST">
      <input type="hidden" name="save_category" value="1">
      <input type="hidden" name="category_id" id="categoryId">
      
      <div class="form-group">
        <label>Category Name *</label>
        <input type="text" name="category_name" id="categoryName" required>
      </div>
      
      <div class="form-group">
        <label>Category Type *</label>
        <select name="category_type" id="categoryType" required>
          <option value="">Select Type</option>
          <option value="inventory">Inventory Category</option>
          <option value="vehicle">Vehicle Category</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" id="categoryDescription" rows="3" placeholder="Optional description..."></textarea>
      </div>
      
      <div class="modal-footer">
        <button type="submit" class="btn-primary">
          <i class="fas fa-save"></i> Save Category
        </button>
        <button type="button" class="btn-secondary" onclick="closeModal('categoryModal')">
          Cancel
        </button>
      </div>
    </form>
  </div>
</div>
<script src="./js/event-notifications.js?v=<?= time() ?>"></script>
<script src="../admin/js/notification_frontend.js?v=<?php echo time(); ?>"></script>
  <script src="../admin/js/sidebar-notifications.js?v=<?php echo time(); ?>"></script>
<script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
  <script>
    // JavaScript for the inventory and vehicle management system
    
    function switchSection(sectionName) {
      // Hide all sections
      document.querySelectorAll('.section-content').forEach(section => {
        section.classList.remove('active');
      });
      
      // Deactivate all toggles
      document.querySelectorAll('.section-toggle').forEach(toggle => {
        toggle.classList.remove('active');
      });
      
      // Show selected section
      const targetSection = document.getElementById(sectionName + '-section');
      if (targetSection) {
        targetSection.classList.add('active');
      }
      
      // Activate selected toggle
      if (event && event.target) {
        const toggle = event.target.closest('.section-toggle');
        if (toggle) {
          toggle.classList.add('active');
        }
      }
      
      // Update URL
      const url = new URL(window.location);
      url.searchParams.set('tab', sectionName);
      window.history.pushState({}, '', url);
    }
    
    function openModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
      }
    }
    
    function closeModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('active');
        document.body.style.overflow = '';
      }
    }
    
    function openAddItemModal() {
      document.getElementById('itemModalTitle').textContent = 'Add New Item';
      document.getElementById('itemId').value = '';
      document.getElementById('itemName').value = '';
      document.getElementById('itemDescription').value = '';
      document.getElementById('itemCategory').value = '';
      document.getElementById('itemUnit').value = 'pcs';
      document.getElementById('itemMinStock').value = '10';
      document.getElementById('itemLocation').value = '';
      openModal('itemModal');
    }
    
    function openEditItemModal(item) {
      document.getElementById('itemModalTitle').textContent = 'Edit Item';
      document.getElementById('itemId').value = item.item_id;
      document.getElementById('itemName').value = item.item_name;
      document.getElementById('itemDescription').value = item.description || '';
      document.getElementById('itemCategory').value = item.category_id;
      document.getElementById('itemUnit').value = item.unit;
      document.getElementById('itemMinStock').value = item.minimum_stock;
      document.getElementById('itemLocation').value = item.location || '';
      openModal('itemModal');
    }
    
    function openAddVehicleModal() {
      document.getElementById('vehicleModalTitle').textContent = 'Add New Vehicle';
      document.getElementById('vehicleId').value = '';
      document.getElementById('vehicleName').value = '';
      document.getElementById('vehicleCategory').value = '';
      document.getElementById('vehicleType').value = '';
      document.getElementById('vehiclePlate').value = '';
      document.getElementById('vehicleModel').value = '';
      document.getElementById('vehicleYear').value = '';
      document.getElementById('vehicleFuelType').value = '';
      document.getElementById('vehicleMileage').value = '0';
      document.getElementById('vehicleBranch').value = '';
      document.getElementById('vehicleLocation').value = '';
      openModal('vehicleModal');
    }
    
    function openEditVehicleModal(vehicle) {
      document.getElementById('vehicleModalTitle').textContent = 'Edit Vehicle';
      document.getElementById('vehicleId').value = vehicle.vehicle_id;
      document.getElementById('vehicleName').value = vehicle.vehicle_name;
      document.getElementById('vehicleCategory').value = vehicle.category_id || '';
      document.getElementById('vehicleType').value = vehicle.vehicle_type || '';
      document.getElementById('vehiclePlate').value = vehicle.plate_number;
      document.getElementById('vehicleModel').value = vehicle.model;
      document.getElementById('vehicleYear').value = vehicle.year;
      document.getElementById('vehicleFuelType').value = vehicle.fuel_type;
      document.getElementById('vehicleMileage').value = vehicle.current_mileage;
      document.getElementById('vehicleBranch').value = vehicle.branch_name || '';
      document.getElementById('vehicleLocation').value = vehicle.location || '';
      openModal('vehicleModal');
    }
    function openAddCategoryModal() {
  document.getElementById('categoryModalTitle').textContent = 'Add New Category';
  document.getElementById('categoryId').value = '';
  document.getElementById('categoryName').value = '';
  document.getElementById('categoryType').value = '';
  document.getElementById('categoryDescription').value = '';
  openModal('categoryModal');
}

function openEditCategoryModal(category) {
  document.getElementById('categoryModalTitle').textContent = 'Edit Category';
  document.getElementById('categoryId').value = category.category_id;
  document.getElementById('categoryName').value = category.category_name;
  document.getElementById('categoryType').value = category.type;
  document.getElementById('categoryDescription').value = category.description || '';
  openModal('categoryModal');
}

function deleteCategory(categoryId, categoryType, categoryName) {
  if (confirm('Are you sure you want to delete the category "' + categoryName + '"?')) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="delete_category" value="1">
      <input type="hidden" name="category_id" value="${categoryId}">
      <input type="hidden" name="category_type" value="${categoryType}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
}
   function openTransactionModal(itemId = null, itemName = '') {
    // Reset form first
    const form = document.querySelector('form[method="POST"]:has(input[name="save_transaction"])');
    if (form) form.reset();
    
    if (itemId) {
        document.getElementById('transactionItem').value = itemId;
        // Don't disable - just mark as readonly instead
        document.getElementById('transactionItem').setAttribute('readonly', true);
        document.getElementById('transactionItem').style.backgroundColor = '#f5f5f5';
    } else {
        document.getElementById('transactionItem').removeAttribute('readonly');
        document.getElementById('transactionItem').style.backgroundColor = '';
    }
    
    // Set today's date
    document.getElementById('transactionDate').value = new Date().toISOString().split('T')[0];
    
    openModal('transactionModal');
}
    
    function openMaintenanceModal(vehicleId = null, vehicleName = '') {
    if (vehicleId) {
        document.getElementById('maintenanceVehicle').value = vehicleId;
        // Don't disable - just make it look disabled
        document.getElementById('maintenanceVehicle').style.backgroundColor = '#f5f5f5';
        document.getElementById('maintenanceVehicle').style.pointerEvents = 'none';
    } else {
        document.getElementById('maintenanceVehicle').style.backgroundColor = '';
        document.getElementById('maintenanceVehicle').style.pointerEvents = '';
    }
    
    // Set today's date
    document.querySelector('input[name="maintenance_date"]').value = new Date().toISOString().split('T')[0];
    
    openModal('maintenanceModal');
}
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
      // Set up modal close on outside click
      document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal')) {
          closeModal(e.target.id);
        }
      });
      
      // Set up transaction type change handler
      const transactionType = document.getElementById('transactionType');
      const quantityLabel = document.querySelector('label[for="transactionQuantity"]');
      
      if (transactionType && quantityLabel) {
        transactionType.addEventListener('change', function() {
          const type = this.value;
          if (type === 'adjustment') {
            quantityLabel.textContent = 'New Stock Level *';
          } else {
            quantityLabel.textContent = 'Quantity *';
          }
        });
      }
      
      // Auto-dismiss alerts
      setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
          alert.style.transition = 'opacity 0.3s';
          alert.style.opacity = '0';
          setTimeout(() => alert.remove(), 300);
        });
      }, 5000);
    });
  </script>
</body>
</html>