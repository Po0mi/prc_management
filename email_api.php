<?php
/**
 * Email Notification API for PRC Management System
 * This file provides email functionality for various system notifications
 */

class EmailNotificationAPI {
    private $config;
    private $templates;

    public function __construct() {
        $this->config = [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_username' => '', // Set your email
            'smtp_password' => '', // Set your app password
            'from_email' => 'noreply@prc-system.com',
            'from_name' => 'Philippine Red Cross System',
            'reply_to' => 'support@prc-system.com'
        ];
        
        $this->loadTemplates();
    }

    /**
     * Load email templates
     */
    private function loadTemplates() {
        $this->templates = [
            'registration_welcome' => [
                'subject' => 'Welcome to Philippine Red Cross Management System',
                'template' => 'registration_welcome.html'
            ],
            'password_reset' => [
                'subject' => 'Password Reset Request - PRC System',
                'template' => 'password_reset.html'
            ],
            'account_verification' => [
                'subject' => 'Verify Your Account - PRC System',
                'template' => 'account_verification.html'
            ],
            'event_registration' => [
                'subject' => 'Event Registration Confirmation - PRC System',
                'template' => 'event_registration.html'
            ],
            'donation_receipt' => [
                'subject' => 'Thank You for Your Donation - PRC System',
                'template' => 'donation_receipt.html'
            ]
        ];
    }

    /**
     * Send registration welcome email
     */
    public function sendRegistrationEmail($email, $firstName, $userType, $userId = null) {
        $data = [
            'firstName' => htmlspecialchars($firstName),
            'email' => htmlspecialchars($email),
            'userType' => ucfirst($userType),
            'registrationDate' => date('Y-m-d H:i:s'),
            'loginUrl' => $this->getBaseUrl() . '/login.php',
            'userId' => $userId
        ];

        $htmlContent = $this->getRegistrationTemplate($data);
        
        return $this->sendEmail(
            $email,
            $this->templates['registration_welcome']['subject'],
            $htmlContent,
            $firstName
        );
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($email, $firstName, $resetToken) {
        $data = [
            'firstName' => htmlspecialchars($firstName),
            'resetUrl' => $this->getBaseUrl() . "/reset-password.php?token=" . urlencode($resetToken),
            'expiryTime' => '24 hours',
            'supportEmail' => $this->config['reply_to']
        ];

        $htmlContent = $this->getPasswordResetTemplate($data);
        
        return $this->sendEmail(
            $email,
            $this->templates['password_reset']['subject'],
            $htmlContent,
            $firstName
        );
    }

    /**
     * Send account verification email
     */
    public function sendVerificationEmail($email, $firstName, $verificationToken) {
        $data = [
            'firstName' => htmlspecialchars($firstName),
            'verificationUrl' => $this->getBaseUrl() . "/verify-account.php?token=" . urlencode($verificationToken),
            'supportEmail' => $this->config['reply_to']
        ];

        $htmlContent = $this->getVerificationTemplate($data);
        
        return $this->sendEmail(
            $email,
            $this->templates['account_verification']['subject'],
            $htmlContent,
            $firstName
        );
    }

    /**
     * Send event registration confirmation
     */
    public function sendEventRegistrationEmail($email, $firstName, $eventTitle, $eventDate, $eventLocation) {
        $data = [
            'firstName' => htmlspecialchars($firstName),
            'eventTitle' => htmlspecialchars($eventTitle),
            'eventDate' => date('F j, Y g:i A', strtotime($eventDate)),
            'eventLocation' => htmlspecialchars($eventLocation),
            'dashboardUrl' => $this->getBaseUrl() . '/dashboard.php'
        ];

        $htmlContent = $this->getEventRegistrationTemplate($data);
        
        return $this->sendEmail(
            $email,
            $this->templates['event_registration']['subject'],
            $htmlContent,
            $firstName
        );
    }

    /**
     * Send donation receipt email
     */
    public function sendDonationReceiptEmail($email, $firstName, $amount, $donationDate, $donationId, $paymentMethod) {
        $data = [
            'firstName' => htmlspecialchars($firstName),
            'amount' => number_format($amount, 2),
            'donationDate' => date('F j, Y g:i A', strtotime($donationDate)),
            'donationId' => $donationId,
            'paymentMethod' => htmlspecialchars($paymentMethod),
            'receiptUrl' => $this->getBaseUrl() . "/receipt.php?id=" . $donationId
        ];

        $htmlContent = $this->getDonationReceiptTemplate($data);
        
        return $this->sendEmail(
            $email,
            $this->templates['donation_receipt']['subject'],
            $htmlContent,
            $firstName
        );
    }

    /**
     * Core email sending function
     */
    private function sendEmail($to, $subject, $htmlContent, $recipientName = null) {
        try {
            // Basic email sending using PHP mail() function
            // For production, consider using PHPMailer or similar library
            
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
                'Reply-To: ' . $this->config['reply_to'],
                'X-Mailer: PHP/' . phpversion(),
                'X-Priority: 3',
                'Return-Path: ' . $this->config['from_email']
            ];

            $success = mail($to, $subject, $htmlContent, implode("\r\n", $headers));
            
            if ($success) {
                $this->logEmail($to, $subject, 'sent');
                return ['success' => true, 'message' => 'Email sent successfully'];
            } else {
                $this->logEmail($to, $subject, 'failed');
                return ['success' => false, 'message' => 'Failed to send email'];
            }
        } catch (Exception $e) {
            $this->logEmail($to, $subject, 'error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Email error: ' . $e->getMessage()];
        }
    }

    /**
     * Get registration email template
     */
    private function getRegistrationTemplate($data) {
        return "
        <html>
        <head>
            <title>Welcome to PRC Management System</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
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
                    <h2 style='color: #a00000;'>Welcome, {$data['firstName']}!</h2>
                    
                    <p>Thank you for registering with the Philippine Red Cross Management System.</p>
                    
                    <div class='info-box'>
                        <strong>Account Details:</strong><br>
                        Email: {$data['email']}<br>
                        Account Type: {$data['userType']}<br>
                        Registration Date: {$data['registrationDate']}
                    </div>
                    
                    <p>Your account has been successfully created. You can now log in to access the system and explore our services.</p>
                    
                    <div style='text-align: center;'>
                        <a href='{$data['loginUrl']}' class='button'>Login to Your Account</a>
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
    }

    /**
     * Get password reset email template
     */
    private function getPasswordResetTemplate($data) {
        return "
        <html>
        <head>
            <title>Password Reset - PRC System</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { background: #a00000; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 20px 0; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 15px 0; }
                .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Password Reset Request</h1>
                </div>
                
                <div class='content'>
                    <h2>Hello {$data['firstName']},</h2>
                    
                    <p>We received a request to reset your password for your PRC Management System account.</p>
                    
                    <div style='text-align: center;'>
                        <a href='{$data['resetUrl']}' class='button'>Reset Your Password</a>
                    </div>
                    
                    <div class='warning'>
                        <strong>Important:</strong> This link will expire in {$data['expiryTime']}. If you didn't request this reset, please ignore this email.
                    </div>
                    
                    <p>If you're having trouble with the button above, copy and paste this URL into your browser:</p>
                    <p style='word-break: break-all; color: #666;'>{$data['resetUrl']}</p>
                    
                    <p>If you need help, contact us at {$data['supportEmail']}</p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.<br>
                    Philippine Red Cross Management System</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Get account verification template
     */
    private function getVerificationTemplate($data) {
        return "
        <html>
        <head>
            <title>Verify Your Account - PRC System</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { background: #a00000; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 20px 0; }
                .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Account Verification</h1>
                </div>
                
                <div class='content'>
                    <h2>Hello {$data['firstName']},</h2>
                    
                    <p>Please verify your email address to complete your registration with the Philippine Red Cross Management System.</p>
                    
                    <div style='text-align: center;'>
                        <a href='{$data['verificationUrl']}' class='button'>Verify My Account</a>
                    </div>
                    
                    <p>If you're having trouble with the button above, copy and paste this URL into your browser:</p>
                    <p style='word-break: break-all; color: #666;'>{$data['verificationUrl']}</p>
                    
                    <p>If you need help, contact us at {$data['supportEmail']}</p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.<br>
                    Philippine Red Cross Management System</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Get event registration template
     */
    private function getEventRegistrationTemplate($data) {
        return "
        <html>
        <head>
            <title>Event Registration Confirmation</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { background: #a00000; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 20px 0; }
                .event-details { background: white; padding: 15px; border-left: 4px solid #a00000; margin: 20px 0; }
                .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Event Registration Confirmed</h1>
                </div>
                
                <div class='content'>
                    <h2>Hello {$data['firstName']},</h2>
                    
                    <p>Your registration for the following event has been confirmed:</p>
                    
                    <div class='event-details'>
                        <strong>Event Details:</strong><br>
                        <strong>Event:</strong> {$data['eventTitle']}<br>
                        <strong>Date & Time:</strong> {$data['eventDate']}<br>
                        <strong>Location:</strong> {$data['eventLocation']}
                    </div>
                    
                    <p>We look forward to seeing you at the event. Please save this email for your records.</p>
                    
                    <div style='text-align: center;'>
                        <a href='{$data['dashboardUrl']}' class='button'>View My Dashboard</a>
                    </div>
                </div>
                
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.<br>
                    Philippine Red Cross Management System</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Get donation receipt template
     */
    private function getDonationReceiptTemplate($data) {
        return "
        <html>
        <head>
            <title>Donation Receipt - PRC System</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #a00000 0%, #a00000 50%, #222e60 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .button { background: #a00000; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; margin: 20px 0; }
                .receipt-details { background: white; padding: 15px; border-left: 4px solid #a00000; margin: 20px 0; }
                .amount { font-size: 24px; color: #a00000; font-weight: bold; text-align: center; margin: 20px 0; }
                .footer { font-size: 12px; color: #666; text-align: center; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Thank You for Your Donation!</h1>
                </div>
                
                <div class='content'>
                    <h2>Dear {$data['firstName']},</h2>
                    
                    <p>Thank you for your generous donation to the Philippine Red Cross. Your support helps us continue our humanitarian mission.</p>
                    
                    <div class='amount'>₱{$data['amount']}</div>
                    
                    <div class='receipt-details'>
                        <strong>Donation Receipt:</strong><br>
                        <strong>Amount:</strong> ₱{$data['amount']}<br>
                        <strong>Date:</strong> {$data['donationDate']}<br>
                        <strong>Reference ID:</strong> {$data['donationId']}<br>
                        <strong>Payment Method:</strong> {$data['paymentMethod']}
                    </div>
                    
                    <p>Your donation is making a real difference in the lives of those we serve. Please keep this receipt for your tax records.</p>
                    
                    <div style='text-align: center;'>
                        <a href='{$data['receiptUrl']}' class='button'>Download Official Receipt</a>
                    </div>
                </div>
                
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.<br>
                    Philippine Red Cross Management System</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Get base URL for links
     */
    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }

    /**
     * Log email activity
     */
    private function logEmail($recipient, $subject, $status) {
        // Create logs directory if it doesn't exist
        if (!is_dir('logs/')) {
            mkdir('logs/', 0755, true);
        }
        
        $logEntry = date('Y-m-d H:i:s') . " - TO: $recipient - SUBJECT: $subject - STATUS: $status\n";
        file_put_contents('logs/email.log', $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Test email configuration
     */
    public function testEmailConfig($testEmail) {
        $testData = [
            'firstName' => 'Test User',
            'email' => $testEmail,
            'userType' => 'Test',
            'registrationDate' => date('Y-m-d H:i:s'),
            'loginUrl' => $this->getBaseUrl() . '/login.php'
        ];

        $htmlContent = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Email Test</h2>
            <p>This is a test email from the PRC Management System.</p>
            <p>If you received this, your email configuration is working correctly.</p>
            <p>Test sent at: " . date('Y-m-d H:i:s') . "</p>
        </body>
        </html>";

        return $this->sendEmail($testEmail, 'PRC System - Email Test', $htmlContent, 'Test User');
    }
}

// Global functions for backward compatibility - only declare if not already defined
if (!function_exists('sendPRCRegistrationEmail')) {
    $emailAPI = new EmailNotificationAPI();

    function sendPRCRegistrationEmail($email, $firstName, $userType) {
        global $emailAPI;
        $result = $emailAPI->sendRegistrationEmail($email, $firstName, $userType);
        return $result['success'];
    }

    function sendPRCPasswordResetEmail($email, $firstName, $resetToken) {
        global $emailAPI;
        $result = $emailAPI->sendPasswordResetEmail($email, $firstName, $resetToken);
        return $result['success'];
    }

    function sendPRCEventRegistrationEmail($email, $firstName, $eventTitle, $eventDate, $eventLocation) {
        global $emailAPI;
        $result = $emailAPI->sendEventRegistrationEmail($email, $firstName, $eventTitle, $eventDate, $eventLocation);
        return $result['success'];
    }

    function sendPRCDonationReceiptEmail($email, $firstName, $amount, $donationDate, $donationId, $paymentMethod) {
        global $emailAPI;
        $result = $emailAPI->sendDonationReceiptEmail($email, $firstName, $amount, $donationDate, $donationId, $paymentMethod);
        return $result['success'];
    }

    function testPRCEmailConfiguration($testEmail) {
        global $emailAPI;
        return $emailAPI->testEmailConfig($testEmail);
    }
}
?>