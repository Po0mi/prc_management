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

// Only super admin and specific roles can manage merchandise
$allowed_roles = ['super', 'welfare']; // Welfare can manage merchandise as it's related to community support
if (!in_array($admin_role, $allowed_roles)) {
    header('Location: dashboard.php');
    exit;
}

// Create merchandise table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `merchandise` (
      `merch_id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(200) NOT NULL,
      `description` text DEFAULT NULL,
      `category` enum('clothing','accessories','supplies','books','collectibles','other') NOT NULL DEFAULT 'other',
      `price` decimal(10,2) NOT NULL DEFAULT 0.00,
      `stock_quantity` int(11) NOT NULL DEFAULT 0,
      `image_url` varchar(500) DEFAULT NULL,
      `is_available` tinyint(1) NOT NULL DEFAULT 1,
      `created_by` int(11) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`merch_id`),
      KEY `category` (`category`),
      KEY `is_available` (`is_available`),
      KEY `created_by` (`created_by`),
      FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Add created_by column if it doesn't exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM merchandise LIKE 'created_by'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `merchandise` ADD COLUMN `created_by` int(11) DEFAULT NULL");
        $pdo->exec("ALTER TABLE `merchandise` ADD FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL");
    }
} catch (PDOException $e) {
    error_log("Merchandise table migration error: " . $e->getMessage());
}

// Handle Add/Edit Merchandise
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_merch'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $category = $_POST['category'];
    $price = (float)$_POST['price'];
    $stock_quantity = (int)$_POST['stock_quantity'];
    $image_url = trim($_POST['image_url']);
    $is_available = isset($_POST['is_available']) ? 1 : 0;
    $merch_id = isset($_POST['merch_id']) ? (int)$_POST['merch_id'] : 0;
    
    if ($name && $category && $price >= 0 && $stock_quantity >= 0) {
        try {
            if ($merch_id) {
                // Update existing item
                $stmt = $pdo->prepare("
                    UPDATE merchandise 
                    SET name = ?, description = ?, category = ?, price = ?, 
                        stock_quantity = ?, image_url = ?, is_available = ?
                    WHERE merch_id = ?
                ");
                $stmt->execute([$name, $description, $category, $price, 
                               $stock_quantity, $image_url, $is_available, $merch_id]);
                $successMessage = "Merchandise item updated successfully!";
            } else {
                // Add new item
                $stmt = $pdo->prepare("
                    INSERT INTO merchandise 
                    (name, description, category, price, stock_quantity, image_url, is_available, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $category, $price, 
                               $stock_quantity, $image_url, $is_available, $user_id]);
                $successMessage = "New merchandise item added successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Error saving merchandise: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill in all required fields with valid values.";
    }
}

// Handle Delete Merchandise
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_merch'])) {
    $merch_id = (int)$_POST['merch_id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM merchandise WHERE merch_id = ?");
        $stmt->execute([$merch_id]);
        
        if ($stmt->rowCount() > 0) {
            $successMessage = "Merchandise item deleted successfully!";
        } else {
            $errorMessage = "Item not found or already deleted.";
        }
    } catch (PDOException $e) {
        $errorMessage = "Error deleting merchandise: " . $e->getMessage();
    }
}

// Handle Stock Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $merch_id = (int)$_POST['merch_id'];
    $new_stock = (int)$_POST['new_stock'];
    
    if ($new_stock >= 0) {
        try {
            $stmt = $pdo->prepare("UPDATE merchandise SET stock_quantity = ? WHERE merch_id = ?");
            $stmt->execute([$new_stock, $merch_id]);
            $successMessage = "Stock updated successfully!";
        } catch (PDOException $e) {
            $errorMessage = "Error updating stock: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Stock quantity must be 0 or greater.";
    }
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : '';

// Build query
$query = "
    SELECT m.*, u.email as created_by_email 
    FROM merchandise m
    LEFT JOIN users u ON m.created_by = u.user_id
    WHERE 1=1
";
$params = [];

if ($search) {
    $query .= " AND (m.name LIKE ? OR m.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category_filter) {
    $query .= " AND m.category = ?";
    $params[] = $category_filter;
}

if ($stock_filter) {
    switch ($stock_filter) {
        case 'in_stock':
            $query .= " AND m.stock_quantity > 0";
            break;
        case 'low_stock':
            $query .= " AND m.stock_quantity > 0 AND m.stock_quantity <= 5";
            break;
        case 'out_of_stock':
            $query .= " AND m.stock_quantity = 0";
            break;
    }
}

$query .= " ORDER BY m.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$merchandise = $stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_items,
        SUM(stock_quantity) as total_stock,
        SUM(CASE WHEN stock_quantity > 0 THEN 1 ELSE 0 END) as in_stock_items,
        SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_items,
        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= 5 THEN 1 ELSE 0 END) as low_stock_items,
        AVG(price) as avg_price,
        SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) as available_items
    FROM merchandise
";
$stats = $pdo->query($stats_query)->fetch();

// Categories
$categories = [
    'clothing' => 'Clothing',
    'accessories' => 'Accessories',
    'supplies' => 'Supplies',
    'books' => 'Books & Materials',
    'collectibles' => 'Collectibles',
    'other' => 'Other'
];

// Stock filters
$stock_filters = [
    'in_stock' => 'In Stock',
    'low_stock' => 'Low Stock (≤5)',
    'out_of_stock' => 'Out of Stock'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Merchandise Management - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/merch.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
      <div class="header-content">
        <h1>
          <i class="fas fa-store"></i> 
          Merchandise Management
        </h1>
        <p>Manage Philippine Red Cross merchandise inventory and availability</p>
      </div>
      <div class="header-stats">
        <div class="stat-item">
          <i class="fas fa-box"></i>
          <span><?= $stats['total_items'] ?> Products</span>
        </div>
        <div class="stat-item">
          <i class="fas fa-layer-group"></i>
          <span><?= number_format($stats['total_stock']) ?> Total Stock</span>
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

    <!-- Stats Section -->
    <div class="stats-section">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
          <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= $stats['total_items'] ?></div>
          <div class="stat-label">Total Products</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #00c853 0%, #64dd17 100%);">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= $stats['in_stock_items'] ?></div>
          <div class="stat-label">In Stock</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= $stats['low_stock_items'] ?></div>
          <div class="stat-label">Low Stock</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= $stats['out_of_stock_items'] ?></div>
          <div class="stat-label">Out of Stock</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);">
          <i class="fas fa-peso-sign"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number">₱<?= number_format($stats['avg_price'], 0) ?></div>
          <div class="stat-label">Avg. Price</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
          <i class="fas fa-eye"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= $stats['available_items'] ?></div>
          <div class="stat-label">Available</div>
        </div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
      <div class="search-filters">
        <form method="GET" style="display: contents;">
          <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search merchandise..." value="<?= htmlspecialchars($search) ?>">
          </div>
          
          <select name="category" class="filter-select" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $key => $label): ?>
              <option value="<?= $key ?>" <?= $category_filter === $key ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
            <?php endforeach; ?>
          </select>
          
          <select name="stock" class="filter-select" onchange="this.form.submit()">
            <option value="">All Stock Levels</option>
            <?php foreach ($stock_filters as $key => $label): ?>
              <option value="<?= $key ?>" <?= $stock_filter === $key ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      
      <div class="action-buttons">
        <button class="btn btn-primary" onclick="openMerchModal()">
          <i class="fas fa-plus"></i> Add Product
        </button>
      </div>
    </div>

    <!-- Merchandise Management Section -->
    <div class="data-table-container" style="background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);">
      <h3 style="margin: 0 0 1.5rem 0; color: var(--dark); display: flex; align-items: center; gap: 0.5rem;">
        <i class="fas fa-list"></i> Merchandise Inventory
      </h3>
      
      <?php if (empty($merchandise)): ?>
        <div class="empty-state">
          <i class="fas fa-store-slash"></i>
          <h3>No Merchandise Found</h3>
          <p>Add your first merchandise item to get started</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Status</th>
              <th>Created By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($merchandise as $item): ?>
              <?php
                // Determine stock status
                if ($item['stock_quantity'] == 0) {
                  $stock_class = 'out-of-stock';
                  $stock_text = 'Out of Stock';
                } elseif ($item['stock_quantity'] <= 5) {
                  $stock_class = 'low-stock';
                  $stock_text = 'Low Stock';
                } else {
                  $stock_class = 'in-stock';
                  $stock_text = 'In Stock';
                }
              ?>
              <tr>
                <td>
                  <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 50px; height: 50px; border-radius: 8px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                      <?php if ($item['image_url']): ?>
                        <img src="<?= htmlspecialchars($item['image_url']) ?>" 
                             alt="<?= htmlspecialchars($item['name']) ?>" 
                             style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">
                      <?php else: ?>
                        <i class="fas fa-<?= 
                          $item['category'] === 'clothing' ? 'tshirt' : 
                          ($item['category'] === 'accessories' ? 'hat-cowboy' : 
                          ($item['category'] === 'supplies' ? 'first-aid' : 
                          ($item['category'] === 'books' ? 'book' : 
                          ($item['category'] === 'collectibles' ? 'medal' : 'box')))) 
                        ?>" style="color: #6c757d; font-size: 1.2rem;"></i>
                      <?php endif; ?>
                    </div>
                    <div>
                      <strong><?= htmlspecialchars($item['name']) ?></strong>
                      <?php if ($item['description']): ?>
                        <div style="color: #6c757d; font-size: 0.85rem; margin-top: 0.2rem;">
                          <?= htmlspecialchars(substr($item['description'], 0, 60)) ?>
                          <?= strlen($item['description']) > 60 ? '...' : '' ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="category-badge category-<?= $item['category'] ?>">
                    <?= $categories[$item['category']] ?? ucfirst($item['category']) ?>
                  </span>
                </td>
                <td>
                  <strong style="color: var(--prc-red);">₱<?= number_format($item['price'], 2) ?></strong>
                </td>
                <td>
                  <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span class="stock-badge <?= $stock_class ?>">
                      <?= $item['stock_quantity'] ?>
                    </span>
                    <button class="btn-sm btn-edit" onclick="openStockModal(<?= $item['merch_id'] ?>, '<?= htmlspecialchars($item['name']) ?>', <?= $item['stock_quantity'] ?>)" title="Update Stock">
                      <i class="fas fa-edit"></i>
                    </button>
                  </div>
                </td>
                <td>
                  <div style="display: flex; flex-direction: column; gap: 0.2rem;">
                    <span class="status-badge <?= $item['is_available'] ? 'available' : 'unavailable' ?>">
                      <?= $item['is_available'] ? 'Available' : 'Hidden' ?>
                    </span>
                    <small style="color: #6c757d;"><?= $stock_text ?></small>
                  </div>
                </td>
                <td>
                  <?php if ($item['created_by_email']): ?>
                    <small style="color: #6c757d;">
                      <?= htmlspecialchars(explode('@', $item['created_by_email'])[0]) ?>
                    </small>
                  <?php else: ?>
                    <small style="color: #6c757d;">System</small>
                  <?php endif; ?>
                  <br>
                  <small style="color: #adb5bd;">
                    <?= date('M d, Y', strtotime($item['created_at'])) ?>
                  </small>
                </td>
                <td>
                  <div class="admin-actions">
                    <button onclick='openEditMerchModal(<?= json_encode($item) ?>)' class="btn-sm btn-edit" title="Edit Product">
                      <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this merchandise item? This action cannot be undone.')">
                      <input type="hidden" name="delete_merch" value="1">
                      <input type="hidden" name="merch_id" value="<?= $item['merch_id'] ?>">
                      <button type="submit" class="btn-sm btn-delete" title="Delete Product">
                        <i class="fas fa-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add/Edit Merchandise Modal -->
  <div class="modal" id="merchModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="merchModalTitle">Add New Product</h2>
        <button class="close-btn" onclick="closeModal('merchModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST" id="merchForm">
        <input type="hidden" name="save_merch" value="1">
        <input type="hidden" name="merch_id" id="merchId">
        
        <div class="form-group">
          <label>Product Name *</label>
          <input type="text" name="name" id="merchName" required placeholder="e.g., PRC T-Shirt - Classic Red">
        </div>
        
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" id="merchDescription" rows="3" placeholder="Detailed product description..."></textarea>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Category *</label>
            <select name="category" id="merchCategory" required>
              <option value="">Select Category</option>
              <?php foreach ($categories as $key => $label): ?>
                <option value="<?= $key ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="form-group">
            <label>Price (₱) *</label>
            <input type="number" name="price" id="merchPrice" min="0" step="0.01" required placeholder="0.00">
          </div>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label>Initial Stock Quantity *</label>
            <input type="number" name="stock_quantity" id="merchStock" min="0" required placeholder="0">
          </div>
          
          <div class="form-group">
            <label>Image URL</label>
            <input type="url" name="image_url" id="merchImage" placeholder="https://example.com/image.jpg">
          </div>
        </div>
        
        <div class="form-group">
          <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
            <input type="checkbox" name="is_available" id="merchAvailable" checked>
            <span>Make this product visible to users</span>
          </label>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Product
          </button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('merchModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Stock Update Modal -->
  <div class="modal" id="stockModal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Update Stock</h2>
        <button class="close-btn" onclick="closeModal('stockModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      
      <form method="POST">
        <input type="hidden" name="update_stock" value="1">
        <input type="hidden" name="merch_id" id="stockMerchId">
        
        <div class="form-group">
          <label>Product</label>
          <input type="text" id="stockProductName" disabled style="background: #f8f9fa; color: #6c757d;">
        </div>
        
        <div class="form-group">
          <label>Current Stock</label>
          <input type="text" id="currentStock" disabled style="background: #f8f9fa; color: #6c757d;">
        </div>
        
        <div class="form-group">
          <label>New Stock Quantity *</label>
          <input type="number" name="new_stock" id="newStock" min="0" required placeholder="Enter new stock quantity">
          <small style="color: #6c757d; margin-top: 0.5rem; display: block;">
            <i class="fas fa-info-circle"></i> This will replace the current stock quantity
          </small>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Update Stock
          </button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('stockModal')">
            Cancel
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Merchandise Management JavaScript
    
    function openMerchModal() {
      document.getElementById('merchModalTitle').textContent = 'Add New Product';
      document.getElementById('merchId').value = '';
      document.getElementById('merchForm').reset();
      document.getElementById('merchAvailable').checked = true;
      openModal('merchModal');
    }
    
    function openEditMerchModal(merch) {
      document.getElementById('merchModalTitle').textContent = 'Edit Product';
      document.getElementById('merchId').value = merch.merch_id;
      document.getElementById('merchName').value = merch.name || '';
      document.getElementById('merchDescription').value = merch.description || '';
      document.getElementById('merchCategory').value = merch.category || '';
      document.getElementById('merchPrice').value = merch.price || '';
      document.getElementById('merchStock').value = merch.stock_quantity || '';
      document.getElementById('merchImage').value = merch.image_url || '';
      document.getElementById('merchAvailable').checked = merch.is_available == 1;
      openModal('merchModal');
    }
    
    function openStockModal(merchId, productName, currentStock) {
      document.getElementById('stockMerchId').value = merchId;
      document.getElementById('stockProductName').value = productName;
      document.getElementById('currentStock').value = currentStock + ' units';
      document.getElementById('newStock').value = currentStock;
      document.getElementById('newStock').focus();
      openModal('stockModal');
    }
    
    function openModal(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('active');
        
        // Focus on first input
        setTimeout(() => {
          const firstInput = modal.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea');
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
        }, 300);
      }
    }
    
    // Real-time stock updates (WebSocket simulation)
    function simulateStockUpdates() {
      // This would normally connect to a WebSocket server
      // For now, we'll simulate with random updates
      setInterval(function() {
        if (Math.random() < 0.1) { // 10% chance every 10 seconds
          const stockBadges = document.querySelectorAll('.stock-badge');
          if (stockBadges.length > 0) {
            const randomBadge = stockBadges[Math.floor(Math.random() * stockBadges.length)];
            const currentStock = parseInt(randomBadge.textContent);
            
            if (currentStock > 0 && Math.random() < 0.5) {
              const newStock = Math.max(0, currentStock - 1);
              randomBadge.textContent = newStock;
              
              // Update badge class
              if (newStock === 0) {
                randomBadge.className = 'stock-badge out-of-stock';
              } else if (newStock <= 5) {
                randomBadge.className = 'stock-badge low-stock';
              }
              
              // Show notification
              showNotification(`Stock updated: Product now has ${newStock} units remaining`);
            }
          }
        }
      }, 10000); // Check every 10 seconds
    }
    
    function showNotification(message) {
      // Create notification element
      const notification = document.createElement('div');
      notification.className = 'notification';
      notification.innerHTML = `
        <i class="fas fa-info-circle"></i>
        <span>${message}</span>
      `;
      notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #28a745;
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10001;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        animation: slideInRight 0.3s ease-out;
      `;
      
      document.body.appendChild(notification);
      
      // Auto-remove after 5 seconds
      setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease-in forwards';
        setTimeout(() => {
          document.body.removeChild(notification);
        }, 300);
      }, 5000);
    }
    
    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
      @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      
      @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
      }
      
      .category-badge {
        padding: 0.25rem 0.5rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
      }
      
      .category-clothing { background: #e3f2fd; color: #1976d2; }
      .category-accessories { background: #f3e5f5; color: #7b1fa2; }
      .category-supplies { background: #e8f5e8; color: #2e7d32; }
      .category-books { background: #fff3e0; color: #f57c00; }
      .category-collectibles { background: #fce4ec; color: #c2185b; }
      .category-other { background: #f5f5f5; color: #616161; }
      
      .stock-badge {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        color: white;
        display: inline-block;
        min-width: 40px;
        text-align: center;
      }
      
      .stock-badge.in-stock { background: #28a745; }
      .stock-badge.low-stock { background: #ffc107; color: #856404; }
      .stock-badge.out-of-stock { background: #dc3545; }
      
      .status-badge {
          padding: 0.4rem 0.8rem;
          border-radius: 20px;
          font-size: 0.8rem;
          font-weight: 600;
          text-transform: uppercase;
          display: inline-block;
          border: 1px solid transparent;
          width: 100px
      }
      
      .status-badge.available {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
        border-color: #b1dfbb;
      }
      
      .status-badge.unavailable {
        background: #f8d7da;
        color: #721c24;
      }
      
      .data-table-container {
        animation: fadeInUp 0.6s ease-out;
      }
      
      @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }
    `;
    document.head.appendChild(style);
    
    document.addEventListener('DOMContentLoaded', function() {
      // Close modal when clicking outside
      document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
          if (e.target === this) {
            closeModal(this.id);
          }
        });
      });

      // Form validation
      const merchForm = document.getElementById('merchForm');
      if (merchForm) {
        merchForm.addEventListener('submit', function(e) {
          const requiredFields = this.querySelectorAll('[required]');
          let isValid = true;
          
          requiredFields.forEach(field => {
            if (!field.value.trim()) {
              isValid = false;
              field.style.borderColor = '#dc3545';
              field.classList.add('is-invalid');
            } else {
              field.style.borderColor = '#e9ecef';
              field.classList.remove('is-invalid');
            }
          });
          
          if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields');
          }
        });
      }

      // Auto-dismiss alerts
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

      // Initialize stock update simulation
      if (window.location.search.includes('demo=true')) {
        simulateStockUpdates();
      }

      // Enhanced search
      const searchInput = document.querySelector('input[name="search"]');
      if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            if (this.value.length >= 2 || this.value.length === 0) {
              this.form.submit();
            }
          }, 500);
        });
      }

      console.log('Merchandise management loaded successfully');
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      // Escape key to close modals
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => {
          closeModal(modal.id);
        });
      }
      
      // Ctrl/Cmd + N to add new product
      if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openMerchModal();
      }
      
      // Ctrl/Cmd + K to focus search
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        document.querySelector('input[name="search"]').focus();
      }
    });
  </script>
</body>
</html>