-- DB setup_infinityfree.sql
-- สำหรับ InfinityFree: ไม่มี CREATE DATABASE / USE (ใช้ DB ที่ host สร้างให้แล้ว)
-- Import ผ่าน phpMyAdmin โดยเลือก database if0_42119828_testlekha ก่อน แล้วกด Import ไฟล์นี้

-- ลบตารางเดิมถ้ามีอยู่ เพื่อความสะอาดในการติดตั้งใหม่
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `reject_logs`;
DROP TABLE IF EXISTS `owners`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `users`;

-- 1. ตารางผู้ใช้งานระบบ (Users)
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_user_id` VARCHAR(255) UNIQUE NOT NULL,
    `user_name` VARCHAR(100) NOT NULL,
    `job_title` VARCHAR(100) DEFAULT NULL,
    `work_context` TEXT DEFAULT NULL,
    `has_teammates` TINYINT DEFAULT 0,
    `teammate_roles` TEXT DEFAULT NULL,
    `agent_areas` TEXT DEFAULT NULL,
    `reject_cases` TEXT DEFAULT NULL,
    `reject_reasons` TEXT DEFAULT NULL,
    `bot_name` VARCHAR(100) DEFAULT 'เลขา AI',
    `persona_style` VARCHAR(50) DEFAULT 'formal_polite',
    `business_type` VARCHAR(100) DEFAULT 'Real Estate',
    `google_sheet_id` VARCHAR(255) NULL,
    `google_drive_id` VARCHAR(255) DEFAULT NULL,
    `encryption_key` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `consent_required` TINYINT DEFAULT 1,
    `consent_optional` TINYINT DEFAULT 0,
    `trial_ends_at` DATETIME NOT NULL,
    `is_subscribed` TINYINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_line_user_id` (`line_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ตารางเก็บข้อมูลลีดลูกค้า (Leads)
CREATE TABLE `leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `lead_code` VARCHAR(50) NOT NULL,
    `lead_name_enc` TEXT DEFAULT NULL,
    `project_enc` TEXT DEFAULT NULL,
    `phone_enc` TEXT DEFAULT NULL,
    `line_id_enc` TEXT DEFAULT NULL,
    `budget_enc` TEXT DEFAULT NULL,
    `potential` VARCHAR(10) DEFAULT 'B',
    `occupation_enc` TEXT DEFAULT NULL,
    `contact_date` DATE DEFAULT NULL,
    `target_date_enc` TEXT DEFAULT NULL,
    `pain_point_enc` TEXT DEFAULT NULL,
    `requirement_enc` TEXT DEFAULT NULL,
    `financials_enc` TEXT DEFAULT NULL,
    `residents_count_enc` TEXT DEFAULT NULL,
    `parking_count_enc` TEXT DEFAULT NULL,
    `background_enc` TEXT DEFAULT NULL,
    `current_update_enc` TEXT DEFAULT NULL,
    `status` ENUM('Call', 'Follow', 'Appointment', 'Show', 'Nego', 'Close', 'Bank', 'Win', 'Hold_Reject', 'Rejected') DEFAULT 'Call',
    `next_plan_action_enc` TEXT DEFAULT NULL,
    `next_plan_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_user_lead` (`user_id`, `lead_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ตารางเก็บข้อมูลฝั่งเจ้าของทรัพย์สิน (Owners)
CREATE TABLE `owners` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `code_list` VARCHAR(50) NOT NULL,
    `owner_name_enc` TEXT DEFAULT NULL,
    `project_enc` TEXT DEFAULT NULL,
    `listing_date` DATE DEFAULT NULL,
    `marketing_status` VARCHAR(50) DEFAULT 'ลงการตลาดแล้ว',
    `incomplete_details_enc` TEXT DEFAULT NULL,
    `property_type_enc` TEXT DEFAULT NULL,
    `phone_enc` TEXT DEFAULT NULL,
    `line_id_enc` TEXT DEFAULT NULL,
    `zone_enc` TEXT DEFAULT NULL,
    `area_enc` TEXT DEFAULT NULL,
    `location_grade_enc` TEXT DEFAULT NULL,
    `bts_mrt_srt_enc` TEXT DEFAULT NULL,
    `arl_enc` TEXT DEFAULT NULL,
    `bed_enc` TEXT DEFAULT NULL,
    `bath_enc` TEXT DEFAULT NULL,
    `unit_no_enc` TEXT DEFAULT NULL,
    `area_rai_enc` TEXT DEFAULT NULL,
    `area_ngan_enc` TEXT DEFAULT NULL,
    `area_sqwa_enc` TEXT DEFAULT NULL,
    `area_sqm_enc` TEXT DEFAULT NULL,
    `floor_enc` TEXT DEFAULT NULL,
    `parking_enc` TEXT DEFAULT NULL,
    `direction_enc` TEXT DEFAULT NULL,
    `asking_price_enc` TEXT DEFAULT NULL,
    `rental_price_enc` TEXT DEFAULT NULL,
    `selling_condition` VARCHAR(100) DEFAULT '50/50 Transfer Fee',
    `map_url_enc` TEXT DEFAULT NULL,
    `availability_status` VARCHAR(50) DEFAULT 'ยังขายอยู่',
    `sold_by_enc` TEXT DEFAULT NULL,
    `sold_price_enc` TEXT DEFAULT NULL,
    `sales_status` VARCHAR(50) DEFAULT 'Sale',
    `owner_urgency` VARCHAR(10) DEFAULT 'B',
    `selling_reason_enc` TEXT DEFAULT NULL,
    `selling_timeline_enc` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_user_owner` (`user_id`, `code_list`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ตารางบันทึกประวัติการปฏิเสธดีล (Reject Logs)
CREATE TABLE `reject_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `target_code` VARCHAR(50) NOT NULL,
    `reject_case` VARCHAR(100) NOT NULL,
    `reject_reason` VARCHAR(255) NOT NULL,
    `raw_message_enc` TEXT DEFAULT NULL,
    `rejected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ตารางงาน/การติดตามผล (Tasks)
CREATE TABLE `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title_enc` TEXT NOT NULL,
    `due_date` DATE DEFAULT NULL,
    `is_completed` TINYINT DEFAULT 0,
    `lead_code` VARCHAR(50) DEFAULT NULL,
    `owner_code` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
