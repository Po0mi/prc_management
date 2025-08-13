<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';


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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];
    if ($category_id) {
        try {
            
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


$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll();


$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search) {
    $stmt = $pdo->prepare("
        SELECT i.*, c.category_name 
        FROM inventory_items i
        LEFT JOIN categories c ON i.category_id = c.category_id
        WHERE MATCH(i.item_name) AGAINST(:search IN BOOLEAN MODE)
        ORDER BY i.expiry_date ASC
    ");
    $stmt->execute([':search' => $search]);
} else {
    $stmt = $pdo->query("
        SELECT i.*, c.category_name 
        FROM inventory_items i
        LEFT JOIN categories c ON i.category_id = c.category_id
        ORDER BY i.expiry_date ASC
    ");
}
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Inventory - PRC Admin</title>
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
  <link rel="stylesheet" href="../assets/manage_inventory.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="inventory-container">
    <div class="page-header">
      <h1>Inventory Management</h1>
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

    <div class="inventory-sections">
      
      <section class="category-management">
        <h2><i class="fas fa-tags"></i> Manage Categories</h2>
        <div class="category-forms">
          
          <form method="POST" class="category-form">
            <input type="hidden" name="add_category" value="1">
            <div class="form-group">
              <label for="category_name">Add New Category</label>
              <div class="input-group">
                <input type="text" id="category_name" name="category_name" required>
                <button type="submit" class="submit-btn small">
                  <i class="fas fa-plus"></i> Add
                </button>
              </div>
            </div>
          </form>
          
          
          <div class="category-list">
            <h3>Existing Categories</h3>
            <?php if (empty($categories)): ?>
              <p>No categories found. Add your first category above.</p>
            <?php else: ?>
              <ul>
                <?php foreach ($categories as $category): ?>
                  <li>
                    <span><?= htmlspecialchars($category['category_name']) ?></span>
                    <form method="POST" onsubmit="return confirm('Delete this category?')">
                      <input type="hidden" name="delete_category" value="1">
                      <input type="hidden" name="category_id" value="<?= $category['category_id'] ?>">
                      <button type="submit" class="delete-btn small">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </form>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </section>

      
      <section class="create-item">
        <h2><i class="fas fa-box-open"></i> Add New Item</h2>
        <form method="POST" class="inventory-form">
          <input type="hidden" name="add_item" value="1">
          
          <div class="form-row">
            <div class="form-group">
              <label for="item_name">Item Name</label>
              <input type="text" id="item_name" name="item_name" required>
            </div>
            
            <div class="form-group">
              <label for="category_id">Category</label>
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
              <label for="quantity">Quantity</label>
              <input type="number" id="quantity" name="quantity" min="0" required>
            </div>
            
            <div class="form-group">
              <label for="expiry_date">Expiry Date</label>
              <input type="date" id="expiry_date" name="expiry_date" required>
            </div>
          </div>
          
          <button type="submit" class="submit-btn">
            <i class="fas fa-save"></i> Add Item
          </button>
        </form>
      </section>

      <!-- Existing Items Section -->
      <section class="existing-items">
        <div class="section-header">
          <h2><i class="fas fa-boxes"></i> Current Inventory</h2>
          <form method="GET" class="search-box">
            <input type="text" name="search" placeholder="Search items..." 
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit"><i class="fas fa-search"></i></button>
            <?php if ($search): ?>
              <a href="manage_inventory.php" class="clear-search">
                <i class="fas fa-times"></i>
              </a>
            <?php endif; ?>
          </form>
        </div>
        
        <?php if (empty($items)): ?>
          <div class="empty-state">
            <i class="fas fa-box-open"></i>
            <h3>No Inventory Items Found</h3>
            <p><?= $search ? 'Try a different search term' : 'Get started by adding your first inventory item' ?></p>
          </div>
        <?php else: ?>
          <div class="inventory-stats">
            <div class="stat-card">
              <div class="stat-icon blue">
                <i class="fas fa-box"></i>
              </div>
              <div class="stat-content">
                <h3>Total Items</h3>
                <p><?= count($items) ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon orange">
                <i class="fas fa-exclamation-triangle"></i>
              </div>
              <div class="stat-content">
                <h3>Expiring Soon</h3>
                <p><?= count(array_filter($items, function($item) {
                  return strtotime($item['expiry_date']) < strtotime('+30 days') && 
                         strtotime($item['expiry_date']) >= strtotime('today');
                })) ?></p>
              </div>
            </div>
            
            <div class="stat-card">
              <div class="stat-icon red">
                <i class="fas fa-skull-crossbones"></i>
              </div>
              <div class="stat-content">
                <h3>Expired</h3>
                <p><?= count(array_filter($items, function($item) {
                  return strtotime($item['expiry_date']) < strtotime('today');
                })) ?></p>
              </div>
            </div>
          </div>
          
          <div class="items-table-container">
            <table class="items-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Item Name</th>
                  <th>Category</th>
                  <th>Quantity</th>
                  <th>Expiry Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): 
                  $expiryClass = '';
                  $expiryDate = strtotime($it['expiry_date']);
                  $today = strtotime('today');
                  $soon = strtotime('+30 days');
                  
                  if ($expiryDate < $today) {
                    $expiryClass = 'expired';
                    $statusText = 'Expired';
                  } elseif ($expiryDate < $soon) {
                    $expiryClass = 'warning';
                    $statusText = 'Expiring Soon';
                  } else {
                    $expiryClass = 'good';
                    $statusText = 'Good';
                  }
                ?>
                  <tr>
                    <td><?= htmlspecialchars($it['item_id']) ?></td>
                    <td>
                      <input type="text" name="item_name" value="<?= htmlspecialchars($it['item_name']) ?>" 
                             form="update-form-<?= $it['item_id'] ?>" required>
                    </td>
                    <td>
                      <select name="category_id" form="update-form-<?= $it['item_id'] ?>" required>
                        <?php foreach ($categories as $category): ?>
                          <option value="<?= $category['category_id'] ?>" <?= $category['category_id'] == $it['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['category_name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td>
                      <input type="number" name="quantity" value="<?= (int)$it['quantity'] ?>" min="0"
                             form="update-form-<?= $it['item_id'] ?>" required>
                    </td>
                    <td>
                      <input type="date" name="expiry_date" value="<?= $it['expiry_date'] ?>"
                             form="update-form-<?= $it['item_id'] ?>" required>
                    </td>
                    <td>
                      <span class="status-badge <?= $expiryClass ?>">
                        <?= $statusText ?>
                      </span>
                    </td>
                    <td class="actions-cell">
                      <form method="POST" id="update-form-<?= $it['item_id'] ?>" class="action-form">
                        <input type="hidden" name="update_item" value="1">
                        <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                        <button type="submit" class="action-btn update-btn">
                          <i class="fas fa-save"></i> Update
                        </button>
                      </form>
                      
                      <form method="POST" class="action-form" 
                            onsubmit="return confirm('Are you sure you want to delete <?= htmlspecialchars($it['item_name']) ?>?')">
                        <input type="hidden" name="delete_item" value="1">
                        <input type="hidden" name="item_id" value="<?= $it['item_id'] ?>">
                        <button type="submit" class="action-btn delete-btn">
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
  <script src="../user/js/general-ui.js?v=<?php echo time(); ?>"></script>
</body>
</html>