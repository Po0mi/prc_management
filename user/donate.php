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
             // Check if donor exists by email OR user_id
$stmt = $pdo->prepare("SELECT donor_id FROM donors WHERE email = ? OR user_id = ?");
$stmt->execute([$donor_email, $userId]);
$existingDonorId = $stmt->fetchColumn();

if ($existingDonorId) {
    // Update existing donor - ensure user_id is always set
    $stmt = $pdo->prepare("UPDATE donors SET name = ?, phone = ?, user_id = ? WHERE donor_id = ?");
    $stmt->execute([$donor_name, $donor_phone, $userId, $existingDonorId]);
    $donorId = $existingDonorId;
    error_log("Updated existing donor ID: $donorId with user_id: $userId");
} else {
    // Insert new donor WITH user_id
    $stmt = $pdo->prepare("INSERT INTO donors (name, email, phone, user_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$donor_name, $donor_email, $donor_phone, $userId]);
    $donorId = $pdo->lastInsertId();
    error_log("Created new donor ID: $donorId with user_id: $userId");
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
        <button class="tab-button <?= $activeTab === 'blood' ? 'active' : '' ?>" 
                data-tab="blood">
          <i class="fas fa-tint"></i> Blood Donation
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
          
          <!-- Blood Donation Fields -->
          <div id="blood-fields" class="donation-fields <?= $activeTab !== 'blood' ? 'hidden' : '' ?>">
            <div class="blood-donation-message">
              <div class="blood-icon">
                <i class="fas fa-tint"></i>
              </div>
              <h3>Visit Your Nearest Philippine Red Cross Chapter</h3>
              <div class="contact-info">
                <p><i class="fas fa-phone"></i> <strong>Phone:</strong> (033) 503-3393 / 09171170066</p>
                <p><i class="fas fa-envelope"></i> <strong>Email:</strong> iloilo@redcross.org.ph</p>
                <p><i class="fas fa-map-marker-alt"></i> <strong>Address:</strong> Brgy. Danao, Bonifacio Drive, 5000</p>
              </div>
              <p class="note">Since the system cannot process blood donations online, please visit our chapter directly to donate blood.</p>
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
  <script src="js/donate.js?v=<?php echo time(); ?>"></script>
  <?php include 'chat_widget.php'; ?>
  <?php include 'floating_notification_widget.php'; ?> 
</body>
</html>