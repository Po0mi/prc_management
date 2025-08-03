<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();
if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

// Get cart items from session or database
$cartItems = $_SESSION['cart'] ?? [];
$totalAmount = 0;

// Calculate total
foreach ($cartItems as $item) {
    $totalAmount += $item['price'] * $item['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Cart - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/cart.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="main-content">
    <div class="cart-container">
      <h1>Your Shopping Cart</h1>
      
      <?php if (empty($cartItems)): ?>
        <div class="empty-cart">
          <i class="fas fa-shopping-cart"></i>
          <h3>Your cart is empty</h3>
          <p>Browse our merchandise and add items to your cart</p>
          <a href="merchandise.php" class="btn btn-primary">Shop Now</a>
        </div>
      <?php else: ?>
        <div class="cart-items">
          <?php foreach ($cartItems as $id => $item): ?>
          <div class="cart-item" data-id="<?= $id ?>">
            <div class="item-image">
              <img src="../assets/images/merchandise/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
            </div>
            <div class="item-info">
              <h3><?= htmlspecialchars($item['name']) ?></h3>
              <?php if (!empty($item['size'])): ?>
                <p>Size: <?= htmlspecialchars($item['size']) ?></p>
              <?php endif; ?>
              <?php if (!empty($item['color'])): ?>
                <p>Color: <?= htmlspecialchars($item['color']) ?></p>
              <?php endif; ?>
            </div>
            <div class="item-price">
              ₱<?= number_format($item['price'], 2) ?>
            </div>
            <div class="item-quantity">
              <button class="quantity-btn minus" data-id="<?= $id ?>"><i class="fas fa-minus"></i></button>
              <input type="number" class="quantity" value="<?= $item['quantity'] ?>" min="1" max="<?= $item['max_stock'] ?>">
              <button class="quantity-btn plus" data-id="<?= $id ?>"><i class="fas fa-plus"></i></button>
            </div>
            <div class="item-total">
              ₱<?= number_format($item['price'] * $item['quantity'], 2) ?>
            </div>
            <button class="remove-item" data-id="<?= $id ?>">
              <i class="fas fa-trash"></i>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        
        <div class="cart-total">
          <div class="total-details">
            <div class="subtotal">
              <span>Subtotal:</span>
              <span>₱<?= number_format($totalAmount, 2) ?></span>
            </div>
            <div class="shipping">
              <span>Shipping:</span>
              <span>₱0.00</span> <!-- Free shipping for now -->
            </div>
            <div class="total">
              <span>Total:</span>
              <span id="cart-total-amount">₱<?= number_format($totalAmount, 2) ?></span>
            </div>
          </div>
          
          <div class="checkout-actions">
            <a href="merchandise.php" class="continue-shopping">
              <i class="fas fa-arrow-left"></i> Continue Shopping
            </a>
            <button id="checkout-button" class="checkout-btn">
              Proceed to Checkout <i class="fas fa-arrow-right"></i>
            </button>
          </div>
        </div>
        
        <!-- Checkout Modal (hidden by default) -->
        <div id="checkout-modal" class="modal">
          <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2>Checkout</h2>
            <form id="checkout-form">
              <div class="form-group">
                <label for="shipping-address">Shipping Address</label>
                <textarea id="shipping-address" name="shipping_address" required></textarea>
              </div>
              <div class="form-group">
                <label for="contact-number">Contact Number</label>
                <input type="tel" id="contact-number" name="contact_number" required>
              </div>
              <div class="form-group">
                <label>Payment Method</label>
                <div class="payment-options">
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="cod" checked>
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Cash on Delivery</span>
                  </label>
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="gcash">
                    <i class="fas fa-mobile-alt"></i>
                    <span>GCash</span>
                  </label>
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="credit_card">
                    <i class="fas fa-credit-card"></i>
                    <span>Credit Card</span>
                  </label>
                  <label class="payment-option">
                    <input type="radio" name="payment_method" value="bank_transfer">
                    <i class="fas fa-university"></i>
                    <span>Bank Transfer</span>
                  </label>
                </div>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Place Order
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="../js/cart.js"></script>
</body>
</html>