<?php
require_once __DIR__ . '/config.php';

// Try to include email API, but don't fail if it doesn't exist
if (file_exists(__DIR__ . '/email_api.php')) {
    require_once __DIR__ . '/email_api.php';
    $emailEnabled = true;
} else {
    $emailEnabled = false;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $userType = trim($_POST['user_type'] ?? 'guest'); // guest or member
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    $role = 'user';

    // File upload handling
    $uploadedDocuments = [];
    $maxFileSize = 5 * 1024 * 1024; // 5MB limit
    $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
    $uploadDir = 'uploads/documents/';
    
    // Create upload directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Create logs directory for email logging
    if (!is_dir('logs/')) {
        mkdir('logs/', 0755, true);
    }

    $secretKey = '6LelZWMrAAAAAAOUFB-ncoxGhkt8OMsUrQgcOgTO'; 
    $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$secretKey&response=$recaptchaResponse");
    $responseData = json_decode($response);

    // Enhanced validation
    if (empty($firstName) || empty($lastName) || empty($gender) || empty($username) || 
        empty($email) || empty($phone) || empty($password) || empty($confirm)) {
        $error = "All fields are required.";
    } elseif (!$responseData->success) {
        $error = "reCAPTCHA verification failed. Please try again.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (strlen($username) < 4 || strlen($username) > 20) {
        $error = "Username must be between 4 and 20 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = "Username can only contain letters, numbers, and underscores.";
    } elseif (!preg_match('/^[0-9]{10,11}$/', $phone)) {
        $error = "Invalid phone number. Must be 10 or 11 digits.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($userType === 'member' && (!isset($_FILES['documents']) || empty($_FILES['documents']['name'][0]))) {
        $error = "Member accounts require at least one verification document to be uploaded.";
    } else {
        // Handle file uploads
        if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
            $files = $_FILES['documents'];
            
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileName = $files['name'][$i];
                    $fileSize = $files['size'][$i];
                    $fileTmp = $files['tmp_name'][$i];
                    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    // Validate file size
                    if ($fileSize > $maxFileSize) {
                        $error = "File '$fileName' exceeds the 5MB size limit.";
                        break;
                    }
                    
                    // Validate file type
                    if (!in_array($fileType, $allowedTypes)) {
                        $error = "File '$fileName' has an invalid type. Allowed: " . implode(', ', $allowedTypes);
                        break;
                    }
                    
                    // Additional security: Check file content if finfo is available
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($finfo, $fileTmp);
                        finfo_close($finfo);
                        
                        $allowedMimeTypes = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'image/jpeg',
                            'image/png',
                            'text/plain'
                        ];
                        
                        if (!in_array($mimeType, $allowedMimeTypes)) {
                            $error = "File '$fileName' has an invalid content type.";
                            break;
                        }
                    }
                    
                    // Generate unique filename
                    $uniqueFileName = uniqid() . '_' . time() . '.' . $fileType;
                    $filePath = $uploadDir . $uniqueFileName;
                    
                    // Move uploaded file
                    if (move_uploaded_file($fileTmp, $filePath)) {
                        $uploadedDocuments[] = [
                            'original_name' => $fileName,
                            'stored_name' => $uniqueFileName,
                            'file_path' => $filePath,
                            'file_size' => $fileSize,
                            'file_type' => $fileType
                        ];
                    } else {
                        $error = "Failed to upload file '$fileName'.";
                        break;
                    }
                }
            }
        }
        
        if (!$error) {
            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username or email is already taken.";
            } else {
                try {
                    $pdo->beginTransaction();
                    
                    // Hash password
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert user with full_name for compatibility
                    $fullName = $firstName . ' ' . $lastName;
                    $stmt = $pdo->prepare("INSERT INTO users 
                        (first_name, last_name, full_name, gender, username, email, phone, password_hash, role, user_type, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$firstName, $lastName, $fullName, $gender, $username, $email, $phone, $hashed, $role, $userType]);
                    
                    $userId = $pdo->lastInsertId();
                    
                    // Insert uploaded documents
                    $documentCount = 0;
                    if (!empty($uploadedDocuments)) {
                        $stmt = $pdo->prepare("INSERT INTO user_documents 
                            (user_id, original_name, stored_name, file_path, file_size, file_type, uploaded_at) 
                            VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        
                        foreach ($uploadedDocuments as $doc) {
                            $stmt->execute([
                                $userId,
                                $doc['original_name'],
                                $doc['stored_name'],
                                $doc['file_path'],
                                $doc['file_size'],
                                $doc['file_type']
                            ]);
                            $documentCount++;
                        }
                    }
                    
                    $pdo->commit();
                    
                    // Send email notification
                    $emailSent = false;
                    $emailMessage = '';
                    
                    try {
                        if ($emailEnabled) {
                            // Use new Email API if available
                            $emailAPI = new EmailNotificationAPI();
                            
                            if ($documentCount > 0) {
                                // Send custom email mentioning documents
                                $emailSent = sendWelcomeEmailWithDocuments($email, $firstName, $userType, $documentCount);
                                $emailMessage = $emailSent ? "A welcome email with document confirmation has been sent to $email." : "However, there was an issue sending the confirmation email.";
                            } else {
                                // Send standard welcome email using EmailAPI directly
                                $emailResult = $emailAPI->sendRegistrationEmail($email, $firstName, $userType, $userId);
                                $emailSent = $emailResult['success'];
                                $emailMessage = $emailSent ? "A welcome email has been sent to $email." : "However, there was an issue sending the confirmation email.";
                            }
                        } else {
                            // Fallback to basic email if Email API not available
                            $emailSent = sendBasicRegistrationEmail($email, $firstName, $userType);
                            $emailMessage = $emailSent ? "A welcome email has been sent to $email." : "However, there was an issue sending the confirmation email.";
                        }
                        
                        // Log registration activity
                        $logMessage = date('Y-m-d H:i:s') . " - New user registered: $username ($email) - Type: $userType - Documents: $documentCount - Email sent: " . ($emailSent ? 'Yes' : 'No') . "\n";
                        file_put_contents('logs/registrations.log', $logMessage, FILE_APPEND | LOCK_EX);
                        
                    } catch (Exception $emailException) {
                        error_log("Email sending error: " . $emailException->getMessage());
                        $emailMessage = "However, there was an issue sending the confirmation email.";
                    }
                    
                    $success = "Registration successful! You can now log in. $emailMessage";
                    echo "<script>window.formSuccess = true;</script>";
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    
                    // Clean up uploaded files on error
                    foreach ($uploadedDocuments as $doc) {
                        if (file_exists($doc['file_path'])) {
                            unlink($doc['file_path']);
                        }
                    }
                    
                    $error = "Registration failed. Please try again.";
                    error_log("Registration error for $email: " . $e->getMessage());
                }
            }
        }
    }
}

// Basic email function (fallback when Email API is not available)
function sendBasicRegistrationEmail($email, $firstName, $userType) {
    $subject = "Welcome to Philippine Red Cross Management System";
    $message = "
    <html>
    <head>
        <title>Welcome to PRC Management System</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .button { background: #a00000; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 20px 0; }
            .info-box { background: white; padding: 15px; border-left: 4px solid #a00000; margin: 20px 0; }
            .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Philippine Red Cross</h1>
                <p>Management System Portal</p>
            </div>
            
            <div class='content'>
                <h2 style='color: #a00000;'>Welcome, " . htmlspecialchars($firstName) . "!</h2>
                
                <p>Thank you for registering with the Philippine Red Cross Management System.</p>
                
                <div class='info-box'>
                    <strong>Account Details:</strong><br>
                    Email: " . htmlspecialchars($email) . "<br>
                    Account Type: " . ucfirst($userType) . "<br>
                    Registration Date: " . date('Y-m-d H:i:s') . "
                </div>
                
                <p>Your account has been successfully created. You can now log in to access the system.</p>
                
                <div style='text-align: center;'>
                    <a href='login.php' class='button'>Login to Your Account</a>
                </div>
                
                <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
            </div>
            
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.<br>
                Philippine Red Cross Management System</p>
            </div>
        </div>
    </body>
    </html>";

    $headers = array(
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: PRC System <noreply@prc-system.com>',
        'Reply-To: support@prc-system.com',
        'X-Mailer: PHP/' . phpversion()
    );

    return mail($email, $subject, $message, implode("\r\n", $headers));
}

// Enhanced welcome email function with document notification
function sendWelcomeEmailWithDocuments($email, $firstName, $userType, $documentCount) {
    $subject = "Welcome to Philippine Red Cross Management System - Documents Received";
    $message = "
    <html>
    <head>
        <title>Welcome to PRC Management System</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 30px; background: #f9f9f9; }
            .button { background: #a00000; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 20px 0; font-weight: bold; }
            .info-box { background: white; padding: 20px; border-left: 4px solid #a00000; margin: 20px 0; border-radius: 0 5px 5px 0; }
            .document-info { background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #c3e6cb; }
            .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; background: #f8f9fa; border-radius: 0 0 8px 8px; }
            .highlight { color: #a00000; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏥 Philippine Red Cross</h1>
                <p>Management System Portal</p>
            </div>
            
            <div class='content'>
                <h2 style='color: #a00000;'>Welcome, " . htmlspecialchars($firstName) . "! 🎉</h2>
                
                <p>Thank you for registering with the Philippine Red Cross Management System. We're excited to have you join our community dedicated to humanitarian service.</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #a00000;'>📋 Account Details</h3>
                    <strong>Email:</strong> " . htmlspecialchars($email) . "<br>
                    <strong>Account Type:</strong> <span class='highlight'>" . ucfirst($userType) . "</span><br>
                    <strong>Registration Date:</strong> " . date('F j, Y g:i A') . "<br>
                    <strong>Account Status:</strong> <span style='color: #28a745;'>✓ Active</span>
                </div>";
    
    if ($documentCount > 0) {
        $message .= "
                <div class='document-info'>
                    <h3 style='margin-top: 0; color: #155724;'>📄 Documents Received</h3>
                    <p><strong>✓ Success!</strong> We have received <strong>$documentCount</strong> document(s) with your registration.</p>
                    <p>Our team will review your documents within 2-3 business days. You will receive an email notification once the review is complete.</p>
                </div>";
    }
    
    $message .= "
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='login.php' class='button'>🔐 Login to Your Account</a>
                </div>
                
                <div style='background: white; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #a00000;'>📞 Need Help?</h3>
                    <p>If you have any questions or need assistance:</p>
                    <ul>
                        <li><strong>Email:</strong> support@prc-system.com</li>
                        <li><strong>Phone:</strong> (02) 8527-0864</li>
                        <li><strong>Website:</strong> <a href='https://redcross.org.ph'>redcross.org.ph</a></li>
                    </ul>
                </div>
                
                <p style='text-align: center; color: #666; font-style: italic;'>
                    \"Together, we can make a difference in the lives of those who need it most.\"
                </p>
            </div>
            
            <div class='footer'>
                <p><strong>This is an automated message. Please do not reply to this email.</strong><br>
                Philippine Red Cross Management System<br>
                © " . date('Y') . " Philippine Red Cross. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    $headers = array(
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Philippine Red Cross System <noreply@prc-system.com>',
        'Reply-To: support@prc-system.com',
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 3',
        'Return-Path: noreply@prc-system.com'
    );

    return mail($email, $subject, $message, implode("\r\n", $headers));
}

// Legacy function for backward compatibility (renamed to avoid conflicts)
function sendLegacyRegistrationEmail($email, $firstName, $userType) {
    return sendBasicRegistrationEmail($email, $firstName, $userType);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | PRC Management System</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/styles.css">
  <link rel="stylesheet" href="assets/register.css?v=<?php echo time(); ?>">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
  <header class="system-header">
    <div class="logo-title">
      <a href="index.php">
        <img src="assets/logo.png" alt="PRC Logo" class="prc-logo">
      </a>
      <div>
        <h1>Philippine Red Cross</h1>
        <p>Management System Portal</p>
      </div>
    </div>
    <div class="system-info">
      <span class="tag">User Registration</span>
      <span class="tag">Create Your PRC Account</span>
    </div>
  </header>

  <div class="register-container">
    <div class="register-box">
      <h2><i class="fas fa-user-plus"></i> Create Your Account</h2>
      <p>Join the Philippine Red Cross community. All fields marked with <span style="color: #a00000;">*</span> are required.</p>

      <?php if ($error): ?>
        <div class="alert error">
          <i class="fas fa-exclamation-circle"></i>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php elseif ($success): ?>
        <div class="alert success">
          <i class="fas fa-check-circle"></i>
          <?= $success ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="register.php" class="register-form" id="registerForm" enctype="multipart/form-data">
        <div class="form-group">
          <label><i class="fas fa-user-tag"></i> Account Type <span style="color: #a00000;">*</span></label>
          <div class="checkbox-group">
            <label class="checkbox-label">
              <input type="radio" name="user_type" value="guest" <?= ($userType ?? 'guest') === 'guest' ? 'checked' : '' ?>>
              <span class="checkmark"></span>
              <div>
                <strong>Guest Account</strong> - Limited access to system features<br>
                <small>View events, basic information access</small>
              </div>
            </label>
            <label class="checkbox-label">
              <input type="radio" name="user_type" value="member" <?= ($userType ?? '') === 'member' ? 'checked' : '' ?>>
              <span class="checkmark"></span>
              <div>
                <strong>Member Account</strong> - Full access to system features<br>
                <small>Event registration, training sessions, donation tracking</small>
              </div>
            </label>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label><i class="fas fa-user"></i> First Name <span style="color: #a00000;">*</span></label>
            <input type="text" name="first_name" required value="<?= htmlspecialchars($firstName ?? '') ?>" 
                   pattern="[A-Za-z\s]+" title="Only letters and spaces allowed">
          </div>
          <div class="form-group">
            <label><i class="fas fa-user"></i> Last Name <span style="color: #a00000;">*</span></label>
            <input type="text" name="last_name" required value="<?= htmlspecialchars($lastName ?? '') ?>"
                   pattern="[A-Za-z\s]+" title="Only letters and spaces allowed">
          </div>
        </div>

        <div class="form-group">
          <label><i class="fas fa-venus-mars"></i> Gender <span style="color: #a00000;">*</span></label>
          <select name="gender" required>
            <option value="">Select Gender</option>
            <option value="male" <?= ($gender ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($gender ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= ($gender ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            <option value="prefer_not_to_say" <?= ($gender ?? '') === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
          </select>
        </div>

        <div class="form-group">
          <label><i class="fas fa-at"></i> Username <span style="color: #a00000;">*</span></label>
          <input type="text" name="username" required minlength="4" maxlength="20" 
                 value="<?= htmlspecialchars($username ?? '') ?>"
                 pattern="[A-Za-z0-9_]+" title="Only letters, numbers, and underscores allowed"
                 id="username">
          <p class="input-hint">4-20 characters. Letters, numbers, and underscores only.</p>
          <div id="username-status" class="validation-status"></div>
        </div>

        <div class="form-group">
          <label><i class="fas fa-envelope"></i> Email Address <span style="color: #a00000;">*</span></label>
          <input type="email" name="email" required value="<?= htmlspecialchars($email ?? '') ?>" id="email">
          <p class="input-hint">We'll send a confirmation email to this address</p>
          <div id="email-status" class="validation-status"></div>
        </div>

        <div class="form-group">
          <label><i class="fas fa-phone"></i> Phone Number <span style="color: #a00000;">*</span></label>
          <input type="tel" name="phone" pattern="[0-9]{10,11}" required 
                 value="<?= htmlspecialchars($phone ?? '') ?>" 
                 placeholder="09XXXXXXXXX or 02XXXXXXXX">
          <p class="input-hint">10 or 11 digits only (mobile or landline)</p>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label><i class="fas fa-lock"></i> Password <span style="color: #a00000;">*</span></label>
            <input type="password" name="password" required minlength="8" id="password">
            <p class="input-hint">At least 8 characters</p>
            <div class="password-strength">
              <div class="strength-meter" id="strengthMeter"></div>
              <div class="strength-text" id="strengthText">Password strength</div>
            </div>
          </div>
          <div class="form-group">
            <label><i class="fas fa-lock"></i> Confirm Password <span style="color: #a00000;">*</span></label>
            <input type="password" name="confirm_password" required minlength="8" id="confirmPassword">
            <div id="password-match" class="validation-status"></div>
          </div>
        </div>

        <!-- Document Upload Section (conditional display) -->
        <div class="form-group" id="documentSection">
          <label>
            <i class="fas fa-paperclip"></i> 
            <span id="documentLabel">Upload Documents</span> 
            <span id="documentRequired" style="color: #a00000; display: none;">*</span>
          </label>
          <input type="file" name="documents[]" multiple 
                 accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt" 
                 id="documentUpload">
          <p class="input-hint" id="documentHint">
            <strong>Accepted:</strong> PDF, DOC, DOCX, JPG, JPEG, PNG, TXT<br>
            <strong>Max size:</strong> 5MB per file<br>
            <span id="documentPurpose">Purpose: ID, certificates, or other relevant documents</span>
          </p>
          <div id="filePreview" class="file-preview"></div>
        </div>

        <div class="form-group recaptcha-group">
          <div class="g-recaptcha" data-sitekey="6LelZWMrAAAAAJdF8yehKwL8dUvL1zAjXFA3Foih"></div>
          <p class="input-hint">Please verify that you're not a robot</p>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary" id="submitBtn">
            <i class="fas fa-user-plus"></i> Create Account
          </button>
        </div>
      </form>

      <div class="login-link">
        Already have an account? <a href="login.php"><i class="fas fa-sign-in-alt"></i> Log in here</a>
      </div>
      
      <div class="help-section">
        <p><i class="fas fa-info-circle"></i> 
        Need help? Contact us at <a href="mailto:support@prc-system.com">support@prc-system.com</a> 
        or call <strong>(02) 8527-0864</strong></p>
      </div>
    </div>
  </div>

  <script>
    // Enhanced form validation and user experience
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');
        const filePreview = document.getElementById('filePreview');
        let selectedFiles = new Map(); // Track selected files with unique IDs
        
        // Handle account type change
        const accountTypeRadios = document.querySelectorAll('input[name="user_type"]');
        accountTypeRadios.forEach(radio => {
            radio.addEventListener('change', updateDocumentRequirement);
        });
        
        // Initialize on page load
        updateDocumentRequirement();
        
        function updateDocumentRequirement() {
            const userType = document.querySelector('input[name="user_type"]:checked').value;
            const documentLabel = document.getElementById('documentLabel');
            const documentRequired = document.getElementById('documentRequired');
            const documentPurpose = document.getElementById('documentPurpose');
            const documentUpload = document.getElementById('documentUpload');
            
            if (userType === 'member') {
                documentLabel.textContent = 'Upload Verification Documents';
                documentRequired.style.display = 'inline';
                documentPurpose.textContent = 'Required: Valid ID, certificates, proof of address, or other verification documents';
                documentUpload.required = true;
            } else {
                documentLabel.textContent = 'Upload Documents (Optional)';
                documentRequired.style.display = 'none';
                documentPurpose.textContent = 'Optional: ID, certificates, or other relevant documents';
                documentUpload.required = false;
            }
        }
        
        // Username availability check (debounced)
        let usernameTimeout;
        document.getElementById('username').addEventListener('input', function(e) {
            clearTimeout(usernameTimeout);
            const username = e.target.value;
            const statusDiv = document.getElementById('username-status');
            
            if (username.length >= 4) {
                usernameTimeout = setTimeout(() => {
                    checkUsernameAvailability(username, statusDiv);
                }, 500);
            } else {
                statusDiv.innerHTML = '';
            }
        });
        
        // Email format validation
        document.getElementById('email').addEventListener('blur', function(e) {
            const email = e.target.value;
            const statusDiv = document.getElementById('email-status');
            
            if (email) {
                if (validateEmail(email)) {
                    statusDiv.innerHTML = '<span class="valid">✓ Valid email format</span>';
                } else {
                    statusDiv.innerHTML = '<span class="invalid">✗ Invalid email format</span>';
                }
            }
        });
        
        // Password strength and confirmation
        document.getElementById('password').addEventListener('input', updatePasswordStrength);
        document.getElementById('confirmPassword').addEventListener('input', checkPasswordMatch);
        
        // File upload preview and validation
        document.getElementById('documentUpload').addEventListener('change', handleFileUpload);
        
        // Form submission validation
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
        });
    });
    
    function checkUsernameAvailability(username, statusDiv) {
        if (/^[A-Za-z0-9_]+$/.test(username)) {
            statusDiv.innerHTML = '<span class="valid">✓ Username format is valid</span>';
        } else {
            statusDiv.innerHTML = '<span class="invalid">✗ Only letters, numbers, and underscores allowed</span>';
        }
    }
    
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    function updatePasswordStrength() {
        const password = document.getElementById('password').value;
        const meter = document.getElementById('strengthMeter');
        const text = document.getElementById('strengthText');
        let strength = 0;
        let feedback = [];
        
        if (password.length >= 8) strength += 25;
        else feedback.push('At least 8 characters');
        
        if (/[a-z]/.test(password)) strength += 25;
        else feedback.push('Lowercase letter');
        
        if (/[A-Z]/.test(password)) strength += 25;
        else feedback.push('Uppercase letter');
        
        if (/[0-9]/.test(password)) strength += 25;
        else feedback.push('Number');
        
        meter.style.width = strength + '%';
        
        if (strength < 50) {
            meter.style.backgroundColor = '#ff4444';
            text.textContent = 'Weak - Missing: ' + feedback.join(', ');
            text.style.color = '#ff4444';
        } else if (strength < 75) {
            meter.style.backgroundColor = '#ffbb33';
            text.textContent = 'Good - Missing: ' + feedback.join(', ');
            text.style.color = '#ffbb33';
        } else {
            meter.style.backgroundColor = '#00C851';
            text.textContent = 'Strong password';
            text.style.color = '#00C851';
        }
    }
    
    function checkPasswordMatch() {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirmPassword').value;
        const statusDiv = document.getElementById('password-match');
        
        if (confirm) {
            if (password === confirm) {
                statusDiv.innerHTML = '<span class="valid">✓ Passwords match</span>';
            } else {
                statusDiv.innerHTML = '<span class="invalid">✗ Passwords do not match</span>';
            }
        } else {
            statusDiv.innerHTML = '';
        }
    }
    
    function handleFileUpload(e) {
        const files = e.target.files;
        const preview = document.getElementById('filePreview');
        const maxSize = 5 * 1024 * 1024; // 5MB
        const allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
        
        preview.innerHTML = '';
        selectedFiles.clear();
        
        if (files.length === 0) return;
        
        let totalSize = 0;
        let validFiles = 0;
        
        Array.from(files).forEach((file, index) => {
            const fileId = 'file_' + Date.now() + '_' + index;
            const fileSize = file.size;
            const fileType = file.name.split('.').pop().toLowerCase();
            totalSize += fileSize;
            
            const fileDiv = document.createElement('div');
            fileDiv.className = 'file-item';
            fileDiv.dataset.fileId = fileId;
            
            let status = 'valid';
            let statusText = 'Valid';
            let statusIcon = '✓';
            
            if (fileSize > maxSize) {
                status = 'invalid';
                statusText = 'Too large (max 5MB)';
                statusIcon = '✗';
            } else if (!allowedTypes.includes(fileType)) {
                status = 'invalid';
                statusText = 'Invalid type';
                statusIcon = '✗';
            } else {
                validFiles++;
                selectedFiles.set(fileId, file);
            }
            
            // Get file icon based on type
            let fileIcon = 'fa-file';
            if (['pdf'].includes(fileType)) fileIcon = 'fa-file-pdf';
            else if (['doc', 'docx'].includes(fileType)) fileIcon = 'fa-file-word';
            else if (['jpg', 'jpeg', 'png'].includes(fileType)) fileIcon = 'fa-file-image';
            else if (['txt'].includes(fileType)) fileIcon = 'fa-file-text';
            
            fileDiv.innerHTML = `
                <div class="file-info">
                    <i class="fas ${fileIcon}"></i>
                    <span class="file-name">${file.name}</span>
                    <span class="file-size">(${(fileSize / 1024 / 1024).toFixed(2)} MB)</span>
                    <span class="file-status ${status}">${statusIcon} ${statusText}</span>
                    <button type="button" class="file-remove-btn" onclick="removeFile('${fileId}')" title="Remove file">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            preview.appendChild(fileDiv);
        });
        
        // Add summary
        const summaryDiv = document.createElement('div');
        summaryDiv.className = 'upload-summary';
        summaryDiv.innerHTML = `
            <div class="summary-info">
                <strong>Summary:</strong> ${validFiles}/${files.length} valid files, 
                Total size: ${(totalSize / 1024 / 1024).toFixed(2)} MB
            </div>
        `;
        preview.appendChild(summaryDiv);
        
        // Update the file input with valid files only
        updateFileInput();
    }
    
    function removeFile(fileId) {
        selectedFiles.delete(fileId);
        const fileElement = document.querySelector(`[data-file-id="${fileId}"]`);
        if (fileElement) {
            fileElement.remove();
        }
        
        // Update summary
        const validFilesCount = selectedFiles.size;
        let totalSize = 0;
        selectedFiles.forEach(file => {
            totalSize += file.size;
        });
        
        const summaryDiv = document.querySelector('.upload-summary .summary-info');
        if (summaryDiv) {
            summaryDiv.innerHTML = `
                <strong>Summary:</strong> ${validFilesCount} valid files, 
                Total size: ${(totalSize / 1024 / 1024).toFixed(2)} MB
            `;
        }
        
        // If no files left, clear the preview
        if (validFilesCount === 0) {
            document.getElementById('filePreview').innerHTML = '';
        }
        
        updateFileInput();
    }
    
    function updateFileInput() {
        const fileInput = document.getElementById('documentUpload');
        const dt = new DataTransfer();
        
        selectedFiles.forEach(file => {
            dt.items.add(file);
        });
        
        fileInput.files = dt.files;
    }
    
    function validateForm() {
        const requiredFields = ['first_name', 'last_name', 'gender', 'username', 'email', 'phone', 'password', 'confirm_password'];
        let isValid = true;
        
        requiredFields.forEach(fieldName => {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (!field.value.trim()) {
                field.style.borderColor = '#ff4444';
                isValid = false;
            } else {
                field.style.borderColor = '#ddd';
            }
        });
        
        // Check password match
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirmPassword').value;
        if (password !== confirm) {
            document.getElementById('confirmPassword').style.borderColor = '#ff4444';
            isValid = false;
        }
        
        // Check member document requirement
        const userType = document.querySelector('input[name="user_type"]:checked').value;
        if (userType === 'member' && selectedFiles.size === 0) {
            const documentUpload = document.getElementById('documentUpload');
            documentUpload.style.borderColor = '#ff4444';
            alert('Member accounts require at least one verification document to be uploaded.');
            isValid = false;
        }
        
        // Check reCAPTCHA
        const recaptcha = grecaptcha.getResponse();
        if (!recaptcha) {
            alert('Please complete the reCAPTCHA verification.');
            isValid = false;
        }
        
        return isValid;
    }
    
    // Form success redirect with improved UX
    if (window.formSuccess) {
        setTimeout(() => {
            if (confirm('Registration successful! Click OK to go to login page, or Cancel to stay here.')) {
                window.location.href = 'login.php';
            }
        }, 2000);
    }
    
    // Auto-save form data to prevent loss
    const formFields = ['first_name', 'last_name', 'username', 'email', 'phone'];
    formFields.forEach(fieldName => {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            // Load saved data
            const savedValue = localStorage.getItem(`register_${fieldName}`);
            if (savedValue && !field.value) {
                field.value = savedValue;
            }
            
            // Save data on input
            field.addEventListener('input', () => {
                localStorage.setItem(`register_${fieldName}`, field.value);
            });
        }
    });
    
    // Clear saved data on successful submission
    if (window.formSuccess) {
        formFields.forEach(fieldName => {
            localStorage.removeItem(`register_${fieldName}`);
        });
    }
  </script>
</body>
</html>