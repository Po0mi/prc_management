<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

// Get cart items count from session
$cartCount = count($_SESSION['cart'] ?? []);

// Sample merchandise data - in a real app, this would come from a database
$merchandise = [
    [
        'id' => 1,
        'name' => 'PRC Logo T-Shirt',
        'description' => 'Official Philippine Red Cross cotton t-shirt with embroidered logo',
        'price' => 350.00,
        'image' => 'tshirt.jpg',
        'sizes' => ['S', 'M', 'L', 'XL'],
        'colors' => ['Red', 'White', 'Black'],
        'stock' => 50
    ],
    [
        'id' => 2,
        'name' => 'PRC Pin',
        'description' => 'Enamel pin with the Red Cross emblem',
        'price' => 120.00,
        'image' => 'pin.jpg',
        'stock' => 100
    ],
    [
        'id' => 3,
        'name' => 'PRC Umbrella',
        'description' => 'Compact umbrella with Red Cross branding',
        'price' => 450.00,
        'image' => 'umbrella.jpg',
        'colors' => ['Red', 'Blue'],
        'stock' => 30
    ],
    [
        'id' => 4,
        'name' => 'PRC Tote Bag',
        'description' => 'Eco-friendly canvas tote bag with Red Cross print',
        'price' => 250.00,
        'image' => 'tote.jpg',
        'stock' => 40
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Merchandise - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/merchandise.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="main-content">
    <div class="merchandise-container">
      <div class="merchandise-header">
        <h1>PRC Merchandise</h1>
        <p>Support our mission by purchasing official Philippine Red Cross merchandise</p>
        
        <div class="cart-summary">
          <a href="cart.php" class="cart-link">
            <i class="fas fa-shopping-cart"></i>
            <span id="cart-count"><?= $cartCount ?></span> items
          </a>
        </div>
      </div>

      <div class="merchandise-content">
        <div class="merchandise-grid">
          <?php foreach ($merchandise as $item): ?>
          <div class="merchandise-card">
            <div class="product-image">
              <img src="../assets/images/merchandise/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
            </div>
            
            <div class="product-info">
              <h3><?= htmlspecialchars($item['name']) ?></h3>
              <p class="product-description"><?= htmlspecialchars($item['description']) ?></p>
              <p class="product-price">₱<?= number_format($item['price'], 2) ?></p>
              
              <?php if (isset($item['sizes'])): ?>
              <div class="product-option">
                <label>Size:</label>
                <select class="product-size">
                  <?php foreach ($item['sizes'] as $size): ?>
                  <option value="<?= htmlspecialchars($size) ?>"><?= htmlspecialchars($size) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <?php if (isset($item['colors'])): ?>
              <div class="product-option">
                <label>Color:</label>
                <select class="product-color">
                  <?php foreach ($item['colors'] as $color): ?>
                  <option value="<?= htmlspecialchars($color) ?>"><?= htmlspecialchars($color) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <div class="product-actions">
                <div class="quantity-selector">
                  <button class="quantity-btn minus"><i class="fas fa-minus"></i></button>
                  <input type="number" class="quantity" value="1" min="1" max="<?= $item['stock'] ?>">
                  <button class="quantity-btn plus"><i class="fas fa-plus"></i></button>
                </div>
                
                <button class="add-to-cart" data-id="<?= $item['id'] ?>">
                  <i class="fas fa-cart-plus"></i> Add to Cart
                </button>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="merchandise-info">
          <div class="info-card">
            <i class="fas fa-truck"></i>
            <h3>Fast Delivery</h3>
            <p>We ship nationwide within 3-5 business days after payment confirmation.</p>
          </div>
          
          <div class="info-card">
            <i class="fas fa-shield-alt"></i>
            <h3>Secure Checkout</h3>
            <p>All transactions are protected with industry-standard encryption.</p>
          </div>
          
          <div class="info-card">
            <i class="fas fa-hand-holding-heart"></i>
            <h3>Support Our Mission</h3>
            <p>Proceeds from merchandise sales fund our humanitarian programs.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <script src="js/merchandise.js"></script>
</body>
</html>