<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
ensure_admin();

$pdo = $GLOBALS['pdo'];
$errorMessage = '';
$successMessage = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        // Add new product
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $stock = (int)$_POST['stock'];
        $sizes = isset($_POST['sizes']) ? json_encode($_POST['sizes']) : '[]';
        $colors = isset($_POST['colors']) ? json_encode($_POST['colors']) : '[]';
        
        // Handle image upload
        $imageName = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imageName = uploadImage($_FILES['image']);
        }
        
        $stmt = $pdo->prepare("INSERT INTO merchandise (name, description, price, image, sizes, colors, stock) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $description, $price, $imageName, $sizes, $colors, $stock])) {
            $successMessage = "Product added successfully!";
        } else {
            $errorMessage = "Error adding product.";
        }
    } elseif (isset($_POST['update_product'])) {
        // Update existing product
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $stock = (int)$_POST['stock'];
        $sizes = isset($_POST['sizes']) ? json_encode($_POST['sizes']) : '[]';
        $colors = isset($_POST['colors']) ? json_encode($_POST['colors']) : '[]';
        
        // Handle image upload if new image was provided
        $imageName = $_POST['current_image'];
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Delete old image if it exists
            if ($imageName && file_exists("../assets/images/merchandise/$imageName")) {
                unlink("../assets/images/merchandise/$imageName");
            }
            $imageName = uploadImage($_FILES['image']);
        }
        
        $stmt = $pdo->prepare("UPDATE merchandise SET name = ?, description = ?, price = ?, image = ?, sizes = ?, colors = ?, stock = ? WHERE id = ?");
        if ($stmt->execute([$name, $description, $price, $imageName, $sizes, $colors, $stock, $id])) {
            $successMessage = "Product updated successfully!";
        } else {
            $errorMessage = "Error updating product.";
        }
    } elseif (isset($_POST['delete_product'])) {
        // Delete product
        $id = (int)$_POST['id'];
        
        // First get image name to delete the file
        $stmt = $pdo->prepare("SELECT image FROM merchandise WHERE id = ?");
        $stmt->execute([$id]);
        $imageName = $stmt->fetchColumn();
        
        if ($imageName && file_exists("../assets/images/merchandise/$imageName")) {
            unlink("../assets/images/merchandise/$imageName");
        }
        
        $stmt = $pdo->prepare("DELETE FROM merchandise WHERE id = ?");
        if ($stmt->execute([$id])) {
            $successMessage = "Product deleted successfully!";
        } else {
            $errorMessage = "Error deleting product.";
        }
    }
}

// Handle image uploads
function uploadImage($file) {
    $targetDir = "../assets/images/merchandise/";
    $imageFileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newFileName = uniqid() . '.' . $imageFileType;
    $targetFile = $targetDir . $newFileName;
    
    // Check if image file is a actual image
    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        throw new Exception("File is not an image.");
    }
    
    // Check file size (max 2MB)
    if ($file['size'] > 2000000) {
        throw new Exception("Sorry, your file is too large.");
    }
    
    // Allow certain file formats
    if (!in_array($imageFileType, ['jpg', 'png', 'jpeg', 'gif'])) {
        throw new Exception("Sorry, only JPG, JPEG, PNG & GIF files are allowed.");
    }
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return $newFileName;
    } else {
        throw new Exception("Sorry, there was an error uploading your file.");
    }
}

// Get all products
$products = $pdo->query("SELECT * FROM merchandise ORDER BY created_at DESC")->fetchAll();

// Get order statistics
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$completedOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'")->fetchColumn();
$totalRevenue = $pdo->query("SELECT SUM(total_amount) FROM orders WHERE status = 'delivered'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Merchandise - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/admin_merchandise.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="admin-content">
    <div class="merchandise-admin-container">
      <div class="page-header">
        <h1><i class="fas fa-tshirt"></i> Merchandise Management</h1>
        <p>Manage PRC merchandise products and orders</p>
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

      <div class="merchandise-sections">
        <!-- Stats Cards -->
        <div class="stats-cards">
          <div class="stat-card">
            <div class="stat-icon blue">
              <i class="fas fa-box-open"></i>
            </div>
            <div class="stat-content">
              <h3>Total Products</h3>
              <p><?= count($products) ?></p>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon green">
              <i class="fas fa-shopping-cart"></i>
            </div>
            <div class="stat-content">
              <h3>Total Orders</h3>
              <p><?= $totalOrders ?></p>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon orange">
              <i class="fas fa-clock"></i>
            </div>
            <div class="stat-content">
              <h3>Pending Orders</h3>
              <p><?= $pendingOrders ?></p>
            </div>
          </div>
          
          <div class="stat-card">
            <div class="stat-icon purple">
              <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-content">
              <h3>Total Revenue</h3>
              <p>₱<?= number_format($totalRevenue, 2) ?></p>
            </div>
          </div>
        </div>

        <!-- Add Product Section -->
        <section class="add-product card">
          <h2><i class="fas fa-plus-circle"></i> <?= isset($_GET['edit']) ? 'Edit Product' : 'Add New Product' ?></h2>
          <form method="POST" enctype="multipart/form-data">
            <?php 
            $editingProduct = null;
            if (isset($_GET['edit'])) {
                $productId = (int)$_GET['edit'];
                foreach ($products as $product) {
                    if ($product['id'] == $productId) {
                        $editingProduct = $product;
                        break;
                    }
                }
            }
            ?>
            
            <?php if ($editingProduct): ?>
              <input type="hidden" name="id" value="<?= $editingProduct['id'] ?>">
              <input type="hidden" name="current_image" value="<?= htmlspecialchars($editingProduct['image']) ?>">
              <input type="hidden" name="update_product" value="1">
            <?php else: ?>
              <input type="hidden" name="add_product" value="1">
            <?php endif; ?>
            
            <div class="form-row">
              <div class="form-group">
                <label for="name">Product Name</label>
                <input type="text" id="name" name="name" required 
                       value="<?= $editingProduct ? htmlspecialchars($editingProduct['name']) : '' ?>">
              </div>
              
              <div class="form-group">
                <label for="price">Price</label>
                <input type="number" id="price" name="price" step="0.01" min="0" required 
                       value="<?= $editingProduct ? htmlspecialchars($editingProduct['price']) : '' ?>">
              </div>
            </div>
            
            <div class="form-group">
              <label for="description">Description</label>
              <textarea id="description" name="description" required><?= $editingProduct ? htmlspecialchars($editingProduct['description']) : '' ?></textarea>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="stock">Stock Quantity</label>
                <input type="number" id="stock" name="stock" min="0" required 
                       value="<?= $editingProduct ? htmlspecialchars($editingProduct['stock']) : '0' ?>">
              </div>
              
              <div class="form-group">
                <label for="image">Product Image</label>
                <input type="file" id="image" name="image" accept="image/*" <?= !$editingProduct ? 'required' : '' ?>>
                <?php if ($editingProduct && $editingProduct['image']): ?>
                  <div class="current-image">
                    <small>Current Image:</small>
                    <img src="../assets/images/merchandise/<?= htmlspecialchars($editingProduct['image']) ?>" 
                         alt="<?= htmlspecialchars($editingProduct['name']) ?>" width="100">
                  </div>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label>Sizes (leave empty if not applicable)</label>
                <div class="option-tags" id="size-tags">
                  <?php if ($editingProduct): ?>
                    <?php foreach (json_decode($editingProduct['sizes'], true) as $size): ?>
                      <span class="tag">
                        <?= htmlspecialchars($size) ?>
                        <input type="hidden" name="sizes[]" value="<?= htmlspecialchars($size) ?>">
                        <button type="button" class="remove-tag">&times;</button>
                      </span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="option-input">
                  <input type="text" id="size-input" placeholder="Add size (e.g. S, M, L)">
                  <button type="button" id="add-size" class="btn btn-sm">Add</button>
                </div>
              </div>
              
              <div class="form-group">
                <label>Colors (leave empty if not applicable)</label>
                <div class="option-tags" id="color-tags">
                  <?php if ($editingProduct): ?>
                    <?php foreach (json_decode($editingProduct['colors'], true) as $color): ?>
                      <span class="tag">
                        <?= htmlspecialchars($color) ?>
                        <input type="hidden" name="colors[]" value="<?= htmlspecialchars($color) ?>">
                        <button type="button" class="remove-tag">&times;</button>
                      </span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="option-input">
                  <input type="text" id="color-input" placeholder="Add color (e.g. Red, Blue)">
                  <button type="button" id="add-color" class="btn btn-sm">Add</button>
                </div>
              </div>
            </div>
            
            <div class="form-actions">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= $editingProduct ? 'Update Product' : 'Add Product' ?>
              </button>
              
              <?php if ($editingProduct): ?>
                <a href="admin_merchandise.php" class="btn btn-secondary">
                  <i class="fas fa-times"></i> Cancel
                </a>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <!-- Product List Section -->
        <section class="product-list card">
          <div class="section-header">
            <h2><i class="fas fa-list"></i> All Products</h2>
            <div class="search-box">
              <input type="text" id="product-search" placeholder="Search products...">
              <button type="button"><i class="fas fa-search"></i></button>
            </div>
          </div>
          
          <?php if (empty($products)): ?>
            <div class="empty-state">
              <i class="fas fa-tshirt"></i>
              <h3>No Products Found</h3>
              <p>Add your first product to get started.</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Options</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($products as $product): 
                    $sizes = json_decode($product['sizes'], true);
                    $colors = json_decode($product['colors'], true);
                  ?>
                    <tr>
                      <td>
                        <?php if ($product['image']): ?>
                          <img src="../assets/images/merchandise/<?= htmlspecialchars($product['image']) ?>" 
                               alt="<?= htmlspecialchars($product['name']) ?>" width="50">
                        <?php else: ?>
                          <div class="no-image">No Image</div>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($product['name']) ?></td>
                      <td>₱<?= number_format($product['price'], 2) ?></td>
                      <td><?= $product['stock'] ?></td>
                      <td>
                        <?php if (!empty($sizes)): ?>
                          <div><small><strong>Sizes:</strong> <?= implode(', ', $sizes) ?></small></div>
                        <?php endif; ?>
                        <?php if (!empty($colors)): ?>
                          <div><small><strong>Colors:</strong> <?= implode(', ', $colors) ?></small></div>
                        <?php endif; ?>
                      </td>
                      <td class="actions">
                        <a href="admin_merchandise.php?edit=<?= $product['id'] ?>" class="btn btn-sm btn-edit">
                          <i class="fas fa-edit"></i> Edit
                        </a>
                        <form method="POST" class="inline-form" 
                              onsubmit="return confirm('Are you sure you want to delete this product?')">
                          <input type="hidden" name="delete_product" value="1">
                          <input type="hidden" name="id" value="<?= $product['id'] ?>">
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

        <!-- Order Management Section -->
        <section class="order-management card">
          <h2><i class="fas fa-shopping-cart"></i> Recent Orders</h2>
          
          <?php 
          $orders = $pdo->query("
              SELECT o.*, u.username 
              FROM orders o
              JOIN users u ON o.user_id = u.id
              ORDER BY o.created_at DESC
              LIMIT 10
          ")->fetchAll();
          ?>
          
          <?php if (empty($orders)): ?>
            <div class="empty-state">
              <i class="fas fa-shopping-cart"></i>
              <h3>No Orders Found</h3>
              <p>Orders will appear here when customers make purchases.</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Items</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($orders as $order): 
                    $items = json_decode($order['items'], true);
                    $statusClass = strtolower($order['status']);
                  ?>
                    <tr>
                      <td>#<?= $order['id'] ?></td>
                      <td><?= htmlspecialchars($order['username']) ?></td>
                      <td>₱<?= number_format($order['total_amount'], 2) ?></td>
                      <td>
                        <div class="order-items">
                          <?php foreach ($items as $item): ?>
                            <div class="order-item">
                              <?= $item['quantity'] ?> × <?= htmlspecialchars($item['name']) ?>
                              <?php if (!empty($item['size'])): ?>
                                (Size: <?= htmlspecialchars($item['size']) ?>)
                              <?php endif; ?>
                              <?php if (!empty($item['color'])): ?>
                                (Color: <?= htmlspecialchars($item['color']) ?>)
                              <?php endif; ?>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </td>
                      <td>
                        <span class="status-badge <?= $statusClass ?>">
                          <?= ucfirst($order['status']) ?>
                        </span>
                      </td>
                      <td><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                      <td class="actions">
                        <a href="order_details.php?id=<?= $order['id'] ?>" class="btn btn-sm btn-view">
                          <i class="fas fa-eye"></i> View
                        </a>
                        <form method="POST" action="update_order_status.php" class="inline-form">
                          <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                          <select name="status" class="status-select" onchange="this.form.submit()">
                            <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= $order['status'] === 'processing' ? 'selected' : '' ?>>Processing</option>
                            <option value="shipped" <?= $order['status'] === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                            <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                            <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                          </select>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            
            <div class="view-all">
              <a href="orders.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> View All Orders
              </a>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
    // Add size tag
    document.getElementById('add-size')?.addEventListener('click', function() {
        const input = document.getElementById('size-input');
        const value = input.value.trim();
        
        if (value) {
            const tagsContainer = document.getElementById('size-tags');
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.innerHTML = `
                ${value}
                <input type="hidden" name="sizes[]" value="${value}">
                <button type="button" class="remove-tag">&times;</button>
            `;
            tagsContainer.appendChild(tag);
            input.value = '';
            
            // Add event listener to remove button
            tag.querySelector('.remove-tag').addEventListener('click', function() {
                tag.remove();
            });
        }
    });
    
    // Add color tag
    document.getElementById('add-color')?.addEventListener('click', function() {
        const input = document.getElementById('color-input');
        const value = input.value.trim();
        
        if (value) {
            const tagsContainer = document.getElementById('color-tags');
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.innerHTML = `
                ${value}
                <input type="hidden" name="colors[]" value="${value}">
                <button type="button" class="remove-tag">&times;</button>
            `;
            tagsContainer.appendChild(tag);
            input.value = '';
            
            // Add event listener to remove button
            tag.querySelector('.remove-tag').addEventListener('click', function() {
                tag.remove();
            });
        }
    });
    
    // Add event listeners to existing remove buttons
    document.querySelectorAll('.remove-tag').forEach(button => {
        button.addEventListener('click', function() {
            this.closest('.tag').remove();
        });
    });
    
    // Product search
    const productSearch = document.getElementById('product-search');
    if (productSearch) {
        productSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.data-table tbody tr');
            
            rows.forEach(row => {
                const name = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                if (name.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
    
    // Prevent form submission when pressing enter in search inputs
    document.querySelectorAll('.search-box input').forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    });
});
  </script>
</body>
</html>