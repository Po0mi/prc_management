<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

$pdo = $GLOBALS['pdo'];

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
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`merch_id`),
      KEY `category` (`category`),
      KEY `is_available` (`is_available`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Insert sample merchandise if table is empty
$stmt = $pdo->query("SELECT COUNT(*) FROM merchandise");
if ($stmt->fetchColumn() == 0) {
    $sample_merch = [
        ['PRC T-Shirt - Classic Red', 'Premium quality cotton t-shirt with Philippine Red Cross logo', 'clothing', 450.00, 25],
        ['PRC Polo Shirt - Official', 'Official Philippine Red Cross polo shirt for volunteers', 'clothing', 650.00, 15],
        ['Red Cross Cap', 'Adjustable cap with embroidered Red Cross symbol', 'accessories', 280.00, 30],
        ['First Aid Kit - Basic', 'Complete basic first aid kit for home and office use', 'supplies', 850.00, 12],
        ['PRC Water Bottle', 'Stainless steel water bottle with PRC logo', 'accessories', 320.00, 20],
        ['Red Cross Handbook', 'Comprehensive guide to Red Cross principles and first aid', 'books', 180.00, 18],
        ['PRC Tote Bag', 'Eco-friendly canvas tote bag with Red Cross design', 'accessories', 250.00, 22],
        ['Emergency Blanket', 'Compact thermal emergency blanket', 'supplies', 150.00, 8],
        ['PRC Pin Collection', 'Set of 5 collectible Red Cross pins', 'collectibles', 120.00, 35],
        ['Volunteer Badge Holder', 'Professional badge holder for Red Cross volunteers', 'accessories', 95.00, 40]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO merchandise (name, description, category, price, stock_quantity) VALUES (?, ?, ?, ?, ?)");
    foreach ($sample_merch as $merch) {
        $stmt->execute($merch);
    }
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';

// Build query
$query = "SELECT * FROM merchandise WHERE is_available = 1";
$params = [];

if ($search) {
    $query .= " AND (name LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category_filter) {
    $query .= " AND category = ?";
    $params[] = $category_filter;
}

$query .= " ORDER BY stock_quantity DESC, name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$merchandise = $stmt->fetchAll();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_items,
        SUM(stock_quantity) as total_stock,
        SUM(CASE WHEN stock_quantity > 0 THEN 1 ELSE 0 END) as in_stock_items,
        SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_items
    FROM merchandise 
    WHERE is_available = 1
";
$stats = $pdo->query($stats_query)->fetch();

// Get categories for filter
$categories = [
    'clothing' => 'Clothing',
    'accessories' => 'Accessories',
    'supplies' => 'Supplies',
    'books' => 'Books & Materials',
    'collectibles' => 'Collectibles',
    'other' => 'Other'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PRC Merchandise Store - Philippine Red Cross</title>
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
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/merch.css?v=<?php echo time(); ?>">
     <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php 
  $sidebar_data = [
    'current_page' => 'merch',
    'user_name' => current_username()
  ];
  include 'sidebar.php'; 
  ?>
  
  <div class="header-content">
    <?php include 'header.php'; ?>
  
  <div class="main-container">
    <!-- Page Header -->
    <div class="page-header">
      <div class="header-content">
        <h1>
          <i class="fas fa-store"></i> 
          PRC Merchandise Store
        </h1>
        <p>Support the Philippine Red Cross through official merchandise</p>
      </div>
      <div class="header-stats">
        <div class="stat-item">
          <i class="fas fa-box"></i>
          <span><?= $stats['total_items'] ?> Products</span>
        </div>
      </div>
    </div>

    <!-- Disclaimer Section -->
    <div class="disclaimer">
      <div class="disclaimer-header">
        <i class="fas fa-info-circle"></i>
        <h3>Important Notice</h3>
      </div>
      <div class="disclaimer-content">
        <p><strong>Please Note:</strong> The Philippine Red Cross merchandise store currently operates as a <strong>display-only catalog</strong>. We do not offer online purchasing or delivery services at this time.</p>
        
        <p><strong>To purchase items:</strong></p>
        <ul style="margin: 0.5rem 0; padding-left: 1.5rem;">
          <li>Visit your nearest Philippine Red Cross chapter</li>
          <li>Contact your local PRC office directly</li>
          <li>Inquire about availability during Red Cross events</li>
        </ul>
        
        <p><strong>Stock levels shown are for reference only</strong> and may not reflect real-time availability. Please confirm product availability when visiting in person.</p>
      </div>
    </div>

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
        <div class="stat-icon" style="background: linear-gradient(135deg, #ff6b6b 0%, #ff8e53 100%);">
          <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= $stats['out_of_stock_items'] ?></div>
          <div class="stat-label">Out of Stock</div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #ffd93d 0%, #ff9800 100%);">
          <i class="fas fa-layer-group"></i>
        </div>
        <div class="stat-details">
          <div class="stat-number"><?= number_format($stats['total_stock']) ?></div>
          <div class="stat-label">Total Stock</div>
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
          
          <button type="submit" class="btn btn-secondary">
            <i class="fas fa-filter"></i> Filter
          </button>
        </form>
      </div>
    </div>

    <!-- Merchandise Grid -->
    <div class="merch-grid">
      <?php if (empty($merchandise)): ?>
        <div class="empty-state">
          <i class="fas fa-store-slash"></i>
          <h3>No Products Found</h3>
          <p>Try adjusting your search criteria or browse all categories</p>
        </div>
      <?php else: ?>
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
          <div class="merch-card">
            <div class="merch-image">
              <?php if ($item['image_url']): ?>
                <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
              <?php else: ?>
                <i class="placeholder-icon fas fa-<?= 
                  $item['category'] === 'clothing' ? 'tshirt' : 
                  ($item['category'] === 'accessories' ? 'hat-cowboy' : 
                  ($item['category'] === 'supplies' ? 'first-aid' : 
                  ($item['category'] === 'books' ? 'book' : 
                  ($item['category'] === 'collectibles' ? 'medal' : 'box')))) 
                ?>"></i>
              <?php endif; ?>
              
              <div class="stock-badge <?= $stock_class ?>">
                <?= $stock_text ?>
              </div>
            </div>
            
            <div class="merch-content">
              <div class="merch-category">
                <?= $categories[$item['category']] ?? ucfirst($item['category']) ?>
              </div>
              
              <h3 class="merch-title">
                <?= htmlspecialchars($item['name']) ?>
              </h3>
              
              <?php if ($item['description']): ?>
                <p class="merch-description">
                  <?= htmlspecialchars($item['description']) ?>
                </p>
              <?php endif; ?>
              
              <div class="merch-details">
                <div class="merch-price">
                  ₱<?= number_format($item['price'], 2) ?>
                </div>
                
                <div class="merch-stock">
                  <i class="fas fa-box"></i>
                  <span><?= $item['stock_quantity'] ?> available</span>
                </div>
              </div>
              
              <div class="merch-actions">
                <button class="btn btn-primary" disabled>
                  <i class="fas fa-info-circle"></i>
                  Visit Chapter to Purchase
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Merchandise store functionality
    document.addEventListener('DOMContentLoaded', function() {
      // Auto-submit search form on Enter
      const searchInput = document.querySelector('input[name="search"]');
      if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            this.form.submit();
          }
        });
      }

      // Add hover effects for cards
      const merchCards = document.querySelectorAll('.merch-card');
      merchCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-8px)';
        });
        
        card.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });

      // Handle disabled buttons with tooltip
      const disabledButtons = document.querySelectorAll('.btn:disabled');
      disabledButtons.forEach(button => {
        button.addEventListener('click', function(e) {
          e.preventDefault();
          alert('Please visit your local Philippine Red Cross chapter to purchase this item. Stock levels are for reference only.');
        });
      });

      // Add animation to stats cards
      const statCards = document.querySelectorAll('.stat-card');
      const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
      };

      const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
          }
        });
      }, observerOptions);

      statCards.forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
      });

      // Animate merchandise cards on scroll
      const merchCardsForAnimation = document.querySelectorAll('.merch-card');
      merchCardsForAnimation.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = `opacity 0.6s ease ${index * 0.1}s, transform 0.6s ease ${index * 0.1}s`;
        observer.observe(card);
      });

      // Real-time stock update simulation (for demo purposes)
      if (window.location.search.includes('demo=true')) {
        setInterval(function() {
          const stockElements = document.querySelectorAll('.merch-stock span');
          stockElements.forEach(element => {
            const currentStock = parseInt(element.textContent.match(/\d+/)[0]);
            if (Math.random() < 0.1 && currentStock > 0) { // 10% chance to decrease stock
              const newStock = Math.max(0, currentStock - Math.floor(Math.random() * 2));
              element.textContent = newStock + ' available';
              
              // Update stock badge
              const card = element.closest('.merch-card');
              const badge = card.querySelector('.stock-badge');
              
              if (newStock === 0) {
                badge.className = 'stock-badge out-of-stock';
                badge.textContent = 'Out of Stock';
                card.querySelector('.btn-primary').innerHTML = '<i class="fas fa-times"></i> Sold Out';
              } else if (newStock <= 5) {
                badge.className = 'stock-badge low-stock';
                badge.textContent = 'Low Stock';
              }
            }
          });
        }, 5000); // Update every 5 seconds
      }

      // Enhanced search functionality
      let searchTimeout;
      if (searchInput) {
        searchInput.addEventListener('input', function() {
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            if (this.value.length >= 3 || this.value.length === 0) {
              // Auto-submit for searches with 3+ characters or empty search
              this.form.submit();
            }
          }, 800); // Wait 800ms after user stops typing
        });
      }

      // Category filter animation
      const categorySelect = document.querySelector('select[name="category"]');
      if (categorySelect) {
        categorySelect.addEventListener('change', function() {
          // Add loading state
          const filterBtn = document.querySelector('.filter-bar button[type="submit"]');
          if (filterBtn) {
            filterBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...';
            filterBtn.disabled = true;
          }
        });
      }

      console.log('Merchandise store loaded successfully');
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      // Ctrl/Cmd + K to focus search
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        document.querySelector('input[name="search"]').focus();
      }
      
      // Escape to clear search
      if (e.key === 'Escape') {
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput && searchInput === document.activeElement) {
          searchInput.value = '';
          searchInput.form.submit();
        }
      }
    });
  </script>
    <script src="js/notifications.js?v=<?php echo time(); ?>"></script>
    <script>
console.log('=== PAGE DEBUG ===');
console.log('Current page:', window.location.pathname);
console.log('Badge system loaded:', !!window.simpleBadgeSystem);
console.log('Badge system class defined:', typeof SimpleNotificationBadge !== 'undefined');

// Force initialize if not loaded
if (!window.simpleBadgeSystem && typeof SimpleNotificationBadge !== 'undefined') {
    console.log('Forcing badge system initialization...');
    window.simpleBadgeSystem = new SimpleNotificationBadge();
}

// Test API call
setTimeout(() => {
    if (window.simpleBadgeSystem) {
        console.log('Forcing badge update...');
        window.simpleBadgeSystem.checkForUpdates();
    } else {
        console.log('Badge system still not available');
    }
}, 2000);
</script>
</body>
</html>