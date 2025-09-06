<?php
/**
 * Database Update Script for Dynamic Content System
 * Run this script to ensure your database has all necessary tables and columns
 * File: update_database.php
 */

require_once __DIR__ . '/config.php';

$pdo = $GLOBALS['pdo'];
$errors = [];
$updates = [];

echo "<h1>Database Update Script</h1>\n";
echo "<p>Updating database schema for dynamic content system...</p>\n";

try {
    // Enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // 1. Update Events Table
    echo "<h3>Updating Events Table...</h3>\n";
    
    // Check if events table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'events'");
    if ($stmt->rowCount() == 0) {
        // Create events table
        $pdo->exec("
            CREATE TABLE `events` (
                `event_id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `event_date` date NOT NULL,
                `event_end_date` date DEFAULT NULL,
                `duration_days` int(11) DEFAULT 1,
                `start_time` time DEFAULT '09:00:00',
                `end_time` time DEFAULT '17:00:00',
                `location` text NOT NULL,
                `major_service` enum('Health Service','Safety Service','Welfare Service','Disaster Management Service','Red Cross Youth') NOT NULL,
                `capacity` int(11) DEFAULT 0,
                `fee` decimal(10,2) DEFAULT 0.00,
                `created_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`event_id`),
                KEY `event_date` (`event_date`),
                KEY `major_service` (`major_service`),
                KEY `created_by` (`created_by`),
                FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $updates[] = "Created events table";
    } else {
        // Check and add missing columns
        $columns_to_add = [
            'event_end_date' => "ALTER TABLE `events` ADD COLUMN `event_end_date` date DEFAULT NULL AFTER `event_date`",
            'duration_days' => "ALTER TABLE `events` ADD COLUMN `duration_days` int(11) DEFAULT 1 AFTER `event_end_date`",
            'start_time' => "ALTER TABLE `events` ADD COLUMN `start_time` time DEFAULT '09:00:00' AFTER `duration_days`",
            'end_time' => "ALTER TABLE `events` ADD COLUMN `end_time` time DEFAULT '17:00:00' AFTER `start_time`",
            'updated_at' => "ALTER TABLE `events` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`"
        ];
        
        foreach ($columns_to_add as $column => $sql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM events LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                try {
                    $pdo->exec($sql);
                    $updates[] = "Added $column column to events table";
                } catch (PDOException $e) {
                    $errors[] = "Error adding $column to events: " . $e->getMessage();
                }
            }
        }
    }
    
    // 2. Update Training Sessions Table
    echo "<h3>Updating Training Sessions Table...</h3>\n";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'training_sessions'");
    if ($stmt->rowCount() == 0) {
        // Create training_sessions table
        $pdo->exec("
            CREATE TABLE `training_sessions` (
                `session_id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `major_service` enum('Health Service','Safety Service','Welfare Service','Disaster Management Service','Red Cross Youth') NOT NULL,
                `session_date` date NOT NULL,
                `session_end_date` date DEFAULT NULL,
                `duration_days` int(11) DEFAULT 1,
                `start_time` time DEFAULT '09:00:00',
                `end_time` time DEFAULT '17:00:00',
                `venue` text NOT NULL,
                `instructor` varchar(100) DEFAULT NULL,
                `instructor_bio` text DEFAULT NULL,
                `instructor_credentials` varchar(500) DEFAULT NULL,
                `capacity` int(11) DEFAULT 0,
                `fee` decimal(10,2) DEFAULT 0.00,
                `created_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`session_id`),
                KEY `session_date` (`session_date`),
                KEY `major_service` (`major_service`),
                KEY `created_by` (`created_by`),
                FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $updates[] = "Created training_sessions table";
    } else {
        // Check and add missing columns for training sessions
        $session_columns_to_add = [
            'session_end_date' => "ALTER TABLE `training_sessions` ADD COLUMN `session_end_date` date DEFAULT NULL AFTER `session_date`",
            'duration_days' => "ALTER TABLE `training_sessions` ADD COLUMN `duration_days` int(11) DEFAULT 1 AFTER `session_end_date`",
            'start_time' => "ALTER TABLE `training_sessions` ADD COLUMN `start_time` time DEFAULT '09:00:00' AFTER `duration_days`",
            'end_time' => "ALTER TABLE `training_sessions` ADD COLUMN `end_time` time DEFAULT '17:00:00' AFTER `start_time`",
            'instructor' => "ALTER TABLE `training_sessions` ADD COLUMN `instructor` varchar(100) DEFAULT NULL AFTER `venue`",
            'instructor_bio' => "ALTER TABLE `training_sessions` ADD COLUMN `instructor_bio` text DEFAULT NULL AFTER `instructor`",
            'instructor_credentials' => "ALTER TABLE `training_sessions` ADD COLUMN `instructor_credentials` varchar(500) DEFAULT NULL AFTER `instructor_bio`",
            'updated_at' => "ALTER TABLE `training_sessions` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`"
        ];
        
        foreach ($session_columns_to_add as $column => $sql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                try {
                    $pdo->exec($sql);
                    $updates[] = "Added $column column to training_sessions table";
                } catch (PDOException $e) {
                    $errors[] = "Error adding $column to training_sessions: " . $e->getMessage();
                }
            }
        }
    }
    
    // 3. Update/Create Merchandise Table
    echo "<h3>Updating Merchandise Table...</h3>\n";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'merchandise'");
    if ($stmt->rowCount() == 0) {
        // Create merchandise table
        $pdo->exec("
            CREATE TABLE `merchandise` (
                `merch_id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(200) NOT NULL,
                `description` text DEFAULT NULL,
                `category` enum('clothing','accessories','supplies','books','collectibles','other') NOT NULL DEFAULT 'other',
                `price` decimal(10,2) NOT NULL DEFAULT 0.00,
                `stock_quantity` int(11) NOT NULL DEFAULT 0,
                `image_url` varchar(500) DEFAULT NULL,
                `is_available` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` int(11) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`merch_id`),
                KEY `category` (`category`),
                KEY `is_available` (`is_available`),
                KEY `created_by` (`created_by`),
                FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $updates[] = "Created merchandise table";
    } else {
        // Check and add missing columns for merchandise
        $merch_columns_to_add = [
            'updated_at' => "ALTER TABLE `merchandise` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`"
        ];
        
        foreach ($merch_columns_to_add as $column => $sql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM merchandise LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                try {
                    $pdo->exec($sql);
                    $updates[] = "Added $column column to merchandise table";
                } catch (PDOException $e) {
                    $errors[] = "Error adding $column to merchandise: " . $e->getMessage();
                }
            }
        }
    }
    
    // 4. Update/Create Announcements Table
    echo "<h3>Updating Announcements Table...</h3>\n";
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'announcements'");
    if ($stmt->rowCount() == 0) {
        // Create announcements table
        $pdo->exec("
            CREATE TABLE `announcements` (
                `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `content` text NOT NULL,
                `image_url` varchar(500) DEFAULT NULL,
                `posted_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `created_by` int(11) DEFAULT NULL,
                PRIMARY KEY (`announcement_id`),
                KEY `posted_at` (`posted_at`),
                KEY `created_by` (`created_by`),
                FOREIGN KEY (`created_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $updates[] = "Created announcements table";
    }
    
    // 5. Update/Create Registration Tables
    echo "<h3>Updating Registration Tables...</h3>\n";
    
    // Event registrations table
    $stmt = $pdo->query("SHOW TABLES LIKE 'registrations'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE `registrations` (
                `registration_id` int(11) NOT NULL AUTO_INCREMENT,
                `event_id` int(11) NOT NULL,
                `user_id` int(11) DEFAULT NULL,
                `full_name` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `phone` varchar(20) DEFAULT NULL,
                `organization` varchar(255) DEFAULT NULL,
                `status` enum('pending','approved','rejected') DEFAULT 'pending',
                `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
                `valid_id_path` varchar(255) DEFAULT NULL,
                `requirements_path` varchar(255) DEFAULT NULL,
                `documents_path` varchar(255) DEFAULT NULL,
                `receipt_path` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`registration_id`),
                KEY `event_id` (`event_id`),
                KEY `user_id` (`user_id`),
                KEY `status` (`status`),
                FOREIGN KEY (`event_id`) REFERENCES `events`(`event_id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $updates[] = "Created registrations table";
    }
    
    // Session registrations table
    $stmt = $pdo->query("SHOW TABLES LIKE 'session_registrations'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("
            CREATE TABLE `session_registrations` (
                `registration_id` int(11) NOT NULL AUTO_INCREMENT,
                `session_id` int(11) NOT NULL,
                `user_id` int(11) DEFAULT NULL,
                `registration_type` enum('individual','organization') DEFAULT 'individual',
                `full_name` varchar(255) DEFAULT NULL,
                `name` varchar(255) DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `organization_name` varchar(255) DEFAULT NULL,
                `emergency_contact` varchar(255) DEFAULT NULL,
                `age` int(3) DEFAULT NULL,
                `location` varchar(255) DEFAULT NULL,
                `rcy_status` varchar(100) DEFAULT NULL,
                `pax_count` int(11) DEFAULT 1,
                `payment_method` varchar(50) DEFAULT 'free',
                `payment_amount` decimal(10,2) DEFAULT 0.00,
                `payment_reference` varchar(100) DEFAULT NULL,
                `payment_status` enum('not_required','pending','approved','rejected') DEFAULT 'not_required',
                `status` enum('pending','approved','rejected') DEFAULT 'pending',
                `attendance_status` enum('registered','attended','absent') DEFAULT 'registered',
                `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
                `training_date` date DEFAULT NULL,
                `purpose` text DEFAULT NULL,
                `medical_info` text DEFAULT NULL,
                `valid_id_path` varchar(255) DEFAULT NULL,
                `requirements_path` varchar(255) DEFAULT NULL,
                `payment_receipt_path` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`registration_id`),
                KEY `session_id` (`session_id`),
                KEY `user_id` (`user_id`),
                KEY `status` (`status`),
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`session_id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $updates[] = "Created session_registrations table";
    }
    
    // 6. Create directories for uploads
    echo "<h3>Creating Upload Directories...</h3>\n";
    
    $upload_dirs = [
        'uploads/announcements',
        'uploads/events',
        'uploads/training',
        'uploads/merchandise',
        'uploads/documents'
    ];
    
    foreach ($upload_dirs as $dir) {
        $full_path = __DIR__ . '/' . $dir;
        if (!file_exists($full_path)) {
            if (mkdir($full_path, 0755, true)) {
                $updates[] = "Created directory: $dir";
            } else {
                $errors[] = "Failed to create directory: $dir";
            }
        }
        
        // Create .htaccess for security
        $htaccess_path = $full_path . '/.htaccess';
        if (!file_exists($htaccess_path)) {
            $htaccess_content = "# Prevent direct access to uploaded files\n";
            $htaccess_content .= "Options -Indexes\n";
            $htaccess_content .= "<Files *.php>\n";
            $htaccess_content .= "    Order Deny,Allow\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</Files>\n";
            
            if (file_put_contents($htaccess_path, $htaccess_content)) {
                $updates[] = "Created .htaccess for $dir";
            }
        }
    }
    
    // 7. Insert sample data (if tables are empty)
    echo "<h3>Checking for Sample Data...</h3>\n";
    
    // Check if we need sample announcements
    $stmt = $pdo->query("SELECT COUNT(*) FROM announcements");
    if ($stmt->fetchColumn() == 0) {
        $sample_announcements = [
            [
                'title' => 'Welcome to PRC Management System',
                'content' => 'We are excited to launch our new management system portal. This system will help us better coordinate our humanitarian activities and serve our community more effectively.',
                'image_url' => null
            ],
            [
                'title' => 'Upcoming Blood Drive Campaign',
                'content' => 'Join us for our monthly blood drive campaign. Your donation can save up to three lives. All donors will receive a certificate of appreciation and light refreshments.',
                'image_url' => null
            ],
            [
                'title' => 'Volunteer Training Schedule',
                'content' => 'New volunteer orientation and training sessions are now available. Please check the training section for available slots and register online.',
                'image_url' => null
            ]
        ];
        
        foreach ($sample_announcements as $announcement) {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, image_url) VALUES (?, ?, ?)");
            $stmt->execute([$announcement['title'], $announcement['content'], $announcement['image_url']]);
        }
        $updates[] = "Added sample announcements";
    }
    
    echo "<h2>Update Summary</h2>\n";
    
    if (!empty($updates)) {
        echo "<h3 style='color: green;'>Successful Updates:</h3>\n";
        echo "<ul>\n";
        foreach ($updates as $update) {
            echo "<li>$update</li>\n";
        }
        echo "</ul>\n";
    }
    
    if (!empty($errors)) {
        echo "<h3 style='color: red;'>Errors:</h3>\n";
        echo "<ul>\n";
        foreach ($errors as $error) {
            echo "<li>$error</li>\n";
        }
        echo "</ul>\n";
    }
    
    if (empty($errors)) {
        echo "<h3 style='color: green;'>Database update completed successfully!</h3>\n";
        echo "<p>Your database is now ready for the dynamic content system.</p>\n";
    } else {
        echo "<h3 style='color: orange;'>Database update completed with some errors.</h3>\n";
        echo "<p>Please review the errors above and fix them manually if needed.</p>\n";
    }
    
} catch (PDOException $e) {
    echo "<h3 style='color: red;'>Critical Database Error:</h3>\n";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>\n";
} catch (Exception $e) {
    echo "<h3 style='color: red;'>General Error:</h3>\n";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ol>\n";
echo "<li>Replace your existing index.php with the enhanced version</li>\n";
echo "<li>Create the api directory and place get_dynamic_content.php inside it</li>\n";
echo "<li>Test the dynamic content by adding events, training sessions, merchandise, and announcements through the admin panel</li>\n";
echo "<li>The content will automatically update on the landing page every 30 seconds</li>\n";
echo "</ol>\n";

echo "<p><em>Database update script completed at " . date('Y-m-d H:i:s') . "</em></p>\n";
?>