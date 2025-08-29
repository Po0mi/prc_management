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
    $userType = trim($_POST['user_type'] ?? 'non_rcy_member'); // non_rcy_member or rcy_member
    $selectedServices = $_POST['services'] ?? []; // Array of selected services for RCY members
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
    } elseif ($userType === 'rcy_member' && empty($selectedServices)) {
        $error = "RCY Members must select at least one service.";
    } elseif ($userType === 'rcy_member' && (!isset($_FILES['documents']) || empty($_FILES['documents']['name'][0]))) {
        $error = "RCY Member accounts require at least one verification document to be uploaded.";
    } else {
        // Validate selected services if RCY member
        if ($userType === 'rcy_member') {
            $validServices = ['health', 'safety', 'welfare', 'disaster_management', 'red_cross_youth'];
            foreach ($selectedServices as $service) {
                if (!in_array($service, $validServices)) {
                    $error = "Invalid service selection detected.";
                    break;
                }
            }
        }
        
        // Handle file uploads
        if (!$error && isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
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
                    
                    // Convert services array to JSON string for storage
                    $servicesJson = $userType === 'rcy_member' ? json_encode($selectedServices) : null;
                    
                    // Insert user with full_name for compatibility
                    $fullName = $firstName . ' ' . $lastName;
                    $stmt = $pdo->prepare("INSERT INTO users 
                        (first_name, last_name, full_name, gender, username, email, phone, password_hash, role, user_type, services, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$firstName, $lastName, $fullName, $gender, $username, $email, $phone, $hashed, $role, $userType, $servicesJson]);
                    
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
                    
                    // Insert RCY member services into separate table if applicable
                    if ($userType === 'rcy_member' && !empty($selectedServices)) {
                        $stmt = $pdo->prepare("INSERT INTO user_services (user_id, service_type, joined_at) VALUES (?, ?, NOW())");
                        foreach ($selectedServices as $service) {
                            $stmt->execute([$userId, $service]);
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
                            
                            if ($userType === 'rcy_member') {
                                // Send RCY member welcome email with services
                                $emailSent = sendRCYMemberWelcomeEmail($email, $firstName, $selectedServices, $documentCount);
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
                                $emailSent = sendRCYMemberWelcomeEmail($email, $firstName, $selectedServices, $documentCount);
                            } else {
                                $emailSent = sendBasicRegistrationEmail($email, $firstName, $userType);
                            }
                            $emailMessage = $emailSent ? "A welcome email has been sent to $email." : "However, there was an issue sending the confirmation email.";
                        }
                        
                        // Log registration activity
                        $servicesList = $userType === 'rcy_member' ? implode(', ', $selectedServices) : 'N/A';
                        $logMessage = date('Y-m-d H:i:s') . " - New user registered: $username ($email) - Type: $userType - Services: $servicesList - Documents: $documentCount - Email sent: " . ($emailSent ? 'Yes' : 'No') . "\n";
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
    $accountTypeName = $userType === 'rcy_member' ? 'RCY Member' : 'Non-RCY Member';
    
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
                    Account Type: " . htmlspecialchars($accountTypeName) . "<br>
                    Registration Date: " . date('Y-m-d H:i:s') . "
                </div>
                
                <p>Your account has been successfully created. You can now log in to access the system.</p>
                
                <div style='text-align: center;'>
                    <a href='https://philippineredcross-iloilochapter.org/login.php' class='button'>Login to Your Account</a>
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

// Enhanced welcome email for RCY members with services
function sendRCYMemberWelcomeEmail($email, $firstName, $selectedServices, $documentCount) {
    $serviceNames = [
        'health' => 'Health Services',
        'safety' => 'Safety Services',
        'welfare' => 'Welfare Services',
        'disaster_management' => 'Disaster Management',
        'red_cross_youth' => 'Red Cross Youth'
    ];
    
    $servicesHtml = '';
    foreach ($selectedServices as $service) {
        $serviceName = $serviceNames[$service] ?? $service;
        $servicesHtml .= "<li style='padding: 5px 0; color: #155724;'>✓ " . htmlspecialchars($serviceName) . "</li>";
    }
    
    $subject = "Welcome to Philippine Red Cross - RCY Member Registration Complete";
    $message = "
    <html>
    <head>
        <title>Welcome to PRC Management System - RCY Member</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 30px; background: #f9f9f9; }
            .button { background: #a00000; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 20px 0; font-weight: bold; }
            .info-box { background: white; padding: 20px; border-left: 4px solid #a00000; margin: 20px 0; border-radius: 0 5px 5px 0; }
            .services-box { background: #e8f5e8; padding: 20px; border-radius: 5px; margin: 15px 0; border: 1px solid #c3e6cb; }
            .document-info { background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 15px 0; border: 1px solid #c3e6cb; }
            .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; background: #f8f9fa; border-radius: 0 0 8px 8px; }
            .highlight { color: #a00000; font-weight: bold; }
            .services-list { list-style: none; padding: 0; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏥 Philippine Red Cross</h1>
                <p>Red Cross Youth (RCY) Member Portal</p>
            </div>
            
            <div class='content'>
                <h2 style='color: #a00000;'>Welcome to RCY, " . htmlspecialchars($firstName) . "! 🎉</h2>
                
                <p>Congratulations on joining the Red Cross Youth! We're excited to welcome you to our community of dedicated volunteers committed to humanitarian service.</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #a00000;'>📋 Account Details</h3>
                    <strong>Email:</strong> " . htmlspecialchars($email) . "<br>
                    <strong>Account Type:</strong> <span class='highlight'>RCY Member</span><br>
                    <strong>Registration Date:</strong> " . date('F j, Y g:i A') . "<br>
                    <strong>Account Status:</strong> <span style='color: #28a745;'>✓ Active</span>
                </div>
                
                <div class='services-box'>
                    <h3 style='margin-top: 0; color: #155724;'>🤝 Your Selected Services</h3>
                    <p><strong>You have registered for the following RCY services:</strong></p>
                    <ul class='services-list'>
                        $servicesHtml
                    </ul>
                    <p style='font-size: 0.9em; color: #666; margin-top: 15px;'>
                        <em>You will receive additional information about each service via email within the next few days. Service coordinators may contact you to schedule orientation sessions.</em>
                    </p>
                </div>";
    
    if ($documentCount > 0) {
        $message .= "
                <div class='document-info'>
                    <h3 style='margin-top: 0; color: #155724;'>📄 Documents Received</h3>
                    <p><strong>✓ Success!</strong> We have received <strong>$documentCount</strong> document(s) with your registration.</p>
                    <p>Our RCY coordinators will review your documents within 2-3 business days. You will receive an email notification once the review is complete and your membership is fully activated.</p>
                </div>";
    }
    
    $message .= "
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='login.php' class='button'>🔐 Access Your RCY Portal</a>
                </div>
                
                <div style='background: white; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #a00000;'>🚀 What's Next?</h3>
                    <ol style='color: #495057; line-height: 1.6;'>
                        <li><strong>Orientation:</strong> You'll receive details about RCY orientation sessions</li>
                        <li><strong>Training:</strong> Service-specific training schedules will be provided</li>
                        <li><strong>Activities:</strong> Join upcoming volunteer activities and events</li>
                        <li><strong>Community:</strong> Connect with other RCY members in your area</li>
                    </ol>
                </div>
                
                <div style='background: white; padding: 20px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #a00000;'>📞 Need Help?</h3>
                    <p>If you have any questions about your RCY membership:</p>
                    <ul>
                        <li><strong>RCY Email:</strong> rcy@redcross.org.ph</li>
                        <li><strong>Phone:</strong> (02) 8527-0864</li>
                        <li><strong>Website:</strong> <a href='https://redcross.org.ph/rcy'>redcross.org.ph/rcy</a></li>
                    </ul>
                </div>
                
                <p style='text-align: center; color: #666; font-style: italic; margin-top: 30px;'>
                    \"Empowering youth to serve humanity with compassion and dedication.\"<br>
                    <strong>- Red Cross Youth Philippines</strong>
                </p>
            </div>
            
            <div class='footer'>
                <p><strong>This is an automated message. Please do not reply to this email.</strong><br>
                Philippine Red Cross - Red Cross Youth Program<br>
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
        'X-Priority: 3',
        'Return-Path: rcy@prc-system.com'
    );

    return mail($email, $subject, $message, implode("\r\n", $headers));
}

// Enhanced welcome email function with document notification (existing function, kept for compatibility)
function sendWelcomeEmailWithDocuments($email, $firstName, $userType, $documentCount) {
    $accountTypeName = $userType === 'rcy_member' ? 'RCY Member' : 'Non-RCY Member';
    
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
                    <strong>Account Type:</strong> <span class='highlight'>" . htmlspecialchars($accountTypeName) . "</span><br>
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

      <div class="alert error" id="errorAlert" style="display: none;">
        <i class="fas fa-exclamation-circle"></i>
        <span id="errorMessage"></span>
      </div>

      <div class="alert success" id="successAlert" style="display: none;">
        <i class="fas fa-check-circle"></i>
        <span id="successMessage"></span>
      </div>

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
                <div class="account-description">Red Cross Youth member with specialized services</div>
                <ul class="account-benefits">
                  <li>All Non-RCY Member benefits</li>
                  <li>Access to specialized service programs</li>
                  <li>Volunteer coordination tools</li>
                  <li>Advanced training opportunities</li>
                  <li>Service hour tracking</li>
                </ul>
              </div>
            </label>
          </div>
        </div>

        <!-- RCY Services Selection -->
        <div class="services-section" id="servicesSection">
          <h3><i class="fas fa-hands-helping"></i> Select Your Services <span style="color: #a00000;">*</span></h3>
          <p style="margin: 0 0 1rem 0; color: #666; font-size: 0.9rem;">
            Choose the Red Cross services you want to participate in. You can select multiple services.
          </p>

          <div class="services-grid">
            <div class="service-option">
              <input type="checkbox" name="services[]" value="health" id="healthService">
              <label for="healthService" class="service-content">
                <div class="service-icon">
                  <i class="fas fa-heartbeat"></i>
                </div>
                <div class="service-details">
                  <div class="service-title">Health Services</div>
                  <div class="service-description">Healthcare support and medical assistance programs</div>
                  <ul class="service-features">
                    <li>First Aid training and certification</li>
                    <li>Blood donation drives</li>
                    <li>Health education programs</li>
                    <li>Medical mission support</li>
                  </ul>
                </div>
              </label>
            </div>

            <div class="service-option">
              <input type="checkbox" name="services[]" value="safety" id="safetyService">
              <label for="safetyService" class="service-content">
                <div class="service-icon">
                  <i class="fas fa-shield-alt"></i>
                </div>
                <div class="service-details">
                  <div class="service-title">Safety Services</div>
                  <div class="service-description">Community safety and emergency preparedness</div>
                  <ul class="service-features">
                    <li>Water safety and swimming instruction</li>
                    <li>CPR and AED training</li>
                    <li>Safety education programs</li>
                    <li>Emergency response training</li>
                  </ul>
                </div>
              </label>
            </div>

            <div class="service-option">
              <input type="checkbox" name="services[]" value="welfare" id="welfareService">
              <label for="welfareService" class="service-content">
                <div class="service-icon">
                  <i class="fas fa-hands-helping"></i>
                </div>
                <div class="service-details">
                  <div class="service-title">Welfare Services</div>
                  <div class="service-description">Social services and community welfare programs</div>
                  <ul class="service-features">
                    <li>Social welfare assistance</li>
                    <li>Community outreach programs</li>
                    <li>Elderly care support</li>
                    <li>Family assistance programs</li>
                  </ul>
                </div>
              </label>
            </div>

            <div class="service-option">
              <input type="checkbox" name="services[]" value="disaster_management" id="disasterService">
              <label for="disasterService" class="service-content">
                <div class="service-icon">
                  <i class="fas fa-cloud-rain"></i>
                </div>
                <div class="service-details">
                  <div class="service-title">Disaster Management</div>
                  <div class="service-description">Emergency response and disaster preparedness</div>
                  <ul class="service-features">
                    <li>Disaster response operations</li>
                    <li>Emergency shelter management</li>
                    <li>Relief goods distribution</li>
                    <li>Evacuation center support</li>
                  </ul>
                </div>
              </label>
            </div>

            <div class="service-option">
              <input type="checkbox" name="services[]" value="red_cross_youth" id="rcyService">
              <label for="rcyService" class="service-content">
                <div class="service-icon">
                  <i class="fas fa-users"></i>
                </div>
                <div class="service-details">
                  <div class="service-title">Red Cross Youth</div>
                  <div class="service-description">Youth development and leadership programs</div>
                  <ul class="service-features">
                    <li>Youth leadership development</li>
                    <li>Peer education programs</li>
                    <li>Community service projects</li>
                    <li>International youth exchanges</li>
                  </ul>
                </div>
              </label>
            </div>
          </div>

          <div class="selection-summary" id="selectionSummary" style="display: none;">
            <h4><i class="fas fa-list-check"></i> Selected Services:</h4>
            <div class="selected-services" id="selectedServicesList"></div>
          </div>

          <div class="services-validation" id="servicesValidation">
            <i class="fas fa-exclamation-circle"></i>
            Please select at least one service to continue.
          </div>
        </div>

        <!-- Personal Information -->
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
            <span id="documentLabel">Upload Documents (Optional)</span> 
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
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');
        const filePreview = document.getElementById('filePreview');
        const servicesSection = document.getElementById('servicesSection');
        const servicesValidation = document.getElementById('servicesValidation');
        const selectionSummary = document.getElementById('selectionSummary');
        const selectedServicesList = document.getElementById('selectedServicesList');
        let selectedFiles = new Map();

        // Handle account type change
        const accountTypeRadios = document.querySelectorAll('input[name="user_type"]');
        accountTypeRadios.forEach(radio => {
            radio.addEventListener('change', handleAccountTypeChange);
        });

        // Handle service selection
        const serviceCheckboxes = document.querySelectorAll('input[name="services[]"]');
        serviceCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateServiceSelection);
        });

        // Initialize form
        handleAccountTypeChange();
        updateServiceSelection();

        function handleAccountTypeChange() {
            const selectedType = document.querySelector('input[name="user_type"]:checked').value;
            const documentLabel = document.getElementById('documentLabel');
            const documentRequired = document.getElementById('documentRequired');
            const documentPurpose = document.getElementById('documentPurpose');
            const documentUpload = document.getElementById('documentUpload');

            if (selectedType === 'rcy_member') {
                servicesSection.classList.add('show');
                documentLabel.textContent = 'Upload Verification Documents';
                documentRequired.style.display = 'inline';
                documentPurpose.textContent = 'Required: Valid ID, certificates, proof of address, or other verification documents';
                documentUpload.required = true;
                
                // Scroll to services section smoothly
                setTimeout(() => {
                    servicesSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 300);
            } else {
                servicesSection.classList.remove('show');
                documentLabel.textContent = 'Upload Documents (Optional)';
                documentRequired.style.display = 'none';
                documentPurpose.textContent = 'Optional: ID, certificates, or other relevant documents';
                documentUpload.required = false;
                
                // Clear service selections
                serviceCheckboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });
                updateServiceSelection();
            }
        }

        function updateServiceSelection() {
            const selectedServices = Array.from(serviceCheckboxes)
                .filter(checkbox => checkbox.checked)
                .map(checkbox => {
                    const serviceNames = {
                        'health': 'Health Services',
                        'safety': 'Safety Services', 
                        'welfare': 'Welfare Services',
                        'disaster_management': 'Disaster Management',
                        'red_cross_youth': 'Red Cross Youth'
                    };
                    return serviceNames[checkbox.value] || checkbox.value;
                });

            if (selectedServices.length > 0) {
                selectionSummary.style.display = 'block';
                selectedServicesList.innerHTML = selectedServices
                    .map(service => `<span class="service-tag">${service}</span>`)
                    .join('');
                servicesValidation.classList.remove('error');
            } else {
                selectionSummary.style.display = 'none';
                if (document.querySelector('input[name="user_type"]:checked').value === 'rcy_member') {
                    servicesValidation.classList.add('error');
                } else {
                    servicesValidation.classList.remove('error');
                }
            }
        }

        // Username validation
        let usernameTimeout;
        document.getElementById('username').addEventListener('input', function(e) {
            clearTimeout(usernameTimeout);
            const username = e.target.value;
            const statusDiv = document.getElementById('username-status');
            
            if (username.length >= 4) {
                usernameTimeout = setTimeout(() => {
                    if (/^[A-Za-z0-9_]+$/.test(username)) {
                        statusDiv.innerHTML = '<span class="valid">✓ Username format is valid</span>';
                    } else {
                        statusDiv.innerHTML = '<span class="invalid">✗ Only letters, numbers, and underscores allowed</span>';
                    }
                }, 500);
            } else {
                statusDiv.innerHTML = '';
            }
        });

        // Email validation
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

        // Password validation
        document.getElementById('password').addEventListener('input', updatePasswordStrength);
        document.getElementById('confirmPassword').addEventListener('input', checkPasswordMatch);

        // File upload
        document.getElementById('documentUpload').addEventListener('change', handleFileUpload);

        // Form submission
        form.addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
        });

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
            
            updateFileInput();
        }

        function validateForm() {
            const requiredFields = ['first_name', 'last_name', 'gender', 'username', 'email', 'phone', 'password', 'confirm_password'];
            let isValid = true;
            
            // Validate basic required fields
            requiredFields.forEach(fieldName => {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (!field.value.trim()) {
                    field.style.borderColor = '#ff4444';
                    isValid = false;
                } else {
                    field.style.borderColor = '#ddd';
                }
            });
            
            // Check account type selection
            const userType = document.querySelector('input[name="user_type"]:checked');
            if (!userType) {
                showError('Please select an account type.');
                isValid = false;
            }
            
            // Check password match
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirmPassword').value;
            if (password !== confirm) {
                document.getElementById('confirmPassword').style.borderColor = '#ff4444';
                showError('Passwords do not match.');
                isValid = false;
            }
            
            // Check RCY member service selection
            if (userType && userType.value === 'rcy_member') {
                const selectedServices = document.querySelectorAll('input[name="services[]"]:checked');
                if (selectedServices.length === 0) {
                    showError('RCY Members must select at least one service.');
                    servicesValidation.classList.add('error');
                    servicesSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    isValid = false;
                } else {
                    servicesValidation.classList.remove('error');
                }
                
                // Check document requirement for RCY members
                if (selectedFiles.size === 0) {
                    const documentUpload = document.getElementById('documentUpload');
                    documentUpload.style.borderColor = '#ff4444';
                    showError('RCY Member accounts require at least one verification document to be uploaded.');
                    isValid = false;
                }
            }
            
            // Check reCAPTCHA
            if (typeof grecaptcha !== 'undefined') {
                const recaptcha = grecaptcha.getResponse();
                if (!recaptcha) {
                    showError('Please complete the reCAPTCHA verification.');
                    isValid = false;
                }
            }
            
            return isValid;
        }

        function showError(message) {
            const errorAlert = document.getElementById('errorAlert');
            const errorMessage = document.getElementById('errorMessage');
            errorMessage.textContent = message;
            errorAlert.style.display = 'block';
            errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function updateFileInput() {
            const fileInput = document.getElementById('documentUpload');
            const dt = new DataTransfer();
            
            selectedFiles.forEach(file => {
                dt.items.add(file);
            });
            
            fileInput.files = dt.files;
        }

        // Global function for removing files
        window.removeFile = function(fileId) {
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
        };

        // Auto-save form data to prevent loss (excluding sensitive data)
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

        // Enhanced visual feedback for service selection
        serviceCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const serviceOption = this.closest('.service-option');
                if (this.checked) {
                    serviceOption.style.transform = 'scale(1.02)';
                    serviceOption.style.borderColor = 'var(--prc-red)';
                    setTimeout(() => {
                        serviceOption.style.transform = 'scale(1)';
                    }, 200);
                } else {
                    serviceOption.style.borderColor = '#e9ecef';
                }
            });
        });

        // Add smooth transitions for account type changes
        accountTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const accountOption = this.closest('.account-option');
                accountOption.style.transform = 'scale(1.02)';
                setTimeout(() => {
                    accountOption.style.transform = 'scale(1)';
                }, 200);
            });
        });
    });
  </script>
</body>
</html>