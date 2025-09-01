<?php
require_once __DIR__ . '/../config.php';
ensure_logged_in();

// FIX: Allow regular users to access donation form, only redirect admins
$user_role = get_user_role();
if ($user_role && $user_role !== 'user') {
    header("Location: /admin/dashboard.php");
    exit;
}
function checkLiveEnvironment() {
    $issues = [];
    
    // Check if uploads directory exists and is writable
    $uploadsDir = __DIR__ . '/../uploads';
    if (!file_exists($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            $issues[] = "Uploads directory doesn't exist and cannot be created";
        }
    } elseif (!is_writable($uploadsDir)) {
        $issues[] = "Uploads directory is not writable";
    }
    
    // Check if required tables exist
    global $pdo;
    try {
        $tables = ['donations', 'donors', 'in_kind_donations'];
        foreach ($tables as $table) {
            $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
            if ($result === false) {
                $issues[] = "Table $table does not exist";
            }
        }
    } catch (Exception $e) {
        $issues[] = "Database error: " . $e->getMessage();
    }
    
    return $issues;
}

// Call this function and log any issues
$environmentIssues = checkLiveEnvironment();
if (!empty($environmentIssues)) {
    error_log("Environment issues: " . implode(", ", $environmentIssues));
}
$userId = current_user_id();
$pdo = $GLOBALS['pdo'];
$successMessage = '';
$errorMessage = '';
$activeTab = $_POST['donation_type'] ?? 'monetary';

// Debug: Log user access
error_log("=== DONATION FORM DEBUG START ===");
error_log("User ID: $userId, Role: " . ($user_role ?? 'none'));
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);

// Enhanced form processing with detailed debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST Request received");
    error_log("POST keys: " . implode(', ', array_keys($_POST)));
    
      if (isset($_POST['submit_donation']) || isset($_POST['donation_type'])) {
        error_log("=== DONATION FORM SUBMISSION DETECTED ===");
        error_log("Full POST data: " . print_r($_POST, true));
        
        // Sanitize and validate inputs
        $donor_name = trim($_POST['donor_name'] ?? '');
        $donor_email = trim($_POST['donor_email'] ?? '');
        $donor_phone = trim($_POST['donor_phone'] ?? '');
        $donation_type = $_POST['donation_type'] ?? 'monetary';
        
        error_log("Basic fields - Name: '$donor_name', Email: '$donor_email', Phone: '$donor_phone', Type: '$donation_type'");
        
        // Validation
        $validation_errors = [];
        
        if (empty($donor_name)) {
            $validation_errors[] = "Donor name is required";
        } elseif (strlen($donor_name) < 2) {
            $validation_errors[] = "Donor name must be at least 2 characters";
        }
        
        if (empty($donor_email)) {
            $validation_errors[] = "Donor email is required";
        } elseif (!filter_var($donor_email, FILTER_VALIDATE_EMAIL)) {
            $validation_errors[] = "Invalid email format";
        }
        
        if (empty($donor_phone)) {
            $validation_errors[] = "Phone number is required";
        }
        
        if (!in_array($donation_type, ['monetary', 'inkind'])) {
            $validation_errors[] = "Invalid donation type";
        }
        
        // FIXED: Handle date field properly based on donation type
        // FIXED: Handle date field properly based on donation type
// FIXED: Handle date field properly based on donation type (supports unique names)
if ($donation_type === 'monetary') {
    $donation_date = trim($_POST['donation_date_monetary'] ?? '');
    $amount_raw = str_replace(['₱', ',', ' '], '', $_POST['amount'] ?? '');
    $amount = ($amount_raw !== '' && is_numeric($amount_raw)) ? (float) $amount_raw : 0;
    $payment_method = trim($_POST['payment_method'] ?? '');

    $allowed_methods = ['cash','gcash','bank_transfer','credit_card'];
    if ($payment_method && !in_array($payment_method, $allowed_methods, true)) {
        $validation_errors[] = "Invalid payment method selected";
    }

    error_log("Monetary fields - Amount: $amount, Date: '$donation_date', Payment: '$payment_method'");

    if (!$amount || $amount <= 0) {
        $validation_errors[] = "Invalid donation amount";
    }
    if (empty($donation_date)) {
        $validation_errors[] = "Donation date is required";
    }
    if (empty($payment_method)) {
        $validation_errors[] = "Payment method is required";
    }

} elseif ($donation_type === 'inkind') {
    $donation_date = trim($_POST['donation_date_inkind'] ?? '');
    $item_description = trim($_POST['item_description'] ?? '');
    $quantity = filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_INT);

    error_log("In-kind fields - Item: '$item_description', Quantity: $quantity, Date: '$donation_date'");

    if (empty($item_description)) {
        $validation_errors[] = "Item description is required";
    }
    if (!$quantity || $quantity <= 0) {
        $validation_errors[] = "Quantity must be greater than 0";
    }
    if (empty($donation_date)) {
        $validation_errors[] = "Donation date is required";
    }
}

        
        // Log validation results
        if (!empty($validation_errors)) {
            error_log("Validation failed: " . implode(", ", $validation_errors));
            $errorMessage = "Please fix the following errors: " . implode(", ", $validation_errors);
        } else {
            error_log("Validation passed - proceeding with database operations");
            
            try {
                $pdo->beginTransaction();
                error_log("Database transaction started");
                
                // Check if donor exists
                $stmt = $pdo->prepare("SELECT donor_id FROM donors WHERE email = ?");
                $stmt->execute([$donor_email]);
                $existingDonorId = $stmt->fetchColumn();
                
                if ($existingDonorId) {
                    // Update existing donor
                    $stmt = $pdo->prepare("UPDATE donors SET name = ?, phone = ? WHERE donor_id = ?");
                    $stmt->execute([$donor_name, $donor_phone, $existingDonorId]);
                    $donorId = $existingDonorId;
                    error_log("Updated existing donor ID: $donorId");
                } else {
                    // Insert new donor
                    $stmt = $pdo->prepare("INSERT INTO donors (name, email, phone) VALUES (?, ?, ?)");
                    $stmt->execute([$donor_name, $donor_email, $donor_phone]);
                    $donorId = $pdo->lastInsertId();
                    error_log("Created new donor ID: $donorId");
                }
                
                if (!$donorId) {
                    throw new Exception("Failed to get donor ID");
                }
                
                if ($donation_type === 'monetary') {
                    $message = trim($_POST['message'] ?? '');
                    $payment_receipt = '';
                    
                    // Handle file upload
                    if (isset($_FILES['payment_receipt']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
                        error_log("Processing file upload");
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
                        $fileType = $_FILES['payment_receipt']['type'];
                        $fileSize = $_FILES['payment_receipt']['size'];
                        $maxFileSize = 5 * 1024 * 1024; // 5MB
                        
                        if (!in_array($fileType, $allowedTypes)) {
                            throw new Exception("Invalid file type. Please upload JPG, PNG, or PDF files only.");
                        }
                        
                        if ($fileSize > $maxFileSize) {
                            throw new Exception("File size too large. Maximum allowed: 5MB");
                        }
                        
                        $uploadsDir = __DIR__ . "/../uploads";
                        if (!file_exists($uploadsDir)) {
                            mkdir($uploadsDir, 0755, true);
                        }
                        
                        $userFolder = $uploadsDir . "/user_" . $userId;
                        if (!file_exists($userFolder)) {
                            mkdir($userFolder, 0755, true);
                        }
                        
                        $fileExtension = pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION);
                        $fileName = 'receipt_' . time() . '_' . uniqid() . '.' . $fileExtension;
                        $filePath = $userFolder . '/' . $fileName;
                        
                        if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], $filePath)) {
                            $payment_receipt = 'uploads/user_' . $userId . '/' . $fileName;
                            error_log("File uploaded successfully: $payment_receipt");
                        } else {
                            error_log("File upload failed");
                        }
                    }
                    
                    // Insert monetary donation
                    $stmt = $pdo->prepare("
                        INSERT INTO donations (donor_id, amount, donation_date, payment_method, message, recorded_by, payment_receipt, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    
                    $result = $stmt->execute([
                        $donorId, 
                        $amount, 
                        $donation_date, 
                        $payment_method, 
                        $message, 
                        $userId, 
                        $payment_receipt
                    ]);
                    
                    if (!$result) {
                        $errorInfo = $stmt->errorInfo();
                        throw new Exception("Failed to insert donation: " . implode(' | ', $errorInfo));
                    }
                    
                    $donationId = $pdo->lastInsertId();
                    error_log("Monetary donation inserted successfully - ID: $donationId");
                    $successMessage = "Thank you for your monetary donation of ₱" . number_format($amount, 2) . "!";
                    
                } elseif ($donation_type === 'inkind') {
                    $estimated_value = filter_var($_POST['estimated_value'] ?? 0, FILTER_VALIDATE_FLOAT) ?: 0;
                    $purpose = trim($_POST['purpose'] ?? '');
                    
                    // Insert in-kind donation
                    $stmt = $pdo->prepare("
                        INSERT INTO in_kind_donations (donor_id, item_description, quantity, estimated_value, donation_date, purpose, recorded_by, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    
                    $result = $stmt->execute([
                        $donorId, 
                        $item_description, 
                        $quantity, 
                        $estimated_value, 
                        $donation_date, 
                        $purpose, 
                        $userId
                    ]);
                    
                    if (!$result) {
                        $errorInfo = $stmt->errorInfo();
                        throw new Exception("Failed to insert in-kind donation: " . implode(' | ', $errorInfo));
                    }
                    
                    $donationId = $pdo->lastInsertId();
                    error_log("In-kind donation inserted successfully - ID: $donationId");
                    $successMessage = "Thank you for your in-kind donation: $quantity x $item_description!";
                }
                
                $pdo->commit();
                error_log("Transaction completed successfully");
                
                // Clear form data
                $_POST = [];
                $activeTab = 'monetary';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMessage = "Error processing donation: " . $e->getMessage();
                error_log("Database error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        }
    } else {
        error_log("Form submission not detected - submit_donation not in POST");
    }
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
          <div id="monetary-fields" class="donation-fields <?= $activeTab !== 'monetary' ? 'hidden' : '' ?>">
            <div class="form-row">
              <div class="form-group">
                <label for="amount">Donation Amount (PHP) *</label>
                <div class="input-with-icon">
                  <i class="fas fa-peso-sign"></i>
                  <input type="number" id="amount" name="amount" min="1" step="0.01" 
                         class="monetary-required" required
                         value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                </div>
              </div>
              
              <div class="form-group">
                <label for="donation_date_monetary">Donation Date *</label>
                <div class="input-with-icon">
                  <i class="fas fa-calendar-day"></i>
                  <input type="date" id="donation_date_monetary" name="donation_date_monetary"
       class="monetary-required" required
       value="<?= htmlspecialchars($_POST['donation_date_monetary'] ?? date('Y-m-d')) ?>">
                </div>
              </div>
            </div>
            
            <!-- Payment Section -->
            <div class="payment-section" id="paymentSection">
              <div class="payment-header">
                <i class="fas fa-credit-card"></i>
                <h3>Payment Information</h3>
              </div>

              <!-- Fee Summary -->
              <div class="fee-summary">
                <h4><i class="fas fa-calculator"></i> Fee Summary</h4>
                <div class="fee-breakdown">
                  <div class="fee-item">
                    <span class="fee-label">Donation Amount:</span>
                    <span class="fee-amount" id="donationAmountDisplay">₱0.00</span>
                  </div>
                  <div class="fee-item">
                    <span class="fee-label">Total Amount:</span>
                    <span class="fee-amount total" id="totalAmountDisplay">₱0.00</span>
                  </div>
                </div>
              </div>

              <!-- Payment Methods -->
              <div class="payment-methods">
                <h4><i class="fas fa-money-check-alt"></i> Payment Method *</h4>
                <div class="payment-options">
                  <div class="payment-option">
                    <input type="radio" name="payment_method" value="bank_transfer" id="bank_transfer" class="monetary-required" required>
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

              <!-- Payment Details Forms -->
              <div class="payment-details-container">
                <!-- Bank Transfer Form -->
                <div class="payment-form" id="bank_transfer_form">
                  <h5><i class="fas fa-university"></i> Bank Transfer Details</h5>
                  <div class="bank-details">
                    <div class="bank-info">
                      <div class="bank-field">
                        <label>Bank Name</label>
                        <span>BDO Unibank</span>
                      </div>
                      <div class="bank-field">
                        <label>Account Number</label>
                        <span>1234-5678-9012</span>
                      </div>
                      <div class="bank-field">
                        <label>Account Name</label>
                        <span>Philippine Red Cross - Tacloban Chapter</span>
                      </div>
                    </div>
                  </div>
                  <div class="payment-instructions">
                    <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                    <ol>
                      <li>Transfer the exact amount to the bank account above</li>
                      <li>Keep your bank receipt/confirmation</li>
                      <li>Upload a clear photo of your receipt below</li>
                    </ol>
                  </div>
                </div>

                <!-- GCash Form -->
                <div class="payment-form" id="gcash_form">
                  <h5><i class="fas fa-mobile-alt"></i> GCash Payment Details</h5>
                  <div class="bank-details">
                    <div class="bank-info">
                      <div class="bank-field">
                        <label>GCash Number</label>
                        <span>+63 917 123 4567</span>
                      </div>
                      <div class="bank-field">
                        <label>Account Name</label>
                        <span>Philippine Red Cross Tacloban</span>
                      </div>
                    </div>
                  </div>
                  <div class="payment-instructions">
                    <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                    <ol>
                      <li>Open your GCash app and select "Send Money"</li>
                      <li>Send the exact amount to the number above</li>
                      <li>Take a screenshot of the successful transaction</li>
                      <li>Upload the screenshot below</li>
                    </ol>
                  </div>
                </div>

                <!-- PayMaya Form -->
                <div class="payment-form" id="paymaya_form">
                  <h5><i class="fas fa-mobile-alt"></i> PayMaya Payment Details</h5>
                  <div class="bank-details">
                    <div class="bank-info">
                      <div class="bank-field">
                        <label>PayMaya Number</label>
                        <span>+63 918 765 4321</span>
                      </div>
                      <div class="bank-field">
                        <label>Account Name</label>
                        <span>Philippine Red Cross Tacloban</span>
                      </div>
                    </div>
                  </div>
                  <div class="payment-instructions">
                    <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                    <ol>
                      <li>Open your PayMaya app and select "Send Money"</li>
                      <li>Send the exact amount to the number above</li>
                      <li>Take a screenshot of the successful transaction</li>
                      <li>Upload the screenshot below</li>
                    </ol>
                  </div>
                </div>

                <!-- Credit Card Form -->
                <div class="payment-form" id="credit_card_form">
                  <h5><i class="fas fa-credit-card"></i> Credit Card Payment Details</h5>
                  <div class="payment-note">
                    <i class="fas fa-info-circle"></i>
                    <div class="payment-note-content">
                      <strong>Important Note:</strong>
                      <p>Credit card payments are processed through our secure payment gateway. You will be redirected to complete your payment after registration.</p>
                    </div>
                  </div>
                  <div class="bank-details">
                    <div class="bank-info">
                      <div class="bank-field">
                        <label>Accepted Cards</label>
                        <span>Visa, Mastercard, JCB</span>
                      </div>
                      <div class="bank-field">
                        <label>Processing Fee</label>
                        <span>3.5% of total amount</span>
                      </div>
                    </div>
                  </div>
                  <div class="payment-instructions">
                    <h6><i class="fas fa-info-circle"></i> Instructions</h6>
                    <ol>
                      <li>Complete the registration form</li>
                      <li>You will be redirected to our secure payment gateway</li>
                      <li>Enter your credit card details on the secure page</li>
                      <li>Complete the payment to finalize your registration</li>
                    </ol>
                  </div>
                </div>

                <!-- Cash Payment Form -->
                <div class="payment-form" id="cash_form">
                  <h5><i class="fas fa-money-bill-wave"></i> Cash Payment Details</h5>
                  <div class="payment-note">
                    <i class="fas fa-info-circle"></i>
                    <div class="payment-note-content">
                      <strong>Important Note:</strong>
                      <p>You have selected cash payment. Please visit our office during business hours to complete your payment.</p>
                    </div>
                  </div>
                  <div class="bank-details">
                    <div class="bank-info">
                      <div class="bank-field">
                        <label>Office Address</label>
                        <span>Philippine Red Cross Tacloban Chapter<br>123 Remedios Street, Tacloban City</span>
                      </div>
                      <div class="bank-field">
                        <label>Business Hours</label>
                        <span>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Payment Receipt Upload (for non-cash payments) -->
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

              <!-- Payment Summary -->
              <div class="payment-summary" id="paymentSummary" style="display: none;">
                <h4><i class="fas fa-file-invoice-dollar"></i> Payment Summary</h4>
                <div class="summary-item">
                  <span class="summary-label">Payment Method:</span>
                  <span class="summary-value" id="selectedPaymentMethod">-</span>
                </div>
                <div class="summary-item">
                  <span class="summary-label">Donation Amount:</span>
                  <span class="summary-value" id="summaryDonationAmount">₱0.00</span>
                </div>
                <div class="summary-item">
                  <span class="summary-label">Total Amount:</span>
                  <span class="summary-value total" id="summaryTotalAmount">₱0.00</span>
                </div>
              </div>
            </div>
          </div>
          
          <!-- In-Kind Donation Fields -->
          <div id="inkind-fields" class="donation-fields <?= $activeTab !== 'inkind' ? 'hidden' : '' ?>">
            <div class="form-group">
              <label for="item_description">Item Description *</label>
              <textarea id="item_description" name="item_description" rows="2" 
                        class="inkind-required" required><?= 
                htmlspecialchars($_POST['item_description'] ?? '') ?></textarea>
            </div>
            
            <div class="form-row">
              <div class="form-group">
                <label for="quantity">Quantity *</label>
                <input type="number" id="quantity" name="quantity" min="1" 
                       class="inkind-required" required
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
                <input type="date" id="donation_date_inkind" name="donation_date_inkind"
       class="inkind-required" required
       value="<?= htmlspecialchars($_POST['donation_date_inkind'] ?? date('Y-m-d')) ?>">
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
          
          <!-- Submit button -->
          <button type="submit" class="donate-button" name="submit_donation" value="1">
            <i class="fas fa-heart"></i> Submit Donation
          </button>
        </form>
        
        <!-- Rest of the info cards remain the same -->
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
 <script src="js/general-ui.js?v=<?php echo time(); ?>"></script>
  <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
  <script src="js/header.js?v=<?php echo time(); ?>"></script>
  <script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Donation form initialized');
    
    // Cache DOM elements
    const tabButtons = document.querySelectorAll('.tab-button');
    const donationFields = document.querySelectorAll('.donation-fields');
    const donationTypeInput = document.getElementById('donation_type');
    const donationForm = document.querySelector('.donation-form');
    const amountInput = document.getElementById('amount');
    
    // Verify critical elements exist
    if (!donationForm) {
        console.error('Donation form not found!');
        return;
    }
    
    console.log(`Found ${tabButtons.length} tab buttons, ${donationFields.length} donation fields`);
    
    // Tab switching functionality - FIXED
    tabButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const targetTab = this.getAttribute('data-tab');
            console.log('Switching to tab:', targetTab);
            
            // Remove active class from all buttons
            tabButtons.forEach(btn => btn.classList.remove('active'));
            
            // Hide all donation fields
            donationFields.forEach(field => {
                field.classList.add('hidden');
                field.style.display = 'none';
            });
            
            // Add active class to clicked button
            this.classList.add('active');
            
            // Show corresponding field
            const targetField = document.getElementById(targetTab + '-fields');
            if (targetField) {
                targetField.classList.remove('hidden');
                targetField.style.display = 'block';
                console.log(`Showing ${targetTab} fields`);
            }
            
            // Update hidden input
            if (donationTypeInput) {
                donationTypeInput.value = targetTab;
                console.log('Donation type updated to:', targetTab);
            }
            
            // Update required fields
            updateRequiredFields(targetTab);
            
            // Handle payment method requirements
            if (targetTab === 'monetary') {
                initializePaymentMethods();
            }
        });
    });
    
    // Initialize payment methods
    initializePaymentMethods();
    
    // Amount input listener for updating fee summary
    if (amountInput) {
        amountInput.addEventListener('input', updateFeeSummary);
    }
    
    // Form submission handling - FIXED (removed duplicate)
    donationForm.addEventListener('submit', function(e) {
        console.log('Form submission attempted');
        
        const currentTab = donationTypeInput.value;
        console.log('Current tab for validation:', currentTab);
        
        let isValid = true;
        let errorMessage = '';

        // Validate common fields
        const donorName = document.getElementById('donor_name');
        const donorEmail = document.getElementById('donor_email');
        const donorPhone = document.getElementById('donor_phone');

        if (!donorName.value.trim()) {
            isValid = false;
            errorMessage = 'Please enter your name';
            donorName.focus();
        } else if (!donorEmail.value.trim() || !donorEmail.value.includes('@')) {
            isValid = false;
            errorMessage = 'Please enter a valid email address';
            donorEmail.focus();
        } else if (!donorPhone.value.trim()) {
            isValid = false;
            errorMessage = 'Please enter your phone number';
            donorPhone.focus();
        }
        
        // Validate tab-specific fields
        if (isValid && currentTab === 'monetary') {
            const amount = document.getElementById('amount');
            const donationDate = document.getElementById('donation_date_monetary');
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
            
            if (!amount.value || parseFloat(amount.value) <= 0) {
                isValid = false;
                errorMessage = 'Please enter a valid donation amount';
                amount.focus();
            } else if (!donationDate.value) {
                isValid = false;
                errorMessage = 'Please select a donation date';
                donationDate.focus();
            } else if (!paymentMethod) {
                isValid = false;
                errorMessage = 'Please select a payment method';
                const firstPaymentOption = document.querySelector('input[name="payment_method"]');
                if (firstPaymentOption) firstPaymentOption.focus();
            }
        }
        
        if (isValid && currentTab === 'inkind') {
            const itemDescription = document.getElementById('item_description');
            const quantity = document.getElementById('quantity');
            const donationDate = document.getElementById('donation_date_inkind');
            
            if (!itemDescription.value.trim()) {
                isValid = false;
                errorMessage = 'Please enter an item description';
                itemDescription.focus();
            } else if (!quantity.value || parseInt(quantity.value) <= 0) {
                isValid = false;
                errorMessage = 'Please enter a valid quantity';
                quantity.focus();
            } else if (!donationDate.value) {
                isValid = false;
                errorMessage = 'Please select a donation date';
                donationDate.focus();
            }
        }
        
        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
            console.log('Form validation failed:', errorMessage);
            return false;
        }
        
        console.log('Form validation passed - submitting');
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
    
    // Initialize fee summary
    updateFeeSummary();
    
    // Sync date fields
    syncDateFields();
    
    // Initialize required fields for current tab
    updateRequiredFields(donationTypeInput.value);
});

function updateRequiredFields(activeTab) {
    console.log('Updating required fields for:', activeTab);
    
    // Remove required from all tab-specific fields
    document.querySelectorAll('.monetary-required, .inkind-required').forEach(field => {
        field.required = false;
    });
    
    // Add required only to active tab fields
    if (activeTab === 'monetary') {
        document.querySelectorAll('.monetary-required').forEach(field => {
            field.required = true;
        });
        
        // Handle payment method requirement separately
        const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
        paymentMethods.forEach(method => {
            method.required = true;
        });
        
    } else if (activeTab === 'inkind') {
        document.querySelectorAll('.inkind-required').forEach(field => {
            field.required = true;
        });
        
        // Remove payment method requirement for in-kind
        const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
        paymentMethods.forEach(method => {
            method.required = false;
            method.checked = false;
        });
    }
}

function initializePaymentMethods() {
    console.log('Initializing payment methods');
    
    const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
    paymentMethods.forEach(method => {
        method.addEventListener('change', function() {
            updatePaymentReceipt();
            updatePaymentSummary();
        });
    });
    
    // File upload handling
    const paymentReceipt = document.getElementById('payment_receipt');
    if (paymentReceipt) {
        paymentReceipt.addEventListener('change', function() {
            handleFileUpload(this);
        });
    }
    
    // Initialize payment receipt visibility
    updatePaymentReceipt();
}

function updatePaymentReceipt() {
    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
    const receiptUpload = document.getElementById('receiptUpload');
    
    if (selectedMethod) {
        console.log('Payment method selected:', selectedMethod.value);
        
        // Show payment details for selected method
        document.querySelectorAll('.payment-form').forEach(form => {
            form.style.display = 'none';
        });
        
        const selectedForm = document.getElementById(selectedMethod.value + '_form');
        if (selectedForm) {
            selectedForm.style.display = 'block';
        }
        
        if (selectedMethod.value !== 'cash') {
            if (receiptUpload) {
                receiptUpload.style.display = 'block';
            }
        } else {
            if (receiptUpload) {
                receiptUpload.style.display = 'none';
            }
        }
        
        // Show payment summary
        const paymentSummary = document.getElementById('paymentSummary');
        if (paymentSummary) {
            paymentSummary.style.display = 'block';
        }
        
        updatePaymentSummary();
    } else {
        // Hide all payment details if no method selected
        document.querySelectorAll('.payment-form').forEach(form => {
            form.style.display = 'none';
        });
        
        const receiptUpload = document.getElementById('receiptUpload');
        if (receiptUpload) {
            receiptUpload.style.display = 'none';
        }
        
        const paymentSummary = document.getElementById('paymentSummary');
        if (paymentSummary) {
            paymentSummary.style.display = 'none';
        }
    }
}

function updateFeeSummary() {
    const amount = parseFloat(document.getElementById('amount')?.value) || 0;
    const donationAmountDisplay = document.getElementById('donationAmountDisplay');
    const totalAmountDisplay = document.getElementById('totalAmountDisplay');
    
    if (donationAmountDisplay) {
        donationAmountDisplay.textContent = `₱${amount.toFixed(2)}`;
    }
    
    if (totalAmountDisplay) {
        totalAmountDisplay.textContent = `₱${amount.toFixed(2)}`;
    }
    
    updatePaymentSummary();
}

function updatePaymentSummary() {
    const amount = parseFloat(document.getElementById('amount')?.value) || 0;
    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
    const selectedMethodDisplay = document.getElementById('selectedPaymentMethod');
    const summaryDonationAmount = document.getElementById('summaryDonationAmount');
    const summaryTotalAmount = document.getElementById('summaryTotalAmount');
    
    if (selectedMethodDisplay && selectedMethod) {
        selectedMethodDisplay.textContent = selectedMethod.value.replace('_', ' ').toUpperCase();
    }
    
    if (summaryDonationAmount) {
        summaryDonationAmount.textContent = `₱${amount.toFixed(2)}`;
    }
    
    if (summaryTotalAmount) {
        let totalAmount = amount;
        
        // Add processing fee for credit card
        if (selectedMethod && selectedMethod.value === 'credit_card') {
            const processingFee = amount * 0.035;
            totalAmount += processingFee;
        }
        
        summaryTotalAmount.textContent = `₱${totalAmount.toFixed(2)}`;
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

function syncDateFields() {
    const monetaryDate = document.getElementById('donation_date_monetary');
    const inkindDate = document.getElementById('donation_date_inkind');
    
    if (monetaryDate && inkindDate) {
        // When monetary date changes, update inkind date
        monetaryDate.addEventListener('change', function() {
            inkindDate.value = this.value;
        });
        
        // When inkind date changes, update monetary date
        inkindDate.addEventListener('change', function() {
            monetaryDate.value = this.value;
        });
        
        // Initialize with current date if empty
        const currentDate = new Date().toISOString().split('T')[0];
        if (!monetaryDate.value) monetaryDate.value = currentDate;
        if (!inkindDate.value) inkindDate.value = currentDate;
    }
}

// Debug functions
window.debugDonationForm = function() {
    const form = document.querySelector('.donation-form');
    const formData = new FormData(form);
    console.log('=== FORM DEBUG ===');
    console.log('Form method:', form.method);
    console.log('Form action:', form.action);
    console.log('Form data:');
    for (let [key, value] of formData.entries()) {
        console.log(`${key}:`, value);
    }
    console.log('=== END DEBUG ===');
};

window.debugFormState = function() {
    console.log('=== FORM STATE DEBUG ===');
    console.log('Active tab:', document.getElementById('donation_type').value);
    
    // Check required fields
    const requiredFields = document.querySelectorAll('[required]');
    console.log('Required fields:');
    requiredFields.forEach(field => {
        console.log(`${field.name}: ${field.value} (visible: ${field.offsetParent !== null})`);
    });
    
    // Check payment method
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
    console.log('Payment method selected:', paymentMethod ? paymentMethod.value : 'NONE');
    
    console.log('=== END DEBUG ===');
};
  </script>
 
</body>
</html>