<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

// Add debug logging
error_log("=== DONATION FORM DEBUG START ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));

$user_role = get_user_role();
if ($user_role) {
    // If user has an admin role, redirect to admin dashboard
    header("Location: /admin/dashboard.php");
    exit;
}

$userId = current_user_id();
$pdo    = $GLOBALS['pdo'];
$successMessage = '';
$errorMessage   = '';
$activeTab = $_POST['donation_type'] ?? 'monetary';

// Debug: Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Form submitted with POST method");
    
    // Check for the hidden field instead of button name
    if (isset($_POST['submit_donation']) && $_POST['submit_donation'] == '1') {
        error_log("submit_donation field found");
        
        $donor_name  = trim($_POST['donor_name'] ?? '');
        $donor_email = trim($_POST['donor_email'] ?? '');
        $donor_phone = trim($_POST['donor_phone'] ?? '');
        $donation_type = $_POST['donation_type'] ?? 'monetary';
        
        error_log("Extracted data - Name: $donor_name, Email: $donor_email, Phone: $donor_phone, Type: $donation_type");
        
        // Better validation
        $validation_errors = [];
        
        if (empty($donor_name)) {
            $validation_errors[] = "Donor name is required";
        }
        
        if (empty($donor_email)) {
            $validation_errors[] = "Donor email is required";
        } elseif (!filter_var($donor_email, FILTER_VALIDATE_EMAIL)) {
            $validation_errors[] = "Invalid email format";
        }
        
        if (empty($donor_phone)) {
            $validation_errors[] = "Phone number is required";
        }
        
        if (empty($validation_errors)) {
            try {
                $pdo->beginTransaction();
                error_log("Transaction started");
                
                // Insert/Update donor information with better error handling
                $stmt = $pdo->prepare("
                    INSERT INTO donors (name, email, phone) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        phone = CASE WHEN phone IS NULL OR phone = '' THEN VALUES(phone) ELSE phone END,
                        name = VALUES(name)
                ");
                
                if (!$stmt->execute([$donor_name, $donor_email, $donor_phone])) {
                    throw new Exception("Failed to insert/update donor: " . print_r($stmt->errorInfo(), true));
                }
                
                $donorId = $pdo->lastInsertId();
                if (!$donorId) {
                    // Get existing donor ID
                    $stmt = $pdo->prepare("SELECT donor_id FROM donors WHERE email = ?");
                    $stmt->execute([$donor_email]);
                    $donorId = $stmt->fetchColumn();
                    
                    if (!$donorId) {
                        throw new Exception("Could not retrieve donor ID");
                    }
                }
                
                error_log("Donor ID: $donorId");

                if ($donation_type === 'monetary') {
                    error_log("Processing monetary donation");
                    
                    $amount = floatval($_POST['amount'] ?? 0);
                    $donation_date = $_POST['donation_date'] ?? '';
                    $payment_method = $_POST['payment_method'] ?? '';
                    $message = trim($_POST['message'] ?? '');
                    $payment_receipt = '';
                    
                    // Validation for monetary donation
                    if ($amount <= 0) {
                        throw new Exception("Invalid donation amount");
                    }
                    
                    if (empty($donation_date)) {
                        throw new Exception("Donation date is required");
                    }
                    
                    if (empty($payment_method)) {
                        throw new Exception("Payment method is required");
                    }

                    // Handle payment receipt upload
                    if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
                        error_log("Processing file upload");
                        
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                        $fileType = $_FILES['payment_receipt']['type'];
                        
                        if (in_array($fileType, $allowedTypes)) {
                            $userFolder = __DIR__ . "/../uploads/user_" . $userId;
                            if (!file_exists($userFolder)) {
                                mkdir($userFolder, 0755, true);
                            }
                            
                            $fileExtension = pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION);
                            $fileName = 'receipt_' . time() . '.' . $fileExtension;
                            $filePath = $userFolder . '/' . $fileName;
                            
                            if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $filePath)) {
                                $payment_receipt = 'uploads/user_' . $userId . '/' . $fileName;
                                error_log("File uploaded successfully: $payment_receipt");
                            } else {
                                error_log("File upload failed");
                            }
                        } else {
                            error_log("Invalid file type: $fileType");
                        }
                    }

                    $stmt2 = $pdo->prepare("
                        INSERT INTO donations (donor_id, amount, donation_date, payment_method, message, recorded_by, payment_receipt, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    
                    if (!$stmt2->execute([$donorId, $amount, $donation_date, $payment_method, $message, $userId, $payment_receipt])) {
                        throw new Exception("Failed to insert donation: " . print_r($stmt2->errorInfo(), true));
                    }
                    
                    $successMessage = "Thank you for your monetary donation! Your donation is pending approval and a receipt will be sent to your email once processed.";
                    error_log("Monetary donation successful");
                    
                } elseif ($donation_type === 'blood') {
                    error_log("Processing blood donation");
                    
                    $blood_type = trim($_POST['blood_type'] ?? '');
                    $donation_date = $_POST['donation_date'] ?? '';
                    $donation_location = trim($_POST['donation_location'] ?? '');
                    $medical_history = trim($_POST['medical_history'] ?? '');
                    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
                    $last_donation_date = !empty($_POST['last_donation_date']) ? $_POST['last_donation_date'] : null;
                    
                    // Validation for blood donation
                    if (empty($blood_type)) {
                        throw new Exception("Blood type is required");
                    }
                    
                    if (empty($donation_date)) {
                        throw new Exception("Donation date is required");
                    }
                    
                    if (empty($emergency_contact)) {
                        throw new Exception("Emergency contact is required");
                    }
                    
                    $stmt2 = $pdo->prepare("
                        INSERT INTO blood_donations 
                        (donor_id, blood_type, donation_date, donation_location, medical_history, emergency_contact, last_donation_date, recorded_by, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                    ");
                    
                    if (!$stmt2->execute([
                        $donorId, 
                        $blood_type, 
                        $donation_date, 
                        $donation_location, 
                        $medical_history,
                        $emergency_contact,
                        $last_donation_date,
                        $userId
                    ])) {
                        throw new Exception("Failed to insert blood donation: " . print_r($stmt2->errorInfo(), true));
                    }
                    
                    $successMessage = "Thank you for your blood donation appointment! We'll contact you to confirm the schedule and provide pre-donation instructions.";
                    error_log("Blood donation successful");
                    
                } else { // in-kind donation
                    error_log("Processing in-kind donation");
                    
                    $item_description = trim($_POST['item_description'] ?? '');
                    $quantity = intval($_POST['quantity'] ?? 0);
                    $estimated_value = floatval($_POST['estimated_value'] ?? 0);
                    $donation_date = $_POST['donation_date'] ?? '';
                    $purpose = trim($_POST['purpose'] ?? '');
                    
                    // Validation for in-kind donation
                    if (empty($item_description)) {
                        throw new Exception("Item description is required");
                    }
                    
                    if ($quantity <= 0) {
                        throw new Exception("Quantity must be greater than 0");
                    }
                    
                    if (empty($donation_date)) {
                        throw new Exception("Donation date is required");
                    }
                    
                    $stmt2 = $pdo->prepare("
                        INSERT INTO in_kind_donations 
                        (donor_id, item_description, quantity, estimated_value, donation_date, purpose, recorded_by, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    
                    if (!$stmt2->execute([
                        $donorId, 
                        $item_description, 
                        $quantity, 
                        $estimated_value, 
                        $donation_date, 
                        $purpose, 
                        $userId
                    ])) {
                        throw new Exception("Failed to insert in-kind donation: " . print_r($stmt2->errorInfo(), true));
                    }
                    
                    $successMessage = "Thank you for your in-kind donation! We'll contact you to arrange the delivery and processing.";
                    error_log("In-kind donation successful");
                }
                
                $pdo->commit();
                error_log("Transaction committed successfully");
                
                // Clear POST data to prevent resubmission
                $_POST = [];
                $activeTab = 'monetary'; // Reset to default tab
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMessage = "An error occurred: " . $e->getMessage();
                error_log("Transaction failed: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        } else {
            $errorMessage = "Please fix the following errors: " . implode(", ", $validation_errors);
            error_log("Validation errors: " . implode(", ", $validation_errors));
        }
    } else {
        error_log("submit_donation field not found in POST data");
    }
} else {
    error_log("Not a POST request");
}

error_log("=== DONATION FORM DEBUG END ===");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Make a Donation - PRC Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/sidebar.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/donate.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/header.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="../assets/dashboard.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'sidebar.php'; ?>
    <div class="header-content">
    <?php include 'header.php'; ?>
  
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
        <button class="tab-button <?= $activeTab === 'blood' ? 'active' : '' ?>" 
                data-tab="blood">
          <i class="fas fa-tint"></i> Blood Donation
        </button>
        <button class="tab-button <?= $activeTab === 'inkind' ? 'active' : '' ?>" 
                data-tab="inkind">
          <i class="fas fa-box-open"></i> In-Kind
        </button>
      </div>

      <div class="donation-form-container">
        <form method="POST" class="donation-form" enctype="multipart/form-data">
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
              <label for="donor_phone">Phone Number *</label>
              <div class="input-with-icon">
                <i class="fas fa-phone"></i>
                <input type="tel" id="donor_phone" name="donor_phone" required
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
                  <input type="number" id="amount" name="amount" min="1" step="0.01" 
                         class="monetary-required"
                         value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                </div>
              </div>
              
              <div class="form-group">
                <label for="donation_date_monetary">Donation Date *</label>
                <div class="input-with-icon">
                  <i class="fas fa-calendar-day"></i>
                  <input type="date" id="donation_date_monetary" name="donation_date" 
                         class="monetary-required"
                         value="<?= htmlspecialchars($_POST['donation_date'] ?? date('Y-m-d')) ?>">
                </div>
              </div>
            </div>
            
            <!-- Enhanced Payment Section -->
            <div class="payment-section">
              <div class="payment-header">
                <i class="fas fa-credit-card"></i>
                <h3>Payment Method *</h3>
              </div>

              <!-- Payment Methods -->
              <div class="payment-methods">
                <div class="payment-options">
                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="bank_transfer" id="bank_transfer" class="monetary-required">
                    <label for="bank_transfer" class="payment-card">
                      <div class="payment-icon bank">
                        <i class="fas fa-university"></i>
                      </div>
                      <div class="payment-details">
                        <div class="payment-name">Bank Transfer</div>
                        <div class="payment-description">Transfer to PRC official bank account</div>
                      </div>
                      <div class="payment-status"></div>
                    </label>
                  </div>

                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="gcash" id="gcash">
                    <label for="gcash" class="payment-card">
                      <div class="payment-icon gcash">
                        <i class="fas fa-mobile-alt"></i>
                      </div>
                      <div class="payment-details">
                        <div class="payment-name">GCash</div>
                        <div class="payment-description">Send money via GCash</div>
                      </div>
                      <div class="payment-status"></div>
                    </label>
                  </div>

                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="paymaya" id="paymaya">
                    <label for="paymaya" class="payment-card">
                      <div class="payment-icon paymaya">
                        <i class="fas fa-mobile-alt"></i>
                      </div>
                      <div class="payment-details">
                        <div class="payment-name">PayMaya</div>
                        <div class="payment-description">Send money via PayMaya</div>
                      </div>
                      <div class="payment-status"></div>
                    </label>
                  </div>

                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="credit_card" id="credit_card">
                    <label for="credit_card" class="payment-card">
                      <div class="payment-icon card">
                        <i class="fas fa-credit-card"></i>
                      </div>
                      <div class="payment-details">
                        <div class="payment-name">Credit Card</div>
                        <div class="payment-description">Pay with Visa/Mastercard</div>
                      </div>
                      <div class="payment-status"></div>
                    </label>
                  </div>

                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="check" id="check">
                    <label for="check" class="payment-card">
                      <div class="payment-icon check">
                        <i class="fas fa-money-check"></i>
                      </div>
                      <div class="payment-details">
                        <div class="payment-name">Check Payment</div>
                        <div class="payment-description">Pay via company check</div>
                      </div>
                      <div class="payment-status"></div>
                    </label>
                  </div>

                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="cash" id="cash">
                    <label for="cash" class="payment-card">
                      <div class="payment-icon cash">
                        <i class="fas fa-money-bill-wave"></i>
                      </div>
                      <div class="payment-details">
                        <div class="payment-name">Cash Payment</div>
                        <div class="payment-description">Pay at PRC office</div>
                      </div>
                      <div class="payment-status"></div>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Payment Receipt Upload -->
              <div class="receipt-upload" id="receiptUpload" style="display: none;">
                <input type="file" name="payment_receipt" id="payment_receipt" accept=".jpg,.jpeg,.png,.pdf">
                <div class="receipt-upload-content">
                  <i class="fas fa-receipt"></i>
                  <div class="upload-text">Upload Payment Receipt</div>
                  <div class="upload-note">Upload a clear photo or scan of your payment receipt/screenshot</div>
                  <div class="upload-note">Accepted formats: JPG, PNG, PDF (Max 5MB)</div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Blood Donation Fields -->
          <div id="blood-fields" class="donation-fields" 
               style="<?= $activeTab !== 'blood' ? 'display:none;' : '' ?>">
            <div class="form-row">
              <div class="form-group">
                <label for="blood_type">Blood Type *</label>
                <div class="input-with-icon">
                  <i class="fas fa-tint"></i>
                  <select id="blood_type" name="blood_type" class="blood-required">
                    <option value="">Select your blood type</option>
                    <option value="A+">A+</option>
                    <option value="A-">A-</option>
                    <option value="B+">B+</option>
                    <option value="B-">B-</option>
                    <option value="AB+">AB+</option>
                    <option value="AB-">AB-</option>
                    <option value="O+">O+</option>
                    <option value="O-">O-</option>
                    <option value="Unknown">Unknown</option>
                  </select>
                </div>
              </div>
              
              <div class="form-group">
                <label for="donation_date_blood">Preferred Donation Date *</label>
                <div class="input-with-icon">
                  <i class="fas fa-calendar-day"></i>
                  <input type="date" id="donation_date_blood" name="donation_date" 
                         class="blood-required"
                         value="<?= htmlspecialchars($_POST['donation_date'] ?? date('Y-m-d')) ?>">
                </div>
              </div>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="donation_location">Preferred Location</label>
                <div class="input-with-icon">
                  <i class="fas fa-map-marker-alt"></i>
                  <select id="donation_location" name="donation_location">
                    <option value="">Select preferred location</option>
                    <option value="PRC Main Office">PRC Main Office</option>
                    <option value="Mobile Blood Drive">Mobile Blood Drive</option>
                    <option value="Hospital Partner">Hospital Partner</option>
                    <option value="Community Center">Community Center</option>
                  </select>
                </div>
              </div>
              
              <div class="form-group">
                <label for="last_donation_date">Last Blood Donation Date</label>
                <div class="input-with-icon">
                  <i class="fas fa-history"></i>
                  <input type="date" id="last_donation_date" name="last_donation_date">
                </div>
              </div>
            </div>
            
            <div class="form-group">
              <label for="emergency_contact">Emergency Contact *</label>
              <div class="input-with-icon">
                <i class="fas fa-phone-alt"></i>
                <input type="tel" id="emergency_contact" name="emergency_contact" 
                       class="blood-required"
                       placeholder="Emergency contact number">
              </div>
            </div>
            
            <div class="form-group">
              <label for="medical_history">Medical History & Notes</label>
              <textarea id="medical_history" name="medical_history" rows="3" 
                        placeholder="Please mention any medications, allergies, or medical conditions..."></textarea>
            </div>
          </div>
          
          <!-- In-Kind Donation Fields -->
          <div id="inkind-fields" class="donation-fields" 
               style="<?= $activeTab !== 'inkind' ? 'display:none;' : '' ?>">
            <div class="form-group">
              <label for="item_description">Item Description *</label>
              <textarea id="item_description" name="item_description" rows="2" 
                        class="inkind-required"><?= 
                htmlspecialchars($_POST['item_description'] ?? '') ?></textarea>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="quantity">Quantity *</label>
                <input type="number" id="quantity" name="quantity" min="1" 
                       class="inkind-required"
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
                <label for="donation_date_inkind">Donation Date *</label>
                <div class="input-with-icon">
                  <i class="fas fa-calendar-day"></i>
                  <input type="date" id="donation_date_inkind" name="donation_date" 
                         class="inkind-required"
                         value="<?= htmlspecialchars($_POST['donation_date'] ?? date('Y-m-d')) ?>">
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
          
          <!-- Fixed submit button - removed the name attribute since we have hidden field -->
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
            <i class="fas fa-tint"></i>
            <h3>Blood Donation Benefits</h3>
            <p>One blood donation can save up to three lives. Regular donation helps maintain emergency blood supplies.</p>
          </div>
          
          <div class="info-card">
            <i class="fas fa-shield-alt"></i>
            <h3>Secure & Safe</h3>
            <p>We use industry-standard security measures and follow strict medical protocols for all donations.</p>
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
  
 
  <script>
// Simplified and fixed JavaScript for donation form
document.addEventListener('DOMContentLoaded', function() {
  console.log('Donation form initialized');
  
  // Cache DOM elements
  const tabButtons = document.querySelectorAll('.tab-button');
  const donationFields = document.querySelectorAll('.donation-fields');
  const donationTypeInput = document.getElementById('donation_type');
  const donationForm = document.querySelector('.donation-form');
  
  // Verify critical elements exist
  if (!donationForm) {
    console.error('Donation form not found!');
    return;
  }
  
  console.log(`Found ${tabButtons.length} tab buttons, ${donationFields.length} donation fields`);
  
  // Tab switching functionality
  tabButtons.forEach(button => {
    button.addEventListener('click', function(e) {
      e.preventDefault();
      const targetTab = this.getAttribute('data-tab');
      console.log('Switching to tab:', targetTab);
      
      // Remove active class from all buttons and hide all fields
      tabButtons.forEach(btn => btn.classList.remove('active'));
      donationFields.forEach(field => field.style.display = 'none');
      
      // Add active class to clicked button and show corresponding field
      this.classList.add('active');
      const targetField = document.getElementById(targetTab + '-fields');
      if (targetField) {
        targetField.style.display = 'block';
      }
      
      // Update hidden input
      if (donationTypeInput) {
        donationTypeInput.value = targetTab;
        console.log('Donation type updated to:', targetTab);
      }
      
      // Handle payment method requirements
      handlePaymentMethodRequirements(targetTab);
      
      // Update required attributes
      updateRequiredFields(targetTab);
    });
  });
  
  // Initialize payment method handling
  initializePaymentMethods();
  
  // Form submission handling
  donationForm.addEventListener('submit', function(e) {
    console.log('Form submission attempted');
    console.log('Form data check:');
    
    const formData = new FormData(this);
    for (let [key, value] of formData.entries()) {
      console.log(`  ${key}:`, value);
    }
    
    // Basic validation
    const currentTab = donationTypeInput.value;
    console.log('Current tab for validation:', currentTab);
    
    // Allow form to submit naturally
    return true;
  });
  
  // Auto-hide alerts
  setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
      if (alert) {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => {
          if (alert.parentNode) {
            alert.remove();
          }
        }, 300);
      }
    });
  }, 8000);
});

function handlePaymentMethodRequirements(donationType) {
  console.log('Handling payment requirements for:', donationType);
  
  const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
  const receiptUpload = document.getElementById('receiptUpload');
  
  if (donationType === 'monetary') {
    paymentMethods.forEach(method => {
      method.addEventListener('change', updatePaymentReceipt);
    });
    updatePaymentReceipt(); // Check current selection
  } else {
    paymentMethods.forEach(method => {
      method.checked = false;
    });
    if (receiptUpload) receiptUpload.style.display = 'none';
  }
}

function initializePaymentMethods() {
  console.log('Initializing payment methods');
  
  const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
  paymentMethods.forEach(method => {
    method.addEventListener('change', updatePaymentReceipt);
  });
  
  // File upload handling
  const paymentReceipt = document.getElementById('payment_receipt');
  if (paymentReceipt) {
    paymentReceipt.addEventListener('change', function() {
      handleFileUpload(this);
    });
  }
}

function updatePaymentReceipt() {
  const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
  const receiptUpload = document.getElementById('receiptUpload');
  
  if (selectedMethod) {
    console.log('Payment method selected:', selectedMethod.value);
    
    if (selectedMethod.value !== 'cash') {
      if (receiptUpload) {
        receiptUpload.style.display = 'block';
      }
    } else {
      if (receiptUpload) {
        receiptUpload.style.display = 'none';
      }
    }
  }
}

function handleFileUpload(input) {
  const file = input.files[0];
  const container = input.closest('.receipt-upload');
  const uploadText = container ? container.querySelector('.upload-text') : null;
  
  if (!container || !uploadText) return;
  
  if (file) {
    console.log('File selected:', file.name);
    
    // Check file size (5MB limit)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
      alert('File size too large. Maximum allowed: 5MB');
      input.value = '';
      return;
    }
    
    // Check file type
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    if (!allowedTypes.includes(file.type)) {
      alert('Invalid file type. Please upload JPG, PNG, or PDF files only.');
      input.value = '';
      return;
    }
    
    container.classList.add('has-file');
    uploadText.textContent = `Selected: ${file.name}`;
  } else {
    container.classList.remove('has-file');
    uploadText.textContent = 'Upload Payment Receipt';
  }
}

function updateRequiredFields(activeTab) {
  // Reset all required fields first
  document.querySelectorAll('input[required], select[required]').forEach(field => {
    if (!['donor_name', 'donor_email', 'donor_phone'].includes(field.name)) {
      field.required = false;
    }
  });
  
  // Set required fields based on active tab
  if (activeTab === 'monetary') {
    const monetaryRequiredFields = ['amount', 'donation_date', 'payment_method'];
    monetaryRequiredFields.forEach(fieldName => {
      const field = document.querySelector(`[name="${fieldName}"]`);
      if (field) field.required = true;
    });
  } else if (activeTab === 'blood') {
    const bloodRequiredFields = ['blood_type', 'donation_date', 'emergency_contact'];
    bloodRequiredFields.forEach(fieldName => {
      const field = document.querySelector(`[name="${fieldName}"]`);
      if (field) field.required = true;
    });
  } else if (activeTab === 'inkind') {
    const inkindRequiredFields = ['item_description', 'quantity', 'donation_date'];
    inkindRequiredFields.forEach(fieldName => {
      const field = document.querySelector(`[name="${fieldName}"]`);
      if (field) field.required = true;
    });
  }
}

// Debug function
window.debugDonationForm = function() {
  const form = document.querySelector('.donation-form');
  const formData = new FormData(form);
  console.log('=== FORM DEBUG ===');
  for (let [key, value] of formData.entries()) {
    console.log(`${key}:`, value);
  }
  console.log('=== END DEBUG ===');
};
  </script>
  <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
</body>
</html>




