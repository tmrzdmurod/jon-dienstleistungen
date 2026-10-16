-- Solar database bootstrap (schema only, no data)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DROP DATABASE IF EXISTS `solar`;
CREATE DATABASE `solar` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `solar`;

CREATE TABLE `user` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `password_enc` VARCHAR(512) DEFAULT NULL,
  `email` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_username` (`username`),
  UNIQUE KEY `uniq_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `booking` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'free') NOT NULL DEFAULT 'free',
  `booked_by` VARCHAR(100) DEFAULT NULL,
  `admin_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `phone` VARCHAR(20) DEFAULT NULL,
  `service_type` VARCHAR(80) DEFAULT NULL,
  `has_review` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_booking_date` (`date`),
  KEY `idx_booking_booked_by` (`booked_by`),
  KEY `idx_booking_status` (`status`),
  KEY `idx_booking_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `reviews` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_email` VARCHAR(100) NOT NULL,
  `review_text` TEXT NOT NULL,
  `booking_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `rating` TINYINT(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_user_email` (`user_email`),
  KEY `idx_reviews_booking_id` (`booking_id`),
  KEY `idx_reviews_rating` (`rating`),
  KEY `idx_reviews_created_at` (`created_at`),
  CONSTRAINT `fk_reviews_user_email`
    FOREIGN KEY (`user_email`) REFERENCES `user` (`email`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_booking_id`
    FOREIGN KEY (`booking_id`) REFERENCES `booking` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `review_replies` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `review_id` INT NOT NULL,
  `reply_text` TEXT NOT NULL,
  `reply_date` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_replies_review_id` (`review_id`),
  CONSTRAINT `fk_review_replies_review_id`
    FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `page_visits` (
  `page` VARCHAR(120) NOT NULL,
  `visit_count` INT NOT NULL DEFAULT 0,
  `last_visit_date` DATETIME DEFAULT NULL,
  PRIMARY KEY (`page`),
  KEY `idx_page_visits_last_visit` (`last_visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `employee_profiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL,
  `role` VARCHAR(40) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `profile_data` LONGTEXT DEFAULT NULL,
  `profile_ready` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_email` (`email`),
  KEY `idx_employee_role` (`role`),
  KEY `idx_employee_status` (`status`),
  KEY `idx_employee_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 11.3. Создание подзапросов с использованием DML операторов

INSERT INTO `page_visits` (`page`, `visit_count`, `last_visit_date`)
SELECT
  'reviews_without_reply',
  COUNT(*),
  NOW()
FROM `reviews` AS r
WHERE r.`id` NOT IN (
  SELECT rr.`review_id`
  FROM `review_replies` AS rr
)
ON DUPLICATE KEY UPDATE
  `visit_count` = VALUES(`visit_count`),
  `last_visit_date` = VALUES(`last_visit_date`);

UPDATE `booking`
SET `has_review` = 1
WHERE `id` IN (
  SELECT DISTINCT r.`booking_id`
  FROM `reviews` AS r
  WHERE r.`booking_id` IS NOT NULL
);

UPDATE `booking`
SET `status` = 'approved',
    `admin_approved` = 1
WHERE `id` IN (
  SELECT reviewed_booking.`booking_id`
  FROM (
    SELECT r.`booking_id`
    FROM `reviews` AS r
    WHERE r.`booking_id` IS NOT NULL
      AND r.`rating` >= 4
  ) AS reviewed_booking
);

DELETE FROM `review_replies`
WHERE `review_id` IN (
  SELECT old_reviews.`id`
  FROM (
    SELECT r.`id`
    FROM `reviews` AS r
    WHERE r.`created_at` < DATE_SUB(NOW(), INTERVAL 2 YEAR)
  ) AS old_reviews
);

-- 11.4. Создание сложных запросов с использованием оператора JOIN

SELECT
  u.`id` AS user_id,
  u.`username`,
  u.`email`,
  b.`id` AS booking_id,
  b.`date` AS booking_date,
  b.`status` AS booking_status,
  b.`service_type`,
  r.`id` AS review_id,
  r.`rating`,
  r.`review_text`
FROM `user` AS u
LEFT JOIN `booking` AS b
  ON b.`booked_by` = u.`email`
LEFT JOIN `reviews` AS r
  ON r.`booking_id` = b.`id`
ORDER BY b.`date` DESC, r.`created_at` DESC;

SELECT
  r.`id` AS review_id,
  r.`user_email`,
  u.`username`,
  r.`rating`,
  r.`review_text`,
  rr.`reply_text`,
  rr.`reply_date`
FROM `reviews` AS r
INNER JOIN `user` AS u
  ON u.`email` = r.`user_email`
LEFT JOIN `review_replies` AS rr
  ON rr.`review_id` = r.`id`
ORDER BY r.`created_at` DESC, rr.`reply_date` ASC;

SELECT
  ep.`role`,
  ep.`status`,
  COUNT(ep.`id`) AS employee_count,
  COUNT(CASE WHEN ep.`profile_ready` = 1 THEN 1 END) AS ready_profiles
FROM `employee_profiles` AS ep
GROUP BY ep.`role`, ep.`status`
ORDER BY ep.`role`, ep.`status`;

SELECT
  b.`service_type`,
  COUNT(b.`id`) AS total_bookings,
  COUNT(r.`id`) AS total_reviews,
  ROUND(AVG(r.`rating`), 2) AS average_rating
FROM `booking` AS b
LEFT JOIN `reviews` AS r
  ON r.`booking_id` = b.`id`
GROUP BY b.`service_type`
HAVING COUNT(b.`id`) > 0
ORDER BY average_rating DESC, total_bookings DESC;

-- 11.5. Создание представления к таблицам БД

CREATE OR REPLACE VIEW `view_user_bookings` AS
SELECT
  u.`id` AS user_id,
  u.`username`,
  u.`email`,
  b.`id` AS booking_id,
  b.`date` AS booking_date,
  b.`status` AS booking_status,
  b.`service_type`,
  b.`phone`,
  b.`admin_approved`,
  b.`has_review`
FROM `user` AS u
LEFT JOIN `booking` AS b
  ON b.`booked_by` = u.`email`;

CREATE OR REPLACE VIEW `view_reviews_with_replies` AS
SELECT
  r.`id` AS review_id,
  r.`user_email`,
  u.`username`,
  r.`booking_id`,
  r.`rating`,
  r.`review_text`,
  r.`created_at` AS review_created_at,
  rr.`id` AS reply_id,
  rr.`reply_text`,
  rr.`reply_date`
FROM `reviews` AS r
INNER JOIN `user` AS u
  ON u.`email` = r.`user_email`
LEFT JOIN `review_replies` AS rr
  ON rr.`review_id` = r.`id`;

CREATE OR REPLACE VIEW `view_booking_statistics` AS
SELECT
  b.`service_type`,
  b.`status`,
  COUNT(b.`id`) AS booking_count,
  COUNT(r.`id`) AS review_count,
  ROUND(AVG(r.`rating`), 2) AS average_rating
FROM `booking` AS b
LEFT JOIN `reviews` AS r
  ON r.`booking_id` = b.`id`
GROUP BY b.`service_type`, b.`status`;

CREATE OR REPLACE VIEW `view_active_employees` AS
SELECT
  ep.`id`,
  ep.`full_name`,
  ep.`role`,
  ep.`email`,
  ep.`phone`,
  ep.`profile_ready`,
  ep.`updated_at`
FROM `employee_profiles` AS ep
WHERE ep.`status` = 'active';
