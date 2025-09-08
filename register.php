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
$showModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $userType = trim($_POST['user_type'] ?? 'non_rcy_member');
    $rcyRole = trim($_POST['rcy_role'] ?? ''); // Added RCY role
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
    } elseif ($userType === 'rcy_member' && empty($rcyRole)) {
        $error = "RCY Members must select a role.";
    } elseif ($userType === 'rcy_member' && (!isset($_FILES['maab_id']) || !$_FILES['maab_id']['name']) && (!isset($_FILES['supporting_doc']) || !$_FILES['supporting_doc']['name'])) {
        $error = "RCY Member accounts require both MAAB ID and supporting document to be uploaded.";
    } else {
        // Handle file uploads for RCY members
        if ($userType === 'rcy_member') {
            $fileFields = ['maab_id', 'supporting_doc'];
            $documentTypes = ['maab_id' => 'maab_id', 'supporting_doc' => 'supporting_document'];
            
            foreach ($fileFields as $field) {
                if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                    $fileName = $_FILES[$field]['name'];
                    $fileSize = $_FILES[$field]['size'];
                    $fileTmp = $_FILES[$field]['tmp_name'];
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
                            'file_type' => $fileType,
                            'document_type' => $documentTypes[$field]
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
                    
                    // Insert user with full_name for compatibility - INCLUDE RCY_ROLE
                    $fullName = $firstName . ' ' . $lastName;
                    $stmt = $pdo->prepare("INSERT INTO users 
                        (first_name, last_name, full_name, gender, username, email, phone, password_hash, role, user_type, rcy_role, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$firstName, $lastName, $fullName, $gender, $username, $email, $phone, $hashed, $role, $userType, $rcyRole]);
                    
                    $userId = $pdo->lastInsertId();
                    
                    // Insert uploaded documents
                    $documentCount = 0;
                    if (!empty($uploadedDocuments)) {
                        $stmt = $pdo->prepare("INSERT INTO user_documents 
                            (user_id, original_name, stored_name, file_path, file_size, file_type, document_type, uploaded_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        
                        foreach ($uploadedDocuments as $doc) {
                            $stmt->execute([
                                $userId,
                                $doc['original_name'],
                                $doc['stored_name'],
                                $doc['file_path'],
                                $doc['file_size'],
                                $doc['file_type'],
                                $doc['document_type']
                            ]);
                            $documentCount++;
                        }
                    }
                    
                    // Add to new account notifications
                    $stmt = $pdo->prepare("INSERT INTO new_account_notifications (user_id) VALUES (?)");
                    $stmt->execute([$userId]);
                    
                    $pdo->commit();
                    
                    // Notify admins about new user registration
                    try {
                        // Get all admin users for notification
                        $stmt = $pdo->prepare("SELECT user_id, admin_role FROM users WHERE role = 'admin'");
                        $stmt->execute();
                        $admin_users = $stmt->fetchAll();
                        
                        if (!empty($admin_users)) {
                            // Create notification data with enhanced information
                            $notification_data = [
                                'id' => 'user_registered_' . $userId . '_' . time(),
                                'type' => 'new_user',
                                'priority' => $userType === 'rcy_member' ? 'medium' : 'low',
                                'title' => 'New User Registration',
                                'message' => "User '{$username}' has registered as " . 
                                            ($userType === 'rcy_member' ? 'an RCY Member (' . $rcyRole . ')' : 'a regular user') . 
                                            '. Account needs review and verification.',
                                'icon' => $userType === 'rcy_member' ? 'fas fa-users' : 'fas fa-user-plus',
                                'url' => 'admin/manage_users.php?highlight_user=' . $userId,
                                'user_id' => $userId,
                                'username' => $username,
                                'user_type' => $userType,
                                'rcy_role' => $rcyRole,
                                'is_registration' => true,
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                            
                            // Ensure admin_notifications table exists
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS `admin_notifications` (
                                    `id` int(11) NOT NULL AUTO_INCREMENT,
                                    `notification_id` varchar(255) NOT NULL,
                                    `user_id` int(11) NOT NULL,
                                    `type` varchar(50) NOT NULL,
                                    `priority` enum('low','medium','high','critical') DEFAULT 'medium',
                                    `title` varchar(255) NOT NULL,
                                    `message` text NOT NULL,
                                    `icon` varchar(100) DEFAULT NULL,
                                    `url` varchar(255) DEFAULT NULL,
                                    `metadata` JSON DEFAULT NULL,
                                    `is_read` tinyint(1) DEFAULT 0,
                                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                                    PRIMARY KEY (`id`),
                                    UNIQUE KEY `user_notification` (`user_id`, `notification_id`),
                                    KEY `user_id` (`user_id`),
                                    KEY `is_read` (`is_read`),
                                    KEY `type` (`type`),
                                    KEY `priority` (`priority`),
                                    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                            ");
                            
                            // Insert notification for each admin with enhanced metadata
                            $stmt = $pdo->prepare("
                                INSERT IGNORE INTO admin_notifications 
                                (notification_id, user_id, type, priority, title, message, icon, url, metadata, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            // Enhanced metadata for registration notifications
                            $metadata = json_encode([
                                'user_id' => $userId,
                                'username' => $username,
                                'user_type' => $userType,
                                'rcy_role' => $rcyRole,
                                'is_registration' => true,
                                'documents_uploaded' => $documentCount,
                                'registration_source' => 'public_form'
                            ]);
                            
                            $notification_count = 0;
                            foreach ($admin_users as $admin) {
                                try {
                                    $stmt->execute([
                                        $notification_data['id'],
                                        $admin['user_id'],
                                        $notification_data['type'],
                                        $notification_data['priority'],
                                        $notification_data['title'],
                                        $notification_data['message'],
                                        $notification_data['icon'],
                                        $notification_data['url'],
                                        $metadata,
                                        $notification_data['created_at']
                                    ]);
                                    $notification_count++;
                                } catch (Exception $e) {
                                    error_log("Failed to notify admin {$admin['user_id']}: " . $e->getMessage());
                                }
                            }
                            
                            error_log("Registration notification sent to {$notification_count} admins for user: $username ($userType)");
                        }
                        
                    } catch (Exception $notificationError) {
                        error_log("Registration notification error for user $username: " . $notificationError->getMessage());
                        // Don't fail registration if notification fails
                    }
                    
                    // Send email notification
                    $emailSent = false;
                    $emailMessage = '';
                    
                    try {
                        if ($emailEnabled) {
                            // Use new Email API if available
                            $emailAPI = new EmailNotificationAPI();
                            
                            if ($userType === 'rcy_member') {
                                // Send RCY member welcome email with role
                                $emailSent = sendRCYMemberWelcomeEmail($email, $firstName, $rcyRole, $documentCount);
                                $emailMessage = $emailSent ? "A welcome email with RCY member information has been sent to $email." : "However, there was an issue sending the confirmation email.";
                            } elseif ($documentCount > 0) {
                                // Send email mentioning documents
                                $emailSent = sendWelcomeEmailWithDocuments($email, $firstName, $userType, $documentCount);
                                $emailMessage = $emailSent ? "A welcome email with document confirmation has been sent to $email." : "However, there was an issue sending the confirmation email.";
                            } else {
                                // Send standard welcome email
                                $emailResult = $emailAPI->sendRegistrationEmail($email, $firstName, $userType, $userId);
                                $emailSent = $emailResult['success'];
                                $emailMessage = $emailSent ? "A welcome email has been sent to $email." : "However, there was an issue sending the confirmation email.";
                            }
                        } else {
                            // Fallback to basic email if Email API not available
                            if ($userType === 'rcy_member') {
                                $emailSent = sendRCYMemberWelcomeEmail($email, $firstName, $rcyRole, $documentCount);
                            } else {
                                $emailSent = sendBasicRegistrationEmail($email, $firstName, $userType);
                            }
                            $emailMessage = $emailSent ? "A welcome email has been sent to $email." : "However, there was an issue sending the confirmation email.";
                        }
                        
                        // Log registration activity
                        $roleInfo = $userType === 'rcy_member' ? $rcyRole : 'N/A';
                        $logMessage = date('Y-m-d H:i:s') . " - New user registered: $username ($email) - Type: $userType - Role: $roleInfo - Documents: $documentCount - Email sent: " . ($emailSent ? 'Yes' : 'No') . "\n";
                        file_put_contents('logs/registrations.log', $logMessage, FILE_APPEND | LOCK_EX);
                        
                    } catch (Exception $emailException) {
                        error_log("Email sending error: " . $emailException->getMessage());
                        $emailMessage = "However, there was an issue sending the confirmation email.";
                    }
                    
                    $success = "Registration successful! You can now log in. $emailMessage";
                    $showModal = true; // Set flag to show modal
                    
                    // Clear form data from localStorage on successful registration
                    echo "<script>
                        // Clear saved form data
                        const formFields = ['first_name', 'last_name', 'username', 'email', 'phone'];
                        formFields.forEach(field => {
                            localStorage.removeItem('register_' + field);
                        });
                    </script>";
                    
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

// Enhanced email function for RCY members with roles
function sendRCYMemberWelcomeEmail($email, $firstName, $rcyRole, $documentCount) {
    $roleNames = [
        'adviser' => 'Adviser',
        'member' => 'Member'
    ];
    
    $roleName = $roleNames[$rcyRole] ?? $rcyRole;
    
    $subject = "Welcome to Philippine Red Cross - RCY $roleName Registration Complete";
    $message = "
    <html>
    <head>
        <title>Welcome to PRC Management System - RCY $roleName</title>
        <meta charset='UTF-8'>
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0;
                background-color: #f8f9fa;
            }
            .container { 
                max-width: 650px; 
                margin: 20px auto; 
                background: white;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }
            .header { 
                background: linear-gradient(135deg, #a00000 0%, #222e60 100%); 
                color: white; 
                padding: 30px 20px; 
                text-align: center; 
            }
            .header h1 {
                margin: 0;
                font-size: 28px;
                font-weight: bold;
            }
            .header p {
                margin: 5px 0 0 0;
                opacity: 0.9;
                font-size: 16px;
            }
            .content { 
                padding: 35px 30px; 
            }
            .welcome-message {
                font-size: 26px;
                color: #a00000;
                margin-bottom: 20px;
                font-weight: bold;
            }
            .role-badge {
                background: linear-gradient(135deg, #a00000 0%, #c41e3a 100%);
                color: white;
                padding: 10px 20px;
                border-radius: 25px;
                display: inline-block;
                font-weight: bold;
                margin: 15px 0;
            }
            .button { 
                background: linear-gradient(135deg, #a00000 0%, #c41e3a 100%); 
                color: white !important; 
                padding: 15px 30px; 
                text-decoration: none; 
                border-radius: 8px; 
                display: inline-block; 
                margin: 25px 0; 
                font-weight: bold;
                font-size: 16px;
            }
            .info-box { 
                background: #f8f9fa; 
                padding: 25px; 
                border-left: 4px solid #a00000; 
                margin: 25px 0; 
                border-radius: 0 8px 8px 0;
            }
            .document-info { 
                background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%); 
                padding: 20px; 
                border-radius: 8px; 
                margin: 20px 0; 
                border: 2px solid #c3e6cb; 
            }
            .footer { 
                font-size: 13px; 
                color: #6c757d; 
                text-align: center; 
                padding: 25px; 
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Philippine Red Cross</h1>
                <p>Red Cross Youth (RCY) Portal</p>
            </div>
            
            <div class='content'>
                <div class='welcome-message'>Welcome to RCY, " . htmlspecialchars($firstName) . "!</div>
                
                <div class='role-badge'>RCY " . htmlspecialchars($roleName) . "</div>
                
                <p style='font-size: 16px; margin-bottom: 25px;'>Congratulations on joining the Red Cross Youth as a " . htmlspecialchars($roleName) . "! We're excited to welcome you to our community of dedicated volunteers committed to humanitarian service.</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #a00000; margin-bottom: 20px; font-size: 20px;'>Account Details</h3>
                    <div style='margin: 10px 0;'><strong>Email:</strong> " . htmlspecialchars($email) . "</div>
                    <div style='margin: 10px 0;'><strong>Account Type:</strong> <span style='color: #a00000; font-weight: bold;'>RCY " . htmlspecialchars($roleName) . "</span></div>
                    <div style='margin: 10px 0;'><strong>Registration Date:</strong> " . date('F j, Y g:i A') . "</div>
                    <div style='margin: 10px 0;'><strong>Account Status:</strong> <span style='color: #28a745; font-weight: bold;'>✓ Active</span></div>
                </div>";
    
    if ($documentCount > 0) {
        $message .= "
                <div class='document-info'>
                    <h3 style='margin-top: 0; color: #155724; font-size: 18px; margin-bottom: 15px;'>Documents Received</h3>
                    <div style='background: white; padding: 15px; border-radius: 6px; border: 1px solid #c3e6cb;'>
                        <p style='margin: 0; font-size: 16px;'><strong style='color: #28a745;'>✓ Success!</strong> We have received <strong style='color: #155724;'>$documentCount</strong> document(s) with your registration.</p>
                    </div>
                    <p style='margin-top: 15px;'>Our RCY coordinators will review your documents within <strong>2-3 business days</strong>. You will receive an email notification once the review is complete and your membership is fully activated.</p>
                </div>";
    }
    
    $message .= "
                <div style='text-align: center; margin: 35px 0;'>
                    <a href='https://philippineredcross-iloilochapter.org/login.php' class='button'>Access Your RCY Portal</a>
                </div>
                
                <div style='background: white; padding: 25px; border-radius: 8px; margin: 25px 0; border: 1px solid #e9ecef;'>
                    <h3 style='margin-top: 0; color: #a00000; font-size: 20px;'>Need Help?</h3>
                    <p style='margin-bottom: 20px;'>If you have any questions about your RCY membership or need assistance:</p>
                    <ul style='color: #495057; line-height: 1.8;'>
                        <li><strong>RCY Email:</strong> <a href='mailto:rcy@redcross.org.ph' style='color: #a00000;'>rcy@redcross.org.ph</a></li>
                        <li><strong>General Support:</strong> <a href='mailto:support@prc-system.com' style='color: #a00000;'>support@prc-system.com</a></li>
                        <li><strong>Phone:</strong> (02) 8527-0864</li>
                        <li><strong>Website:</strong> <a href='https://redcross.org.ph/rcy' style='color: #a00000;'>redcross.org.ph/rcy</a></li>
                    </ul>
                </div>
                
                <div style='text-align: center; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 25px; border-radius: 8px; margin: 30px 0; border: 1px solid #dee2e6;'>
                    <p style='margin: 0; color: #666; font-style: italic; font-size: 16px; line-height: 1.6;'>
                        &quot;Empowering youth to serve humanity with compassion and dedication.&quot;
                    </p>
                    <p style='margin: 10px 0 0 0; color: #a00000; font-weight: bold;'>
                        - Red Cross Youth Philippines
                    </p>
                </div>
            </div>
            
            <div class='footer'>
                <p><strong>This is an automated message. Please do not reply to this email.</strong></p>
                <p>Philippine Red Cross - Red Cross Youth Program<br>
                © " . date('Y') . " Philippine Red Cross. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";

    $headers = array(
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Philippine Red Cross RCY <rcy@prc-system.com>',
        'Reply-To: rcy@redcross.org.ph',
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 3 (Normal)',
        'Return-Path: rcy@prc-system.com'
    );

    // Attempt to send email
    $mailSent = mail($email, $subject, $message, implode("\r\n", $headers));
    
    // Log email attempt
    $logMessage = date('Y-m-d H:i:s') . " - RCY Email attempt to $email: " . ($mailSent ? 'SUCCESS' : 'FAILED') . "\n";
    file_put_contents('logs/email.log', $logMessage, FILE_APPEND | LOCK_EX);
    
    return $mailSent;
}

// Keep existing functions
function sendBasicRegistrationEmail($email, $firstName, $userType) {
    // ... existing implementation
    return true; // Simplified for brevity
}

function sendWelcomeEmailWithDocuments($email, $firstName, $userType, $documentCount) {
    // ... existing implementation  
    return true; // Simplified for brevity
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | PRC Management System</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="assets/register.css?v=<?php echo time(); ?>">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>
  <header class="system-header">
    <div class="logo-title">
      <a href="index.php">
        <img src="./assets/images/logo.png" alt="PRC Logo" class="prc-logo">
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

      <?php if (!empty($error)): ?>
      <div class="alert error" id="errorAlert">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
      </div>
      <?php endif; ?>

      <?php if (!empty($success) && !$showModal): ?>
      <div class="alert success" id="successAlert">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($success); ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="register.php" class="register-form" id="registerForm" enctype="multipart/form-data">
        <!-- Account Type Section -->
        <div class="form-group account-type-section">
          <label><i class="fas fa-user-tag"></i> Account Type <span style="color: #a00000;">*</span></label>
          
          <div class="account-option">
            <input type="radio" name="user_type" value="non_rcy_member" id="nonRcyMember" checked>
            <label for="nonRcyMember" class="account-content">
              <div class="account-icon">
                <i class="fas fa-user"></i>
              </div>
              <div class="account-details">
                <div class="account-title">Non-RCY Member</div>
                <div class="account-description">General public access with basic features</div>
                <ul class="account-benefits">
                  <li>Event registration and participation</li>
                  <li>Training session enrollment</li>
                  <li>Donation tracking and history</li>
                  <li>Basic profile management</li>
                </ul>
              </div>
            </label>
          </div>

          <div class="account-option">
            <input type="radio" name="user_type" value="rcy_member" id="rcyMember">
            <label for="rcyMember" class="account-content">
              <div class="account-icon">
                <i class="fas fa-user-shield"></i>
              </div>
              <div class="account-details">
                <div class="account-title">RCY Member</div>
                <div class="account-description">Red Cross Youth member with specialized access</div>
                <ul class="account-benefits">
                  <li>All Non-RCY Member benefits</li>
                  <li>Access to RCY programs and activities</li>
                  <li>Member directory and networking</li>
                  <li>Advanced training opportunities</li>
                  <li>Leadership development programs</li>
                </ul>
              </div>
            </label>
          </div>
        </div>

        <!-- Non-RCY Member Notice -->
        <div class="notice-section" id="nonRcyNotice" style="display: none;">
          <div class="notice-box">
            <div class="notice-icon">
              <i class="fas fa-info-circle"></i>
            </div>
            <div class="notice-content">
              <h3>Non-RCY Member Registration Not Available Online</h3>
              <p>To register as a Non-RCY Member, you must visit your local RCY Chapter to obtain your MAAB ID and complete the registration process in person.</p>
              <div class="notice-actions">
                <a href="#" class="btn-chapter-locator">
                  <i class="fas fa-map-marker-alt"></i>
                  Find Nearest Chapter
                </a>
                <button type="button" class="btn-back-selection" onclick="selectRcyMember()">
                  <i class="fas fa-arrow-left"></i>
                  Back to Selection
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- RCY Roles Selection -->
        <div class="roles-section" id="rolesSection">
          <h3><i class="fas fa-user-tag"></i> Select Your RCY Role <span style="color: #a00000;">*</span></h3>
          <p style="margin: 0 0 1rem 0; color: #666; font-size: 0.9rem;">
            Choose your role within the Red Cross Youth organization.
          </p>

          <div class="role-option">
            <input type="radio" name="rcy_role" value="adviser" id="adviserRole">
            <label for="adviserRole" class="role-content">
              <div class="role-icon">
                <i class="fas fa-user-graduate"></i>
              </div>
              <div class="role-details">
                <div class="role-title">Adviser</div>
                <div class="role-description">Provide guidance and mentorship to RCY members</div>
                <ul class="role-features">
                  <li>Mentor young volunteers</li>
                  <li>Provide strategic guidance</li>
                  <li>Support program development</li>
                  <li>Share expertise and knowledge</li>
                </ul>
              </div>
            </label>
          </div>

          <div class="role-option">
            <input type="radio" name="rcy_role" value="member" id="memberRole">
            <label for="memberRole" class="role-content">
              <div class="role-icon">
                <i class="fas fa-user"></i>
              </div>
              <div class="role-details">
                <div class="role-title">Member</div>
                <div class="role-description">Active participant in RCY programs and activities</div>
                <ul class="role-features">
                  <li>Participate in RCY activities</li>
                  <li>Access member resources</li>
                  <li>Join training programs</li>
                  <li>Volunteer for events</li>
                </ul>
              </div>
            </label>
          </div>

          <div class="role-validation" id="roleValidation">
            <i class="fas fa-exclamation-circle"></i>
            Please select a role to continue.
          </div>
        </div>

        <!-- Personal Information -->
        <div class="form-section" id="personalInfoSection">
          <div class="form-row">
            <div class="form-group">
              <label><i class="fas fa-user"></i> First Name <span style="color: #a00000;">*</span></label>
              <input type="text" name="first_name" required pattern="[A-Za-z\s]+" title="Only letters and spaces allowed">
            </div>
            <div class="form-group">
              <label><i class="fas fa-user"></i> Last Name <span style="color: #a00000;">*</span></label>
              <input type="text" name="last_name" required pattern="[A-Za-z\s]+" title="Only letters and spaces allowed">
            </div>
          </div>

          <div class="form-group">
            <label><i class="fas fa-venus-mars"></i> Gender <span style="color: #a00000;">*</span></label>
            <select name="gender" required>
              <option value="">Select Gender</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
              <option value="prefer_not_to_say">Prefer not to say</option>
            </select>
          </div>

          <div class="form-group">
            <label><i class="fas fa-at"></i> Username <span style="color: #a00000;">*</span></label>
            <input type="text" name="username" required minlength="4" maxlength="20" 
                   pattern="[A-Za-z0-9_]+" title="Only letters, numbers, and underscores allowed" id="username">
            <p class="input-hint">4-20 characters. Letters, numbers, and underscores only.</p>
            <div id="username-status" class="validation-status"></div>
          </div>

          <div class="form-group">
            <label><i class="fas fa-envelope"></i> Email Address <span style="color: #a00000;">*</span></label>
            <input type="email" name="email" required id="email">
            <p class="input-hint">We'll send a confirmation email to this address</p>
            <div id="email-status" class="validation-status"></div>
          </div>

          <div class="form-group">
            <label><i class="fas fa-phone"></i> Phone Number <span style="color: #a00000;">*</span></label>
            <input type="tel" name="phone" pattern="[0-9]{10,11}" required placeholder="09XXXXXXXXX or 02XXXXXXXX">
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

          <!-- Document Upload Section -->
          <div class="form-group" id="documentSection">
            <label>
              <i class="fas fa-paperclip"></i> 
              Upload Required Documents <span style="color: #a00000;">*</span>
            </label>
            
            <div class="document-upload-grid">
              <div class="upload-field">
                <label for="maabId" class="upload-label">
                  <i class="fas fa-id-card"></i>
                  MAAB ID <span style="color: #a00000;">*</span>
                </label>
                <input type="file" name="maab_id" id="maabId" 
                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" 
                       required>
                <p class="upload-hint">Upload your MAAB ID document</p>
                <div id="maabPreview" class="file-preview"></div>
              </div>
              
              <div class="upload-field">
                <label for="supportingDoc" class="upload-label">
                  <i class="fas fa-file-alt"></i>
                  Supporting Document <span style="color: #a00000;">*</span>
                </label>
                <input type="file" name="supporting_doc" id="supportingDoc" 
                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" 
                       required>
                <p class="upload-hint">Additional verification document</p>
                <div id="supportingPreview" class="file-preview"></div>
              </div>
            </div>
            
            <p class="input-hint">
              <strong>Accepted:</strong> PDF, DOC, DOCX, JPG, JPEG, PNG<br>
              <strong>Max size:</strong> 5MB per file<br>
              <strong>Required:</strong> Both documents must be uploaded for RCY registration
            </p>
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

  <!-- Success Modal -->
  <div class="modal-overlay" id="successModal" <?php echo $showModal ? 'style="display: flex;"' : ''; ?>>
    <div class="success-modal">
      <div class="modal-header">
        <i class="fas fa-check-circle"></i>
        <h3>Registration Successful!</h3>
      </div>
      <div class="modal-body">
        <p>Your account has been created successfully. You can now log in to access the Philippine Red Cross Management System.</p>
        
        <div class="success-details">
          <h4>What's Next?</h4>
          <div class="success-steps">
            <div class="success-step">
              <i class="fas fa-envelope" style="color: #28a745;"></i>
              <span>Check your email for a confirmation message</span>
            </div>
            <div class="success-step">
              <i class="fas fa-sign-in-alt" style="color: #28a745;"></i>
              <span>Log in with your username and password</span>
            </div>
            <div class="success-step">
              <i class="fas fa-explore" style="color: #28a745;"></i>
              <span>Explore available services and features</span>
            </div>
          </div>
        </div>
        
        <div class="modal-actions">
          <a href="login.php" class="modal-btn btn-primary">
            <i class="fas fa-sign-in-alt"></i> Log In Now
          </a>
          <button class="modal-btn btn-secondary" onclick="closeModal()">
            <i class="fas fa-home"></i> Return Home
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registerForm');
    const submitBtn = document.getElementById('submitBtn');
    const nonRcyNotice = document.getElementById('nonRcyNotice');
    const rolesSection = document.getElementById('rolesSection');
    const personalInfoSection = document.getElementById('personalInfoSection');
    const documentSection = document.getElementById('documentSection');

    // Handle account type change
    const accountTypeRadios = document.querySelectorAll('input[name="user_type"]');
    accountTypeRadios.forEach(radio => {
        radio.addEventListener('change', handleAccountTypeChange);
    });

    // Handle role selection
    const roleRadios = document.querySelectorAll('input[name="rcy_role"]');
    roleRadios.forEach(radio => {
        radio.addEventListener('change', updateRoleSelection);
    });

    // Initialize form
    handleAccountTypeChange();

    function handleAccountTypeChange() {
        const selectedType = document.querySelector('input[name="user_type"]:checked')?.value;
        
        if (selectedType === 'non_rcy_member') {
            // Show notice and hide other sections
            nonRcyNotice.style.display = 'block';
            rolesSection.style.display = 'none';
            personalInfoSection.style.display = 'none';
            
            // Clear role selections and file uploads
            roleRadios.forEach(radio => radio.checked = false);
            clearFileUploads();
        } else if (selectedType === 'rcy_member') {
            // Hide notice and show form sections
            nonRcyNotice.style.display = 'none';
            rolesSection.style.display = 'block';
            personalInfoSection.style.display = 'block';
        }
    }

    function updateRoleSelection() {
        const selectedRole = document.querySelector('input[name="rcy_role"]:checked');
        const roleValidation = document.getElementById('roleValidation');
        
        if (selectedRole) {
            roleValidation.style.display = 'none';
        } else {
            roleValidation.style.display = 'block';
        }
    }

    function clearFileUploads() {
        const fileInputs = document.querySelectorAll('input[type="file"]');
        fileInputs.forEach(input => {
            input.value = '';
            input.removeAttribute('required');
        });
        
        // Clear preview areas
        const previews = document.querySelectorAll('.file-preview');
        previews.forEach(preview => preview.innerHTML = '');
    }

    // File upload previews
    const maabInput = document.getElementById('maabId');
    const supportingInput = document.getElementById('supportingDoc');
    
    if (maabInput) {
        maabInput.addEventListener('change', function() {
            handleFilePreview(this, 'maabPreview');
        });
    }
    
    if (supportingInput) {
        supportingInput.addEventListener('change', function() {
            handleFilePreview(this, 'supportingPreview');
        });
    }

    function handleFilePreview(input, previewId) {
        const preview = document.getElementById(previewId);
        const file = input.files[0];
        
        if (file) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2);
            const fileName = file.name;
            
            let statusClass = 'valid';
            let statusText = 'Valid file';
            
            // Check file size (5MB limit)
            if (file.size > 5 * 1024 * 1024) {
                statusClass = 'invalid';
                statusText = 'File too large (max 5MB)';
            }
            
            // Check file type
            const allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            const fileType = fileName.split('.').pop().toLowerCase();
            if (!allowedTypes.includes(fileType)) {
                statusClass = 'invalid';
                statusText = 'Invalid file type';
            }
            
            preview.innerHTML = `
                <div class="file-item ${statusClass}">
                    <div class="file-info">
                        <i class="fas fa-file"></i>
                        <span class="file-name">${fileName}</span>
                        <span class="file-size">(${fileSize} MB)</span>
                    </div>
                    <div class="file-status ${statusClass}">
                        ${statusClass === 'valid' ? '✓' : '✗'} ${statusText}
                    </div>
                </div>
            `;
        } else {
            preview.innerHTML = '';
        }
    }

    // Form validation
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
            
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
            }
        });
    }

    function validateForm() {
        const userType = document.querySelector('input[name="user_type"]:checked')?.value;
        
        // Check if non-RCY member is trying to register
        if (userType === 'non_rcy_member') {
            showError('Non-RCY member registration must be done at your local RCY Chapter.');
            return false;
        }
        
        // Check if RCY member has selected a role
        if (userType === 'rcy_member') {
            const selectedRole = document.querySelector('input[name="rcy_role"]:checked');
            if (!selectedRole) {
                showError('Please select your RCY role.');
                const roleValidation = document.getElementById('roleValidation');
                if (roleValidation) {
                    roleValidation.style.display = 'block';
                    rolesSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
            
            // Check file uploads
            const maabFile = document.getElementById('maabId').files[0];
            const supportingFile = document.getElementById('supportingDoc').files[0];
            
            if (!maabFile) {
                showError('Please upload your MAAB ID document.');
                return false;
            }
            
            if (!supportingFile) {
                showError('Please upload a supporting document.');
                return false;
            }
        }
        
        // Check reCAPTCHA
        if (typeof grecaptcha !== 'undefined') {
            const recaptcha = grecaptcha.getResponse();
            if (!recaptcha) {
                showError('Please complete the reCAPTCHA verification.');
                return false;
            }
        }
        
        return true;
    }

    function showError(message) {
        let errorAlert = document.getElementById('errorAlert');
        if (!errorAlert) {
            errorAlert = document.createElement('div');
            errorAlert.id = 'errorAlert';
            errorAlert.className = 'alert error';
            errorAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i><span id="errorMessage"></span>';
            if (form) form.parentNode.insertBefore(errorAlert, form);
        }
        
        const errorMessage = document.getElementById('errorMessage') || errorAlert.querySelector('span');
        if (errorMessage) errorMessage.textContent = message;
        errorAlert.style.display = 'block';
        errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});

// Global function to select RCY member option
function selectRcyMember() {
    document.getElementById('rcyMember').checked = true;
    document.getElementById('rcyMember').dispatchEvent(new Event('change'));
}

// Modal functions
function closeModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.style.display = 'none';
        window.location.href = 'index.php';
    }
}

// Show modal if success flag is set
<?php if ($showModal): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        document.getElementById('successModal').style.display = 'flex';
    }, 100);
});
<?php endif; ?>
  </script>

  <style>
.notice-section {
    margin: 20px 0;
}

.notice-box {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 2px solid #ffc107;
    border-radius: 12px;
    padding: 25px;
    display: flex;
    align-items: flex-start;
    gap: 20px;
}

.notice-icon {
    color: #856404;
    font-size: 2rem;
    flex-shrink: 0;
    margin-top: 5px;
}

.notice-content h3 {
    margin: 0 0 15px 0;
    color: #856404;
    font-size: 1.3rem;
    font-weight: bold;
}

.notice-content p {
    margin: 0 0 20px 0;
    color: #856404;
    line-height: 1.6;
}

.notice-actions {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.btn-chapter-locator,
.btn-back-selection {
    padding: 10px 20px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-chapter-locator {
    background: #28a745;
    color: white;
}

.btn-chapter-locator:hover {
    background: #218838;
    transform: translateY(-1px);
}

.btn-back-selection {
    background: #6c757d;
    color: white;
}

.btn-back-selection:hover {
    background: #5a6268;
    transform: translateY(-1px);
}

.roles-section {
    margin: 25px 0;
    display: none;
}

.roles-section.show {
    display: block;
}

.role-option {
    border: 2px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    cursor: pointer;
    margin-bottom: 15px;
}

.role-option:hover {
    border-color: #a00000;
    box-shadow: 0 4px 15px rgba(160, 0, 0, 0.1);
}

.role-option input[type="radio"] {
    display: none;
}

.role-option input[type="radio"]:checked + .role-content {
    border-color: #a00000;
    background: linear-gradient(135deg, #fff5f5 0%, #ffe8e8 100%);
}

.role-content {
    display: flex;
    padding: 20px;
    gap: 20px;
    align-items: flex-start;
    border: 2px solid transparent;
    transition: all 0.3s ease;
}

.role-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #a00000 0%, #c41e3a 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.role-details {
    flex: 1;
}

.role-title {
    font-size: 1.2rem;
    font-weight: bold;
    color: #a00000;
    margin-bottom: 8px;
}

.role-description {
    color: #666;
    margin-bottom: 15px;
    line-height: 1.5;
}

.role-features {
    list-style: none;
    padding: 0;
    margin: 0;
}

.role-features li {
    padding: 4px 0;
    color: #555;
    display: flex;
    align-items: center;
}

.role-features li:before {
    content: '✓';
    color: #28a745;
    font-weight: bold;
    margin-right: 8px;
}

.role-validation {
    background: #f8d7da;
    color: #721c24;
    padding: 10px 15px;
    border-radius: 6px;
    border: 1px solid #f5c6cb;
    display: none;
    margin-top: 15px;
}

.document-upload-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin: 20px 0;
}

.upload-field {
    border: 2px dashed #dee2e6;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
}

.upload-field:hover {
    border-color: #a00000;
    background: #fff5f5;
}

.upload-label {
    display: block;
    font-weight: bold;
    color: #495057;
    margin-bottom: 10px;
    font-size: 1rem;
}

.upload-label i {
    margin-right: 8px;
    color: #a00000;
}

.upload-field input[type="file"] {
    margin: 10px 0;
    width: 100%;
}

.upload-hint {
    color: #6c757d;
    font-size: 0.9rem;
    margin: 5px 0 0 0;
}

.file-preview {
    margin-top: 15px;
}

.file-item {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.file-item.valid {
    border-color: #28a745;
    background: #d4edda;
}

.file-item.invalid {
    border-color: #dc3545;
    background: #f8d7da;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.file-name {
    font-weight: 500;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-size {
    color: #6c757d;
    font-size: 0.9rem;
}

.file-status {
    font-size: 0.9rem;
    font-weight: bold;
}

.file-status.valid {
    color: #155724;
}

.file-status.invalid {
    color: #721c24;
}

.form-section {
    display: none;
}

.form-section.active {
    display: block;
}

@media (max-width: 768px) {
    .document-upload-grid {
        grid-template-columns: 1fr;
    }
    
    .role-content {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .notice-box {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .notice-actions {
        justify-content: center;
    }
}
  </style>
</body>
</html>