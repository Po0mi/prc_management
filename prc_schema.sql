
CREATE DATABASE IF NOT EXISTS `prc_system`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `prc_system`;


SET FOREIGN_KEY_CHECKS = 0;


DROP TABLE IF EXISTS `registrations`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `donations`;
DROP TABLE IF EXISTS `blood_inventory`;

DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `training_sessions`;
DROP TABLE IF EXISTS `donors`;
DROP TABLE IF EXISTS `blood_banks`;

DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `inventory_items`;
DROP TABLE IF EXISTS `announcements`;



CREATE TABLE `users` (
  `user_id`      INT AUTO_INCREMENT PRIMARY KEY,
  `username`     VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`    VARCHAR(100) NOT NULL,
  `role`         ENUM('admin','user') NOT NULL DEFAULT 'user',
  `email`        VARCHAR(100),
  `phone`        VARCHAR(20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE `events` (
  `event_id`    INT AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(150) NOT NULL,
  `description` TEXT,
  `event_date`  DATE NOT NULL,
  `location`    VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



-
CREATE TABLE `registrations` (
  `registration_id`  INT AUTO_INCREMENT PRIMARY KEY,
  `event_id`         INT NOT NULL,
  `user_id`          INT NOT NULL,
  `registration_date` DATETIME NOT NULL,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`event_id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)  REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `training_sessions` (
  `session_id`   INT AUTO_INCREMENT PRIMARY KEY,
  `title`        VARCHAR(150) NOT NULL,
  `session_date` DATE NOT NULL,
  `start_time`   TIME NOT NULL,
  `end_time`     TIME NOT NULL,
  `venue`        VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE `attendance` (
  `attendance_id`   INT AUTO_INCREMENT PRIMARY KEY,
  `session_id`      INT NOT NULL,
  `user_id`         INT NOT NULL,
  `attendance_time` DATETIME NOT NULL,
  FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`session_id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)      REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE `donors` (
  `donor_id`  INT AUTO_INCREMENT PRIMARY KEY,
  `name`      VARCHAR(150) NOT NULL,
  `email`     VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




CREATE TABLE `donations` (
  `donation_id`   INT AUTO_INCREMENT PRIMARY KEY,
  `donor_id`      INT NOT NULL,
  `amount`        DECIMAL(10,2) NOT NULL,
  `donation_date` DATE NOT NULL,
  `recorded_by`   INT NULL,
  FOREIGN KEY (`donor_id`)    REFERENCES `donors`(`donor_id`) ON DELETE CASCADE,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `inventory_items` (
  `item_id`    INT AUTO_INCREMENT PRIMARY KEY,
  `item_name`  VARCHAR(150) NOT NULL,
  `quantity`   INT NOT NULL DEFAULT 0,
  `expiry_date` DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `blood_banks` (
  `bank_id`     INT AUTO_INCREMENT PRIMARY KEY,
  `branch_name` VARCHAR(150) NOT NULL,
  `address`     VARCHAR(255) NOT NULL,
  `latitude`    DECIMAL(10,6) NOT NULL,
  `longitude`   DECIMAL(10,6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `blood_inventory` (
  `inventory_id`   INT AUTO_INCREMENT PRIMARY KEY,
  `bank_id`        INT NOT NULL,
  `blood_type`     ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  `units_available` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`bank_id`) REFERENCES `blood_banks`(`bank_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `announcements` (
  `announcement_id` INT AUTO_INCREMENT PRIMARY KEY,
  `title`           VARCHAR(200) NOT NULL,
  `content`         TEXT NOT NULL,
  `posted_at`       DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




SET FOREIGN_KEY_CHECKS = 1;
