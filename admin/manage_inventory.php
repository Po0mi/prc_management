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

// Debug logging to check session values
error_log("SESSION DEBUG: user_id = $user_id, user_role = $user_role, admin_role = $admin_role, email = $user_email");

// Verify admin_role from database as well
try {
    $stmt = $pdo->prepare("SELECT admin_role FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $db_admin_role = $stmt->fetchColumn();
    error_log("DATABASE DEBUG: admin_role from DB = $db_admin_role");
    
    // Use database value if session doesn't match
    if ($db_admin_role && $db_admin_role !== $admin_role) {
        $admin_role = $db_admin_role;
        $_SESSION['admin_role'] = $admin_role;
        error_log("DATABASE DEBUG: Updated admin_role to $admin_role from database");
    }
} catch (PDOException $e) {
    error_log("DATABASE DEBUG: Error fetching admin_role: " . $e->getMessage());
}

// Get active tab from query parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'inventory';

// Define service-specific inventory categories
$serviceCategories = [
    'health' => [
        'Medical Supplies', 'Medications', 'Blood Collection', 
        'Laboratory Supplies', 'Surgical Instruments', 'First Aid'
    ],
    'safety' => [
        'Safety Equipment', 'First Aid', 'Emergency Equipment', 
        'Personal Protective Equipment', 'Training Materials'
    ],
    'welfare' => [
        'Relief Goods', 'Food Supplies', 'Clothing', 
        'Educational Materials', 'Community Supplies'
    ],
    'disaster' => [
        'Emergency Equipment', 'Communication Devices', 'Rescue Tools', 
        'Emergency Shelter', 'Disaster Response', 'Medical Supplies'
    ],
    'youth' => [
        'Training Materials', 'Educational Supplies', 'Sports Equipment', 
        'Activity Materials', 'Leadership Resources'
    ]
];

// Get current admin's service area
$currentService = $admin_role;
if ($currentService === 'super') {
    $currentService = 'all'; // Super admin can see everything
}

// Get or create admin branch
function getOrCreateAdminBranch($pdo, $user_id) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'admin_branches'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `admin_branches` (
              `branch_id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `branch_name` varchar(100) NOT NULL,
              `service_area` enum('health','safety','welfare','disaster','youth','super') DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`branch_id`),
              UNIQUE KEY `user_id` (`user_id`),
              FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
    
    // Check if service_area column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM admin_branches LIKE 'service_area'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `admin_branches` ADD COLUMN `service_area` enum('health','safety','welfare','disaster','youth','super') DEFAULT NULL");
    }
    
    $stmt = $pdo->prepare("SELECT branch_name, service_area FROM admin_branches WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    
    if (!$result) {
        $stmt = $pdo->prepare("SELECT email, admin_role FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $branch_name = $user['admin_role'] ? ucfirst($user['admin_role']) . ' Services Branch' : explode('@', $user['email'])[0] . '_branch';
        $service_area = $user['admin_role'] ?: 'super';
        
        $stmt = $pdo->prepare("INSERT INTO admin_branches (user_id, branch_name, service_area, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $branch_name, $service_area]);
        
        return $branch_name;
    }
    
    // Update service_area if it's NULL
    if (!$result['service_area']) {
        $stmt = $pdo->prepare("SELECT admin_role FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        $stmt = $pdo->prepare("UPDATE admin_branches SET service_area = ? WHERE user_id = ?");
        $stmt->execute([$user['admin_role'] ?: 'super', $user_id]);
    }
    
    return $result['branch_name'];
}

$admin_branch = getOrCreateAdminBranch($pdo, $user_id);

// Update inventory_items table to include admin_id and service_area
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'admin_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `inventory_items` ADD COLUMN `admin_id` int(11) DEFAULT NULL");
        $pdo->exec("ALTER TABLE `inventory_items` ADD FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE");
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM inventory_items LIKE 'service_area'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `inventory_items` ADD COLUMN `service_area` enum('health','safety','welfare','disaster','youth','super') DEFAULT NULL");
    }
    
    // Migrate existing items without service_area
    if ($admin_role !== 'super') {
        $stmt = $pdo->prepare("UPDATE inventory_items SET admin_id = ?, service_area = ? WHERE admin_id IS NULL OR service_area IS NULL");
        $stmt->execute([$user_id, $admin_role]);
    }
} catch (PDOException $e) {
    error_log("Inventory migration error: " . $e->getMessage());
}

// Update categories table to include service_area
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM categories LIKE 'service_area'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `categories` ADD COLUMN `service_area` enum('health','safety','welfare','disaster','youth','super','shared') DEFAULT 'shared'");
    }
    
    // Create service-specific categories if they don't exist
    foreach ($serviceCategories as $service => $cats) {
        foreach ($cats as $catName) {
            $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = ? AND (service_area = ? OR service_area = 'shared')");
            $stmt->execute([$catName, $service]);
            
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO categories (category_name, service_area) VALUES (?, ?)");
                $stmt->execute([$catName, $service]);
            }
        }
    }
} catch (PDOException $e) {
    error_log("Category migration error: " . $e->getMessage());
}

// Remove bank_id column
try {
    $pdo->exec("ALTER TABLE `inventory_items` DROP FOREIGN KEY IF EXISTS `inventory_items_ibfk_2`");
    $pdo->exec("ALTER TABLE `inventory_items` DROP COLUMN IF EXISTS `bank_id`");
} catch (PDOException $e) {
    // Column might not exist, ignore error
}

// Create vehicles table with service_area
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
      `service_area` enum('health','safety','welfare','disaster','youth','super') DEFAULT NULL,
      `branch_name` varchar(100) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`vehicle_id`),
      UNIQUE KEY `plate_number_admin` (`plate_number`, `admin_id`),
      KEY `admin_id` (`admin_id`),
      KEY `status` (`status`),
      KEY `service_area` (`service_area`),
      FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Add service_area to existing vehicles table if missing
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM vehicles LIKE 'service_area'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `service_area` enum('health','safety','welfare','disaster','youth','super') DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log("Vehicle migration error: " . $e->getMessage());
}

// Create vehicle_maintenance table
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
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`maintenance_id`),
      KEY `vehicle_id` (`vehicle_id`),
      KEY `admin_id` (`admin_id`),
      FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`vehicle_id`) ON DELETE CASCADE,
      FOREIGN KEY (`admin_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Handle Category Management (Service-specific)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $category_name = trim($_POST['category_name']);
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    
    if ($category_name) {
        try {
            $service_area = ($admin_role === 'super') ? 'shared' : $admin_role;
            
            if ($category_id) {
                // Check ownership before updating
                $stmt = $pdo->prepare("SELECT service_area FROM categories WHERE category_id = ?");
                $stmt->execute([$category_id]);
                $cat_service = $stmt->fetchColumn();
                
                if ($admin_role === 'super' || $cat_service === $admin_role || $cat_service === 'shared') {
                    $stmt = $pdo->prepare("UPDATE categories SET category_name = ? WHERE category_id = ?");
                    $stmt->execute([$category_name, $category_id]);
                    $successMessage = "Category updated successfully!";
                } else {
                    $errorMessage = "You don't have permission to edit this category.";
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (category_name, service_area) VALUES (?, ?)");
                $stmt->execute([$category_name, $service_area]);
                $successMessage = "Category added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving category: " . $e->getMessage();
        }
    }
}

// Handle Delete Category (Service-specific)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];
    
    try {
        // Check ownership
        $stmt = $pdo->prepare("SELECT service_area FROM categories WHERE category_id = ?");
        $stmt->execute([$category_id]);
        $cat_service = $stmt->fetchColumn();
        
        if ($admin_role === 'super' || $cat_service === $admin_role || $cat_service === 'shared') {
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
        } else {
            $errorMessage = "You don't have permission to delete this category.";
        }
    } catch (PDOException $e) {
        $errorMessage = "Error deleting category: " . $e->getMessage();
    }
}

// Handle Add/Edit Inventory Item (Service-specific)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $quantity = (int)$_POST['quantity'];
    $location = trim($_POST['location']);
    $expiry_date = $_POST['expiry_date'];
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    
    if ($item_name && $category_id) {
        try {
            $service_area = ($admin_role === 'super') ? 'super' : $admin_role;
            
            if ($item_id) {
                // Check ownership before updating - more restrictive logic like events
                $stmt = $pdo->prepare("SELECT service_area, admin_id FROM inventory_items WHERE item_id = ?");
                $stmt->execute([$item_id]);
                $item_data = $stmt->fetch();
                
                if (!$item_data) {
                    $errorMessage = "Item not found.";
                } else {
                    // Allow update if:
                    // 1. They are super admin
                    // 2. They created the item (can update regardless of service)
                    // 3. Item is in their service area AND they created it
                    $canUpdate = false;
                    
                    if ($admin_role === 'super') {
                        $canUpdate = true;
                    } elseif ($item_data['admin_id'] == $user_id) {
                        // Creator can always update their items
                        $canUpdate = true;
                    } else {
                        // Non-creator cannot update items they didn't create
                        $canUpdate = false;
                        $errorMessage = "You can only edit items you created.";
                    }
                    
                    if ($canUpdate) {
                        $stmt = $pdo->prepare("
                            UPDATE inventory_items 
                            SET item_name = ?, category_id = ?, quantity = ?, 
                                location = ?, expiry_date = ?, service_area = ?
                            WHERE item_id = ?
                        ");
                        $stmt->execute([$item_name, $category_id, $quantity, 
                                       $location, $expiry_date, $service_area, $item_id]);
                        $successMessage = "Item updated successfully!";
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_items 
                    (item_name, category_id, quantity, location, expiry_date, admin_id, service_area, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$item_name, $category_id, $quantity, 
                               $location, $expiry_date, $user_id, $service_area]);
                $successMessage = "Item added successfully to " . ucfirst($service_area) . " inventory!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving item: " . $e->getMessage();
        }
    }
}

// Handle Delete Item (Service-specific with creator access)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    
    try {
        if ($admin_role === 'super') {
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE item_id = ?");
            $stmt->execute([$item_id]);
        } else {
            // Only allow deletion if they created it OR (it's in their service area AND they created it)
            // Changed to be more restrictive - must be the creator
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE item_id = ? AND admin_id = ?");
            $stmt->execute([$item_id, $user_id]);
        }
        
        if ($stmt->rowCount() > 0) {
            $successMessage = "Item deleted successfully!";
        } else {
            $errorMessage = "You don't have permission to delete this item or item not found.";
        }
    } catch (PDOException $e) {
        $errorMessage = "Error deleting item: " . $e->getMessage();
    }
}

// Handle Vehicle Operations (Service-specific)
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
            $service_area = ($admin_role === 'super') ? 'super' : $admin_role;
            
            if ($vehicle_id) {
                // Check ownership before updating - more restrictive like events
                $stmt = $pdo->prepare("SELECT service_area, admin_id FROM vehicles WHERE vehicle_id = ?");
                $stmt->execute([$vehicle_id]);
                $vehicle_data = $stmt->fetch();
                
                if (!$vehicle_data) {
                    $errorMessage = "Vehicle not found.";
                } else {
                    // Allow update if:
                    // 1. They are super admin  
                    // 2. They created the vehicle
                    $canUpdate = false;
                    
                    if ($admin_role === 'super') {
                        $canUpdate = true;
                    } elseif ($vehicle_data['admin_id'] == $user_id) {
                        // Creator can always update their vehicles
                        $canUpdate = true;
                    } else {
                        // Non-creator cannot update vehicles they didn't create
                        $canUpdate = false;
                        $errorMessage = "You can only edit vehicles you created.";
                    }
                    
                    if ($canUpdate) {
                        $stmt = $pdo->prepare("
                            UPDATE vehicles 
                            SET vehicle_type = ?, plate_number = ?, model = ?, year = ?, 
                                status = ?, fuel_type = ?, current_mileage = ?, service_area = ?
                            WHERE vehicle_id = ? AND admin_id = ?
                        ");
                        $stmt->execute([$vehicle_type, $plate_number, $model, $year, 
                                       $status, $fuel_type, $current_mileage, $service_area, $vehicle_id, $user_id]);
                        $successMessage = "Vehicle updated successfully!";
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles 
                    (vehicle_type, plate_number, model, year, status, fuel_type, 
                     current_mileage, admin_id, service_area, branch_name, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$vehicle_type, $plate_number, $model, $year, 
                               $status, $fuel_type, $current_mileage, $user_id, $service_area, $admin_branch]);
                $successMessage = "Vehicle added successfully to " . ucfirst($service_area) . " fleet!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving vehicle: " . $e->getMessage();
        }
    }
}

// Handle Delete Vehicle (Service-specific with creator access)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vehicle'])) {
    $vehicle_id = (int)$_POST['vehicle_id'];
    
    try {
        if ($admin_role === 'super') {
            $stmt = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
            $stmt->execute([$vehicle_id]);
        } else {
            // Only allow deletion if they created it
            $stmt = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ? AND admin_id = ?");
            $stmt->execute([$vehicle_id, $user_id]);
        }
        
        if ($stmt->rowCount() > 0) {
            $successMessage = "Vehicle deleted successfully!";
        } else {
            $errorMessage = "You don't have permission to delete this vehicle or vehicle not found.";
        }
    } catch (PDOException $e) {
        $errorMessage = "Error deleting vehicle: " . $e->getMessage();
    }
}

// Handle Add Maintenance (Service-specific)
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

// Get data based on active tab and service area
$categories = [];
$items = [];
$vehicles = [];
$maintenance_records = [];

// Get categories (Service-specific)
try {
    if ($admin_role === 'super') {
        $categories = $pdo->query("SELECT * FROM categories ORDER BY service_area, category_name")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE service_area = ? OR service_area = 'shared' ORDER BY category_name");
        $stmt->execute([$admin_role]);
        $categories = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $categories = [];
}

// Search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Get inventory items (Service-specific with creator access)
try {
    if ($admin_role === 'super') {
        $query = "
            SELECT i.*, c.category_name, c.service_area as category_service,
                   u.email as creator_email,
                   CASE WHEN i.admin_id = ? THEN 1 ELSE 0 END as is_my_item
            FROM inventory_items i
            LEFT JOIN categories c ON i.category_id = c.category_id
            LEFT JOIN users u ON i.admin_id = u.user_id
            WHERE 1=1
        ";
        $params = [$user_id];
    } else {
        $query = "
            SELECT i.*, c.category_name, c.service_area as category_service,
                   u.email as creator_email,
                   CASE WHEN i.admin_id = ? THEN 1 ELSE 0 END as is_my_item
            FROM inventory_items i
            LEFT JOIN categories c ON i.category_id = c.category_id
            LEFT JOIN users u ON i.admin_id = u.user_id
            WHERE (i.service_area = ? OR i.admin_id = ?)
        ";
        $params = [$user_id, $admin_role, $user_id];
        
        // Debug logging
        error_log("INVENTORY DEBUG: User ID: $user_id, Admin Role: $admin_role");
        error_log("INVENTORY DEBUG: Query params: " . implode(', ', $params));
    }
    
    if ($search) {
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
    $items = $stmt->fetchAll();
    
    // Debug logging for non-super users
    if ($admin_role !== 'super') {
        error_log("INVENTORY DEBUG: Found " . count($items) . " items for user $user_id with role $admin_role");
        foreach ($items as $item) {
            error_log("INVENTORY DEBUG: Item ID: {$item['item_id']}, Service: {$item['service_area']}, Admin ID: {$item['admin_id']}, Is Mine: {$item['is_my_item']}");
        }
    }

    // Get stats (Service-specific with creator access)
    if ($admin_role === 'super') {
        $statsWhere = "";
        $statsParams = [];
    } else {
        $statsWhere = "WHERE (service_area = ? OR admin_id = ?)";
        $statsParams = [$admin_role, $user_id];
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items $statsWhere");
    $stmt->execute($statsParams);
    $total_items = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items $statsWhere " . 
                         ($statsWhere ? "AND" : "WHERE") . " expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute($statsParams);
    $expiring_soon = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items $statsWhere " . 
                         ($statsWhere ? "AND" : "WHERE") . " expiry_date < CURDATE()");
    $stmt->execute($statsParams);
    $expired_items = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT SUM(quantity) FROM inventory_items $statsWhere");
    $stmt->execute($statsParams);
    $total_quantity = $stmt->fetchColumn() ?: 0;
    
} catch (PDOException $e) {
    $items = [];
    $total_items = 0;
    $expiring_soon = 0;
    $expired_items = 0;
    $total_quantity = 0;
}

// Get vehicles (Service-specific with creator access)
try {
    if ($admin_role === 'super') {
        $query = "
            SELECT v.*, u.email as creator_email,
                   CASE WHEN v.admin_id = ? THEN 1 ELSE 0 END as is_my_vehicle
            FROM vehicles v
            LEFT JOIN users u ON v.admin_id = u.user_id
            WHERE 1=1
        ";
        $params = [$user_id];
    } else {
        $query = "
            SELECT v.*, u.email as creator_email,
                   CASE WHEN v.admin_id = ? THEN 1 ELSE 0 END as is_my_vehicle
            FROM vehicles v
            LEFT JOIN users u ON v.admin_id = u.user_id
            WHERE (v.service_area = ? OR v.admin_id = ?)
        ";
        $params = [$user_id, $admin_role, $user_id];
        
        // Debug logging
        error_log("VEHICLE DEBUG: User ID: $user_id, Admin Role: $admin_role");
        error_log("VEHICLE DEBUG: Query params: " . implode(', ', $params));
    }
    
    if ($search && $activeTab === 'vehicles') {
        $query .= " AND (plate_number LIKE ? OR model LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $query .= " ORDER BY vehicle_type, plate_number";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
    
    // Debug logging for non-super users
    if ($admin_role !== 'super') {
        error_log("VEHICLE DEBUG: Found " . count($vehicles) . " vehicles for user $user_id with role $admin_role");
        foreach ($vehicles as $vehicle) {
            error_log("VEHICLE DEBUG: Vehicle ID: {$vehicle['vehicle_id']}, Service: {$vehicle['service_area']}, Admin ID: {$vehicle['admin_id']}, Is Mine: {$vehicle['is_my_vehicle']}");
        }
    }
    
    // Get vehicle stats (Service-specific with creator access)
    if ($admin_role === 'super') {
        $statsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                SUM(CASE WHEN status = 'out_of_service' THEN 1 ELSE 0 END) as out_of_service
            FROM vehicles
        ";
        $stmt = $pdo->prepare($statsQuery);
        $stmt->execute();
    } else {
        $statsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                SUM(CASE WHEN status = 'out_of_service' THEN 1 ELSE 0 END) as out_of_service
            FROM vehicles WHERE (service_area = ? OR admin_id = ?)
        ";
        $stmt = $pdo->prepare($statsQuery);
        $stmt->execute([$admin_role, $user_id]);
    }
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

// Get service-specific title
$serviceTitle = [
    'health' => 'Health Services',
    'safety' => 'Safety Services', 
    'welfare' => 'Welfare Services',
    'disaster' => 'Disaster Management',
    'youth' => 'Red Cross Youth',
    'super' => 'All Services'
];

$currentServiceTitle = $serviceTitle[$admin_role] ?? 'Admin';

// Service icons
$serviceIcons = [
    'health' => 'heartbeat',
    'safety' => 'shield-alt', 
    'welfare' => 'hands-helping',
    'disaster' => 'exclamation-triangle',
    'youth' => 'users',
    'super' => 'crown'
];

// Service colors for badges
$serviceColors = [
    'health' => '#dc3545, #ff6b6b',
    'safety' => '#28a745, #20c997', 
    'welfare' => '#17a2b8, #6f42c1',
    'disaster' => '#fd7e14, #ffc107',
    'youth' => '#6f42c1, #e83e8c',
    'super' => '#343a40, #6c757d'
];

$badgeColors = [
    'health' => '#dc3545',
    'safety' => '#28a745', 
    'welfare' => '#17a2b8',
    'disaster' => '#fd7e14',
    'youth' => '#6f42c1',
    'super' => '#6c757d'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $currentServiceTitle ?> - Inventory & Fleet Management - PRC Admin</title>
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
  <style>
    .service-indicator {
      background: linear-gradient(135deg, 
        <?php echo $serviceColors[$admin_role] ?? '#6c757d, #adb5bd'; ?>
      );
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 0.375rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .item-service-badge {
      background: <?php echo $badgeColors[$admin_role] ?? '#6c757d'; ?>;
      color: white;
      padding: 0.25rem 0.5rem;
      border-radius: 0.25rem;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .category-service-indicator {
      font-size: 0.75rem;
      opacity: 0.7;
      margin-left: 0.5rem;
    }
  </style>
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="main-container">
    <div class="page-header">
      <div class="header-content">
        <h1>
          <i class="fas fa-warehouse"></i> 
          <?= $currentServiceTitle ?> - Inventory & Fleet Management
        </h1>
        <p>Service-specific management system for <?= htmlspecialchars($admin_branch) ?></p>
      </div>
      <div class="branch-indicator">
        <div class="service-indicator">
          <i class="fas fa-<?= $serviceIcons[$admin_role] ?? 'building' ?>"></i>
          <span><?= $currentServiceTitle ?></span>
        </div>
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
        <span><?= $admin_role === 'health' ? 'Medical' : ucfirst($admin_role) ?> Inventory</span>
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
            <i class="fas fa-boxes"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $total_items ?></div>
            <div class="stat-label">Total Items</div>
            <?php if ($admin_role !== 'super'): ?>
              <div class="stat-sublabel"><?= $currentServiceTitle ?></div>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
            <i class="fas fa-layer-group"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $total_quantity ?></div>
            <div class="stat-label">Total Quantity</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
            <i class="fas fa-clock"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $expiring_soon ?></div>
            <div class="stat-label">Expiring Soon</div>
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $expired_items ?></div>
            <div class="stat-label">Expired Items</div>
          </div>
        </div>
      </div>

      <!-- Category Manager -->
      <div class="action-bar">
        <div class="search-filters">
          <form method="GET" class="search-form">
            <input type="hidden" name="tab" value="inventory">
            <div class="search-box">
              <i class="fas fa-search"></i>
              <input type="text" name="search" placeholder="Search items..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="category" onchange="this.form.submit()">
              <option value="">All Categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['category_name']) ?>
                  <?php if ($admin_role === 'super' && $cat['service_area'] !== 'shared'): ?>
                    <span class="category-service-indicator">(<?= ucfirst($cat['service_area']) ?>)</span>
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        
        <div class="action-buttons">
          <button class="btn-secondary" onclick="toggleCategoryManager()">
            <i class="fas fa-tags"></i> Manage Categories
          </button>
          <button class="btn-primary" onclick="openAddItemModal()">
            <i class="fas fa-plus"></i> Add Item
          </button>
        </div>
      </div>

      <!-- Category Manager -->
      <div id="categoryManager" style="display: none;" class="category-manager">
        <h3>
          <i class="fas fa-tags"></i> 
          Category Management 
          <?php if ($admin_role !== 'super'): ?>
            <span class="item-service-badge"><?= $currentServiceTitle ?></span>
          <?php endif; ?>
        </h3>
        <form method="POST" class="category-form">
          <input type="hidden" name="save_category" value="1">
          <input type="hidden" name="category_id" id="categoryId">
          <div class="form-row">
            <input type="text" name="category_name" id="categoryName" placeholder="Category Name" required>
            <button type="submit" class="btn-primary">
              <i class="fas fa-save"></i> Save
            </button>
            <button type="button" class="btn-secondary" onclick="resetCategoryForm()">
              <i class="fas fa-times"></i> Clear
            </button>
          </div>
        </form>
        
        <div class="categories-list">
          <?php foreach ($categories as $cat): ?>
            <div class="category-item">
              <span>
                <?= htmlspecialchars($cat['category_name']) ?>
                <?php if ($admin_role === 'super'): ?>
                  <span class="category-service-indicator">[<?= ucfirst($cat['service_area']) ?>]</span>
                <?php endif; ?>
              </span>
              <div class="category-actions">
                <?php if ($admin_role === 'super' || $cat['service_area'] === $admin_role || $cat['service_area'] === 'shared'): ?>
                  <button onclick="editCategory(<?= $cat['category_id'] ?>, '<?= htmlspecialchars($cat['category_name']) ?>')" class="btn-sm btn-edit">
                    <i class="fas fa-edit"></i>
                  </button>
                  <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this category?')">
                    <input type="hidden" name="delete_category" value="1">
                    <input type="hidden" name="category_id" value="<?= $cat['category_id'] ?>">
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

      <!-- Inventory Items Table -->
      <div class="items-section">
        <h3>
          <i class="fas fa-list"></i> 
          <?= $admin_role === 'super' ? 'All Service' : $currentServiceTitle ?> Inventory Items
          <?php if ($admin_role !== 'super'): ?>
            <small style="color: #666; font-weight: normal; margin-left: 1rem;">
              (Including items you created in other services)
            </small>
          <?php endif; ?>
        </h3>
        
        <?php if (empty($items)): ?>
          <div class="empty-state">
            <i class="fas fa-boxes"></i>
            <h3>No Items Found</h3>
            <p>Add your first <?= $admin_role === 'super' ? '' : strtolower($currentServiceTitle) ?> inventory item to get started</p>
            <?php if ($admin_role !== 'super'): ?>
              <small style="color: #666; margin-top: 0.5rem; display: block;">
                You can view items from your service area and items you created in other services
              </small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Item Name</th>
                <th>Category</th>
                <?php if ($admin_role === 'super'): ?>
                  <th>Service</th>
                <?php endif; ?>
                <th>Quantity</th>
                <th>Location</th>
                <th>Expiry Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <?php
                  $today = new DateTime();
                  $expiry = new DateTime($item['expiry_date']);
                  $diff = $today->diff($expiry);
                  
                  if ($expiry < $today) {
                    $status = 'expired';
                    $statusText = 'Expired';
                  } elseif ($diff->days <= 30) {
                    $status = 'expiring';
                    $statusText = 'Expiring Soon';
                  } else {
                    $status = 'good';
                    $statusText = 'Good';
                  }
                ?>
                <tr class="<?= $status ?>">
                  <td><?= htmlspecialchars($item['item_name']) ?></td>
                  <td><?= htmlspecialchars($item['category_name'] ?? 'N/A') ?></td>
                  <?php if ($admin_role === 'super'): ?>
                    <td>
                      <span class="item-service-badge" style="background: <?= $badgeColors[$item['service_area'] ?? 'super'] ?? '#6c757d' ?>">
                        <?= ucfirst($item['service_area'] ?? 'Super') ?>
                      </span>
                    </td>
                  <?php endif; ?>
                  <td><?= $item['quantity'] ?></td>
                  <td><?= htmlspecialchars($item['location']) ?></td>
                  <td><?= date('M d, Y', strtotime($item['expiry_date'])) ?></td>
                  <td>
                    <span class="status-badge <?= $status ?>">
                      <?= $statusText ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($admin_role === 'super' || $item['service_area'] === $admin_role || $item['admin_id'] == $user_id): ?>
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
                    <?php else: ?>
                      <span class="text-muted">View Only</span>
                    <?php endif; ?>
                    <?php if ($item['admin_id'] == $user_id): ?>
                      <small style="display: block; color: #28a745; font-size: 0.7rem; margin-top: 0.2rem;">
                        <i class="fas fa-user"></i> Your Item
                      </small>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Fleet Management Tab -->
    <div class="tab-content <?= $activeTab === 'vehicles' ? 'active' : '' ?>" id="vehicles-tab">
      <!-- Stats Overview -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-details">
            <div class="stat-number"><?= $vehicle_stats['total'] ?></div>
            <div class="stat-label">Total Vehicles</div>
            <?php if ($admin_role !== 'super'): ?>
              <div class="stat-sublabel"><?= $currentServiceTitle ?></div>
            <?php endif; ?>
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
            <p>Add your first <?= $admin_role === 'super' ? '' : strtolower($currentServiceTitle) ?> vehicle to get started</p>
            <?php if ($admin_role !== 'super'): ?>
              <small style="color: #666; margin-top: 0.5rem; display: block;">
                You can view vehicles from your service area and vehicles you created in other services
              </small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($vehicles as $vehicle): ?>
            <div class="vehicle-card <?= $vehicle['status'] ?>">
              <div class="vehicle-header">
                <div class="vehicle-type">
                  <i class="fas fa-<?= $vehicle['vehicle_type'] === 'ambulance' ? 'ambulance' : 'truck' ?>"></i>
                  <?= ucfirst($vehicle['vehicle_type']) ?>
                  <?php if ($admin_role === 'super' && $vehicle['service_area']): ?>
                    <span class="item-service-badge" style="background: <?= $badgeColors[$vehicle['service_area']] ?? '#6c757d' ?>; margin-left: 0.5rem;">
                      <?= ucfirst($vehicle['service_area']) ?>
                    </span>
                  <?php endif; ?>
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
                  <?php if ($admin_role === 'super' || $vehicle['service_area'] === $admin_role || $vehicle['admin_id'] == $user_id): ?>
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
                  <?php else: ?>
                    <a href="?tab=vehicles&vehicle=<?= $vehicle['vehicle_id'] ?>" class="btn-sm btn-info">
                      <i class="fas fa-history"></i>
                    </a>
                    <span class="text-muted">View Only</span>
                  <?php endif; ?>
                  <?php if ($vehicle['admin_id'] == $user_id): ?>
                    <small style="display: block; color: #28a745; font-size: 0.7rem; margin-top: 0.2rem; text-align: center;">
                      <i class="fas fa-user"></i> Your Vehicle
                    </small>
                  <?php endif; ?>
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
        
        <div class="form-row">
          <div class="form-group">
            <label>Category *</label>
            <select name="category_id" id="itemCategory" required>
              <option value="">Select Category</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>">
                  <?= htmlspecialchars($cat['category_name']) ?>
                  <?php if ($admin_role === 'super' && $cat['service_area'] !== 'shared'): ?>
                    (<?= ucfirst($cat['service_area']) ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label>Quantity *</label>
            <input type="number" name="quantity" id="itemQuantity" min="0" required>
          </div>
        </div>
        
        <div class="form-group">
          <label>Storage Location *</label>
          <input type="text" name="location" id="itemLocation" placeholder="e.g., Room A, Shelf 1" required>
        </div>
        
        <div class="form-group">
          <label>Expiry Date *</label>
          <input type="date" name="expiry_date" id="itemExpiry" required>
        </div>
        
        <?php if ($admin_role !== 'super'): ?>
          <div class="form-group">
            <div class="service-indicator">
              <i class="fas fa-<?= $serviceIcons[$admin_role] ?>"></i>
              This item will be added to <?= $currentServiceTitle ?> inventory
            </div>
          </div>
        <?php endif; ?>
        
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
              <?php if ($admin_role === 'health' || $admin_role === 'super'): ?>
                <option value="ambulance">Ambulance</option>
              <?php endif; ?>
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
          <input type="number" name="current_mileage" id="currentMileage" min="0" value="0">
        </div>
        
        <?php if ($admin_role !== 'super'): ?>
          <div class="form-group">
            <div class="service-indicator">
              <i class="fas fa-<?= $serviceIcons[$admin_role] ?>"></i>
              This vehicle will be added to <?= $currentServiceTitle ?> fleet
            </div>
          </div>
        <?php endif; ?>
        
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
              <?php if ($admin_role === 'super' || $vehicle['service_area'] === $admin_role || $vehicle['admin_id'] == $user_id): ?>
                <option value="<?= $vehicle['vehicle_id'] ?>">
                  <?= htmlspecialchars($vehicle['vehicle_type']) ?> - 
                  <?= htmlspecialchars($vehicle['plate_number']) ?>
                  <?php if ($admin_role === 'super'): ?>
                    (<?= ucfirst($vehicle['service_area']) ?><?= $vehicle['admin_id'] == $user_id ? ' - Yours' : '' ?>)
                  <?php elseif ($vehicle['admin_id'] == $user_id): ?>
                    (Your Vehicle)
                  <?php endif; ?>
                </option>
              <?php endif; ?>
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
          <textarea name="description" rows="3" placeholder="Describe the maintenance work performed..."></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Service Provider</label>
            <input type="text" name="service_provider" placeholder="e.g., ABC Auto Shop">
          </div>
          
          <div class="form-group">
            <label>Cost (₱)</label>
            <input type="number" name="cost" min="0" step="0.01" placeholder="0.00">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Mileage at Service</label>
            <input type="number" name="mileage_at_service" min="0" placeholder="Current odometer reading">
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
  <script>
// Service-Specific Inventory & Fleet Management JavaScript

// Service configuration
const currentService = '<?= $admin_role ?>';
const isSuper = currentService === 'super';

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
    document.getElementById('itemModalTitle').textContent = `Add New ${isSuper ? '' : '<?= $currentServiceTitle ?>'} Item`;
    document.getElementById('itemId').value = '';
    document.getElementById('itemName').value = '';
    document.getElementById('itemCategory').value = '';
    document.getElementById('itemQuantity').value = '';
    document.getElementById('itemLocation').value = '';
    document.getElementById('itemExpiry').value = '';
    
    // Set default expiry date to 6 months from now
    const futureDate = new Date();
    futureDate.setMonth(futureDate.getMonth() + 6);
    document.getElementById('itemExpiry').value = futureDate.toISOString().split('T')[0];
    
    // Filter categories based on service
    filterCategoriesForService();
    
    openModal('itemModal');
}

function openEditItemModal(item) {
    document.getElementById('itemModalTitle').textContent = `Edit ${isSuper ? '' : '<?= $currentServiceTitle ?>'} Item`;
    document.getElementById('itemId').value = item.item_id;
    document.getElementById('itemName').value = item.item_name;
    document.getElementById('itemCategory').value = item.category_id || '';
    document.getElementById('itemQuantity').value = item.quantity;
    document.getElementById('itemLocation').value = item.location || '';
    document.getElementById('itemExpiry').value = item.expiry_date;
    
    filterCategoriesForService();
    
    openModal('itemModal');
}

// Filter categories based on current service
function filterCategoriesForService() {
    const categorySelect = document.getElementById('itemCategory');
    const options = categorySelect.querySelectorAll('option');
    
    if (!isSuper) {
        options.forEach(option => {
            if (option.value === '') return; // Keep the "Select Category" option
            
            const text = option.textContent;
            const isServiceSpecific = text.includes('(<?= ucfirst($admin_role) ?>)') || 
                                    !text.includes('(') || 
                                    text.includes('(Shared)');
            
            option.style.display = isServiceSpecific ? 'block' : 'none';
        });
    }
}

// Vehicle Management
function openVehicleModal() {
    console.log('Opening vehicle modal...'); // Debug log
    
    const modal = document.getElementById('vehicleModal');
    if (!modal) {
        console.error('Vehicle modal not found!');
        return;
    }
    
    // Reset form
    document.getElementById('vehicleModalTitle').textContent = `Add New ${isSuper ? '' : '<?= $currentServiceTitle ?>'} Vehicle`;
    document.getElementById('vehicleId').value = '';
    document.getElementById('plateNumber').value = '';
    document.getElementById('vehicleModel').value = '';
    document.getElementById('vehicleYear').value = new Date().getFullYear();
    document.getElementById('vehicleStatus').value = 'operational';
    document.getElementById('fuelType').value = 'gasoline';
    document.getElementById('currentMileage').value = '0';
    
    // Set default vehicle type based on service
    const vehicleTypeSelect = document.getElementById('vehicleType');
    if (vehicleTypeSelect) {
        if ('<?= $admin_role ?>' === 'health') {
            vehicleTypeSelect.value = 'ambulance';
        } else {
            vehicleTypeSelect.value = 'van';
        }
    }
    
    openModal('vehicleModal');
}

function openEditVehicleModal(vehicle) {
    console.log('Opening edit vehicle modal...', vehicle); // Debug log
    
    const modal = document.getElementById('vehicleModal');
    if (!modal) {
        console.error('Vehicle modal not found!');
        return;
    }
    
    // Populate form with vehicle data
    document.getElementById('vehicleModalTitle').textContent = `Edit ${isSuper ? '' : '<?= $currentServiceTitle ?>'} Vehicle`;
    document.getElementById('vehicleId').value = vehicle.vehicle_id || '';
    document.getElementById('vehicleType').value = vehicle.vehicle_type || 'van';
    document.getElementById('plateNumber').value = vehicle.plate_number || '';
    document.getElementById('vehicleModel').value = vehicle.model || '';
    document.getElementById('vehicleYear').value = vehicle.year || new Date().getFullYear();
    document.getElementById('vehicleStatus').value = vehicle.status || 'operational';
    document.getElementById('fuelType').value = vehicle.fuel_type || 'gasoline';
    document.getElementById('currentMileage').value = vehicle.current_mileage || '0';
    
    openModal('vehicleModal');
}

function openMaintenanceModal() {
    // Filter vehicles based on service
    const vehicleSelect = document.querySelector('select[name="vehicle_id"]');
    const options = vehicleSelect.querySelectorAll('option');
    
    if (!isSuper) {
        options.forEach(option => {
            if (option.value === '') return; // Keep the "Select Vehicle" option
            
            const text = option.textContent;
            const isServiceSpecific = !text.includes('(') || text.includes('(<?= ucfirst($admin_role) ?>)');
            
            option.style.display = isServiceSpecific ? 'block' : 'none';
        });
    }
    
    openModal('maintenanceModal');
}

// Modal Management
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
        
        // Add service-specific styling
        if (!isSuper) {
            modal.classList.add('service-modal');
            modal.classList.add(`service-${currentService}`);
        }
        
        // Focus on first input
        setTimeout(() => {
            const firstInput = modal.querySelector('input, select');
            if (firstInput) {
                firstInput.focus();
            }
        }, 100);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.style.display = 'none';
            modal.classList.remove('service-modal');
            modal.classList.remove(`service-${currentService}`);
        }, 300);
    }
}

// Service-specific validation
function validateServiceAccess(action, itemService = null) {
    if (isSuper) return true;
    
    if (itemService && itemService !== currentService && itemService !== 'shared') {
        alert(`You can only ${action} items belonging to <?= $currentServiceTitle ?>.`);
        return false;
    }
    
    return true;
}

// Enhanced form validation with service-specific rules
function validateItemForm(form) {
    const requiredFields = form.querySelectorAll('[required]');
    let valid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            valid = false;
            field.style.borderColor = '#dc3545';
            field.classList.add('is-invalid');
        } else {
            field.style.borderColor = '#e0e0e0';
            field.classList.remove('is-invalid');
        }
    });
    
    // Service-specific validation
    if (!isSuper) {
        const categorySelect = document.getElementById('itemCategory');
        const selectedOption = categorySelect.options[categorySelect.selectedIndex];
        
        if (selectedOption && selectedOption.textContent.includes('(') && 
            !selectedOption.textContent.includes(`(<?= ucfirst($admin_role) ?>)`) &&
            !selectedOption.textContent.includes('(Shared)')) {
            valid = false;
            categorySelect.style.borderColor = '#dc3545';
            alert('Please select a category available for <?= $currentServiceTitle ?>.');
        }
    }
    
    return valid;
}

// Close modal when clicking outside
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing scripts...');
    
    // Modal click outside to close
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
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

    // Enhanced form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (this.querySelector('[name="save_item"]')) {
                if (!validateItemForm(this)) {
                    e.preventDefault();
                }
            } else if (this.querySelector('[name="save_vehicle"]')) {
                console.log('Vehicle form submitting...'); // Debug log
                
                const requiredFields = this.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.classList.add('is-invalid');
                        field.style.borderColor = '#dc3545';
                    } else {
                        field.classList.remove('is-invalid');
                        field.style.borderColor = '#28a745';
                        field.classList.add('is-valid');
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill all required fields');
                    return false;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                }
                
                console.log('Form validation passed, submitting...'); // Debug log
            } else {
                // Standard validation for other forms
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
            }
        });
    });

    // Clear form validation styles on input
    document.querySelectorAll('input, select, textarea').forEach(field => {
        field.addEventListener('input', function() {
            if (this.value.trim()) {
                this.style.borderColor = '#e0e0e0';
                this.classList.remove('is-invalid');
            }
        });
        
        field.addEventListener('change', function() {
            if (this.value.trim()) {
                this.style.borderColor = '#e0e0e0';
                this.classList.remove('is-invalid');
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

    // Service-specific UI enhancements
    if (!isSuper) {
        // Add service-specific class to body for styling
        document.body.classList.add(`service-${currentService}`);
        
        // Highlight service-specific items
        document.querySelectorAll('.item-service-badge').forEach(badge => {
            if (badge.textContent.toLowerCase().includes(currentService.toLowerCase())) {
                badge.style.fontWeight = 'bold';
                badge.style.boxShadow = '0 0 0 2px rgba(255,255,255,0.3)';
            }
        });
    }

    // Add service context to forms
    const serviceContexts = document.querySelectorAll('.service-indicator');
    serviceContexts.forEach(context => {
        context.style.animation = 'fadeIn 0.5s ease-in';
    });
    
    console.log('All scripts initialized successfully');
});

// Service-specific keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Escape key to close modals
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
            closeModal(modal.id);
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
    
    // Ctrl/Cmd + M to add maintenance (service-specific)
    if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
        e.preventDefault();
        const activeTab = document.querySelector('.tab-content.active');
        if (activeTab && activeTab.id === 'vehicles-tab') {
            openMaintenanceModal();
        }
    }
});

// Service-specific tooltips and help text
function initServiceTooltips() {
    const serviceHelp = {
        'health': {
            'inventory': 'Manage medical supplies, medications, and blood collection materials for health services.',
            'vehicles': 'Manage ambulances and medical transport vehicles for emergency response.'
        },
        'safety': {
            'inventory': 'Manage safety equipment, PPE, and emergency response materials.',
            'vehicles': 'Manage rescue vehicles and safety equipment transport.'
        },
        'welfare': {
            'inventory': 'Manage relief goods, food supplies, and community assistance materials.',
            'vehicles': 'Manage distribution vehicles for community welfare programs.'
        },
        'disaster': {
            'inventory': 'Manage emergency equipment, rescue tools, and disaster response supplies.',
            'vehicles': 'Manage emergency response vehicles and mobile command units.'
        },
        'youth': {
            'inventory': 'Manage training materials, educational supplies, and youth program resources.',
            'vehicles': 'Manage vehicles for youth activities and educational programs.'
        }
    };
    
    if (serviceHelp[currentService]) {
        const inventorySection = document.querySelector('#inventory-tab .items-section h3');
        const vehicleSection = document.querySelector('#vehicles-tab .vehicles-grid');
        
        if (inventorySection) {
            inventorySection.title = serviceHelp[currentService].inventory;
        }
        
        if (vehicleSection) {
            vehicleSection.title = serviceHelp[currentService].vehicles;
        }
    }
}

// Initialize service-specific features
window.addEventListener('load', function() {
    initServiceTooltips();
    
    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .service-modal {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: scale(0.9) translateY(-20px); opacity: 0; }
            to { transform: scale(1) translateY(0); opacity: 1; }
        }
        
        .service-${currentService} .btn-primary {
            background: <?= $serviceColors[$admin_role] ?? '#007bff, #0056b3' ?>;
        }
        
        .is-invalid {
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
    `;
    document.head.appendChild(style);
});
</script>
</body>
</html>