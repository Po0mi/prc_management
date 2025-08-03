<?php


require_once __DIR__ . '/../config.php';
ensure_logged_in();
if (current_user_role() !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}

$userId = current_user_id();
$pdo    = $GLOBALS['pdo'];
$successMessage = '';
$errorMessage   = '';
$activeTab = $_POST['donation_type'] ?? 'monetary';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_donation'])) {
    $donor_name  = trim($_POST['donor_name']);
    $donor_email = trim($_POST['donor_email']);
    $donor_phone = trim($_POST['donor_phone'] ?? '');
    $donation_type = $_POST['donation_type'] ?? 'monetary';
    
    if ($donor_name && $donor_email) {
        try {
            $pdo->beginTransaction();
            
     
            $stmt = $pdo->prepare("
                INSERT INTO donors (name, email, phone) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE phone = IF(phone IS NULL OR phone = '', VALUES(phone), phone)
            ");
            $stmt->execute([$donor_name, $donor_email, $donor_phone]);
            $donorId = $pdo->lastInsertId() ?: $pdo->query("SELECT donor_id FROM donors WHERE email = '$donor_email'")->fetchColumn();

            if ($donation_type === 'monetary') {
                $amount = floatval($_POST['amount']);
                $donation_date = $_POST['donation_date'];
                $payment_method = $_POST['payment_method'];
                $message = trim($_POST['message'] ?? '');

                $stmt2 = $pdo->prepare("
                    INSERT INTO donations (donor_id, amount, donation_date, payment_method, message, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt2->execute([$donorId, $amount, $donation_date, $payment_method, $message, $userId]);
                
                $successMessage = "Thank you for your monetary donation! A receipt will be sent to your email.";
            } 
            else { 
                $item_description = trim($_POST['item_description']);
                $quantity = intval($_POST['quantity']);
                $estimated_value = floatval($_POST['estimated_value'] ?? 0);
                $donation_date = $_POST['donation_date'];
                $purpose = trim($_POST['purpose'] ?? '');
                
                $stmt2 = $pdo->prepare("
                    INSERT INTO in_kind_donations 
                    (donor_id, item_description, quantity, estimated_value, donation_date, purpose, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt2->execute([
                    $donorId, 
                    $item_description, 
                    $quantity, 
                    $estimated_value, 
                    $donation_date, 
                    $purpose, 
                    $userId
                ]);
                
                $successMessage = "Thank you for your in-kind donation! We'll contact you to arrange the delivery.";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMessage = "An error occurred: " . $e->getMessage();
        }
    } else {
        $errorMessage = "Please fill all required fields correctly.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Make a Donation - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css">
  <link rel="stylesheet" href="../assets/sidebar.css">
  <link rel="stylesheet" href="../assets/donate.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
  
  <div class="main-content">
    <div class="donation-container">
      <div class="donation-header">
        <h1>Make a Donation</h1>
        <p>Your support helps us save lives. Every donation makes a difference.</p>
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

      <div class="donation-tabs">
        <button class="tab-button <?= $activeTab === 'monetary' ? 'active' : '' ?>" 
                data-tab="monetary">
          <i class="fas fa-money-bill-wave"></i> Monetary
        </button>
        <button class="tab-button <?= $activeTab === 'inkind' ? 'active' : '' ?>" 
                data-tab="inkind">
          <i class="fas fa-box-open"></i> In-Kind
        </button>
      </div>

      <div class="donation-form-container">
        <form method="POST" class="donation-form">
          <input type="hidden" name="donation_type" id="donation_type" value="<?= $activeTab ?>">
          <input type="hidden" name="submit_donation" value="1">
          
          <div class="form-group">
            <label for="donor_name">Full Name *</label>
            <div class="input-with-icon">
              <i class="fas fa-user"></i>
              <input type="text" id="donor_name" name="donor_name" required 
                     value="<?= htmlspecialchars($_POST['donor_name'] ?? '') ?>">
            </div>
          </div>
          
          <div class="form-row">
            <div class="form-group">
              <label for="donor_email">Email Address *</label>
              <div class="input-with-icon">
                <i class="fas fa-envelope"></i>
                <input type="email" id="donor_email" name="donor_email" required
                       value="<?= htmlspecialchars($_POST['donor_email'] ?? '') ?>">
              </div>
            </div>
            
            <div class="form-group">
              <label for="donor_phone">Phone Number</label>
              <div class="input-with-icon">
                <i class="fas fa-phone"></i>
                <input type="tel" id="donor_phone" name="donor_phone"
                       value="<?= htmlspecialchars($_POST['donor_phone'] ?? '') ?>">
              </div>
            </div>
          </div>
          
          <!-- Monetary Donation Fields -->
          <div id="monetary-fields" class="donation-fields" 
               style="<?= $activeTab !== 'monetary' ? 'display:none;' : '' ?>">
            <div class="form-row">
              <div class="form-group">
                <label for="amount">Donation Amount (PHP) *</label>
                <div class="input-with-icon">
                  <i class="fas fa-peso-sign"></i>
                  <input type="number" id="amount" name="amount" min="1" step="0.01" required
                         value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                </div>
              </div>
              
              <div class="form-group">
                <label for="donation_date">Donation Date *</label>
                <div class="input-with-icon">
                  <i class="fas fa-calendar-day"></i>
                  <input type="date" id="donation_date" name="donation_date" 
                         value="<?= htmlspecialchars($_POST['donation_date'] ?? date('Y-m-d')) ?>" required>
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <label for="payment_method">Payment Method *</label>
              <div class="input-with-icon">
                <i class="fas fa-credit-card"></i>
                <select id="payment_method" name="payment_method" required>
                  <option value="">Select payment method</option>
                  <option value="Credit Card" <?= ($_POST['payment_method'] ?? '') === 'Credit Card' ? 'selected' : '' ?>>Credit Card</option>
                  <option value="Bank Transfer" <?= ($_POST['payment_method'] ?? '') === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                  <option value="GCash" <?= ($_POST['payment_method'] ?? '') === 'GCash' ? 'selected' : '' ?>>GCash</option>
                  <option value="PayMaya" <?= ($_POST['payment_method'] ?? '') === 'PayMaya' ? 'selected' : '' ?>>PayMaya</option>
                  <option value="Cash" <?= ($_POST['payment_method'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                </select>
              </div>
            </div>
          </div>
          
          <!-- In-Kind Donation Fields -->
          <div id="inkind-fields" class="donation-fields" 
               style="<?= $activeTab !== 'inkind' ? 'display:none;' : '' ?>">
            <div class="form-group">
              <label for="item_description">Item Description *</label>
              <textarea id="item_description" name="item_description" rows="2" required><?= 
                htmlspecialchars($_POST['item_description'] ?? '') ?></textarea>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="quantity">Quantity *</label>
                <input type="number" id="quantity" name="quantity" min="1" required
                       value="<?= htmlspecialchars($_POST['quantity'] ?? '1') ?>">
              </div>
              
              <div class="form-group">
                <label for="estimated_value">Estimated Value (PHP)</label>
                <div class="input-with-icon">
                  <i class="fas fa-peso-sign"></i>
                  <input type="number" id="estimated_value" name="estimated_value" min="0" step="0.01"
                         value="<?= htmlspecialchars($_POST['estimated_value'] ?? '') ?>">
                </div>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="donation_date">Donation Date *</label>
                <div class="input-with-icon">
                  <i class="fas fa-calendar-day"></i>
                  <input type="date" id="donation_date_inkind" name="donation_date" 
                         value="<?= htmlspecialchars($_POST['donation_date'] ?? date('Y-m-d')) ?>" required>
                </div>
              </div>
              
              <div class="form-group">
                <label for="purpose">Intended Purpose</label>
                <input type="text" id="purpose" name="purpose"
                       value="<?= htmlspecialchars($_POST['purpose'] ?? '') ?>">
              </div>
            </div>
          </div>
          
          <div class="form-group">
            <label for="message">Message (Optional)</label>
            <textarea id="message" name="message" rows="3"><?= 
              htmlspecialchars($_POST['message'] ?? '') ?></textarea>
          </div>
          
          <button type="submit" class="donate-button">
            <i class="fas fa-heart"></i> Submit Donation
          </button>
        </form>
        
        <div class="donation-info">
          <div class="info-card">
            <i class="fas fa-hand-holding-heart"></i>
            <h3>Where Your Donation Goes</h3>
            <p>Your contribution helps fund our life-saving programs, blood banks, and disaster response efforts.</p>
          </div>
          
          <div class="info-card">
            <i class="fas fa-shield-alt"></i>
            <h3>Secure Donation</h3>
            <p>We use industry-standard security measures to protect your information.</p>
          </div>
          
          <div class="info-card">
            <i class="fas fa-receipt"></i>
            <h3>Tax Deductible</h3>
            <p>Donations to PRC are tax-deductible. You'll receive a receipt for your records.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <script src="js/donate.js"></script>
</body>
</html>